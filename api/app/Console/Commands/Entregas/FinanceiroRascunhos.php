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
 * cada loja com lançamento ainda sem fatura: para cada fechamento do ciclo dela (ou do padrão da empresa) nos últimos 7
 * dias até ontem (no fuso da empresa), do mais antigo para o mais novo, cria o rascunho do período se a loja ainda não
 * tem fatura (rascunho, emitida ou cancelada) com aquele fim (Faturas::gerarRascunho também não cria vazio). Assim o
 * agendador fora do ar no dia seguinte ao fechamento não perde o rascunho, e o rascunho descartado pelo admin não volta.
 * O admin confere e emite no console. Uma falha numa loja não para as outras (log só com ids).
 */
class FinanceiroRascunhos extends Command
{
    /** Fechamentos de ciclo procurados para trás (o agendador fora do ar no dia seguinte não perde o rascunho). */
    public const DIAS_PARA_TRAS = 7;

    protected $signature   = 'entregas:financeiro-rascunhos';
    protected $description = 'Entregas: cria os rascunhos das faturas das lojas cujo ciclo de cobrança fechou (últimos 7 dias)';

    public function handle(): int
    {
        if (!Financeiro::ligada()) {
            return self::SUCCESS;
        }

        $dias = [];
        foreach (static::lojasComLancamentoSemFatura() as [$companyUuid, $vendorUuid]) {
            try {
                $dias[$companyUuid] ??= static::ultimosDias($companyUuid);
                $config              = Faturas::configDaLoja($companyUuid, $vendorUuid);
                foreach ($dias[$companyUuid] as $dia) {
                    $periodo = CicloDeCobranca::periodoQueFechaEm($config, $dia);
                    if (!$periodo || static::jaTemFatura($companyUuid, $vendorUuid, $periodo['fim'])) {
                        continue;
                    }
                    $fatura = Faturas::gerarRascunho($companyUuid, $vendorUuid, $periodo['inicio'], $periodo['fim'], null);
                    if ($fatura) {
                        Log::info('[entregas] financeiro: rascunho da fatura criado', ['empresa' => $companyUuid, 'loja' => $vendorUuid, 'fatura' => $fatura->uuid]);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[entregas] financeiro: rascunho da fatura falhou', ['empresa' => $companyUuid, 'loja' => $vendorUuid, 'erro' => get_class($e)]);
            }
        }

        return self::SUCCESS;
    }

    /** Os DIAS_PARA_TRAS dias até ontem ('Y-m-d', no fuso da empresa), do mais antigo para ontem. */
    public static function ultimosDias(string $companyUuid): array
    {
        $hoje = \DateTimeImmutable::createFromFormat('!Y-m-d', Financeiro::hoje($companyUuid));
        $dias = [];
        for ($n = static::DIAS_PARA_TRAS; $n >= 1; $n--) {
            $dias[] = $hoje->modify('-' . $n . ' day')->format('Y-m-d');
        }

        return $dias;
    }

    /**
     * Se a loja já tem fatura (rascunho, emitida ou cancelada) com esse fim de período: a rotina não cria outra (o
     * rascunho descartado pelo admin não volta; o lançamento solto entra no próximo ciclo ou num rascunho feito à mão).
     */
    public static function jaTemFatura(string $companyUuid, string $vendorUuid, string $fim): bool
    {
        return DB::table(Faturas::TABELA)
            ->where('company_uuid', $companyUuid)
            ->where('vendor_uuid', $vendorUuid)
            ->where('periodo_fim', $fim)
            ->exists();
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
