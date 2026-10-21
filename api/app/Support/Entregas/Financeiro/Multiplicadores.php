<?php

namespace App\Support\Entregas\Financeiro;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Entregas RestaurantePro: multiplicadores por período do Financeiro (chuva, intempérie). A entrega atribuída dentro do
 * período (início ≤ atribuição < fim) vale a faixa × o fator: um para o motoboy, outro para a loja. Tabela
 * entregas_multiplicadores; horários em Brasília ('Y-m-d H:i:s', como as DATETIME do Fleet-Ops).
 *
 * Regras: nome até 40; fator de 1,00 a 5,00 com até duas casas; fim obrigatório, depois do início e de agora, no máximo
 * 7 dias depois do início; início no passado vira agora ("Começar agora"); períodos da empresa não se sobrepõem.
 * Edição: agendado muda tudo ou é apagado; em andamento só muda o fim (ou encerra); encerrado não muda (os valores já
 * estão congelados nos pedidos). Spec: docs/superpowers/specs/2026-10-21-entrega-junto-e-multiplicador-design.md.
 */
final class Multiplicadores
{
    public const TABELA = 'entregas_multiplicadores';

    public const FATOR_MINIMO = 1.0;
    public const FATOR_MAXIMO = 5.0;
    public const MAX_DIAS     = 7;
    public const MAX_NOME     = 40;

    public const AGENDADO     = 'agendado';
    public const EM_ANDAMENTO = 'em_andamento';
    public const ENCERRADO    = 'encerrado';

    /** O período que vale no instante ('Y-m-d H:i:s' de Brasília), ou null. */
    public static function ativoEm(string $empresa, string $instante): ?object
    {
        return DB::table(static::TABELA)
            ->where('company_uuid', $empresa)
            ->whereNull('deleted_at')
            ->where('inicio', '<=', $instante)
            ->where('fim', '>', $instante)
            ->first();
    }

    /** Os períodos que tocam [de, ate], numa consulta só (reserva do CalculoEntregas). */
    public static function doPeriodo(string $empresa, string $de, string $ate): array
    {
        return DB::table(static::TABELA)
            ->where('company_uuid', $empresa)
            ->whereNull('deleted_at')
            ->where('inicio', '<=', $ate)
            ->where('fim', '>', $de)
            ->get()
            ->all();
    }

    /** O período de uma lista (doPeriodo) que vale no instante, ou null. */
    public static function noInstante(array $periodos, string $instante): ?object
    {
        foreach ($periodos as $periodo) {
            if ((string) $periodo->inicio <= $instante && (string) $periodo->fim > $instante) {
                return $periodo;
            }
        }

        return null;
    }

    /** Os períodos da empresa no formato do console, o de início mais recente primeiro. */
    public static function lista(string $empresa, string $agora): array
    {
        $periodos = DB::table(static::TABELA)->where('company_uuid', $empresa)->whereNull('deleted_at')->get()->all();
        usort($periodos, fn ($a, $b) => strcmp((string) $b->inicio, (string) $a->inicio));

        return array_map(fn ($periodo) => static::formatar($periodo, $agora), $periodos);
    }

    /** {id, nome, mult_motoboy, mult_loja, inicio, fim (ISO com fuso), situacao}. */
    public static function formatar(object $periodo, string $agora): array
    {
        return [
            'id'           => (string) $periodo->uuid,
            'nome'         => (string) $periodo->nome,
            'mult_motoboy' => round((float) $periodo->mult_motoboy, 2),
            'mult_loja'    => round((float) $periodo->mult_loja, 2),
            'inicio'       => static::iso((string) $periodo->inicio),
            'fim'          => static::iso((string) $periodo->fim),
            'situacao'     => static::situacao($periodo, $agora),
        ];
    }

    public static function situacao(object $periodo, string $agora): string
    {
        if ((string) $periodo->fim <= $agora) {
            return static::ENCERRADO;
        }

        return (string) $periodo->inicio <= $agora ? static::EM_ANDAMENTO : static::AGENDADO;
    }

    /** 'Y-m-d H:i:s' de Brasília → '2026-10-21T18:00:00-03:00'. */
    public static function iso(string $instante): string
    {
        return (new \DateTimeImmutable($instante, new \DateTimeZone(date_default_timezone_get())))->format('Y-m-d\TH:i:sP');
    }

