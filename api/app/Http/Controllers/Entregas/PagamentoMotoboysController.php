<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Company;
use Fleetbase\Models\Setting;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: pagamento dos motoboys por km.
 *
 * O km de cada entrega é a rota de rua loja (pickup) → cliente (dropoff), calculada pelo OSRM
 * uma única vez e guardada no meta do pedido (`entregas.km_rota`). O campo `orders.distance`
 * não serve: é a distância *restante*, que vai a ~0 quando o pedido termina.
 *
 * O período é filtrado pela data em que o pedido foi concluído (tracking status COMPLETED),
 * no fuso da organização.
 */
class PagamentoMotoboysController extends Controller
{
    /** Chave do valor por km nas configurações da organização. */
    public const CHAVE_VALOR_KM = 'entregas.valor_km';

    /** Quantas rotas novas calcular por requisição (o console repete enquanto houver pendentes). */
    public const LIMITE_CALCULOS = 40;

    /** Linha reta → rua, usado só quando o OSRM não responde. */
    public const FATOR_ESTIMATIVA = 1.3;

    public function relatorio(Request $request)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }

        $request->validate([
            'inicio'  => ['required', 'date_format:Y-m-d'],
            'fim'     => ['required', 'date_format:Y-m-d', 'after_or_equal:inicio'],
            'motoboy' => ['nullable', 'string'],
        ]);

        $companyUuid = session('company');
        $fuso        = Company::where('uuid', $companyUuid)->value('timezone') ?: 'America/Sao_Paulo';
        $inicio      = Carbon::createFromFormat('Y-m-d', $request->input('inicio'), $fuso)->startOfDay()->utc();
        $fim         = Carbon::createFromFormat('Y-m-d', $request->input('fim'), $fuso)->endOfDay()->utc();

        $conclusoes = DB::table('tracking_statuses')
            ->select('tracking_number_uuid', DB::raw('MIN(created_at) as concluido_em'))
            ->where('company_uuid', $companyUuid)
            ->where('code', 'COMPLETED')
            ->whereNull('deleted_at')
            ->groupBy('tracking_number_uuid');

        $pedidos = Order::query()
            ->leftJoinSub($conclusoes, 'conclusoes', 'conclusoes.tracking_number_uuid', '=', 'orders.tracking_number_uuid')
            ->where('orders.company_uuid', $companyUuid)
            ->where('orders.status', 'completed')
            ->whereNull('orders.deleted_at')
            ->whereNotNull('orders.driver_assigned_uuid')
            ->whereBetween(DB::raw('COALESCE(conclusoes.concluido_em, orders.updated_at)'), [$inicio, $fim])
            ->when($request->filled('motoboy'), function ($query) use ($request) {
                $query->whereHas('driverAssigned', fn ($driver) => $driver->where('public_id', $request->input('motoboy')));
            })
            ->select('orders.*', DB::raw('COALESCE(conclusoes.concluido_em, orders.updated_at) as entregas_concluido_em'))
            ->with(['payload', 'driverAssigned'])
            ->orderBy('entregas_concluido_em')
            ->get();

        $calculos  = 0;
        $pendentes = 0;
        $entregas  = [];

        foreach ($pedidos as $pedido) {
            $rota = $this->rotaDoPedido($pedido, $calculos < static::LIMITE_CALCULOS, $calculado);
            if ($calculado) {
                $calculos++;
            }
            if ($rota === null) {
                $pendentes++;
            }

            $motoboy    = $pedido->driverAssigned;
            $entregas[] = [
                'pedido'        => $pedido->public_id,
                'id_interno'    => $pedido->internal_id,
                'motoboy'       => $motoboy?->public_id,
                'motoboy_nome'  => $motoboy?->name,
                'concluido_em'  => Carbon::parse($pedido->entregas_concluido_em, 'UTC')->setTimezone($fuso)->toIso8601String(),
                'origem'        => $this->enderecoCurto($pedido->payload?->getPickupOrFirstWaypoint()),
                'destino'       => $this->enderecoCurto($pedido->payload?->getDropoffOrLastWaypoint()),
                'km'            => $rota ? round($rota['metros'] / 1000, 2) : null,
                'fonte'         => $rota['fonte'] ?? null,
            ];
        }

        $valorKm  = (float) Setting::lookupCompany(static::CHAVE_VALOR_KM, 0);
        $motoboys = collect($entregas)
            ->groupBy('motoboy')
            ->map(function ($itens) use ($valorKm) {
                $km = round($itens->sum(fn ($item) => $item['km'] ?? 0), 2);

                return [
                    'motoboy'       => $itens->first()['motoboy'],
                    'motoboy_nome'  => $itens->first()['motoboy_nome'],
                    'entregas'      => $itens->count(),
                    'sem_km'        => $itens->whereNull('km')->count(),
                    'km'            => $km,
                    'valor'         => round($km * $valorKm, 2),
                ];
            })
            ->sortByDesc('km')
            ->values();

        return response()->json([
            'inicio'     => $request->input('inicio'),
            'fim'        => $request->input('fim'),
            'fuso'       => $fuso,
            'valor_km'   => $valorKm,
            'pendentes'  => $pendentes,
            'totais'     => [
                'entregas' => count($entregas),
                'km'       => round($motoboys->sum('km'), 2),
                'valor'    => round($motoboys->sum('valor'), 2),
            ],
            'motoboys'   => $motoboys,
            'entregas'   => $entregas,
        ]);
    }

    public function salvarValorKm(Request $request)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }

        $request->validate(['valor_km' => ['required', 'numeric', 'min:0', 'max:1000']]);

        $valorKm = round((float) $request->input('valor_km'), 2);
        Setting::configureCompany(static::CHAVE_VALOR_KM, $valorKm);

        return response()->json(['valor_km' => $valorKm]);
    }

    /**
     * Rota loja → cliente em metros, do cache no meta do pedido ou calculada agora.
     * Retorna null quando falta endereço ou quando o limite de cálculos desta requisição acabou.
     */
    protected function rotaDoPedido(Order $pedido, bool $podeCalcular, ?bool &$calculado = null): ?array
    {
        $calculado = false;
        $origem    = $pedido->payload?->getPickupOrFirstWaypoint();
        $destino   = $pedido->payload?->getDropoffOrLastWaypoint();

        if (!$this->temCoordenadas($origem) || !$this->temCoordenadas($destino)) {
            return null;
        }

        $chave = md5(implode(',', [$origem->location->getLat(), $origem->location->getLng(), $destino->location->getLat(), $destino->location->getLng()]));
        $cache = $pedido->getMeta('entregas.km_rota');

        // estimativa (OSRM fora do ar) é recalculada quando houver folga
        if (is_array($cache) && ($cache['chave'] ?? null) === $chave && (($cache['fonte'] ?? null) === 'osrm' || !$podeCalcular)) {
            return $cache;
        }

        if (!$podeCalcular) {
            return null;
        }

        $calculado = true;
        $rota      = ['metros' => 0, 'fonte' => 'osrm', 'chave' => $chave];

        try {
            $matriz = Utils::getDistanceMatrixFromOSRM(
                $origem->location->getLat() . ',' . $origem->location->getLng(),
                $destino->location->getLat() . ',' . $destino->location->getLng()
            );
            $rota['metros'] = (float) $matriz->distance;
        } catch (\Throwable $e) {
            Log::warning('[entregas] OSRM falhou no cálculo do km', ['pedido' => $pedido->public_id, 'erro' => $e->getMessage()]);
        }

        if ($rota['metros'] <= 0) {
            $linhaReta      = Utils::calculateDrivingDistanceAndTime($origem->location, $destino->location);
            $rota['metros'] = round($linhaReta->distance * static::FATOR_ESTIMATIVA);
            $rota['fonte']  = 'estimativa';
        }

        // sem mexer no updated_at: ele é o fallback da data de conclusão no filtro do período
        $pedido->timestamps = false;
        $pedido->updateMeta('entregas.km_rota', $rota);

        return $rota;
    }

    protected function temCoordenadas(?Place $lugar): bool
    {
        if (!$lugar || !$lugar->location) {
            return false;
        }

        return abs((float) $lugar->location->getLat()) > 0.0001 || abs((float) $lugar->location->getLng()) > 0.0001;
    }

    protected function enderecoCurto(?Place $lugar): ?string
    {
        if (!$lugar) {
            return null;
        }

        $partes = array_filter([$lugar->street1, $lugar->street2, $lugar->neighborhood]);

        return $partes ? implode(', ', $partes) : ($lugar->name ?: $lugar->address);
    }

    protected function negarSeNaoAdmin(Request $request)
    {
        $usuario = Auth::getUserFromSession($request);

        if (!$usuario || $usuario->isNotAdmin()) {
            return response()->json(['errors' => ['Somente administradores podem ver o pagamento dos motoboys.']], 403);
        }

        return null;
    }
}
