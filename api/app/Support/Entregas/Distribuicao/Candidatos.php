<?php

namespace App\Support\Entregas\Distribuicao;

use App\Support\Entregas\SituacaoDoMotoboy;
use App\Support\Entregas\StatusDoPedido;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Utils;

/**
 * Quem pode receber a oferta de um pedido aberto e o que cada um ainda tem para fazer.
 *
 * Motoboys: os da empresa do pedido, online, com status `available` (o do Fleet-Ops, manual), com posição válida dentro
 * do raio de pedido aberto da coleta (Order::getAdhocDistance, linha reta, a mesma consulta do HandleOrderDispatched,
 * agora filtrada pela empresa) e, para a oferta, com o GPS atualizado há menos de GPS_MINUTOS (drivers.updated_at).
 * Paradas: os pedidos em andamento dele (a regra do capacete do mapa: SituacaoDoMotoboy), na ordem de aceite
 * (started_at, depois dispatched_at), com a coleta (se ainda não pegou) e a entrega.
 *
 * As duas consultas ficam atrás de closures trocáveis para os testes (o banco em memória não simula distanceSphere).
 */
class Candidatos
{
    /** @var null|\Closure(Order, bool): array<int, array{motoboy: Driver, posicao: array, distancia: float}> */
    public static ?\Closure $buscarMotoboys = null;
    /** @var null|\Closure(string, array<string>): array<string, array<int, array{0: float, 1: float, 2: string}>> */
    public static ?\Closure $buscarParadas = null;

    /**
     * Os candidatos elegíveis à oferta, com as paradas: fora os uuids excluídos (quem já respondeu neste despacho,
     * quem tem oferta pendente de outro pedido).
     *
     * @return array<int, array{motoboy: Driver, posicao: array, distancia: float, paradas: array}>
     */
    public static function elegiveis(Order $pedido, array $excluidos): array
    {
        $motoboys = array_values(array_filter(static::noRaio($pedido, true), fn ($c) => !in_array((string) $c['motoboy']->uuid, $excluidos, true)));
        if ($motoboys === []) {
            return [];
        }
        $paradas = static::paradas((string) $pedido->company_uuid, array_map(fn ($c) => (string) $c['motoboy']->uuid, $motoboys));
        foreach ($motoboys as &$candidato) {
            $candidato['paradas'] = $paradas[(string) $candidato['motoboy']->uuid] ?? [];
        }

        return $motoboys;
    }

    /** @return array<int, array{motoboy: Driver, posicao: array, distancia: float}> */
    public static function noRaio(Order $pedido, bool $gpsRecente): array
    {
        if (static::$buscarMotoboys) {
            return (static::$buscarMotoboys)($pedido, $gpsRecente);
        }

        $coleta = $pedido->getPickupLocation();
        if (!Utils::isPoint($coleta)) {
            return [];
        }

        $motoboys = Driver::where(['status' => 'available', 'online' => 1])
            ->where('company_uuid', $pedido->company_uuid)
            ->whereNull('deleted_at')
            ->whereNotNull('location')
            ->whereRaw('ST_Y(location) BETWEEN -90 AND 90 AND ST_X(location) BETWEEN -180 AND 180 AND NOT (ST_X(location) = 0 AND ST_Y(location) = 0)')
            ->when($gpsRecente, fn ($q) => $q->where('updated_at', '>=', now()->subMinutes(Distribuicao::GPS_MINUTOS)))
            ->distanceSphere('location', $coleta, $pedido->getAdhocDistance())
            ->distanceSphereValue('location', $coleta)
            ->withoutGlobalScopes()
            ->get();

        $candidatos = [];
        foreach ($motoboys as $motoboy) {
            $posicao = Pontos::de($motoboy->location);
            if ($posicao) {
                $candidatos[] = ['motoboy' => $motoboy, 'posicao' => $posicao, 'distancia' => (float) ($motoboy->distance ?? 0)];
            }
        }

        return $candidatos;
    }

    /** @return array<string, array<int, array{0: float, 1: float, 2: string}>> uuid do motoboy => paradas [lat, lng, tipo] */
    public static function paradas(string $empresa, array $motoboyUuids): array
    {
        if (static::$buscarParadas) {
            return (static::$buscarParadas)($empresa, $motoboyUuids);
        }

        $pedidos = Order::where('company_uuid', $empresa)
            ->whereIn('driver_assigned_uuid', $motoboyUuids)
            ->whereNotIn('status', StatusDoPedido::ENCERRADOS)
            ->where('updated_at', '>=', now()->subHours(SituacaoDoMotoboy::HORAS_PEDIDO_EM_ANDAMENTO))
            ->with(['payload.pickup', 'payload.dropoff'])
            ->orderByRaw('started_at IS NULL')
            ->orderBy('started_at')
            ->orderBy('dispatched_at')
            ->orderBy('id')
            ->get();

        return static::paradasDosPedidos($pedidos);
    }

    /**
     * Pedidos (já na ordem de aceite) em paradas por motoboy: coleta (se ainda não pegou) e entrega; ponto inválido sai.
     *
     * @param iterable<object> $pedidos driver_assigned_uuid, status, payload->pickup->location, payload->dropoff->location
     *
     * @return array<string, array<int, array{0: float, 1: float, 2: string}>>
     */
    public static function paradasDosPedidos(iterable $pedidos): array
    {
        $porMotoboy = [];
        foreach ($pedidos as $pedido) {
            $coleta    = Pontos::de($pedido->payload?->pickup?->location);
            $entrega   = Pontos::de($pedido->payload?->dropoff?->location);
            $emEntrega = in_array(strtolower((string) $pedido->status), SituacaoDoMotoboy::STATUS_EM_ENTREGA, true);
            if (!$emEntrega && $coleta) {
                $porMotoboy[$pedido->driver_assigned_uuid][] = [$coleta[0], $coleta[1], 'coleta'];
            }
            if ($entrega) {
                $porMotoboy[$pedido->driver_assigned_uuid][] = [$entrega[0], $entrega[1], 'entrega'];
            }
        }

        return $porMotoboy;
    }
}
