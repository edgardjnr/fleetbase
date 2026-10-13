<?php

namespace App\Console\Commands\Entregas;

use App\Support\Entregas\Financeiro\CicloDeCobranca;
use App\Support\Entregas\Financeiro\Faturas;
use App\Support\Entregas\Financeiro\Financeiro;
use App\Support\Entregas\Financeiro\Lancamentos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: rascunhos das faturas das lojas, todo dia às 00:30 (Kernel, só com ENTREGAS_FINANCEIRO). Para
 * cada loja com lançamento ainda sem fatura: se o ciclo dela (ou o padrão da empresa) fechou ontem (no fuso da empresa),
 * cria o rascunho do período (Faturas::gerarRascunho, que não repete o mesmo fim de período nem cria vazio). O admin
 * confere e emite no console. Uma falha numa loja não para as outras (log só com ids).
 */
class FinanceiroRascunhos extends Command
{
    protected $signature   = 'entregas:financeiro-rascunhos';
    protected $description = 'Entregas: cria os rascunhos das faturas das lojas cujo ciclo de cobrança fechou ontem';

    public function handle(): int
    {
        if (!Financeiro::ligada()) {
            return self::SUCCESS;
        }

        $ontem = [];
        foreach (static::lojasComLancamentoSemFatura() as [$companyUuid, $vendorUuid]) {
            try {
                $ontem[$companyUuid] ??= \DateTimeImmutable::createFromFormat('!Y-m-d', Financeiro::hoje($companyUuid))->modify('-1 day')->format('Y-m-d');
                $periodo = CicloDeCobranca::periodoQueFechaEm(Faturas::configDaLoja($companyUuid, $vendorUuid), $ontem[$companyUuid]);
                if (!$periodo) {
                    continue;
                }
                $fatura = Faturas::gerarRascunho($companyUuid, $vendorUuid, $periodo['inicio'], $periodo['fim'], null);
                if ($fatura) {
                    Log::info('[entregas] financeiro: rascunho da fatura criado', ['empresa' => $companyUuid, 'loja' => $vendorUuid, 'fatura' => $fatura->uuid]);
                }
            } catch (\Throwable $e) {
                Log::warning('[entregas] financeiro: rascunho da fatura falhou', ['empresa' => $companyUuid, 'loja' => $vendorUuid, 'erro' => get_class($e)]);
            }
        }

        return self::SUCCESS;
    }

    /** [[company_uuid, vendor_uuid]] das lojas com lançamento sem fatura (sem contar recebimentos), sem repetir. */
    public static function lojasComLancamentoSemFatura(): array
    {
        $linhas = DB::table(Lancamentos::TABELA)
            ->where('conta_tipo', Financeiro::CONTA_LOJA)
            ->whereNull('fatura_uuid')
            ->whereNull('pagamento_uuid')
            ->select('company_uuid', 'conta_uuid')
            ->distinct()
            ->get();

        $lojas = [];
        foreach ($linhas as $linha) {
            $lojas[$linha->company_uuid . '|' . $linha->conta_uuid] = [(string) $linha->company_uuid, (string) $linha->conta_uuid];
        }

        return array_values($lojas);
    }
}
