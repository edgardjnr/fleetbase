<?php

namespace App\Http\Middleware;

use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Distribuicoes;
use App\Support\Entregas\Distribuicao\Distribuidor;
use App\Support\Entregas\MotoboyDaSessao;
use App\Support\Entregas\StatusDoPedido;
use App\Support\Entregas\TravaDoPedido;
use Closure;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: o motoboy não aceita pedido encerrado (cancelado, concluído ou expirado), e o aceite
 * nunca corre junto com o cancelamento pela loja ou pela API v1.
 *
 * Por quê: o aceite do app (POST v1/orders/{id}/start, OrderController@startOrder do Fleet-Ops, que vem do
 * Composer) só recusa pedido já iniciado. Um motoboy que recebeu o aviso de um pedido que a loja cancelou depois
 * ainda consegue aceitá-lo, e o pedido "ressuscita": fica atribuído, iniciado e com o status da atividade de
 * início por cima do cancelado. Se for concluído, entra no pagamento dos motoboys e na cobrança da loja.
 *
 * A conferência e o aceite inteiro rodam com a trava do pedido (TravaDoPedido), a mesma do cancelamento pelo
 * portal da loja (RegrasPortalLoja): ou o cancelamento termina antes e o aceite é barrado aqui, ou o aceite
 * termina antes e o cancelamento vê o pedido iniciado. Dois motoboys aceitando juntos também entram em fila,
 * e o segundo leva o "Order has already started." do Fleet-Ops.
 *
 * Pedido passado para outro motoboy (o líder dos motoboys pela aba Mapa do app, que desliga o adhoc; ou a central pelo
 * console, mas só quando o pedido já não era aberto: o modal de troca do console não mexe no adhoc, e um pedido ainda
 * aberto continua aceitável por qualquer motoboy, que o startOrder reatribui pelo `assign`): o pedido não é aberto e
 * fica atribuído ao novo. O motoboy anterior ainda pode ter o card do pedido aberto
 * e tocar em Aceitar (POST v1/orders/{id}/start com assign = ele): como o startOrder do Fleet-Ops só usa o `assign`
 * em pedido aberto, ele iniciaria o pedido em nome do novo motoboy. Aqui o aceite é barrado (409) quando o pedido não
 * é aberto, tem motoboy atribuído e quem aceita é outro: o motoboy da sessão (token de motoboy, MotoboyDaSessao; chave
 * de API não tem motoboy da sessão e não é conferida por aí) ou o `assign` enviado.
 *
 * Distribuição de pedidos abertos (ENTREGAS_DISTRIBUICAO): na fase `ofertas` só aceita quem tem a oferta pendente (ou a
 * vencida, enquanto ninguém foi oferecido depois), `Distribuicoes::ofertaParaAceite`; quem aceita é o motoboy da sessão
 * ou, sem ele, o `assign`. Os outros levam 409. O aceite válido (resposta 2xx do startOrder) registra a oferta como
 * aceita (`Distribuidor::registrarAceite`, sem trava própria: já roda dentro desta). Na fase `aberta` o aceite é livre.
 * Em rodadas (ENTREGAS_DISTRIBUICAO_RODADAS), com a lista aberta (`lista_aberta_em`) qualquer motoboy aceita: quem tem
 * a oferta grava `aceita` nela; os outros, a linha `aceita_pela_lista` (`Distribuidor::registrarAceitePelaLista`, que
 * cancela a pendente). E quem tenta aceitar um pedido aberto que outro já iniciou leva 409 "Este pedido passou para
 * outro motoboy." (sem rodadas, segue o "Order has already started." do Fleet-Ops).
 *
 * O cancelamento da API v1 (DELETE v1/orders/{id}/cancel, OrderController@cancelOrder, o caminho provável da
 * integração iFood) também roda com a trava, sem conferência nenhuma: ele cancela até pedido iniciado, que é
 * decisão de quem chama, mas, se chegasse no meio de um aceite, o startOrder gravaria o status de início por cima
 * do cancelamento. O início e o cancelamento pela central (int/v1) ficam fora: são decisão da central.
 *
 * Se a trava não sair no tempo de espera (TravaDoPedido::ESPERA), o aceite responde 409 e o motoboy tenta de novo,
 * mas o cancelamento nunca é recusado por causa dela: segue sem a trava, com um aviso no log. Uma trava presa por
 * tanto tempo quase sempre é de um processo que morreu (o aceite grava o início em milissegundos), e um 409 perderia
 * o cancelamento se a integração não repetisse a chamada: o motoboy entregaria um pedido cancelado, cobrado da loja.
 *
 * Fica no fim do grupo de middleware `fleetbase.api` das rotas v1 (registrado no RouteServiceProvider), não na
 * lista global: roda depois da autenticação (AuthenticateOnceWithBasicAuth), então só vê requisições autenticadas
 * (sem credencial válida, o core responde 401 antes, sem a trava) e já com a sessão da empresa montada. A busca
 * do pedido filtra pela empresa da sessão, como o findRecordOrFail das duas ações (nenhum model registra o
 * CompanyScope nesta versão: o filtro tem de ser explícito).
 *
 * Os erros saem com as duas chaves, `{"error": "...", "errors": ["..."]}` (recusar()): `error` é o formato da API v1
 * (o mesmo do startOrder) e `errors` é o que o @fleetbase/sdk lê para montar a mensagem do erro. Os dois aceites do app
 * (AdhocOrderCard e OrderScreen, no entregas-navigator) mostram essa mensagem em toast e recarregam a lista.
 */
