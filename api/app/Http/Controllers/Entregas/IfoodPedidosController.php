<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\Ifood\ConclusaoIfood;
use App\Support\Entregas\Ifood\PedidosIfood;
use App\Support\Entregas\StatusDoPedido;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Entregas RestaurantePro: o painel iFood no detalhe do pedido do console (só administradores; etapa 4 desenha a tela).
 * - painel (GET int/v1/entregas/pedidos/{id}/ifood): número, última ação aceita pelo iFood, a última recusa, cobrança,
 *   observações, código exigido, conclusão liberada ou sem código, cancelamento pelo iFood. Pedido que não é do iFood:
 *   {"ifood": false}. As ações vão pelo código (assignDriver…): o console traduz (fleet-ops.ui.ifood.acao.*).
 *   Sem o 0800 do cliente (só o motoboy do pedido o recebe, no app).
 * - liberarSemCodigo (POST .../ifood/liberar-sem-codigo): a saída da central quando o motoboy não consegue o código
 *   do cliente (inclusive no pedido de teste, sem app do cliente): libera a conclusão comum no app, registrada no banco
 *   (conclusao_sem_codigo) e no log. O iFood fica com a confirmação pendente e conclui sozinho 4 h depois.
 */
class IfoodPedidosController extends Controller
{
    public function painel(Request $request, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }

        $pedido = $this->pedido($id);
        if (!$pedido) {
            return response()->json(['errors' => ['Pedido não encontrado.']], 404);
        }

        return response()->json(static::resposta(PedidosIfood::doPedido((string) $pedido->uuid)));
    }

    public function liberarSemCodigo(Request $request, string $id, ConclusaoIfood $conclusao)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }

        $pedido = $this->pedido($id);
        $linha  = $pedido ? PedidosIfood::doPedido((string) $pedido->uuid) : null;
        if (!$linha) {
            return response()->json(['errors' => ['Pedido do iFood não encontrado.']], 404);
        }
        if ($linha->cancelado_pelo_ifood_em || in_array($pedido->status, StatusDoPedido::ENCERRADOS, true)) {
            return response()->json(['errors' => ['Este pedido já foi encerrado.']], 409);
        }

        $conclusao->liberarSemCodigo($linha, $pedido, session('user'));

        return response()->json(static::resposta(PedidosIfood::doPedido((string) $pedido->uuid)));
    }

    /** O que o painel mostra (função pura sobre a linha da entregas_ifood_pedidos). */
    public static function resposta(?object $linha): array
    {
        if (!$linha) {
            return ['ifood' => false];
        }

        $iso = fn ($data) => $data ? Carbon::parse(substr((string) $data, 0, 19), date_default_timezone_get())->toIso8601String() : null;

        return [
            'ifood'                   => true,
            'numero'                  => $linha->numero !== null ? (string) $linha->numero : null,
            'teste'                   => (bool) $linha->teste,
            'agendado'                => (bool) $linha->agendado,
            'ultima_acao'             => $linha->ultima_acao,
            'recusa'                  => $linha->recusa_acao ? [
                'acao'   => $linha->recusa_acao,
                'status' => $linha->recusa_status !== null ? (int) $linha->recusa_status : 0,
                'em'     => $iso($linha->recusa_em),
            ] : null,
            'cobrar_centavos'         => (int) $linha->cobrar_centavos,
            'forma_pagamento'         => $linha->forma_pagamento,
            'troco_para_centavos'     => $linha->troco_para_centavos !== null ? (int) $linha->troco_para_centavos : null,
            'observacoes'             => $linha->observacoes,
            'complemento'             => $linha->complemento,
            'referencia'              => $linha->referencia,
            'exige_codigo'            => (bool) $linha->exige_codigo,
            'conclusao_liberada_em'   => $iso($linha->conclusao_liberada_em),
            'conclusao_sem_codigo'    => (bool) $linha->conclusao_sem_codigo,
            'cancelado_pelo_ifood_em' => $iso($linha->cancelado_pelo_ifood_em),
            'pago_mesmo_cancelado'    => (bool) $linha->pago_mesmo_cancelado,
        ];
    }

    protected function pedido(string $id): ?Order
    {
        // nenhum model registra o CompanyScope nesta versão: a empresa é filtrada aqui
        return Order::where('company_uuid', session('company'))
            ->where(fn ($query) => $query->where('public_id', $id)->orWhere('uuid', $id))
            ->first();
    }

    protected function negarSeNaoAdmin(Request $request)
    {
        $usuario = Auth::getUserFromSession($request);
        if (!$usuario || $usuario->isNotAdmin()) {
            return response()->json(['errors' => ['Somente administradores podem ver os dados do iFood do pedido.']], 403);
        }

        return null;
    }
}
