<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\CalculoEntregas;
use App\Support\Entregas\GanhosDoMotoboy;
use App\Support\Entregas\MotoboyDaSessao;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Entregas RestaurantePro: ganhos do motoboy no app (Navigator), na API v1, com o token dele.
 * - ganhos: as entregas concluídas dele no período, com o valor de cada uma e o total (tela Início do app);
 * - valor: km, faixa e valor de um pedido dele ou aberto (card de aceitar e detalhes do pedido).
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
        $inicio      = Carbon::createFromFormat('Y-m-d', $request->input('inicio'), $fuso)->startOfDay()->utc();
        $fim         = Carbon::createFromFormat('Y-m-d', $request->input('fim'), $fuso)->endOfDay()->utc();

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

        // nenhum model registra o CompanyScope nesta versão: a empresa é filtrada aqui
        $pedido = Order::where('company_uuid', session('company'))
            ->where(fn ($query) => $query->where('public_id', $id)->orWhere('uuid', $id))
            ->with(['payload.pickup', 'payload.dropoff', 'payload.waypoints'])
            ->first();

        if (!$pedido || !GanhosDoMotoboy::podeVer($pedido, $motoboy)) {
            return response()->json(['errors' => ['Pedido não encontrado.']], 404);
        }

        return response()->json(GanhosDoMotoboy::valor($pedido, $calculo->valorDoPedido($pedido)));
    }

    protected function soParaMotoboy()
    {
        return response()->json(['errors' => ['Disponível só para motoboys.']], 403);
    }
}
