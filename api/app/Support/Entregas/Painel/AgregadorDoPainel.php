<?php

namespace App\Support\Entregas\Painel;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Entregas RestaurantePro: contas do painel inicial do console (PainelInicial), sem banco, testadas no php-wasm
 * (scripts/teste-php/painel-inicial-agregador.php).
 * Instantes sem fuso ('Y-m-d H:i:s', DATETIME/TIMESTAMP já em Brasília) são lidos no fuso da empresa.
 */
final class AgregadorDoPainel
{
    /** Período de comparação: um dia → o mesmo dia da semana anterior; N dias → os N dias antes do início. */
    public static function periodoAnterior(string $inicio, string $fim): array
    {
        $de    = new DateTimeImmutable($inicio);
        $ate   = new DateTimeImmutable($fim);
        $dias  = (int) $de->diff($ate)->days + 1;
        $passo = $dias === 1 ? 7 : $dias;

        return [$de->modify("-{$passo} days")->format('Y-m-d'), $ate->modify("-{$passo} days")->format('Y-m-d')];
    }

    /** Variação em % (inteiro) do atual sobre o anterior; null sem base. */
    public static function variacao(int $atual, int $anterior): ?int
    {
        return $anterior > 0 ? (int) round(($atual - $anterior) / $anterior * 100) : null;
    }

    /**
     * Quantas corridas em cada hora do dia (0 a 23).
     *
     * @param array<int, string> $instantes 'Y-m-d H:i:s'
     *
     * @return array<int, int>
     */
    public static function porHora(array $instantes): array
    {
        $horas = array_fill(0, 24, 0);
        foreach ($instantes as $instante) {
            $hora = (int) substr((string) $instante, 11, 2);
            if ($hora >= 0 && $hora < 24) {
                $horas[$hora]++;
            }
        }

        return $horas;
    }

    /**
     * Corridas por dia, do início ao fim (dia sem corrida com 0).
     *
     * @return array<int, array{dia: string, total: int}>
     */
    public static function porDia(array $instantes, string $inicio, string $fim): array
    {
        $contagem = [];
        foreach ($instantes as $instante) {
            $dia            = substr((string) $instante, 0, 10);
            $contagem[$dia] = ($contagem[$dia] ?? 0) + 1;
        }

        $dias = [];
        $ate  = new DateTimeImmutable($fim);
        for ($dia = new DateTimeImmutable($inicio); $dia <= $ate; $dia = $dia->modify('+1 day')) {
            $chave  = $dia->format('Y-m-d');
            $dias[] = ['dia' => $chave, 'total' => $contagem[$chave] ?? 0];
        }

        return $dias;
    }

    /**
     * Média por hora de várias semanas (as corridas do mesmo dia da semana), com uma casa.
     *
     * @return array<int, float>
     */
    public static function mediaPorHora(array $instantes, int $semanas): array
    {
        return array_map(fn (int $total) => round($total / max(1, $semanas), 1), static::porHora($instantes));
    }

    /**
     * Ranking por conta (loja ou motoboy): mais corridas primeiro, empate pelo nome. Linha sem uuid fica fora.
     *
     * @param array<int, object|array> $linhas
     *
     * @return array<int, array{uuid: string, nome: string, corridas: int}>
     */
    public static function ranking(array $linhas, string $campoUuid, string $campoNome): array
    {
        $grupos = [];
        foreach ($linhas as $linha) {
            $linha = (array) $linha;
            $uuid  = (string) ($linha[$campoUuid] ?? '');
            if ($uuid === '') {
                continue;
            }
            $grupos[$uuid] ??= ['uuid' => $uuid, 'nome' => '', 'corridas' => 0];
            $grupos[$uuid]['corridas']++;
            if ($grupos[$uuid]['nome'] === '' && !empty($linha[$campoNome])) {
                $grupos[$uuid]['nome'] = (string) $linha[$campoNome];
            }
        }

        $lista = array_values($grupos);
        usort($lista, fn ($a, $b) => [$b['corridas'], $a['nome']] <=> [$a['corridas'], $b['nome']]);

        return $lista;
    }

    /**
     * % entregue no prazo (só pedidos com prazo legível) e tempo médio do aceite à conclusão, em minutos.
     *
     * @param array<int, array{concluido_em: ?string, aceito_em: ?string, prazo_em: ?string}> $pedidos
     *
     * @return array{no_prazo: ?int, tempo_medio_min: ?int}
     */
    public static function prazos(array $pedidos, string $fuso): array
    {
        $zona     = new DateTimeZone($fuso);
        $comPrazo = 0;
        $noPrazo  = 0;
        $minutos  = [];

        foreach ($pedidos as $pedido) {
            $concluido = static::instante($pedido['concluido_em'] ?? null, $zona);
            if (!$concluido) {
                continue;
            }
            $prazo = static::instante($pedido['prazo_em'] ?? null, $zona);
            if ($prazo) {
                $comPrazo++;
                if ($concluido <= $prazo) {
                    $noPrazo++;
                }
            }
            $aceito = static::instante($pedido['aceito_em'] ?? null, $zona);
            if ($aceito && $aceito <= $concluido) {
                $minutos[] = ($concluido->getTimestamp() - $aceito->getTimestamp()) / 60;
            }
        }

        return [
            'no_prazo'        => $comPrazo ? (int) round($noPrazo / $comPrazo * 100) : null,
            'tempo_medio_min' => $minutos ? (int) round(array_sum($minutos) / count($minutos)) : null,
        ];
    }

    /** Texto → instante (com o fuso do texto, se tiver; senão o da empresa); null se vazio ou inválido. */
    private static function instante($valor, DateTimeZone $zona): ?DateTimeImmutable
    {
        if (!is_string($valor) || trim($valor) === '') {
            return null;
        }
        try {
            return new DateTimeImmutable(trim($valor), $zona);
        } catch (\Exception) {
            return null;
        }
    }
}
