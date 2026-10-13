<?php

namespace App\Support\Entregas\Financeiro;

/**
 * Entregas RestaurantePro: o ciclo de cobrança de uma loja (`{ciclo, dia_fechamento, prazo_dias}`). Funções puras, só
 * com datas 'Y-m-d' (dias do calendário, sem hora: nada de fuso aqui; quem chama passa o dia já no fuso da empresa).
 *
 * - semanal: fecha no dia da semana `dia_fechamento` (0 = domingo .. 6 = sábado); o período são os 7 dias até ele.
 * - quinzenal: fecha no dia 15 (1–15) e no último dia do mês (16–fim); `dia_fechamento` é ignorado (gravado 0).
 * - mensal: fecha no dia `dia_fechamento` (1–28; 0 = último dia do mês); o período começa no dia seguinte ao fechamento
 *   do mês anterior.
 * - vencimento = fim do período + `prazo_dias` (0 a 60).
 */
final class CicloDeCobranca
{
    public const CICLOS = ['semanal', 'quinzenal', 'mensal'];

    public const PRAZO_MAXIMO = 60;

    /** O período que termina exatamente em $dia ('Y-m-d') pela config {ciclo, dia_fechamento}; null se $dia não fecha ciclo. */
    public static function periodoQueFechaEm(array $config, string $dia): ?array
    {
        $config = static::normalizar($config);
        $data   = static::data($dia);

        switch ($config['ciclo']) {
            case 'semanal':
                if ((int) $data->format('w') !== $config['dia_fechamento']) {
                    return null;
                }

                return static::periodo($data->modify('-6 days'), $data);
            case 'quinzenal':
                if ((int) $data->format('j') === 15) {
                    return static::periodo($data->modify('first day of this month'), $data);
                }
                if ((int) $data->format('j') === (int) $data->format('t')) {
                    return static::periodo($data->setDate((int) $data->format('Y'), (int) $data->format('n'), 16), $data);
                }

                return null;
            case 'mensal':
                if (!static::fechaNoMes($config['dia_fechamento'], $data)) {
                    return null;
                }

                return static::periodo(static::inicioDoMensal($config['dia_fechamento'], $data), $data);
        }

        return null;
    }

    /** Período em andamento que contém $dia (para "ciclo em andamento"): termina no próximo fechamento (ou no próprio $dia). */
    public static function periodoQueContem(array $config, string $dia): array
    {
        $config = static::normalizar($config);
        $data   = static::data($dia);

        switch ($config['ciclo']) {
            case 'semanal':
                $faltam = ($config['dia_fechamento'] - (int) $data->format('w') + 7) % 7;
                $fim    = $data->modify('+' . $faltam . ' days');

                return static::periodo($fim->modify('-6 days'), $fim);
            case 'quinzenal':
                if ((int) $data->format('j') <= 15) {
                    $inicio = $data->modify('first day of this month');

                    return static::periodo($inicio, $inicio->modify('+14 days'));
                }

                return static::periodo($data->setDate((int) $data->format('Y'), (int) $data->format('n'), 16), $data->modify('last day of this month'));
            default:
                // mensal: o fechamento deste mês, se ainda não passou; senão o do mês que vem
                $fim = static::fechamentoDoMes($config['dia_fechamento'], $data);
                if ($fim < $data) {
                    $fim = static::fechamentoDoMes($config['dia_fechamento'], $data->modify('first day of next month'));
                }

                return static::periodo(static::inicioDoMensal($config['dia_fechamento'], $fim), $fim);
        }
    }

    /** fim + prazo_dias ('Y-m-d'). */
    public static function vencimento(array $config, string $fim): string
    {
        $prazo = max(0, (int) ($config['prazo_dias'] ?? 0));

        return static::data($fim)->modify('+' . $prazo . ' days')->format('Y-m-d');
    }

    /** Mensagem de erro em pt-BR, ou null se a configuração vale. Números podem vir em texto ("3"). */
    public static function validar(array $config): ?string
    {
        $ciclo = $config['ciclo'] ?? null;
        if (!in_array($ciclo, static::CICLOS, true)) {
            return 'Escolha o ciclo: semanal, quinzenal ou mensal.';
        }

        if ($ciclo !== 'quinzenal') {
            $dia = static::inteiro($config['dia_fechamento'] ?? null);
            if ($ciclo === 'semanal' && ($dia === null || $dia < 0 || $dia > 6)) {
                return 'Escolha o dia da semana em que o ciclo fecha.';
            }
            if ($ciclo === 'mensal' && ($dia === null || $dia < 0 || $dia > 28)) {
                return 'O dia de fechamento do ciclo mensal vai de 1 a 28 (ou o último dia do mês).';
            }
        }

        $prazo = static::inteiro($config['prazo_dias'] ?? null);
        if ($prazo === null || $prazo < 0 || $prazo > static::PRAZO_MAXIMO) {
            return 'O prazo de vencimento vai de 0 a ' . static::PRAZO_MAXIMO . ' dias.';
        }

        return null;
    }

    /** A config em números ({ciclo, dia_fechamento, prazo_dias}); quinzenal grava dia 0. Não valida (use validar antes). */
    public static function normalizar(array $config): array
    {
        $ciclo = (string) ($config['ciclo'] ?? '');

        return [
            'ciclo'          => $ciclo,
            'dia_fechamento' => $ciclo === 'quinzenal' ? 0 : (int) ($config['dia_fechamento'] ?? 0),
            'prazo_dias'     => (int) ($config['prazo_dias'] ?? 0),
        ];
    }

    /** Inteiro de um número ou texto só com dígitos (com sinal); null se não for. */
    private static function inteiro($valor): ?int
    {
        if (is_int($valor)) {
            return $valor;
        }
        if (is_float($valor) && floor($valor) === $valor) {
            return (int) $valor;
        }
        if (is_string($valor) && preg_match('/^-?\d+$/', trim($valor))) {
            return (int) trim($valor);
        }

        return null;
    }

    /** O dia, à meia-noite em UTC: só aritmética de calendário (UTC não tem horário de verão para pular dias). */
    private static function data(string $dia): \DateTimeImmutable
    {
        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($dia, 0, 10), new \DateTimeZone('UTC'));
        if (!$data || $data->format('Y-m-d') !== substr($dia, 0, 10)) {
            throw new \InvalidArgumentException('Data inválida.');
        }

        return $data;
    }

    private static function periodo(\DateTimeImmutable $inicio, \DateTimeImmutable $fim): array
    {
        return ['inicio' => $inicio->format('Y-m-d'), 'fim' => $fim->format('Y-m-d')];
    }

    /** O dia de fechamento do mensal no mês de $data (0 = último dia). */
    private static function fechamentoDoMes(int $dia, \DateTimeImmutable $data): \DateTimeImmutable
    {
        return $dia === 0 ? $data->modify('last day of this month') : $data->setDate((int) $data->format('Y'), (int) $data->format('n'), $dia);
    }

    private static function fechaNoMes(int $dia, \DateTimeImmutable $data): bool
    {
        return static::fechamentoDoMes($dia, $data)->format('Y-m-d') === $data->format('Y-m-d');
    }

    /** Início do período mensal que fecha em $fim: o dia seguinte ao fechamento do mês anterior. */
    private static function inicioDoMensal(int $dia, \DateTimeImmutable $fim): \DateTimeImmutable
    {
        return static::fechamentoDoMes($dia, $fim->modify('first day of last month'))->modify('+1 day');
    }
}
