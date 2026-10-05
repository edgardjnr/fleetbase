<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\PedidosNoMapa;
use App\Support\Entregas\SituacaoDoMotoboy;
use Fleetbase\FleetOps\Http\Resources\v1\Index\Place as PlaceIndexResource;
use Fleetbase\FleetOps\Models\Driver;
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
 * - motoboys também traz `pedidos`: os pedidos em andamento, um alfinete no endereço de entrega (PedidosNoMapa).
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

        $pedidosPorMotoboy = SituacaoDoMotoboy::pedidosEmAndamento(session('company'), $motoboys->pluck('uuid'));

        return response()->json([
            'motoboys' => $motoboys->map(fn ($motoboy) => [
                'uuid'      => $motoboy->uuid,
                'public_id' => $motoboy->public_id,
                // o console grava no store para a lista "Operações ao vivo" (reserva do evento entregas.motoboy_online)
                'online'    => (bool) $motoboy->online,
                // array_map, e não array_column: com o model do Eloquent, o array_column pula status nulo
                'situacao'  => SituacaoDoMotoboy::classificar(
                    (bool) $motoboy->online,
                    array_map(fn ($pedido) => $pedido->status, $pedidosPorMotoboy[$motoboy->uuid] ?? [])
                ),
            ])->values(),
            // Entregas: os alfinetes dos pedidos em andamento (todos da empresa), relidos junto com os capacetes
            'pedidos'  => PedidosNoMapa::daCentral(session('company')),
        ]);
    }
}
