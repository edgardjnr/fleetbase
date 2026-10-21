<?php

namespace App\Support\Entregas;

/**
 * Entregas RestaurantePro: valor final de uma entrega a partir da linha congelada (entregas_valores_pedido): a tabela
 * normal ou a segunda tabela ("a partir da 2ª entrega junto", quando a posição é 2 ou mais e havia segunda tabela no
 * cálculo), vezes o multiplicador do período gravado na atribuição. Funções puras, sem banco.
 * Spec: docs/superpowers/specs/2026-10-21-entrega-junto-e-multiplicador-design.md.
 *
 * A linha pode vir do banco (decimal em texto, booleano em 0/1) ou recém-montada (números).
 */
final class ValorDaEntrega
{
    /** A partir desta posição o pedido usa a segunda tabela. */
    public const JUNTO_A_PARTIR = 2;

    /** A parte da atribuição na linha congelada (gravada por ValoresCongelados::gravarAtribuicoes). */
    public const COLUNAS_DA_ATRIBUICAO = ['motoboy_uuid', 'atribuido_em', 'posicao', 'multiplicador_uuid', 'multiplicador_nome', 'mult_motoboy', 'mult_loja'];

    /**
     * @return array{faixa: array, motoboy: float, loja: float, tabela: string, posicao: ?int, multiplicador: ?array{nome: string, motoboy: float, loja: float}}
     */
    public static function final(array $linha): array
    {
        $posicao = static::temValor($linha['posicao'] ?? null) ? (int) $linha['posicao'] : null;
        $junto   = $posicao !== null && $posicao >= static::JUNTO_A_PARTIR
            && static::temValor($linha['junto_motoboy'] ?? null) && static::temValor($linha['junto_loja'] ?? null);

        $prefixo = $junto ? 'junto_' : '';
        $faixa   = [
            'de_km'   => (float) $linha[$prefixo . 'de_km'],
            'ate_km'  => (float) $linha[$prefixo . 'ate_km'],
            'motoboy' => (float) $linha[$junto ? 'junto_motoboy' : 'valor_motoboy'],
            'loja'    => (float) $linha[$junto ? 'junto_loja' : 'valor_loja'],
        ];
        if (!empty($linha[$prefixo . 'acima'])) {
            $faixa['acima'] = true;
        }

        $multiplicador = null;
        if (static::temValor($linha['mult_motoboy'] ?? null) || static::temValor($linha['mult_loja'] ?? null)) {
            $multiplicador = [
                'nome'    => (string) ($linha['multiplicador_nome'] ?? ''),
                'motoboy' => static::fator($linha['mult_motoboy'] ?? null),
                'loja'    => static::fator($linha['mult_loja'] ?? null),
            ];
        }

        return [
            'faixa'         => $faixa,
            'motoboy'       => round($faixa['motoboy'] * ($multiplicador['motoboy'] ?? 1.0), 2),
            'loja'          => round($faixa['loja'] * ($multiplicador['loja'] ?? 1.0), 2),
            'tabela'        => $junto ? 'junto' : 'normal',
            'posicao'       => $posicao,
            'multiplicador' => $multiplicador,
        ];
    }

    /**
     * Posição do pedido aceito em $instante: 1 + os outros pedidos do mesmo motoboy aceitos antes (no máximo
     * $horasMaximas antes) e ainda não encerrados nele. O limite é o mesmo do emAberto da atribuição
     * (SituacaoDoMotoboy::HORAS_PEDIDO_EM_ANDAMENTO): um pedido esquecido aberto não faz todos os seguintes do motoboy
     * virarem 2ª entrega. $outros: [['aceite' => 'Y-m-d H:i:s'|null, 'fim' => 'Y-m-d H:i:s'|null], ...] em Brasília.
     */
    public static function posicaoPorHorarios(string $instante, array $outros, int $horasMaximas = 12): int
    {
        $desde   = (new \DateTimeImmutable($instante))->modify('-' . $horasMaximas . ' hours')->format('Y-m-d H:i:s');
        $levando = 0;
        foreach ($outros as $outro) {
            $aceite = $outro['aceite'] ?? null;
            $fim    = $outro['fim'] ?? null;
            if ($aceite !== null && $aceite >= $desde && $aceite < $instante && ($fim === null || $fim > $instante)) {
                $levando++;
            }
        }

        return 1 + $levando;
    }

    /** O que o motoboy vê do multiplicador: nome e o fator dele; nada quando o fator dele é 1. */
    public static function doMotoboy(?array $multiplicador): ?array
    {
        return static::daConta($multiplicador, 'motoboy');
    }

    /** O que a loja vê do multiplicador: nome e o fator dela; nada quando o fator dela é 1. */
    public static function daLoja(?array $multiplicador): ?array
    {
        return static::daConta($multiplicador, 'loja');
    }

    private static function daConta(?array $multiplicador, string $conta): ?array
    {
        $fator = $multiplicador[$conta] ?? null;
        if ($fator === null || abs((float) $fator - 1.0) < 0.001) {
            return null;
        }

        return ['nome' => (string) ($multiplicador['nome'] ?? ''), 'fator' => round((float) $fator, 2)];
    }

    private static function fator($valor): float
    {
        return static::temValor($valor) ? round((float) $valor, 2) : 1.0;
    }

    private static function temValor($valor): bool
    {
        return $valor !== null && $valor !== '';
    }
}
