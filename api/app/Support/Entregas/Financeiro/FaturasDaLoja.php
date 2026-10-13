<?php

namespace App\Support\Entregas\Financeiro;

use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: o que só o portal da loja e os e-mails precisam das faturas (leitura direta de
 * entregas_faturas, DB::table): a fatura pelo uuid, as faturas visíveis da loja e o ciclo em andamento do portal.
 *
 * O formato da fatura e dos itens (e a situação: paga, parcial, aberta, vencida) é o mesmo do console: Faturas::resumo,
 * Faturas::itensFormatados e Faturas::situacoesDaLoja, com $publico = true no portal. Assim o portal e o console mostram
 * os mesmos números para a mesma fatura (teste: scripts/teste-php/financeiro-portal-emails.php, "console × portal").
 */
final class FaturasDaLoja
{
    public const TABELA = 'entregas_faturas';

    /** Situações gravadas que a loja vê (o rascunho nunca chega ao portal nem ao e-mail). */
    public const VISIVEIS = ['emitida', 'cancelada'];

    public static function porUuid(string $companyUuid, string $uuid): ?object
    {
        return DB::table(static::TABELA)->where('company_uuid', $companyUuid)->where('uuid', $uuid)->first();
    }

    /** As faturas da loja nas situações pedidas, mais nova primeiro (fim do período, depois id). */
    public static function daLoja(string $companyUuid, string $vendorUuid, array $situacoes): array
    {
        return DB::table(static::TABELA)
            ->where('company_uuid', $companyUuid)
            ->where('vendor_uuid', $vendorUuid)
            ->whereIn('situacao', $situacoes)
            ->orderBy('periodo_fim', 'desc')
            ->orderBy('id', 'desc')
            ->get()
            ->all();
    }

    /** Recebimento ou estorno de recebimento (o estorno copia o pagamento_uuid): mexe no recebido, nunca entra em fatura. */
    public static function ehRecebimento(object $lancamento): bool
    {
        return $lancamento->tipo === 'recebimento'
            || ($lancamento->tipo === 'estorno' && ($lancamento->pagamento_uuid ?? null) !== null && $lancamento->pagamento_uuid !== '');
    }

    /** O período do ciclo da loja que contém $hoje (CicloDeCobranca::periodoQueContem); null em erro. */
    public static function periodoEmAndamento(string $companyUuid, string $vendorUuid, string $hoje): ?array
    {
        try {
            return CicloDeCobranca::periodoQueContem(Faturas::configDaLoja($companyUuid, $vendorUuid), $hoje);
        } catch (\Throwable $erro) {
            return null;
        }
    }

    /**
     * O ciclo em andamento do portal (função pura): os lançamentos da loja ainda sem fatura, menos recebimentos (vão para
     * a próxima fatura). `entregas` = corridas não estornadas; `valor` = soma (com ajustes e estornos). `inicio` = o
     * início do ciclo da loja ou o lançamento sem fatura mais antigo, o que vier antes (uma corrida atrasada ou de fatura
     * cancelada também entra); `fim` = fim do ciclo. Sem o ciclo ($periodo null): do mais antigo (ou hoje) até hoje.
     */
    public static function cicloAtual(array $lancamentos, string $hoje, ?array $periodo): array
    {
        $estornados = [];
        foreach ($lancamentos as $lancamento) {
            if (($lancamento->estorno_de ?? null) !== null) {
                $estornados[(int) $lancamento->estorno_de] = true;
            }
        }

        $entregas  = 0;
        $centavos  = 0;
        $maisAntigo = null;
        foreach ($lancamentos as $lancamento) {
            if (($lancamento->fatura_uuid ?? null) !== null || static::ehRecebimento($lancamento)) {
                continue;
            }
            $centavos += (int) round(((float) $lancamento->valor) * 100);
            if ($lancamento->tipo === 'corrida' && !isset($estornados[(int) $lancamento->id])) {
                $entregas++;
            }
            $dia = substr((string) $lancamento->data_referencia, 0, 10);
            if ($maisAntigo === null || $dia < $maisAntigo) {
                $maisAntigo = $dia;
            }
        }

        $inicio = $periodo['inicio'] ?? $hoje;
        if ($maisAntigo !== null && ($periodo === null || $maisAntigo < $inicio)) {
            $inicio = $maisAntigo;
        }

        return [
            'inicio'   => $inicio,
            'fim'      => $periodo['fim'] ?? $hoje,
            'entregas' => $entregas,
            'valor'    => round($centavos / 100, 2),
        ];
    }
}
