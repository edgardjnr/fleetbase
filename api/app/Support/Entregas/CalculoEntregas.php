<?php

namespace App\Support\Entregas;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: pagamento dos motoboys e cobrança das lojas por faixa de km.
 * Usado pela tela "Pagamento e cobrança" (PagamentoMotoboysController) e pelo extrato do
 * portal da loja (PortalLojaController).
 *
 * Uma tabela de faixas só (`entregas.faixas`): cada faixa tem o km máximo e dois valores, o pago
 * ao motoboy e o cobrado da loja. A entrega vale o valor da faixa em que o km dela cai
 * (0 < km ≤ 1 → 1ª faixa, 1 < km ≤ 2 → 2ª…). Acima da última faixa vale o valor da última.
 *
 * A loja de cada pedido é o Vendor dono do pedido (o cliente do pedido), como nos pedidos do
 * portal da loja e nos que a central cria escolhendo a loja. Pedidos sem loja caem no nome do
 * local de coleta (lojas com o mesmo nome são agrupadas, porque a integração pode criar um Place
 * novo por pedido); sem nome, no próprio Place.
 *
 * O km de cada entrega é a rota de rua loja (pickup) → cliente (dropoff), calculada pelo OSRM
 * uma única vez e guardada no meta do pedido (`entregas.km_rota`). O campo `orders.distance`
 * não serve: é a distância *restante*, que vai a ~0 quando o pedido termina.
 *
 * O período é filtrado pela data em que o pedido foi concluído (tracking status COMPLETED),
 * no fuso da organização.
 */
class CalculoEntregas
{
    /** Chave da tabela de faixas nas configurações da organização. */
    public const CHAVE_FAIXAS = 'entregas.faixas';

    public const MAX_FAIXAS = 50;

    /** Quantas rotas novas calcular por requisição (a tela repete enquanto houver pendentes). */
    public const LIMITE_CALCULOS = 40;

    /** Linha reta → rua, usado só quando o OSRM não responde. */
    public const FATOR_ESTIMATIVA = 1.3;

    /**
     * Pedidos concluídos no período, com a data de conclusão em `entregas_concluido_em`.
     * $filtro recebe a query para restringir (por motoboy, por loja).
     */
    public function pedidosConcluidos(string $companyUuid, Carbon $inicio, Carbon $fim, ?\Closure $filtro = null): Collection
    {
        $conclusoes = DB::table('tracking_statuses')
            ->select('tracking_number_uuid', DB::raw('MIN(created_at) as concluido_em'))
            ->where('company_uuid', $companyUuid)
            ->where('code', 'COMPLETED')
            ->whereNull('deleted_at')
            ->groupBy('tracking_number_uuid');

        return Order::query()
            ->leftJoinSub($conclusoes, 'conclusoes', 'conclusoes.tracking_number_uuid', '=', 'orders.tracking_number_uuid')
            ->where('orders.company_uuid', $companyUuid)
            ->where('orders.status', 'completed')
            ->whereNull('orders.deleted_at')
            ->whereNotNull('orders.driver_assigned_uuid')
            ->whereBetween(DB::raw('COALESCE(conclusoes.concluido_em, orders.updated_at)'), [$inicio, $fim])
            // condição booleana: com a closure como condição, o when() a executaria só para decidir
            ->when($filtro !== null, $filtro)
            ->select('orders.*', DB::raw('COALESCE(conclusoes.concluido_em, orders.updated_at) as entregas_concluido_em'))
            ->with(['payload', 'driverAssigned'])
            ->orderBy('entregas_concluido_em')
            ->get();
    }

