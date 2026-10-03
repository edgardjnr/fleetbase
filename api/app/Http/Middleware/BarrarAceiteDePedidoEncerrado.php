<?php

namespace App\Http\Middleware;

use App\Support\Entregas\StatusDoPedido;
use App\Support\Entregas\TravaDoPedido;
use Closure;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController;
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
 * O cancelamento da API v1 (DELETE v1/orders/{id}/cancel, OrderController@cancelOrder, o caminho provável da
 * integração iFood) também roda com a trava, sem conferência nenhuma: ele cancela até pedido iniciado, que é
 * decisão de quem chama, mas, se chegasse no meio de um aceite, o startOrder gravaria o status de início por cima
 * do cancelamento. O início e o cancelamento pela central (int/v1) ficam fora: são decisão da central.
 *
 * Fica no fim do grupo de middleware `fleetbase.api` das rotas v1 (registrado no RouteServiceProvider), não na
 * lista global: roda depois da autenticação (AuthenticateOnceWithBasicAuth), então só vê requisições autenticadas
 * (sem credencial válida, o core responde 401 antes, sem a trava) e já com a sessão da empresa montada. A busca
 * do pedido filtra pela empresa da sessão, como o findRecordOrFail das duas ações (nenhum model registra o
 * CompanyScope nesta versão: o filtro tem de ser explícito).
 *
 * Os erros saem no formato da API v1 (`{"error": "..."}`, o mesmo do startOrder). O app do motoboy ainda não mostra
 * a mensagem: os dois aceites dele (AdhocOrderCard e OrderScreen, no entregas-navigator) só fazem console.warn.
 * Mostrar o erro ao motoboy é ajuste do app.
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
            Log::warning(
                $aceite ? '[entregas] aceite do motoboy: trava do pedido ocupada' : '[entregas] cancelamento pela API v1: trava do pedido ocupada',
                ['pedido' => $pedido->public_id, 'ip' => $request->ip()]
            );

            return response()->apiError($aceite
                ? 'Este pedido está sendo atualizado neste momento. Tente aceitar de novo em alguns segundos.'
                : 'Este pedido está sendo atualizado neste momento. Tente cancelar de novo em alguns segundos.', 409);
        }
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
        // relido com a trava: um cancelamento que terminou enquanto esta requisição esperava aparece aqui
        $status = Order::where('uuid', $pedido->uuid)->value('status');

        if (in_array($status, StatusDoPedido::ENCERRADOS, true)) {
            Log::info('[entregas] aceite do motoboy barrado: pedido encerrado', [
                'pedido'  => $pedido->public_id,
                'status'  => $status,
                'motoboy' => $request->input('assign'),
                'ip'      => $request->ip(),
            ]);

            return response()->apiError(in_array($status, StatusDoPedido::CANCELADOS, true)
                ? 'Este pedido foi cancelado.'
                : 'Este pedido já foi encerrado.');
        }

        // o aceite inteiro com a trava: um cancelamento que chegar agora espera o aceite terminar
        return $next($request);
    }
}
