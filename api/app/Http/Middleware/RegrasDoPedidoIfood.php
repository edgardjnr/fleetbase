<?php

namespace App\Http\Middleware;

use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\PedidosIfood;
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
 *    - API v1: DELETE v1/orders/{id}/cancel (cancelOrder) e a atividade "canceled" (update-activity);
 *    - console: PATCH int/v1/orders/cancel (corpo `order` = uuid), PATCH int/v1/orders/bulk-cancel (`ids`) e a
 *      atividade "canceled" (PATCH int/v1/orders/update-activity/{id}, que o quadro usa ao arrastar o card).
 *    - O portal da loja é barrado no RegrasPortalLoja (rota do customer-portal).
 * 2. Trava "Atualize o app" (só com ClienteIfood::exigeAppNovo(), ENTREGAS_IFOOD_EXIGE_APP_NOVO=1): a conclusão comum
 *    pela API v1 (atividade "completed" ou que conclui o pedido no update-activity, e o POST v1/orders/{id}/complete) de
 *    pedido iFood é recusada com 400 até a rota concluir-ifood do APK novo liberar (conclusao_liberada_em: chegada
 *    avisada e, se exigido, código conferido). O APK antigo não conhece a rota e não consegue concluir. A conclusão
 *    pelo console é decisão da central e passa (o AcoesIfood registra conclusao_sem_codigo quando faltou o código).
 *
 * Registrado nos grupos fleetbase.api (v1) e fleetbase.protected (int/v1) pelo RouteServiceProvider, antes do
 * BarrarAceiteDePedidoEncerrado: roda depois da autenticação, com a empresa na sessão (o filtro da empresa é explícito;
 * nenhum model registra o CompanyScope nesta versão). Os erros saem no formato de cada API: {"error": "..."} na v1 (o do
 * Fleet-Ops) e {"errors": ["..."]} no console.
 */
class RegrasDoPedidoIfood
{
    public const CANCELAR_NA_API     = OrderDaApi::class . '@cancelOrder';
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
                return $this->ehDoIfood($this->pedidoPeloId($request->route('id'))) ? $this->recusarNaApi($request, static::MENSAGEM_CANCELAMENTO) : $next($request);

            case static::CONCLUIR_NA_API:
                return $this->travaDoAppAntigo($this->pedidoPeloId($request->route('id'))) ? $this->recusarNaApi($request, static::MENSAGEM_APP_ANTIGO) : $next($request);

            case static::ATIVIDADE_NA_API:
                $pedido    = $this->pedidoPeloId($request->route('id'));
                $atividade = $request->array('activity');
                if ($this->cancela($atividade) && $this->ehDoIfood($pedido)) {
                    return $this->recusarNaApi($request, static::MENSAGEM_CANCELAMENTO);
                }
                if ($this->conclui($atividade) && $this->travaDoAppAntigo($pedido)) {
                    return $this->recusarNaApi($request, static::MENSAGEM_APP_ANTIGO);
                }

                return $next($request);

            case static::CANCELAR_NO_CONSOLE:
                return $this->ehDoIfood($this->pedidoPeloId($request->input('order'))) ? $this->recusarNoConsole() : $next($request);

            case static::CANCELAR_EM_LOTE:
                foreach ((array) $request->input('ids', []) as $id) {
                    if ($this->ehDoIfood($this->pedidoPeloId($id))) {
                        return $this->recusarNoConsole('Há pedido do iFood na seleção: o cancelamento dele é feito no iFood. Tire-o da seleção e tente de novo.');
                    }
                }

                return $next($request);

            case static::ATIVIDADE_NO_CONSOLE:
                return $this->cancela($request->array('activity')) && $this->ehDoIfood($this->pedidoPeloId($request->route('id'))) ? $this->recusarNoConsole() : $next($request);
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

    protected function ehDoIfood(?Order $pedido): bool
    {
        return $pedido !== null && PedidosIfood::ehDoIfood((string) $pedido->uuid);
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

    protected function conclui(array $atividade): bool
    {
        return ($atividade['code'] ?? null) === 'completed' || filter_var($atividade['complete'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    protected function recusarNaApi(Request $request, string $mensagem)
    {
        Log::info('[entregas] ifood: ação barrada no pedido do iFood', ['motivo' => $mensagem, 'pedido' => $request->route('id')]);

        return response()->apiError($mensagem, 400);
    }

    protected function recusarNoConsole(string $mensagem = self::MENSAGEM_CANCELAMENTO)
    {
        return response()->json(['errors' => [$mensagem]], 400);
    }
}