    /**
     * Uma linha por pedido: loja, motoboy, km, faixa e os dois valores.
     * Retorna [entregas, pendentes] (pendentes = pedidos ainda sem km).
     */
    public function entregas(Collection $pedidos, string $fuso): array
    {
        $calculos  = 0;
        $pendentes = 0;
        $entregas  = [];
        $faixas    = $this->faixas();
        $lojas     = $this->lojasDosPedidos($pedidos);

        foreach ($pedidos as $pedido) {
            $rota = $this->rotaDoPedido($pedido, $calculos < static::LIMITE_CALCULOS, $calculado);
            if ($calculado) {
                $calculos++;
            }
            if ($rota === null) {
                $pendentes++;
            }

            $motoboy           = $pedido->driverAssigned;
            $coleta            = $pedido->payload?->getPickupOrFirstWaypoint();
            $km                = $rota ? round($rota['metros'] / 1000, 2) : null;
            $faixa             = $km === null ? null : $this->faixaDoKm($faixas, $km);
            [$loja, $lojaNome] = $this->lojaDoPedido($pedido, $coleta, $lojas);

            $entregas[] = [
                'loja'          => $loja,
                'loja_nome'     => $lojaNome,
                'pedido'        => $pedido->public_id,
                'id_interno'    => $pedido->internal_id,
                'motoboy'       => $motoboy?->public_id,
                'motoboy_nome'  => $motoboy?->name,
                'concluido_em'  => Carbon::parse($pedido->entregas_concluido_em, 'UTC')->setTimezone($fuso)->toIso8601String(),
                'origem'        => $this->enderecoCurto($coleta),
                'destino'       => $this->enderecoCurto($pedido->payload?->getDropoffOrLastWaypoint()),
                'km'            => $km,
                'fonte'         => $rota['fonte'] ?? null,
                'faixa'         => $faixa,
                'valor_motoboy' => $faixa ? $faixa['motoboy'] : null,
                'valor_loja'    => $faixa ? $faixa['loja'] : null,
            ];
        }

        return [$entregas, $pendentes];
    }

    /**
     * Loja do pedido: o Vendor dono do pedido; sem loja, o nome do local de coleta; sem nome, o Place.
     *
     * @return array{0: ?string, 1: ?string} [chave, nome]
     */
    public function lojaDoPedido(Order $pedido, ?Place $coleta, Collection $lojas): array
    {
        $vendor = $pedido->customer_type === Vendor::class ? $lojas->get($pedido->customer_uuid) : null;
        if ($vendor) {
            return ['loja:' . $vendor->public_id, $vendor->name];
        }

        if (!$coleta) {
            return [null, null];
        }

        $nome = trim(mb_strtolower((string) $coleta->name));

        return [$nome !== '' ? 'nome:' . $nome : $coleta->public_id, $coleta->name ?: $this->enderecoCurto($coleta)];
    }

    /**
     * Lojas (Vendor) donas dos pedidos, numa consulta só, indexadas pelo uuid.
     * Inclui lojas excluídas, para o histórico da cobrança não mudar de agrupamento.
     */
    protected function lojasDosPedidos(Collection $pedidos): Collection
    {
        $uuids = $pedidos->where('customer_type', Vendor::class)->pluck('customer_uuid')->filter()->unique()->values();

        return $uuids->isEmpty() ? collect() : Vendor::withTrashed()->whereIn('uuid', $uuids)->get()->keyBy('uuid');
    }

    /** Faixas salvas, em ordem crescente de km. */
    public function faixas(): array
    {
        $faixas = Setting::lookupCompany(static::CHAVE_FAIXAS, []);

        return $this->normalizarFaixas(is_array($faixas) ? $faixas : []);
    }

    public function normalizarFaixas(array $faixas): array
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
    public function faixaDoKm(array $faixas, float $km): ?array
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
    public function agrupar(array $entregas, string $chave, array $campos, string $campoValor)
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
    public function rotaDoPedido(Order $pedido, bool $podeCalcular, ?bool &$calculado = null): ?array
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

    public function temCoordenadas(?Place $lugar): bool
    {
        if (!$lugar || !$lugar->location) {
            return false;
        }

        return abs((float) $lugar->location->getLat()) > 0.0001 || abs((float) $lugar->location->getLng()) > 0.0001;
    }

    public function enderecoCurto(?Place $lugar): ?string
    {
        if (!$lugar) {
            return null;
        }

        $partes = array_filter([$lugar->street1, $lugar->street2, $lugar->neighborhood]);

        return $partes ? implode(', ', $partes) : ($lugar->name ?: $lugar->address);
    }
}
