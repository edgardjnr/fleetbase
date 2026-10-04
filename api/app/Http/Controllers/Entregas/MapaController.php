<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\SituacaoDoMotoboy;
use App\Support\Entregas\StatusDoPedido;
use Fleetbase\FleetOps\Http\Resources\v1\Index\Place as PlaceIndexResource;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;

/**
 * Entregas RestaurantePro: dados do mapa ao vivo do console (Fleet-Ops → mapa).
 *
 * - locais-de-coleta: só o Local de cada loja (Vendor type=customer → place_uuid). Substitui o live/places do
 *   Fleet-Ops no mapa, que traz todo Place da empresa (um por endereço de entrega) e poluía o mapa com prédios.
 *   Local antigo de loja que mudou de coordenada fica sem dono e não aparece.
 * - motoboys: a situação de cada motoboy (SituacaoDoMotoboy), que vira a cor do capacete. O console consulta a
 *   cada ~20 s com o mapa aberto.
 * Mesmo filtro de permissão do live/* do Fleet-Ops (applyDirectivesForPermissions). Usuário de loja não chega
 * aqui: o ProtegerPortalLoja só libera entregas/loja/*.
 */
class MapaController extends Controller
{
    public function locaisDeColeta()
    {
        $locaisDasLojas = Vendor::where('company_uuid', session('company'))
            ->where('type', LojasController::TIPO_LOJA)
            ->whereNotNull('place_uuid')
            ->pluck('place_uuid');

        $locais = Place::where('company_uuid', session('company'))
            ->whereIn('uuid', $locaisDasLojas)
            ->whereNotNull('location')
            ->applyDirectivesForPermissions('fleet-ops list place')
            ->orderBy('name')
            ->get();

        return PlaceIndexResource::collection($locais);
    }

    public function motoboys()
    {
        $motoboys = Driver::where('company_uuid', session('company'))
            ->applyDirectivesForPermissions('fleet-ops list driver')
            ->get(['uuid', 'public_id', 'online']);

        $statusPorMotoboy = Order::where('company_uuid', session('company'))
            ->whereIn('driver_assigned_uuid', $motoboys->pluck('uuid'))
            ->whereNotIn('status', StatusDoPedido::ENCERRADOS)
            ->where('updated_at', '>=', now()->subHours(SituacaoDoMotoboy::HORAS_PEDIDO_EM_ANDAMENTO))
            ->get(['driver_assigned_uuid', 'status'])
            ->groupBy('driver_assigned_uuid')
            ->map(fn ($pedidos) => $pedidos->pluck('status')->all());

        return response()->json([
            'motoboys' => $motoboys->map(fn ($motoboy) => [
                'uuid'      => $motoboy->uuid,
                'public_id' => $motoboy->public_id,
                'situacao'  => SituacaoDoMotoboy::classificar((bool) $motoboy->online, $statusPorMotoboy->get($motoboy->uuid, [])),
            ])->values(),
        ]);
    }
}