class BarrarAceiteDePedidoEncerrado
{
    /** Ações da API v1 que rodam com a trava do pedido (o grupo fleetbase.api serve a API v1 inteira). */
    public const ACAO_DO_ACEITE = OrderController::class . '@startOrder';

    public const ACAO_DO_CANCELAMENTO = OrderController::class . '@cancelOrder';

    public function handle(Request $request, Closure $next)
    {
        // pela ação da rota que o roteador casou: o caminho, codificado ou não, não importa
        $rota = $request->route();
        $acao = $rota instanceof Route ? ltrim($rota->getActionName(), '\\') : null;
        if ($acao !== static::ACAO_DO_ACEITE && $acao !== static::ACAO_DO_CANCELAMENTO) {
            return $next($request);
        }

        // inexistente: o próprio controller responde 404
        $pedido = $this->pedido($request->route('id'));
        if (!$pedido) {
            return $next($request);
        }

        $aceite = $acao === static::ACAO_DO_ACEITE;

        try {
            return TravaDoPedido::executar($pedido->uuid, $aceite
                ? fn () => $this->aceitarComATrava($request, $next, $pedido)
                // o cancelamento só entra na fila, sem conferência (o Fleet-Ops cancela em qualquer status)
                : fn () => $next($request));
        } catch (LockTimeoutException $e) {
            if (!$aceite) {
                // o cancelamento nunca é recusado por causa da trava: presa por tanto tempo, ela quase sempre é de um
                // processo morto, e um 409 perderia o cancelamento se a integração não repetisse a chamada
                Log::warning('[entregas] cancelamento pela API v1: trava do pedido ocupada, seguindo sem a trava', [
                    'pedido' => $pedido->public_id,
                    'motivo' => 'a trava não saiu em ' . TravaDoPedido::ESPERA
                        . ' s (quem a segurava provavelmente morreu)',
                ]);

                return $next($request);
            }

            Log::warning(
                '[entregas] aceite do motoboy: trava do pedido ocupada',
                ['pedido' => $pedido->public_id, 'ip' => $request->ip()]
            );

            return $this->recusar(
                'Este pedido está sendo atualizado neste momento. Tente aceitar de novo em alguns segundos.',
                409
            );
        }
    }

    /** A recusa com as duas chaves: `error` (formato da API v1) e `errors` (o que o @fleetbase/sdk do app lê). */
    protected function recusar(string $mensagem, int $status = 400)
    {
        return response()->json(['error' => $mensagem, 'errors' => [$mensagem]], $status);
    }

    /**
     * A mesma busca do startOrder e do cancelOrder (Order::findRecordOrFail): public_id ou internal_id e, com a
     * empresa na sessão (depois da autenticação, sempre), só nessa empresa.
     */
    protected function pedido($id): ?Order
    {
        if (!is_string($id) || $id === '') {
            return null;
        }

        return Order::where(fn ($q) => $q->where('public_id', $id)->orWhere('internal_id', $id))
            ->when(session('company'), fn ($q, $empresa) => $q->where('company_uuid', $empresa))
            ->first();
    }

