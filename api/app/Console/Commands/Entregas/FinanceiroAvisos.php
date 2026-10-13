<?php

namespace App\Console\Commands\Entregas;

use App\Support\Entregas\Financeiro\AvisosDaFatura;
use App\Support\Entregas\Financeiro\FaturasDaLoja;
use App\Support\Entregas\Financeiro\Financeiro;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: e-mails de cobrança das faturas emitidas, às 09:00 (Kernel, só com ENTREGAS_FINANCEIRO):
 * lembrete na véspera do vencimento e aviso de vencida (venceu ontem ou antes), cada um uma vez por fatura e só com valor
 * em aberto (AvisosDaFatura confere de novo). "Hoje" é o dia no fuso da empresa. Falha numa fatura não para as outras; a
 * que não saiu (SMTP fora, loja sem e-mail) é tentada de novo no dia seguinte.
 */
class FinanceiroAvisos extends Command
{
    protected $signature   = 'entregas:financeiro-avisos';
    protected $description = 'Entregas: e-mails de lembrete (vence amanhã) e de fatura vencida para as lojas';

    public function handle(): int
    {
        if (!Financeiro::ligada()) {
            return self::SUCCESS;
        }

        $faturas = DB::table(FaturasDaLoja::TABELA)
            ->where('situacao', 'emitida')
            ->where(fn ($q) => $q->whereNull('lembrete_enviado_em')->orWhereNull('aviso_vencida_enviado_em'))
            ->orderBy('vencimento')
            ->orderBy('id')
            ->get();

        $dias     = [];
        $enviados = 0;
        foreach ($faturas as $fatura) {
            try {
                $dias[$fatura->company_uuid] ??= static::hojeEAmanha((string) $fatura->company_uuid);
                [$hoje, $amanha] = $dias[$fatura->company_uuid];
                $vencimento      = substr((string) $fatura->vencimento, 0, 10);

                if ($vencimento === $amanha && $fatura->lembrete_enviado_em === null) {
                    $enviados += AvisosDaFatura::lembrete($fatura) ? 1 : 0;
                } elseif ($vencimento < $hoje && $fatura->aviso_vencida_enviado_em === null) {
                    $enviados += AvisosDaFatura::vencida($fatura) ? 1 : 0;
                }
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
