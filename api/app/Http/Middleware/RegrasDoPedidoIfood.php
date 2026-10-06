<?php

namespace App\Http\Middleware;

use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\PedidosIfood;
use App\Support\Entregas\StatusDoPedido;
use Closure;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController as OrderDaApi;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController as OrderDoConsole;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: regras dos pedidos iFood nas rotas do Fleet-Ops (que vêm do Composer), pela ação que o
 * roteador casou (o caminho, codificado ou não, não importa). Spec, seções 3 e 4.
 *
 * 1. Cancelamento do nosso lado é proibido (400 "Pedido do iFood: o cancelamento é feito no iFood"): o Logistics não
 *    tem ação de cancelar, e para nós vale o CAN do iFood (CancelamentoPeloIfood). Com problema do motoboy, a central
 *    troca o motoboy.
 *    - API v1: DELETE v1/orders/{id}/cancel (cancelOrder), a atividade "canceled" (update-activity) e o PUT
 *      v1/orders/{id} (update) com `status` de cancelamento;
 *    - console: PATCH int/v1/orders/cancel (corpo `order` = uuid), PATCH int/v1/orders/bulk-cancel (`ids`), a
 *      atividade "canceled" (PATCH int/v1/orders/update-activity/{id}, que o quadro usa ao arrastar o card) e o
 *      PUT/PATCH int/v1/orders/{id} (updateRecord) com `order.status` (ou `status`) de cancelamento.
 *    - O portal da loja é barrado no RegrasPortalLoja (rota do customer-portal), sempre.
 *    - Saída da central (console e API v1): o cancelamento passa quando a linha já tem cancelado_pelo_ifood_em (o
 *      iFood já cancelou: CAN que chegou sem cancelar o Order, ou cancelamento local que falhou) ou quando a integração
 *      está desligada (!ClienteIfood::ligada(), o desligamento de emergência com ENTREGAS_IFOOD vazio). Fica no log
 *      ("cancelamento liberado no pedido do iFood", só com ids nossos).
 * 2. Trava "Atualize o app" (só com ClienteIfood::exigeAppNovo(), ENTREGAS_IFOOD_EXIGE_APP_NOVO=1): a conclusão comum
 *    pela API v1 (atividade "completed" ou que conclui o pedido no update-activity, e o POST v1/orders/{id}/complete) de
 *    pedido iFood é recusada com 400 até a rota concluir-ifood do APK novo liberar (conclusao_liberada_em: chegada
 *    avisada e, se exigido, código conferido). O APK antigo não conhece a rota e não consegue concluir. A conclusão
 *    pelo console é decisão da central e passa (o AcoesIfood registra conclusao_sem_codigo quando faltou o código).
 *
 * O pedido só é consultado quando a ação pode ser barrada (cancelamento, ou conclusão com a trava ligada): as outras
 * atividades do app (update-activity neutra) passam sem ir ao banco. As recusas ficam no log ("ação barrada no pedido do
 * iFood", só com ids nossos).
 *
 * Registrado nos grupos fleetbase.api (v1) e fleetbase.protected (int/v1) pelo RouteServiceProvider, antes do
 * BarrarAceiteDePedidoEncerrado: roda depois da autenticação, com a empresa na sessão (o filtro da empresa é explícito;
 * nenhum model registra o CompanyScope nesta versão). Os erros saem no formato de cada API: {"error": "..."} na v1 (o do
 * Fleet-Ops) e {"errors": ["..."]} no console.
 */
class RegrasDoPedidoIfood
{
    public const CANCELAR_NA_API     = OrderDaApi::class . '@cancelOrder';
    public const ATUALIZAR_NA_API    = OrderDaApi::class . '@update';
    public const ATUALIZAR_NO_CONSOLE = OrderDoConsole::class . '@updateRecord';
    public const ATIVIDADE_NA_API    = OrderDaApi::class . '@updateActivity';
    public const CONCLUIR_NA_API     = OrderDaApi::class . '@completeOrder';
    public const CANCELAR_NO_CONSOLE = OrderDoConsole::class . '@cancel';
    public const CANCELAR_EM_LOTE    = OrderDoConsole::class . '@bulkCancel';
    public const ATIVIDADE_NO_CONSOLE = OrderDoConsole::class . '@updateActivity';

    public const MENSAGEM_CANCELAMENTO = 'Pedido do iFood: o cancelamento é feito no iFood.';
    public const MENSAGEM_APP_ANTIGO   = 'Atualize o app para concluir pedidos do iFood.';

    /** Códigos de atividade de cancelamento. */
    public const ATIVIDADES_DE_CANCELAMENTO = ['canceled', 'cancelled', 'order_canceled'];

    public function handle(Request $request, Closure $next)
    {
        $rota = $request->route();
        $acao = $rota instanceof Route ? ltrim($rota->getActionName(), '\\') : null;

        switch ($acao) {
            case static::CANCELAR_NA_API:
                return $this->cancelamentoBarrado($this->pedidoPeloId($request->route('id'))) ? $this->recusarNaApi($request, static::MENSAGEM_CANCELAMENTO) : $next($request);

            case static::CONCLUIR_NA_API:
                return ClienteIfood::exigeAppNovo() && $this->travaDoAppAntigo($this->pedidoPeloId($request->route('id'))) ? $this->recusarNaApi($request, static::MENSAGEM_APP_ANTIGO) : $next($request);

            case static::ATIVIDADE_NA_API:
                // decide pela atividade antes de ir ao banco: a atividade neutra do app passa sem consulta
                $atividade = $request->array('activity');
                if ($this->cancela($atividade)) {
                    return $this->cancelamentoBarrado($this->pedidoPeloId($request->route('id'))) ? $this->recusarNaApi($request, static::MENSAGEM_CANCELAMENTO) : $next($request);
                }
                if ($this->conclui($atividade) && ClienteIfood::exigeAppNovo()) {
                    return $this->travaDoAppAntigo($this->pedidoPeloId($request->route('id'))) ? $this->recusarNaApi($request, static::MENSAGEM_APP_ANTIGO) : $next($request);
                }

                return $next($request);

            case static::ATUALIZAR_NA_API:
                return $this->statusCancela($request->input('status')) && $this->cancelamentoBarrado($this->pedidoPeloId($request->route('id'))) ? $this->recusarNaApi($request, static::MENSAGEM_CANCELAMENTO) : $next($request);

            case static::ATUALIZAR_NO_CONSOLE:
                // o updateRecord lê o corpo em `order` (ou o corpo inteiro): HasApiModelBehavior::getApiPayloadFromRequest
                $dados  = $request->input('order');
                $status = is_array($dados) && array_key_exists('status', $dados) ? $dados['status'] : $request->input('status');

                if (!$this->statusCancela($status)) {
                    return $next($request);
                }
                $pedido = $this->pedidoPeloId($request->route('id'));

                return $this->cancelamentoBarrado($pedido) ? $this->recusarNoConsole($pedido) : $next($request);

            case static::CANCELAR_NO_CONSOLE:
                $pedido = $this->pedidoPeloId($request->input('order'));

                return $this->cancelamentoBarrado($pedido) ? $this->recusarNoConsole($pedido) : $next($request);

            case static::CANCELAR_EM_LOTE:
                foreach ((array) $request->input('ids', []) as $id) {
                    $pedido = $this->pedidoPeloId($id);
                    if ($this->cancelamentoBarrado($pedido)) {
                        return $this->recusarNoConsole($pedido, 'Há pedido do iFood na seleção: o cancelamento dele é feito no iFood. Tire-o da seleção e tente de novo.');
                    }
                }

                return $next($request);

            case static::ATIVIDADE_NO_CONSOLE:
                if (!$this->cancela($request->array('activity'))) {
                    return $next($request);
                }
                $pedido = $this->pedidoPeloId($request->route('id'));

                return $this->cancelamentoBarrado($pedido) ? $this->recusarNoConsole($pedido) : $next($request);
        }

        return $next($request);
    }

    /** O pedido da empresa da sessão pelo uuid, public_id ou internal_id (as rotas aceitam os três), ou null. */
    protected function pedidoPeloId($id): ?Order
    {
        if (!is_string($id) || $id === '') {
            return null;
        }

        return Order::where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id)->orWhere('internal_id', $id))
            ->when(session('company'), fn ($q, $empresa) => $q->where('company_uuid', $empresa))
            ->first();
    }

    /**
     * Cancelamento pela central (console ou API v1) barrado: pedido do iFood, com a integração ligada e sem
     * cancelado_pelo_ifood_em. Com o iFood já cancelado ou a integração desligada, passa (e fica no log).
     */
    protected function cancelamentoBarrado(?Order $pedido): bool
    {
        $linha = $pedido !== null ? PedidosIfood::doPedido((string) $pedido->uuid) : null;
        if ($linha === null) {
            return false;
        }

        $motivo = $linha->cancelado_pelo_ifood_em ? 'cancelado pelo iFood' : (ClienteIfood::ligada() ? null : 'integração desligada');
        if ($motivo === null) {
            return true;
        }

        Log::info('[entregas] ifood: cancelamento liberado no pedido do iFood', ['motivo' => $motivo, 'pedido' => $pedido->public_id, 'order_uuid' => $pedido->uuid]);

        return false;
    }

    /** Trava ligada, pedido iFood e conclusão ainda não liberada pela rota concluir-ifood. */
    protected function travaDoAppAntigo(?Order $pedido): bool
    {
        if ($pedido === null || !ClienteIfood::exigeAppNovo()) {
            return false;
        }

        $linha = PedidosIfood::doPedido((string) $pedido->uuid);

        return $linha !== null && !$linha->conclusao_liberada_em;
    }

    protected function cancela(array $atividade): bool
    {
        return in_array($atividade['code'] ?? null, static::ATIVIDADES_DE_CANCELAMENTO, true);
    }

    /** O status gravado direto no pedido (PUT do pedido) é de cancelamento? */
    protected function statusCancela($status): bool
    {
        return is_string($status) && in_array(strtolower(trim($status)), StatusDoPedido::CANCELADOS, true);
    }

    protected function conclui(array $atividade): bool
    {
        return ($atividade['code'] ?? null) === 'completed' || filter_var($atividade['complete'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    protected function recusarNaApi(Request $request, string $mensagem)
    {
        Log::info('[entregas] ifood: ação barrada no pedido do iFood', ['motivo' => $mensagem, 'pedido' => $request->route('id')]);

        return response()->apiError($mensagem, 400);
    }

    protected function recusarNoConsole(?Order $pedido, string $mensagem = self::MENSAGEM_CANCELAMENTO)
    {
        Log::info('[entregas] ifood: ação barrada no pedido do iFood', ['motivo' => $mensagem, 'pedido' => $pedido?->public_id, 'order_uuid' => $pedido?->uuid]);

        return response()->json(['errors' => [$mensagem]], 400);
    }
}
