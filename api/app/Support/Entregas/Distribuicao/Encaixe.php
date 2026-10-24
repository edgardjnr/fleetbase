<?php

namespace App\Support\Entregas\Distribuicao;

/**
 * Onde a coleta (P) e a entrega (D) do pedido novo entram na sequência de paradas que o motoboy ainda tem, e quanto
 * tempo ele leva até D. Funções puras: a matriz vem de fora ($dur(i, j) em segundos entre dois índices de ponto).
 *
 * - Chegada numa parada = saída da anterior + rota; saída = chegada + parada fixa (PARADA_LOJA_S ou PARADA_CLIENTE_S).
 * - Coleta logo depois de outra coleta no mesmo ponto (rota de 0 s: a mesma loja) sai na mesma parada, sem somar outros
 *   3 min (decisão do Edgard de 2026-10-24: o pedido 9888 não foi ao Roger, que pegaria os dois na loja, porque a parada
 *   dobrada atrasava o cliente dele em mais de 5 min).
 * - Testa todas as inserções com P antes de D (inclusive P e D no fim = "termina tudo e depois vai").
 * - Uma inserção cabe se nenhuma entrega já aceita chega mais que o atraso máximo depois do que chegaria sem o pedido
 *   novo: Distribuicao::ATRASO_MAXIMO_S (10 min) no ciclo sem rodadas, ATRASO_MAXIMO_EM_RODADAS_S (5 min) em rodadas.
 * - Vale a que cabe com a menor chegada em D. P e D no fim sempre cabem (atraso 0).
 */
class Encaixe
{
    /**
     * @param int                                           $posicao índice do ponto onde o motoboy está
     * @param array<int, array{indice: int, tipo: string}> $base    paradas que faltam, na ordem
     * @param callable(int, int): float                     $dur     segundos de rota entre dois índices; deve ser um número finito (o EstimadorDeTempo garante)
     * @param int                                           $atrasoMaximoS maior atraso aceito numa entrega que ele já leva
     *
     * @return array{tempo_s: int, encaixe: bool, atraso_s: int} tempo até D; encaixe = P ou D entraram antes do fim; o maior atraso imposto
     */
    public static function calcular(int $posicao, array $base, int $p, int $d, callable $dur, int $atrasoMaximoS = Distribuicao::ATRASO_MAXIMO_S): array
    {
        $n              = count($base);
        $chegadasDaBase = static::chegadas($posicao, $base, $dur);
        $melhor         = null;

        for ($i = 0; $i <= $n; $i++) {
            for ($j = $i; $j <= $n; $j++) {
                $sequencia = array_merge(
                    array_slice($base, 0, $i),
                    [['indice' => $p, 'tipo' => 'coleta']],
                    array_slice($base, $i, $j - $i),
                    [['indice' => $d, 'tipo' => 'entrega']],
                    array_slice($base, $j)
                );
                $chegadas = static::chegadas($posicao, $sequencia, $dur);

                // atraso das paradas da base: a base ocupa, na sequência nova, as posições fora de i (P) e j + 1 (D)
                $atraso  = 0;
                $k       = 0;
                foreach ($sequencia as $pos => $parada) {
                    if ($pos === $i || $pos === $j + 1) {
                        continue;
                    }
                    if ($parada['tipo'] === 'entrega') {
                        $atraso = max($atraso, $chegadas[$pos] - $chegadasDaBase[$k]);
                    }
                    $k++;
                }
                if ($atraso > $atrasoMaximoS) {
                    continue;
                }

                $tempo = $chegadas[$j + 1];
                if ($melhor === null || $tempo < $melhor['tempo_s']) {
                    $melhor = ['tempo_s' => $tempo, 'encaixe' => !($i === $n && $j === $n), 'atraso_s' => $atraso];
                }
            }
        }

        return $melhor;
    }

    /** Segundos de chegada em cada parada da sequência, a partir do ponto de partida. */
    public static function chegadas(int $partida, array $sequencia, callable $dur): array
    {
        $chegadas     = [];
        $saida        = 0;
        $anterior     = $partida;
        $tipoAnterior = null;
        foreach ($sequencia as $parada) {
            $rota = (int) round((float) $dur($anterior, $parada['indice']));
            if ($parada['tipo'] === 'coleta' && $tipoAnterior === 'coleta' && $rota === 0) {
                // a mesma loja: coleta na mesma parada da anterior (mesma chegada e mesma saída)
                $chegadas[] = end($chegadas);
                $anterior   = $parada['indice'];
                continue;
            }
            $chegada      = $saida + $rota;
            $chegadas[]   = $chegada;
            $saida        = $chegada + ($parada['tipo'] === 'coleta' ? Distribuicao::PARADA_LOJA_S : Distribuicao::PARADA_CLIENTE_S);
            $anterior     = $parada['indice'];
            $tipoAnterior = $parada['tipo'];
        }

        return $chegadas;
    }
}
