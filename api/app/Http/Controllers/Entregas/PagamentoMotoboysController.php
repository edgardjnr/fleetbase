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
 * Entregas RestaurantePro: pagamento dos motoboys e cobrança das lojas por faixa de km.
 *
 * Uma tabela de faixas só (`entregas.faixas`): cada faixa tem o km máximo e dois valores, o pago
 * ao motoboy e o cobrado da loja. A entrega vale o valor da faixa em que o km dela cai
 * (0 < km ≤ 1 → 1ª faixa, 1 < km ≤ 2 → 2ª…). Acima da última faixa vale o valor da última.
 *
 * A loja de cada pedido é o local de coleta (pickup). Lojas com o mesmo nome são agrupadas,
 * porque a integração pode criar um Place novo por pedido em vez de reutilizar o Local da loja.
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
    /** Chave da tabela de faixas nas configurações da organização. */
    public const CHAVE_FAIXAS = 'entregas.faixas';

    public const MAX_FAIXAS = 50;

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
        $faixas    = $this->faixas();

        foreach ($pedidos as $pedido) {
            $rota = $this->rotaDoPedido($pedido, $calculos < static::LIMITE_CALCULOS, $calculado);
            if ($calculado) {
                $calculos++;
            }
            if ($rota === null) {
                $pendentes++;
            }

            $motoboy    = $pedido->driverAssigned;
            $coleta     = $pedido->payload?->getPickupOrFirstWaypoint();
            $km         = $rota ? round($rota['metros'] / 1000, 2) : null;
            $faixa      = $km === null ? null : $this->faixaDoKm($faixas, $km);
            $entregas[] = [
                'loja'          => $this->chaveDaLoja($coleta),
                'loja_nome'     => $coleta ? ($coleta->name ?: $this->enderecoCurto($coleta)) : null,
                'pedido'        => $pedido->public_id,
                'id_interno'    => $pedido->internal_id,
                'motoboy'       => $motoboy?->public_id,
                'motoboy_nome'  => $motoboy?->name,
                'concluido_em'  => Carbon::parse($pedido->entregas_concluido_em, 'UTC')->setTimezone($fuso)->toIso8601String(),
                'origem'        => $this->enderecoCurto($pedido->payload?->getPickupOrFirstWaypoint()),
                'destino'       => $this->enderecoCurto($pedido->payload?->getDropoffOrLastWaypoint()),
                'km'            => $km,
                'fonte'         => $rota['fonte'] ?? null,
                'faixa'         => $faixa,
                'valor_motoboy' => $faixa ? $faixa['motoboy'] : null,
                'valor_loja'    => $faixa ? $faixa['loja'] : null,
            ];
        }

        $motoboys = $this->agrupar($entregas, 'motoboy', ['motoboy', 'motoboy_nome'], 'valor_motoboy')->sortByDesc('valor')->values();
        $lojas    = $this->agrupar($entregas, 'loja', ['loja', 'loja_nome'], 'valor_loja')->sortByDesc('valor')->values();

        $totalPagar  = round($motoboys->sum('valor'), 2);
        $totalCobrar = round($lojas->sum('valor'), 2);

        return response()->json([
            'inicio'     => $request->input('inicio'),
            'fim'        => $request->input('fim'),
            'fuso'       => $fuso,
            'faixas'     => $faixas,
            'pendentes'  => $pendentes,
            'totais'     => [
                'entregas' => count($entregas),
                'km'       => round(collect($entregas)->sum(fn ($item) => $item['km'] ?? 0), 2),
                'valor'    => $totalPagar,
                'cobrar'   => $totalCobrar,
                'margem'   => round($totalCobrar - $totalPagar, 2),
            ],
            'motoboys'   => $motoboys,
            'lojas'      => $lojas,
            'entregas'   => $entregas,
        ]);
    }

    public function salvarFaixas(Request $request)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }

        $request->validate([
            'faixas'           => ['present', 'array', 'max:' . static::MAX_FAIXAS],
            'faixas.*.ate_km'  => ['required', 'numeric', 'gt:0', 'max:1000'],
            'faixas.*.motoboy' => ['required', 'numeric', 'min:0', 'max:100000'],
            'faixas.*.loja'    => ['required', 'numeric', 'min:0', 'max:100000'],
        ]);

        $faixas  = $this->normalizarFaixas($request->input('faixas', []));
        $limites = array_column($faixas, 'ate_km');
        if (count($limites) !== count(array_unique($limites))) {
            return response()->json(['errors' => ['Há duas faixas com o mesmo "até km".']], 422);
        }

        Setting::configureCompany(static::CHAVE_FAIXAS, $faixas);

        return response()->json(['faixas' => $faixas]);
    }

    /** Faixas salvas, em ordem crescente de km. */
    protected function faixas(): array
    {
        $faixas = Setting::lookupCompany(static::CHAVE_FAIXAS, []);

        return $this->normalizarFaixas(is_array($faixas) ? $faixas : []);
    }

    protected function normalizarFaixas(array $faixas): array
    {
        $faixas = array_map(fn ($faixa) => [
            'ate_km'  => round((float) data_get($faixa, 'ate_km'), 2),
            'motoboy' => round((float) data_get($faixa, 'motoboy'), 2),
            'loja'    => round((float) data_get($faixa, 'loja'), 2),
        ], array_values($faixas));

        usort($faixas, fn ($a, $b) => $a['ate_km'] <=> $b['ate_km']);

        return $faixas;
    }

    /** Faixa em que o km cai; acima da última, a última. Sem faixas cadastradas, null. */
    protected function faixaDoKm(array $faixas, float $km): ?array
    {
        if (!$faixas) {
            return null;
        }

        $anterior = 0.0;
        foreach ($faixas as $faixa) {
            if ($km <= $faixa['ate_km']) {
                return ['de_km' => $anterior] + $faixa;
            }
            $anterior = $faixa['ate_km'];
        }

        $ultima = end($faixas);

        return ['de_km' => $ultima['ate_km'], 'acima' => true] + $ultima;
    }

    /** Resumo por motoboy ou por loja: entregas, km e soma dos valores da faixa. */
    protected function agrupar(array $entregas, string $chave, array $campos, string $campoValor)
    {
        return collect($entregas)
            ->groupBy(fn ($item) => $item[$chave] ?? 'sem-' . $chave)
            ->map(function ($itens) use ($campos, $campoValor) {
                $resumo = [];
                foreach ($campos as $campo) {
                    $resumo[$campo] = $itens->first()[$campo];
                }

                return $resumo + [
                    'entregas' => $itens->count(),
                    'sem_km'   => $itens->whereNull('km')->count(),
                    'km'       => round($itens->sum(fn ($item) => $item['km'] ?? 0), 2),
                    'valor'    => round($itens->sum(fn ($item) => $item[$campoValor] ?? 0), 2),
                ];
            });
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

    /** Agrupa pelo nome do local de coleta; sem nome, pelo próprio Place. */
    protected function chaveDaLoja(?Place $lugar): ?string
    {
        if (!$lugar) {
            return null;
        }

        $nome = trim(mb_strtolower((string) $lugar->name));

        return $nome !== '' ? 'nome:' . $nome : $lugar->public_id;
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
            return response()->json(['errors' => ['Somente administradores podem ver o pagamento dos motoboys e a cobrança das lojas.']], 403);
        }

        return null;
    }
}
