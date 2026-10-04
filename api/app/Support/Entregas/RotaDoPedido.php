<?php

namespace App\Support\Entregas;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\OSRM;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: o traçado da rota loja (pickup) → cliente (dropoff) que o app do motoboy desenha no mapa do
 * pedido, igual à linha do console (MotoboyController@rota). É o mesmo trecho do km do pagamento (CalculoEntregas) e o
 * mesmo OSRM (OSRM_HOST) que o console usa; o app não usa mais o Google Directions, que é pago.
 *
 * O traçado fica no cache (Redis) por pedido e coordenadas, e não no meta do pedido (o meta sai na API v1 e no socket):
 * VALIDADE_OSRM com a rota do OSRM e VALIDADE_ESTIMATIVA com a linha reta, que vale quando o OSRM falha ou não acha rota.
 */
class RotaDoPedido
{
    public const VALIDADE_OSRM       = 24 * 60 * 60;
    public const VALIDADE_ESTIMATIVA = 5 * 60;

    /** Mais pontos que isto é sobra: o overview=simplified do OSRM já devolve poucos para uma entrega na cidade. */
    public const MAX_PONTOS = 1000;

    public function __construct(protected CalculoEntregas $calculo)
    {
    }

    /**
     * ['linha' => [[lat, lng], ...], 'metros' => int, 'segundos' => ?int, 'aproximado' => bool], ou null quando a
     * coleta ou o destino não tem posição.
     */
    public function doPedido(Order $pedido): ?array
    {
        $origem  = $pedido->payload?->getPickupOrFirstWaypoint();
        $destino = $pedido->payload?->getDropoffOrLastWaypoint();
        if (!$this->calculo->temCoordenadas($origem) || !$this->calculo->temCoordenadas($destino)) {
            return null;
        }

        $coordenadas = static::coordenadasOsrm($origem, $destino);
        $chave       = 'entregas:rota:' . $pedido->uuid . ':' . md5($coordenadas);
        $guardada    = Cache::get($chave);
        if (is_array($guardada)) {
            return $guardada;
        }

        $rota = $this->peloOsrm($pedido, $coordenadas);
        if ($rota) {
            Cache::put($chave, $rota, static::VALIDADE_OSRM);

            return $rota;
        }

        $rota = $this->estimativa($origem, $destino);
        Cache::put($chave, $rota, static::VALIDADE_ESTIMATIVA);

        return $rota;
    }

    /** "lng,lat;lng,lat": o OSRM recebe a longitude primeiro. */
    public static function coordenadasOsrm(Place $origem, Place $destino): string
    {
        return $origem->location->getLng() . ',' . $origem->location->getLat() . ';' . $destino->location->getLng() . ',' . $destino->location->getLat();
    }

    /**
     * Polyline do Google com precisão 5 (o formato padrão do OSRM) em [[lat, lng], ...]. Decodificada aqui, e não pelo
     * Fleet-Ops, para o formato da resposta não depender da biblioteca dele. Texto corrompido devolve o que deu para ler.
     */
    public static function decodificar(string $polyline): array
    {
        $pontos  = [];
        $indice  = 0;
        $tamanho = strlen($polyline);
        $lat     = 0;
        $lng     = 0;

        while ($indice < $tamanho) {
            $deltas = [];
            foreach ([0, 1] as $_) {
                $resultado = 0;
                $deslocar  = 0;
                do {
                    if ($indice >= $tamanho) {
                        return $pontos;
                    }
                    $byte = ord($polyline[$indice++]) - 63;
                    $resultado |= ($byte & 0x1F) << $deslocar;
                    $deslocar += 5;
                } while ($byte >= 0x20 && $deslocar < 35);
                $deltas[] = ($resultado & 1) ? ~($resultado >> 1) : ($resultado >> 1);
            }
            $lat += $deltas[0];
            $lng += $deltas[1];
            $pontos[] = [round($lat / 1e5, 5), round($lng / 1e5, 5)];
        }

        return $pontos;
    }

    protected function peloOsrm(Order $pedido, string $coordenadas): ?array
    {
        $resposta = null;
        $erro     = null;

        try {
            // geometria em polyline (o padrão): o Fleet-Ops decodifica a polyline de cada rota e quebraria com o geojson
            $resposta = OSRM::getRouteFromCoordinatesString($coordenadas, ['overview' => 'simplified']);
        } catch (\Throwable $e) {
            $erro = $e->getMessage();
        }

        $rota   = is_array($resposta) && ($resposta['code'] ?? null) === 'Ok' ? ($resposta['routes'][0] ?? null) : null;
        $linha  = is_string($rota['geometry'] ?? null) ? static::decodificar($rota['geometry']) : [];
        $metros = (float) ($rota['distance'] ?? 0);
        if (count($linha) < 2 || $metros <= 0) {
            Log::info('[entregas] OSRM sem rota para o mapa do app; vai a linha reta', ['pedido' => $pedido->public_id, 'code' => is_array($resposta) ? ($resposta['code'] ?? null) : null, 'erro' => $erro]);

            return null;
        }

        if (count($linha) > static::MAX_PONTOS) {
            $passo  = (int) ceil(count($linha) / static::MAX_PONTOS);
            $ultimo = end($linha);
            $linha  = array_values(array_filter($linha, fn ($_, $i) => $i % $passo === 0, ARRAY_FILTER_USE_BOTH));
            if (end($linha) !== $ultimo) {
                $linha[] = $ultimo;
            }
        }

        return [
            'linha'      => $linha,
            'metros'     => (int) round($metros),
            'segundos'   => isset($rota['duration']) ? (int) round((float) $rota['duration']) : null,
            'aproximado' => false,
        ];
    }

    protected function estimativa(Place $origem, Place $destino): array
    {
        $linhaReta = Utils::calculateDrivingDistanceAndTime($origem->location, $destino->location);

        return [
            'linha'      => [
                [round((float) $origem->location->getLat(), 5), round((float) $origem->location->getLng(), 5)],
                [round((float) $destino->location->getLat(), 5), round((float) $destino->location->getLng(), 5)],
            ],
            'metros'     => (int) round($linhaReta->distance * CalculoEntregas::FATOR_ESTIMATIVA),
            'segundos'   => null,
            'aproximado' => true,
        ];
    }
}
