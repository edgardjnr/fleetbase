<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\CalculoEntregas;
use App\Support\Entregas\ChatComACentral;
use App\Support\Entregas\ConversasDaLoja;
use App\Support\Entregas\Distribuicao\Distribuidor;
use App\Support\Entregas\GanhosDoMotoboy;
use App\Support\Entregas\Ifood\ConclusaoIfood;
use App\Support\Entregas\Ifood\DadosIfoodDoMotoboy;
use App\Support\Entregas\Ifood\PedidosIfood;
use App\Support\Entregas\MotoboyDaSessao;
use App\Support\Entregas\RotaDoPedido;
use App\Support\Entregas\SituacaoDoMotoboy;
use App\Support\Entregas\StatusDoPedido;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\Http\Resources\ChatChannel as ChatChannelResource;
use Fleetbase\Models\Company;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Entregas RestaurantePro: ganhos do motoboy no app (Navigator), na API v1, com o token dele.
 * - ganhos: as entregas concluídas dele no período, com o valor de cada uma e o total (tela Início do app);
 * - valor: km, faixa e valor de um pedido dele ou aberto (card de aceitar e detalhes do pedido);
 * - rota: o traçado loja → cliente de um pedido dele ou aberto e a situação dele (cor do capacete), para o mapa do pedido
 *   no app ficar igual ao do console (RotaDoPedido, SituacaoDoMotoboy).
 * - chatDoPedido: o botão "Chat" do cliente nos detalhes do pedido. Abre a conversa dele com a loja do pedido (a mesma
 *   do portal da loja, com a central dentro: ConversasDaLoja) ou, num pedido sem loja, com a central (ChatComACentral),
 *   no formato do chat do Fleetbase, para o app abrir a tela do canal;
 * - chatComACentral: a conversa dele com a central (APKs anteriores ao chatDoPedido).
 * - ifood, concluirIfood e codigoIfood: o pedido iFood no app (DadosIfoodDoMotoboy) e a conclusão com o código do
 *   cliente (ConclusaoIfood): o servidor avisa a chegada ao iFood, confere o código na hora e libera a conclusão comum.
 * O motoboy vem do token (MotoboyDaSessao); as respostas só trazem o valor pago a ele (GanhosDoMotoboy). Usuário de
 * loja nem chega aqui: o ProtegerPortalLoja nega a API v1 a ele.
 */
class MotoboyController extends Controller
{
    public function ganhos(Request $request, CalculoEntregas $calculo)
    {
        $motoboy = MotoboyDaSessao::motoboy($request);
        if (!$motoboy) {
            return $this->soParaMotoboy();
        }

        $request->validate([
            'inicio' => ['required', 'date_format:Y-m-d'],
            'fim'    => ['required', 'date_format:Y-m-d', 'after_or_equal:inicio'],
        ]);

        // até 3 meses por consulta, como o extrato da loja: carrega de uma vez os pedidos do período e calcula rotas
        if (GanhosDoMotoboy::diasDoPeriodo($request->input('inicio'), $request->input('fim')) > GanhosDoMotoboy::MAX_DIAS) {
            return response()->json(['errors' => ['Escolha um período de até 3 meses.']], 422);
        }

        $companyUuid = session('company');
        $fuso        = Company::where('uuid', $companyUuid)->value('timezone') ?: 'America/Sao_Paulo';
        $inicio      = Carbon::createFromFormat('Y-m-d', $request->input('inicio'), $fuso)->startOfDay()->setTimezone(date_default_timezone_get());
        $fim         = Carbon::createFromFormat('Y-m-d', $request->input('fim'), $fuso)->endOfDay()->setTimezone(date_default_timezone_get());

        $pedidos = $calculo->pedidosConcluidos($companyUuid, $inicio, $fim, function ($query) use ($motoboy) {
            $query->where('orders.driver_assigned_uuid', $motoboy->uuid);
        });
        [$entregas, $pendentes] = $calculo->entregas($pedidos, $fuso, GanhosDoMotoboy::LIMITE_CALCULOS);

        return response()->json(GanhosDoMotoboy::resumo($entregas, $request->input('inicio'), $request->input('fim'), $pendentes));
    }

    public function valor(Request $request, string $id, CalculoEntregas $calculo)
    {
        $motoboy = MotoboyDaSessao::motoboy($request);
        if (!$motoboy) {
            return $this->soParaMotoboy();
        }

        $pedido = $this->pedidoQuePodeVer($id, $motoboy);
        if (!$pedido) {
            return response()->json(['errors' => ['Pedido não encontrado.']], 404);
        }

        return response()->json(GanhosDoMotoboy::valor($pedido, $calculo->valorDoPedido($pedido)));
    }

