<?php

namespace App\Support\Entregas\Distribuicao;

use Fleetbase\FleetOps\Models\Order;

/**
 * A fila de um pedido aberto: para cada candidato, o tempo até o cliente novo (Encaixe) com uma matriz só do OSRM
 * (coleta, entrega, posições e paradas de todos). Ordem (ordenar): quem está até Distribuicao::DESEMPATE_CORRIDAS_S
 * do mais rápido vem primeiro, por menos corridas hoje; o resto pelo tempo. Empates: o tempo, o livre, o public_id.
 * Spec do desempate: docs/superpowers/specs/2026-10-19-desempate-por-corridas-design.md.
 */
class FilaDeCandidatos
{
    public function __construct(protected EstimadorDeTempo $estimador) {}

    /**
     * @param array<int, array{motoboy: object, posicao: array, distancia: float, paradas: array}> $candidatos
     * @param int $atrasoMaximoS maior atraso aceito numa entrega que o motoboy já leva (Encaixe::calcular): 10 min no ciclo
     *                           sem rodadas, Distribuicao::ATRASO_MAXIMO_EM_RODADAS_S (5 min) em rodadas
     *
     * @return array<int, array{motoboy_uuid: string, public_id: string, nome: string, tempo_s: int, encaixe: bool, aproximado: bool, livre: bool, distancia_m: int, corridas_hoje: int, desempate: bool}>
     */
    public function para(Order $pedido, array $candidatos, int $atrasoMaximoS = Distribuicao::ATRASO_MAXIMO_S): array
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
            $resultado = Encaixe::calcular($posicao, $base, 0, 1, $dur, $atrasoMaximoS);
            $fila[]    = [
                'motoboy_uuid' => (string) $candidato['motoboy']->uuid,
                'public_id'    => (string) $candidato['motoboy']->public_id,
                'nome'         => (string) $candidato['motoboy']->name,
                'tempo_s'      => $resultado['tempo_s'],
                'encaixe'      => $resultado['encaixe'],
                'aproximado'   => $matriz['aproximado'],
                'livre'        => $base === [],
                'distancia_m'  => (int) round($candidato['distancia']),
                'corridas_hoje' => (int) ($candidato['corridas_hoje'] ?? 0),
            ];
        }

        return static::ordenar($fila);
    }

    /**
     * Ordena a fila: o grupo de quem está até DESEMPATE_CORRIDAS_S do menor tempo vem primeiro, por menos corridas hoje
     * (empate: o tempo, o livre, o public_id); depois o resto pelo tempo, o livre e o public_id. `desempate` marca quem
     * ficou à frente de alguém com tempo menor. Linha sem corridas_hoje vale 0.
     *
     * @param array<int, array> $fila linhas do para()
     *
     * @return array<int, array>
     */
    public static function ordenar(array $fila): array
    {
        foreach ($fila as &$linha) {
            $linha['corridas_hoje'] = (int) ($linha['corridas_hoje'] ?? 0);
            $linha['desempate']     = false;
        }
        unset($linha);

        $porTempo = fn ($a, $b) => [$a['tempo_s'], $a['livre'] ? 0 : 1, $a['public_id']] <=> [$b['tempo_s'], $b['livre'] ? 0 : 1, $b['public_id']];
        usort($fila, $porTempo);
        if ($fila === []) {
            return [];
        }

        $limite = $fila[0]['tempo_s'] + Distribuicao::DESEMPATE_CORRIDAS_S;
        $grupo  = array_values(array_filter($fila, fn ($l) => $l['tempo_s'] <= $limite));
        $resto  = array_values(array_filter($fila, fn ($l) => $l['tempo_s'] > $limite));
        usort($grupo, fn ($a, $b) => [$a['corridas_hoje'], $a['tempo_s'], $a['livre'] ? 0 : 1, $a['public_id']] <=> [$b['corridas_hoje'], $b['tempo_s'], $b['livre'] ? 0 : 1, $b['public_id']]);

        // desempate: alguém atrás dele no grupo chegaria antes (o resto chega sempre depois de todo o grupo)
        $total = count($grupo);
        for ($i = 0; $i < $total; $i++) {
            for ($j = $i + 1; $j < $total; $j++) {
                if ($grupo[$j]['tempo_s'] < $grupo[$i]['tempo_s']) {
                    $grupo[$i]['desempate'] = true;
                    break;
                }
            }
        }

        return array_merge($grupo, $resto);
    }
}
