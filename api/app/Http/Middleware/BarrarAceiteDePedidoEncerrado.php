<?php

namespace App\Http\Middleware;

use App\Support\Entregas\TravaDoPedido;
use Closure;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: o motoboy não aceita pedido encerrado (cancelado, concluído ou expirado).
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
 * Os erros saem no formato da API v1 (`{"error": "..."}`, o mesmo do startOrder), que o app já trata.
 */
class BarrarAceiteDePedidoEncerrado
{
    /** Encerrados por cancelamento, que têm mensagem própria. */
    public const STATUS_CANCELADOS = ['canceled', 'cancelled', 'order_canceled'];

    public function handle(Request $request, Closure $next)
    {
        // caminho decodificado, como o roteador do Laravel casa as rotas (rawurldecode); o method() é o mesmo que
        // o roteador usa (já com o _method / X-HTTP-Method-Override aplicado)
        $caminho = trim(rawurldecode($request->path()), '/');
        if ($request->method() !== 'POST' || preg_match('#^v1/orders/([^/]+)/start$#', $caminho, $m) !== 1) {
            return $next($request);
        }

        // a mesma busca do startOrder (Order::findRecordOrFail): public_id ou internal_id. A sessão ainda não foi
        // montada (é o middleware da rota que a monta), então o filtro de empresa do Fleetbase não age aqui
        $id     = $m[1];
        $pedido = Order::where(fn ($q) => $q->where('public_id', $id)->orWhere('internal_id', $id))->first();

        // inexistente: o próprio controller responde 404
        if (!$pedido) {
            return $next($request);
        }

        try {
            return TravaDoPedido::executar($pedido->uuid, fn () => $this->aceitarComATrava($request, $next, $pedido));
        } catch (LockTimeoutException $e) {
            Log::warning('[entregas] aceite do motoboy: trava do pedido ocupada', ['pedido' => $pedido->public_id, 'ip' => $request->ip()]);

            return response()->apiError('Este pedido está sendo atualizado neste momento. Tente aceitar de novo em alguns segundos.', 409);
        }
    }

    protected function aceitarComATrava(Request $request, Closure $next, Order $pedido)
    {
        // relido com a trava: um cancelamento que terminou enquanto esta requisição esperava aparece aqui
        $status = Order::where('uuid', $pedido->uuid)->value('status');

        if (in_array($status, RegrasPortalLoja::STATUS_ENCERRADOS, true)) {
            Log::info('[entregas] aceite do motoboy barrado: pedido encerrado', [
                'pedido'  => $pedido->public_id,
                'status'  => $status,
                'motoboy' => $request->input('assign'),
                'ip'      => $request->ip(),
            ]);

            return response()->apiError(in_array($status, static::STATUS_CANCELADOS, true)
                ? 'Este pedido foi cancelado.'
                : 'Este pedido já foi encerrado.');
        }

        // o aceite inteiro com a trava: o cancelamento pela loja espera e depois vê o pedido iniciado
        return $next($request);
    }
}