    public function rota(Request $request, string $id, RotaDoPedido $rotas)
    {
        $motoboy = MotoboyDaSessao::motoboy($request);
        if (!$motoboy) {
            return $this->soParaMotoboy();
        }

        $pedido = $this->pedidoQuePodeVer($id, $motoboy);
        if (!$pedido) {
            return response()->json(['errors' => ['Pedido não encontrado.']], 404);
        }

        return response()->json([
            'pedido'   => $pedido->public_id,
            'rota'     => $rotas->doPedido($pedido),
            'situacao' => $this->situacao($motoboy),
        ]);
    }

    public function chatDoPedido(Request $request, string $id)
    {
        $motoboy = MotoboyDaSessao::motoboy($request);
        if (!$motoboy) {
            return $this->soParaMotoboy();
        }

        // nenhum model registra o CompanyScope nesta versão: a empresa é filtrada aqui
        $pedido = Order::where('company_uuid', session('company'))
            ->where(fn ($query) => $query->where('public_id', $id)->orWhere('uuid', $id))
            ->first();
        if (!$pedido) {
            return response()->json(['errors' => ['Pedido não encontrado.']], 404);
        }

        // só com o pedido dele: um pedido aberto ainda sem aceite não liga o motoboy à loja
        if ((string) $pedido->driver_assigned_uuid !== $motoboy->uuid) {
            return response()->json(['errors' => ['Aceite o pedido para conversar com a loja.']], 403);
        }

        // a loja do pedido é o Vendor dono dele (customer_uuid), como na cobrança e no portal da loja
        $loja = $pedido->customer_uuid
            ? Vendor::where('company_uuid', session('company'))->where('uuid', $pedido->customer_uuid)->first()
            : null;

        $canal = $loja
            ? ConversasDaLoja::abrir($loja, $motoboy, (string) $motoboy->user_uuid)
            : ChatComACentral::abrir($motoboy);
        if (!$canal) {
            return response()->json(['errors' => ['Não foi possível abrir a conversa.']], 422);
        }

        return new ChatChannelResource($canal);
    }

    public function chatComACentral(Request $request)
    {
        $motoboy = MotoboyDaSessao::motoboy($request);
        if (!$motoboy) {
            return $this->soParaMotoboy();
        }

        $canal = ChatComACentral::abrir($motoboy);
        if (!$canal) {
            return response()->json(['errors' => ['A central ainda não tem ninguém para conversar.']], 422);
        }

        return new ChatChannelResource($canal);
    }

    public function ifood(Request $request, string $id)
    {
        $motoboy = MotoboyDaSessao::motoboy($request);
        if (!$motoboy) {
            return $this->soParaMotoboy();
        }

        $pedido = $this->pedidoQuePodeVer($id, $motoboy);
        if (!$pedido) {
            return response()->json(['errors' => ['Pedido não encontrado.']], 404);
        }

        return response()->json(DadosIfoodDoMotoboy::resposta(PedidosIfood::doPedido((string) $pedido->uuid), $pedido, (string) $motoboy->uuid, now()->toDateTimeString()));
    }

    public function concluirIfood(Request $request, string $id, ConclusaoIfood $conclusao)
    {
        [$pedido, $linha, $erro] = $this->pedidoIfoodEmEntrega($request, $id);
        if ($erro) {
            return $erro;
        }

        return $this->respostaDaConclusao($conclusao->concluir($linha, $pedido));
    }

    public function codigoIfood(Request $request, string $id, ConclusaoIfood $conclusao)
    {
        [$pedido, $linha, $erro] = $this->pedidoIfoodEmEntrega($request, $id);
        if ($erro) {
            return $erro;
        }

        // o código que o cliente vê no app do iFood: só números (a documentação fala em 4 a 6 dígitos)
        $codigo = trim((string) $request->input('codigo'));
        if (!preg_match('/^\d{3,10}$/', $codigo)) {
            return response()->json(['errors' => ['Digite só os números do código que o cliente recebeu.']], 422);
        }

        return $this->respostaDaConclusao($conclusao->conferirCodigo($linha, $pedido, $codigo));
    }