    /** Cria o período. Devolve o formato do console ou a mensagem de erro. */
    public static function criar(string $empresa, array $dados, ?string $usuario, string $agora): array|string
    {
        $periodo = static::conferir($dados, $agora);
        if (is_string($periodo)) {
            return $periodo;
        }

        return DB::transaction(function () use ($empresa, $periodo, $usuario, $agora) {
            if ($conflito = static::sobreposto($empresa, $periodo['inicio'], $periodo['fim'], null)) {
                return static::mensagemDeConflito($conflito);
            }

            $uuid = (string) Str::uuid();
            DB::table(static::TABELA)->insert($periodo + [
                'uuid'         => $uuid,
                'company_uuid' => $empresa,
                'criado_por'   => $usuario,
                'created_at'   => $agora,
                'updated_at'   => $agora,
            ]);

            return static::formatar(static::buscar($empresa, $uuid), $agora);
        });
    }

    /**
     * Agendado: muda tudo (mesmas regras do criar). Em andamento: só o fim (os outros campos são ignorados). Encerrado:
     * erro. Devolve o formato do console, a mensagem de erro, ou null quando o período não existe nesta empresa.
     */
    public static function alterar(string $empresa, string $uuid, array $dados, string $agora): array|string|null
    {
        return DB::transaction(function () use ($empresa, $uuid, $dados, $agora) {
            $periodo = static::buscar($empresa, $uuid);
            if (!$periodo) {
                return null;
            }

            $situacao = static::situacao($periodo, $agora);
            if ($situacao === static::ENCERRADO) {
                return 'Período encerrado não muda.';
            }

            if ($situacao === static::AGENDADO) {
                $novo = static::conferir($dados, $agora);
                if (is_string($novo)) {
                    return $novo;
                }
            } else {
                $fim = static::instante($dados['fim'] ?? null);
                if ($fim === null) {
                    return 'Informe o fim do período.';
                }
                if ($erro = static::erroDoFim((string) $periodo->inicio, $fim, $agora)) {
                    return $erro;
                }
                $novo = ['fim' => $fim];
            }

            if ($conflito = static::sobreposto($empresa, $novo['inicio'] ?? (string) $periodo->inicio, $novo['fim'], $uuid)) {
                return static::mensagemDeConflito($conflito);
            }

            DB::table(static::TABELA)->where('company_uuid', $empresa)->where('uuid', $uuid)->update($novo + ['updated_at' => $agora]);

            return static::formatar(static::buscar($empresa, $uuid), $agora);
        });
    }

    /** "Encerrar agora": só o período em andamento (fim = agora). */
    public static function encerrar(string $empresa, string $uuid, ?string $usuario, string $agora): array|string|null
    {
        $periodo = static::buscar($empresa, $uuid);
        if (!$periodo) {
            return null;
        }

        $situacao = static::situacao($periodo, $agora);
        if ($situacao === static::AGENDADO) {
            return 'O período ainda não começou: apague em vez de encerrar.';
        }
        if ($situacao === static::ENCERRADO) {
            return 'O período já está encerrado.';
        }

        DB::table(static::TABELA)->where('company_uuid', $empresa)->where('uuid', $uuid)->update(['fim' => $agora, 'encerrado_por' => $usuario, 'updated_at' => $agora]);

        return static::formatar(static::buscar($empresa, $uuid), $agora);
    }

    /** Apaga (marca deleted_at) só o período agendado. true, a mensagem de erro, ou null quando não existe. */
    public static function apagar(string $empresa, string $uuid, string $agora): bool|string|null
    {
        $periodo = static::buscar($empresa, $uuid);
        if (!$periodo) {
            return null;
        }
        if (static::situacao($periodo, $agora) !== static::AGENDADO) {
            return 'Só dá para apagar período que ainda não começou.';
        }

        DB::table(static::TABELA)->where('company_uuid', $empresa)->where('uuid', $uuid)->update(['deleted_at' => $agora, 'updated_at' => $agora]);

        return true;
    }

