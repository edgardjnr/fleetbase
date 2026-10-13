<?php

namespace App\Console\Commands\Entregas;

use App\Support\Entregas\Financeiro\Financeiro;
use App\Support\Entregas\Financeiro\LancadorDeCorridas;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: conferência do financeiro, a cada 5 min (Kernel, só com ENTREGAS_FINANCEIRO). Para cada
 * empresa com data de corte: lança as corridas que escaparam do observador (pedidos concluídos desde max(corte, hoje − 30
 * dias) sem a corrida do motoboy, ou com ela e sem a da loja) e estorna as corridas dos pedidos cancelados depois da
 * conclusão (últimos 30 dias); se um desses pedidos volta a valer (cancelado pago marcado depois, ou concluído de novo),
 * relança a corrida.
 * Uma falha numa empresa não para as outras (log só com ids).
 */
class FinanceiroConferir extends Command
{
    public const DIAS_DA_CONFERENCIA = 30;

    public const DIAS_DO_ESTORNO = 30;

    protected $signature   = 'entregas:financeiro-conferir';
    protected $description = 'Entregas: lança no financeiro as corridas que faltaram e estorna as de pedidos cancelados depois da conclusão';

    public function handle(LancadorDeCorridas $lancador): int
    {
        if (!Financeiro::ligada()) {
            return self::SUCCESS;
        }

        foreach (static::empresasComCorte() as $companyUuid => $corte) {
            $this->tentar($companyUuid, 'lancar', function () use ($lancador, $companyUuid, $corte) {
                session(['company' => $companyUuid]);
                $doCorte  = Carbon::parse($corte . ' 00:00:00', Financeiro::fuso($companyUuid))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                $janela   = now()->subDays(static::DIAS_DA_CONFERENCIA);
                $r        = $lancador->conferir($companyUuid, $doCorte > $janela ? $doCorte : $janela);
                if ($r['lancados'] > 0) {
                    Log::info('[entregas] financeiro: conferência lançou corridas', ['empresa' => $companyUuid, 'lancados' => $r['lancados'], 'sem_valor' => $r['sem_valor']]);
                }
            });
            $this->tentar($companyUuid, 'estornar', function () use ($lancador, $companyUuid) {
                $n = $lancador->estornarCancelados($companyUuid, now()->subDays(static::DIAS_DO_ESTORNO));
                if ($n > 0) {
                    Log::info('[entregas] financeiro: corridas de pedidos cancelados estornadas', ['empresa' => $companyUuid, 'estornos' => $n]);
                }
            });
            $this->tentar($companyUuid, 'relancar', function () use ($lancador, $companyUuid) {
                $n = $lancador->relancarEstornados($companyUuid, now()->subDays(static::DIAS_DO_ESTORNO));
                if ($n > 0) {
                    Log::info('[entregas] financeiro: corridas de pedidos que voltaram a valer lançadas de novo', ['empresa' => $companyUuid, 'corridas' => $n]);
                }
            });
        }

        return self::SUCCESS;
    }

    /**
     * Empresas com data de corte válida: [company_uuid => 'Y-m-d']. Procura as chaves `company.<uuid>.entregas.financeiro_corte`
     * na tabela settings e valida cada uma pelo Financeiro::corte. Também usada pelo entregas:financeiro-lancar-historico.
     */
    public static function empresasComCorte(): array
    {
        $empresas = [];
        $chaves   = DB::table('settings')->where('key', 'like', 'company.%.entregas.financeiro_corte')->pluck('key');
        foreach ($chaves as $chave) {
            if (!preg_match('/^company\.([^.]+)\.entregas\.financeiro_corte$/', (string) $chave, $partes)) {
                continue;
            }
            $corte = Financeiro::corte($partes[1]);
            if ($corte !== null) {
                $empresas[$partes[1]] = $corte;
            }
        }

        return $empresas;
    }

    protected function tentar(string $companyUuid, string $etapa, \Closure $fazer): void
    {
        try {
            $fazer();
        } catch (\Throwable $e) {
            Log::warning('[entregas] financeiro: conferência falhou', ['empresa' => $companyUuid, 'etapa' => $etapa, 'erro' => get_class($e)]);
        }
    }
}
