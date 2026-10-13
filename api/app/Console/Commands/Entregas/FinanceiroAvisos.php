<?php

namespace App\Console\Commands\Entregas;

use App\Support\Entregas\Financeiro\AvisosDaFatura;
use App\Support\Entregas\Financeiro\Faturas;
use App\Support\Entregas\Financeiro\FaturasDaLoja;
use App\Support\Entregas\Financeiro\Financeiro;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: e-mails de cobrança das faturas emitidas, às 09:00 (Kernel, só com ENTREGAS_FINANCEIRO):
 * lembrete na véspera do vencimento e aviso de vencida (venceu ontem ou antes), cada um uma vez por fatura e só com valor
 * em aberto (AvisosDaFatura confere de novo). "Hoje" é o dia no fuso da empresa. A situação (paga, em aberto) sai uma vez
 * por empresa (Faturas::situacoesDaEmpresa) e só entram as faturas com vencimento nos últimos 60 dias. Falha numa fatura
 * não para as outras; a que não saiu (SMTP fora, loja sem e-mail) é tentada de novo no dia seguinte.
 */
class FinanceiroAvisos extends Command
{
    /** Só as faturas com vencimento nos últimos DIAS_PARA_TRAS dias (ou depois) entram na rotina. */
    public const DIAS_PARA_TRAS = 60;

    protected $signature   = 'entregas:financeiro-avisos';
    protected $description = 'Entregas: e-mails de lembrete (vence amanhã) e de fatura vencida para as lojas';

    public function handle(): int
    {
        if (!Financeiro::ligada()) {
            return self::SUCCESS;
        }

        // a paga nunca deixa de ter aviso pendente: sem o limite, seria reavaliada para sempre (vencimento no fuso do app,
        // Brasília; a folga de DIAS_PARA_TRAS cobre a diferença de fuso de qualquer empresa)
        $limite  = now()->subDays(static::DIAS_PARA_TRAS)->format('Y-m-d');
        $faturas = DB::table(FaturasDaLoja::TABELA)
            ->where('situacao', 'emitida')
            ->where('vencimento', '>=', $limite)
            ->where(fn ($q) => $q->whereNull('lembrete_enviado_em')->orWhereNull('aviso_vencida_enviado_em'))
            ->orderBy('vencimento')
            ->orderBy('id')
            ->get();

        $dias      = [];
        $situacoes = [];
        $enviados  = 0;
        foreach ($faturas as $fatura) {
            try {
                $empresa           = (string) $fatura->company_uuid;
                $dias[$empresa]  ??= static::hojeEAmanha($empresa);
                [$hoje, $amanha]   = $dias[$empresa];
                $vencimento        = substr((string) $fatura->vencimento, 0, 10);

                $aviso = null;
                if ($vencimento === $amanha && $fatura->lembrete_enviado_em === null) {
                    $aviso = 'lembrete';
                } elseif ($vencimento < $hoje && $fatura->aviso_vencida_enviado_em === null) {
                    $aviso = 'vencida';
                }
                if ($aviso === null) {
                    continue;
                }

                // a situação de todas as emitidas da empresa, uma vez (a paga sai daqui sem ler a conta da loja de novo)
                $situacoes[$empresa] ??= Faturas::situacoesDaEmpresa($empresa);
                $situacao              = $situacoes[$empresa][(string) $fatura->vendor_uuid][(string) $fatura->uuid] ?? null;
                if ($situacao === null || (float) $situacao['em_aberto'] <= 0) {
                    continue;
                }

                $enviados += AvisosDaFatura::{$aviso}($fatura) ? 1 : 0;
            } catch (\Throwable $e) {
                Log::warning('[entregas] financeiro: aviso da fatura falhou', ['fatura' => $fatura->uuid, 'erro' => get_class($e)]);
            }
        }

        if ($enviados > 0) {
            Log::info('[entregas] financeiro: avisos de fatura enviados', ['enviados' => $enviados]);
        }

        return self::SUCCESS;
    }

    /** ['Y-m-d' de hoje, 'Y-m-d' de amanhã] no fuso da empresa. */
    private static function hojeEAmanha(string $companyUuid): array
    {
        $hoje = Financeiro::hoje($companyUuid);

        return [$hoje, (new \DateTimeImmutable($hoje . ' 12:00:00'))->modify('+1 day')->format('Y-m-d')];
    }
}