    protected function aceitarComATrava(Request $request, Closure $next, Order $pedido)
    {
        // relido com a trava: um cancelamento (ou uma troca de motoboy) que terminou enquanto esta requisição esperava
        // aparece aqui
        $atual  = Order::where('uuid', $pedido->uuid)->first();
        $status = $atual?->status;

        if (in_array($status, StatusDoPedido::ENCERRADOS, true)) {
            Log::info('[entregas] aceite do motoboy barrado: pedido encerrado', [
                'pedido'  => $pedido->public_id,
                'status'  => $status,
                'motoboy' => $request->input('assign'),
                'ip'      => $request->ip(),
            ]);

            return $this->recusar(in_array($status, StatusDoPedido::CANCELADOS, true)
                ? 'Este pedido foi cancelado.'
                : 'Este pedido já foi encerrado.');
        }

        if ($atual && $this->passouParaOutroMotoboy($request, $atual)) {
            Log::info('[entregas] aceite do motoboy barrado: pedido passou para outro motoboy', [
                'pedido'  => $pedido->public_id,
                'motoboy' => $request->input('assign'),
                'ip'      => $request->ip(),
            ]);

            return $this->recusar('Este pedido passou para outro motoboy.', 409);
        }

        if ($atual && $this->aceitoPorOutroNaDistribuicao($request, $atual)) {
            Log::info('[entregas] aceite do motoboy barrado: pedido passou para outro motoboy', [
                'pedido'  => $pedido->public_id,
                'motoboy' => $request->input('assign'),
                'ip'      => $request->ip(),
            ]);

            return $this->recusar('Este pedido passou para outro motoboy.', 409);
        }

        // distribuição de pedidos abertos: na fase ofertas só quem tem a oferta aceita (Distribuicoes::ofertaParaAceite)
        if ($atual && Distribuicao::ligada() && Distribuicoes::emOfertas((string) $atual->uuid)) {
            $distribuicao = Distribuicoes::doPedido((string) $atual->uuid);
            if (Distribuicao::emRodadas() && $distribuicao && $distribuicao->lista_aberta_em) {
                return $this->aceitarPelaLista($request, $next, $pedido, $distribuicao);
            }
            $quem   = $this->quemAceita($request);
            $oferta = $quem && !$this->assignDivergeDaSessao($request)
                ? Distribuicoes::ofertaParaAceite((string) $atual->uuid, $quem)
                : null;
            if (!$oferta) {
                Log::info('[entregas] aceite do motoboy barrado: pedido oferecido a outro motoboy', [
                    'pedido'  => $pedido->public_id,
                    'motoboy' => $request->input('assign'),
                    'ip'      => $request->ip(),
                ]);

                return $this->recusar('Este pedido está sendo oferecido a outro motoboy.', 409);
            }

            $resposta = $next($request);
            if ($this->deuCerto($resposta)) {
                try {
                    app(Distribuidor::class)->registrarAceite($oferta);
                } catch (\Throwable $e) {
                    // o startOrder já gravou o aceite: a falha aqui nunca o derruba (a varredura encerra a distribuição)
                    Log::warning('[entregas] distribuição: falha ao registrar o aceite', [
                        'pedido' => $pedido->public_id,
                        'erro'   => get_class($e),
                    ]);
                }
            }

            return $resposta;
        }

        // o aceite inteiro com a trava: um cancelamento que chegar agora espera o aceite terminar
        return $next($request);
    }

    /**
     * Pedido não aberto (adhoc falso) com motoboy atribuído, aceito por outro: o motoboy da sessão é diferente do
     * atribuído, ou o `assign` enviado não é o public_id do atribuído. Sem motoboy da sessão (chave de API) e sem `assign`,
     * nada a conferir.
     */
    protected function passouParaOutroMotoboy(Request $request, $pedido): bool
    {
        if ($pedido->adhoc || !$pedido->driver_assigned_uuid) {
            return false;
        }

        $daSessao = MotoboyDaSessao::motoboy($request);
        if ($daSessao && (string) $daSessao->uuid !== (string) $pedido->driver_assigned_uuid) {
            return true;
        }

        $assign = $request->input('assign');
        if (is_string($assign) && $assign !== '') {
            $atribuido = Driver::where('uuid', $pedido->driver_assigned_uuid)->first();

            return $atribuido && (string) $atribuido->public_id !== $assign;
        }

        return false;
    }