    /**
     * O pedido iFood do motoboy em entrega (dele, iniciado, não encerrado nem cancelado pelo iFood): [Order, linha, null],
     * ou [null, null, resposta de erro].
     */
    protected function pedidoIfoodEmEntrega(Request $request, string $id): array
    {
        $motoboy = MotoboyDaSessao::motoboy($request);
        if (!$motoboy) {
            return [null, null, $this->soParaMotoboy()];
        }

        // nenhum model registra o CompanyScope nesta versão: a empresa é filtrada aqui
        $pedido = Order::where('company_uuid', session('company'))
            ->where(fn ($query) => $query->where('public_id', $id)->orWhere('uuid', $id))
            ->first();
        if (!$pedido || (string) $pedido->driver_assigned_uuid !== (string) $motoboy->uuid) {
            return [null, null, response()->json(['errors' => ['Pedido não encontrado.']], 404)];
        }

        $linha = PedidosIfood::doPedido((string) $pedido->uuid);
        if (!$linha) {
            return [null, null, response()->json(['errors' => ['Este pedido não é do iFood.']], 422)];
        }
        if ($linha->cancelado_pelo_ifood_em || in_array($pedido->status, StatusDoPedido::ENCERRADOS, true)) {
            return [null, null, response()->json(['errors' => ['Este pedido já foi encerrado.']], 409)];
        }
        if (!$pedido->started) {
            return [null, null, response()->json(['errors' => ['Inicie o pedido antes de concluir.']], 409)];
        }

        return [$pedido, $linha, null];
    }

    /**
     * O resultado da ConclusaoIfood como resposta: 200 com o resultado, 422 código incorreto, 429 muitas tentativas, 409
     * código não conferido (outro 4xx do iFood: a central libera), 503 tente de novo.
     */
    protected function respostaDaConclusao(string $resultado)
    {
        return match ($resultado) {
            ConclusaoIfood::PODE_CONCLUIR, ConclusaoIfood::PRECISA_CODIGO => response()->json(['resultado' => $resultado]),
            ConclusaoIfood::CODIGO_INCORRETO => response()->json(['resultado' => $resultado, 'errors' => ['Código incorreto. Peça o código de novo ao cliente.']], 422),
            ConclusaoIfood::MUITAS_TENTATIVAS => response()->json(['resultado' => $resultado, 'errors' => ['Muitas tentativas: peça à central para liberar.']], 429),
            ConclusaoIfood::CODIGO_NAO_CONFERIDO => response()->json(['resultado' => $resultado, 'errors' => ['Não foi possível conferir o código neste pedido. Peça à central para liberar.']], 409),
            default => response()->json(['resultado' => ConclusaoIfood::TENTE_DE_NOVO, 'errors' => ['Não consegui falar com o iFood agora. Tente de novo em alguns segundos.']], 503),
        };
    }

    /** Pedido da empresa da sessão (pelo public_id ou uuid) que o motoboy pode ver: dele ou aberto. */
    protected function pedidoQuePodeVer(string $id, Driver $motoboy): ?Order
    {
        // nenhum model registra o CompanyScope nesta versão: a empresa é filtrada aqui
        $pedido = Order::where('company_uuid', session('company'))
            ->where(fn ($query) => $query->where('public_id', $id)->orWhere('uuid', $id))
            ->with(['payload.pickup', 'payload.dropoff', 'payload.waypoints'])
            ->first();

        return $pedido && GanhosDoMotoboy::podeVer($pedido, $motoboy) ? $pedido : null;
    }

    /** A situação do motoboy com a mesma regra do capacete do console (MapaController@motoboys). */
    protected function situacao(Driver $motoboy): string
    {
        $pedidos = SituacaoDoMotoboy::pedidosEmAndamento((string) session('company'), [$motoboy->uuid])[$motoboy->uuid] ?? [];

        return SituacaoDoMotoboy::classificar((bool) $motoboy->online, array_map(fn ($pedido) => $pedido->status, $pedidos));
    }

    /**
     * Distribuição de pedidos abertos: o motoboy recusa a oferta que está com ele (o Distribuidor passa ao próximo na hora).
     * 409 se não há oferta pendente dele neste pedido (já venceu, outro foi oferecido, pedido aberto a todos).
     */
    public function recusar(Request $request, string $id, Distribuidor $distribuidor)
    {
        $motoboy = MotoboyDaSessao::motoboy($request);
        if (!$motoboy) {
            return $this->soParaMotoboy();
        }

        $pedido = Order::where('company_uuid', session('company'))
            ->where(fn ($query) => $query->where('public_id', $id)->orWhere('uuid', $id))
            ->first();
        if (!$pedido) {
            return response()->json(['errors' => ['Pedido não encontrado.']], 404);
        }

        try {
            $recusou = $distribuidor->recusar((string) $pedido->uuid, $motoboy);
        } catch (LockTimeoutException $e) {
            return response()->json(['errors' => ['Este pedido está sendo atualizado. Tente de novo.']], 503);
        }

        return $recusou
            ? response()->json(['resultado' => 'recusada'])
            : response()->json(['errors' => ['Esta oferta não está mais com você.']], 409);
    }

    protected function soParaMotoboy()
    {
        return response()->json(['errors' => ['Disponível só para motoboys.']], 403);
    }
}
