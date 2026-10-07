<?php

namespace App\Support\Entregas\Distribuicao;

use Fleetbase\FleetOps\Models\Order;

/**
 * A fila de um pedido aberto: para cada candidato, o tempo até o cliente novo (Encaixe) com uma matriz só do OSRM
 * (coleta, entrega, posições e paradas de todos). Ordem: menor tempo; empate: o livre primeiro; depois o public_id.
 */
class FilaDeCandidatos
{
    public function __construct(protected EstimadorDeTempo $estimador) {}

    /**
     * @param array<int, array{motoboy: object, posicao: array, distancia: float, paradas: array}> $candidatos
     * @param bool $noFim distribuição em rodadas: "termina tudo e depois vai" (Encaixe::noFim), sem encaixe no meio
     *
     * @return array<int, array{motoboy_uuid: string, public_id: string, nome: string, tempo_s: int, encaixe: bool, aproximado: bool, livre: bool, distancia_m: int}>
     */
    public function para(Order $pedido, array $candidatos, bool $noFim = false): array
    {
        $coleta  = Pontos::de($pedido->payload?->pickup?->location);
        $entrega = Pontos::de($pedido->payload?->dropoff?->location);
        if (!$coleta || !$entrega || $candidatos === []) {
            return [];
        }

        // índices da matriz: 0 = coleta (P), 1 = entrega (D), depois posição e paradas de cada candidato
        $pontos = [$coleta, $entrega];
        $plano  = [];
        foreach ($candidatos as $candidato) {
            $posicao  = count($pontos);
            $pontos[] = $candidato['posicao'];
            $base     = [];
            foreach ($candidato['paradas'] as $parada) {
                $base[]   = ['indice' => count($pontos), 'tipo' => $parada[2]];
                $pontos[] = [$parada[0], $parada[1]];
            }
            $plano[] = [$candidato, $posicao, $base];
        }

        $matriz = $this->estimador->matriz($pontos);
        $dur    = fn (int $i, int $j) => (float) ($matriz['durations'][$i][$j] ?? 0);

        $fila = [];
        foreach ($plano as [$candidato, $posicao, $base]) {
            $resultado = $noFim ? Encaixe::noFim($posicao, $base, 0, 1, $dur) : Encaixe::calcular($posicao, $base, 0, 1, $dur);
            $fila[]    = [
                'motoboy_uuid' => (string) $candidato['motoboy']->uuid,
                'public_id'    => (string) $candidato['motoboy']->public_id,
                'nome'         => (string) $candidato['motoboy']->name,
                'tempo_s'      => $resultado['tempo_s'],
                'encaixe'      => $resultado['encaixe'],
                'aproximado'   => $matriz['aproximado'],
                'livre'        => $base === [],
                'distancia_m'  => (int) round($candidato['distancia']),
            ];
        }

        usort($fila, fn ($a, $b) => [$a['tempo_s'], $a['livre'] ? 0 : 1, $a['public_id']] <=> [$b['tempo_s'], $b['livre'] ? 0 : 1, $b['public_id']]);

        return $fila;
    }
}