    /** Confere e normaliza {nome, mult_motoboy, mult_loja, inicio, fim}. Erro: a mensagem. */
    public static function conferir(array $dados, string $agora): array|string
    {
        $nome = trim((string) ($dados['nome'] ?? ''));
        if ($nome === '') {
            return 'Dê um nome ao período (ex.: Chuva).';
        }
        if (mb_strlen($nome) > static::MAX_NOME) {
            return 'O nome pode ter até 40 caracteres.';
        }

        $motoboy = static::fator($dados['mult_motoboy'] ?? null);
        $loja    = static::fator($dados['mult_loja'] ?? null);
        if ($motoboy === null || $loja === null) {
            return 'O multiplicador vai de 1,00 a 5,00, com até duas casas (ex.: 1,2).';
        }

        $inicio = static::instante($dados['inicio'] ?? null);
        $fim    = static::instante($dados['fim'] ?? null);
        if ($inicio === null || $fim === null) {
            return 'Informe o início e o fim do período.';
        }
        // "Começar agora" (e relógio do navegador um pouco atrasado): o período começa agora, não no passado
        if ($inicio < $agora) {
            $inicio = $agora;
        }
        if ($erro = static::erroDoFim($inicio, $fim, $agora)) {
            return $erro;
        }

        return ['nome' => $nome, 'mult_motoboy' => $motoboy, 'mult_loja' => $loja, 'inicio' => $inicio, 'fim' => $fim];
    }

    /** Fator de 1,00 a 5,00 com até duas casas ("1,2", "1.2", 1.2), ou null. */
    public static function fator($valor): ?float
    {
        if (is_string($valor)) {
            $valor = str_replace(',', '.', trim($valor));
        }
        if (!is_numeric($valor)) {
            return null;
        }

        $numero = (float) $valor;
        if (!is_finite($numero) || abs($numero * 100 - round($numero * 100)) > 0.0001) {
            return null;
        }
        $numero = round($numero, 2);

        return $numero >= static::FATOR_MINIMO && $numero <= static::FATOR_MAXIMO ? $numero : null;
    }

    /** 'Y-m-d H:i', 'Y-m-d\TH:i' (o datetime-local do console), com ou sem segundos → 'Y-m-d H:i:s'; inválido: null. */
    public static function instante($valor): ?string
    {
        if (!is_string($valor) || !preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(:\d{2})?$/', trim($valor), $partes)) {
            return null;
        }

        $texto = $partes[1] . ' ' . $partes[2] . (($partes[3] ?? '') !== '' ? $partes[3] : ':00');
        $data  = \DateTime::createFromFormat('!Y-m-d H:i:s', $texto);

        return $data && $data->format('Y-m-d H:i:s') === $texto ? $texto : null;
    }

    protected static function erroDoFim(string $inicio, string $fim, string $agora): ?string
    {
        if ($fim <= $inicio || $fim <= $agora) {
            return 'O fim precisa ser depois do início e de agora.';
        }

        $limite = (new \DateTimeImmutable($inicio))->modify('+' . static::MAX_DIAS . ' days')->format('Y-m-d H:i:s');

        return $fim > $limite ? 'O período pode durar até 7 dias.' : null;
    }

    /** O período da empresa que cruza [inicio, fim), com trava das linhas (dentro da transação), ou null. */
    protected static function sobreposto(string $empresa, string $inicio, string $fim, ?string $ignorar): ?object
    {
        $periodos = DB::table(static::TABELA)->where('company_uuid', $empresa)->whereNull('deleted_at')->lockForUpdate()->get()->all();
        foreach ($periodos as $periodo) {
            if ($ignorar !== null && (string) $periodo->uuid === $ignorar) {
                continue;
            }
            if ((string) $periodo->inicio < $fim && (string) $periodo->fim > $inicio) {
                return $periodo;
            }
        }

        return null;
    }

    protected static function mensagemDeConflito(object $periodo): string
    {
        $curto = fn (string $instante) => (new \DateTimeImmutable($instante))->format('d/m H:i');

        return sprintf('Já existe o período "%s" de %s a %s nesse horário.', $periodo->nome, $curto((string) $periodo->inicio), $curto((string) $periodo->fim));
    }

    protected static function buscar(string $empresa, string $uuid): ?object
    {
        return DB::table(static::TABELA)->where('company_uuid', $empresa)->where('uuid', $uuid)->whereNull('deleted_at')->first();
    }
}