    /**
     * Lista aberta (distribuição em rodadas): qualquer motoboy aceita (o primeiro leva; a trava garante um só). Com o 2xx
     * do startOrder, quem tem a oferta (a pendente, ou a vencida enquanto ninguém foi oferecido depois) grava `aceita`
     * nela; os outros, `aceita_pela_lista`. Uma falha ao registrar nunca derruba o aceite (a varredura encerra). Motoboy
     * da sessão com `assign` de outro: 409, como na fase de ofertas.
     */
    protected function aceitarPelaLista(Request $request, Closure $next, Order $pedido, object $distribuicao)
    {
        // o startOrder atribuiria a outro motoboy (o `assign`), e o registro gravaria o da sessão: o mesmo 409 das ofertas
        if ($this->assignDivergeDaSessao($request)) {
            Log::info('[entregas] aceite do motoboy barrado: pedido oferecido a outro motoboy', [
                'pedido'  => $pedido->public_id,
                'motoboy' => $request->input('assign'),
                'ip'      => $request->ip(),
            ]);

            return $this->recusar('Este pedido está sendo oferecido a outro motoboy.', 409);
        }
        $quem     = $this->quemAceita($request);
        $oferta   = $quem ? Distribuicoes::ofertaParaAceite((string) $pedido->uuid, $quem) : null;
        $resposta = $next($request);
        if ($this->deuCerto($resposta)) {
            try {
                $oferta
                    ? app(Distribuidor::class)->registrarAceite($oferta)
                    : app(Distribuidor::class)->registrarAceitePelaLista($distribuicao, $quem);
            } catch (\Throwable $e) {
                Log::warning('[entregas] distribuição: falha ao registrar o aceite', [
                    'pedido' => $pedido->public_id,
                    'erro'   => get_class($e),
                ]);
            }
        }

        return $resposta;
    }

    /**
     * Em rodadas: o pedido aberto já foi aceito (iniciado, com motoboy) e quem tenta aceitar é outro (motoboy da sessão
     * ou `assign`). Sem rodadas: false (o Fleet-Ops responde "Order has already started.").
     */
    protected function aceitoPorOutroNaDistribuicao(Request $request, $pedido): bool
    {
        if (!Distribuicao::emRodadas() || !$pedido->adhoc || !$pedido->driver_assigned_uuid || !$pedido->started) {
            return false;
        }
        $quem = $this->quemAceita($request);

        return $quem !== null && $quem !== (string) $pedido->driver_assigned_uuid;
    }

    /** O uuid de quem está aceitando: o motoboy da sessão ou, sem ele, o `assign` (public_id). */
    protected function quemAceita(Request $request): ?string
    {
        $daSessao = MotoboyDaSessao::motoboy($request);
        if ($daSessao) {
            return (string) $daSessao->uuid;
        }
        $assign = $request->input('assign');
        if (is_string($assign) && $assign !== '') {
            $uuid = Driver::where('public_id', $assign)->first()?->uuid;

            return $uuid ? (string) $uuid : null;
        }

        return null;
    }

    /** Há motoboy na sessão e veio um `assign` que não é o public_id dele: o startOrder atribuiria a outro. */
    protected function assignDivergeDaSessao(Request $request): bool
    {
        $assign = $request->input('assign');
        if (!is_string($assign) || $assign === '') {
            return false;
        }
        $daSessao = MotoboyDaSessao::motoboy($request);

        return $daSessao && (string) $daSessao->public_id !== $assign;
    }

    /** A resposta do startOrder foi 2xx (o Response do Laravel tem getStatusCode; a dos testes, `status`). */
    protected function deuCerto($resposta): bool
    {
        if (is_object($resposta) && method_exists($resposta, 'getStatusCode')) {
            return $resposta->getStatusCode() < 300;
        }
        if (is_object($resposta) && isset($resposta->status)) {
            return (int) $resposta->status < 300;
        }

        return true;
    }
}
