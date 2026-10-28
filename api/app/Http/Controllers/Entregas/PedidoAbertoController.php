<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\CalculoEntregas;
use App\Support\Entregas\Ifood\PedidosIfood;
use App\Support\Entregas\Integracao\PedidosDaIntegracao;
use App\Support\Entregas\Painel\ComandaDaCentral;
use App\Support\Entregas\Painel\PainelInicial;
use App\Support\Entregas\PedidosNoMapa;
use App\Support\Entregas\Portal\PedidosDoPortal;
use App\Support\Entregas\RotaDoPedido;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: o pedido aberto numa folha do console, no estilo da Comanda do app do motoboy
 * (GET int/v1/entregas/pedidos/{id}/comanda). Monta a resposta com o Painel\ComandaDaCentral; aqui só as consultas.
 *
 * - Qualquer usuário da central lê; os valores (dinheiro) só o admin (para os outros, `valores: null`).
 * - A loja (usuário de loja do portal) não chega aqui: o ProtegerPortalLoja só libera a ela as rotas da lista
 *   PERMITIDAS_INTERNAS (`entregas/loja/*`), e o motoboy é barrado em todo `int/v1` pelo BarrarMotoboyNoConsole.
 * - Sem o 0800/localizador do iFood e sem o telefone do cliente da integração (só o motoboy do pedido, no app).
 * - O valor sai do CalculoEntregas::valorDoPedido, o mesmo do card do app: na primeira vez que o pedido tem km, ele grava
 *   o km no meta do pedido (updateQuietly, sem eventos nem updated_at) e congela a faixa (entregas_valores_pedido).
 */
class PedidoAbertoController extends Controller
{
    public function comanda(Request $request, string $id, RotaDoPedido $rotas, CalculoEntregas $calculo)
    {
        $empresa = (string) session('company');

        // nenhum model registra o CompanyScope nesta versão: a empresa é filtrada aqui
        $pedido = Order::where('company_uuid', $empresa)
            ->where(fn ($query) => $query->where('public_id', $id)->orWhere('uuid', $id))
            ->with([...PedidosNoMapa::RELACOES_DO_LIDER, 'payload.waypoints'])
            ->first();
        if (!$pedido) {
            return response()->json(['errors' => ['Pedido não encontrado.']], 404);
        }

        $usuario = Auth::getUserFromSession($request);
        $admin   = $usuario !== null && !$usuario->isNotAdmin();
        $uuid    = (string) $pedido->uuid;

        // a loja do pedido é o Vendor dono dele (customer_uuid); sem ele, a loja dona do Local de coleta (como o chat do pedido)
        $loja = $this->lojaDaEmpresa($empresa, $pedido->customer_uuid)
            ?? $this->lojaDaEmpresa($empresa, $pedido->payload?->getPickupOrFirstWaypoint()?->owner_uuid);

        return response()->json(ComandaDaCentral::resposta(
            pedido: $pedido,
            noMapa: PedidosNoMapa::umDoLider($pedido, true),
            ifood: PedidosIfood::doPedido($uuid),
            integracao: PedidosDaIntegracao::doPedido($uuid),
            loja: $loja,
            statuses: $this->statuses($pedido),
            finalizadaEm: DB::table(PainelInicial::TABELA_CORRIDAS)->where('order_uuid', $uuid)->value('finalizada_em'),
            rota: $this->rota($rotas, $pedido),
            valor: $admin ? $this->valor($calculo, $pedido) : null,
            admin: $admin,
            portal: PedidosDoPortal::doPedido($uuid),
        ));
    }

    /** Os status do rastreio do pedido (tracking_statuses), do mais antigo ao mais novo. */
    protected function statuses(Order $pedido): array
    {
        $numero = (string) ($pedido->tracking_number_uuid ?? '');
        if ($numero === '') {
            return [];
        }

        return DB::table('tracking_statuses')
            ->where('tracking_number_uuid', $numero)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['code', 'status', 'details', 'created_at'])
            ->all();
    }

    /** A rota no mapa; o OSRM fora já cai na linha reta (RotaDoPedido), e qualquer outra falha não derruba a folha. */
    protected function rota(RotaDoPedido $rotas, Order $pedido): ?array
    {
        try {
            return $rotas->doPedido($pedido);
        } catch (\Throwable $e) {
            Log::warning('[entregas] comanda: falha ao montar a rota do pedido', ['pedido' => $pedido->public_id, 'erro' => get_class($e)]);

            return null;
        }
    }

    /** O valor do pedido para o motoboy dele (sem motoboy: a prévia na 1ª posição); falha = sem valores, e o log. */
    protected function valor(CalculoEntregas $calculo, Order $pedido): ?array
    {
        try {
            return $calculo->valorDoPedido($pedido, (string) ($pedido->driver_assigned_uuid ?? '') ?: null);
        } catch (\Throwable $e) {
            Log::warning('[entregas] comanda: falha ao calcular o valor do pedido', ['pedido' => $pedido->public_id, 'erro' => get_class($e)]);

            return null;
        }
    }

    /** A loja (Vendor) com este uuid na empresa da sessão; um contato ou uma loja de outra empresa dá null. */
    protected function lojaDaEmpresa(string $empresa, ?string $uuid): ?Vendor
    {
        return $uuid ? Vendor::where('company_uuid', $empresa)->where('uuid', $uuid)->first() : null;
    }
}
