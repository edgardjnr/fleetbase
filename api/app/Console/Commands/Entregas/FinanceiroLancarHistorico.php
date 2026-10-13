<?php

namespace App\Console\Commands\Entregas;

use App\Support\Entregas\CalculoEntregas;
use App\Support\Entregas\Financeiro\Financeiro;
use App\Support\Entregas\Financeiro\LancadorDeCorridas;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Entregas RestaurantePro: lança no financeiro as corridas desde a data de corte da empresa até agora, em janelas de 7
 * dias (uso manual, na implantação). Roda mesmo com ENTREGAS_FINANCEIRO desligado e é seguro repetir (chave_unica).
 * Sem --company, todas as empresas com data de corte.
 *
 *   docker exec -it $(docker ps -q -f name=entregas_application) php artisan entregas:financeiro-lancar-historico --company=<uuid>
 */
class FinanceiroLancarHistorico extends Command
{
    public const DIAS_DA_JANELA = 7;

    protected $signature   = 'entregas:financeiro-lancar-historico {--company=}';
    protected $description = 'Entregas: lança no financeiro as corridas desde a data de corte (uso na implantação)';

    public function handle(CalculoEntregas $calculo): int
    {
        $lancador = new LancadorDeCorridas($calculo);
        $empresa  = $this->option('company');

        if ($empresa) {
            $corte    = Financeiro::corte((string) $empresa);
            $empresas = $corte !== null ? [(string) $empresa => $corte] : [];
            if (!$empresas) {
                $this->warn("A empresa {$empresa} não tem data de corte (Financeiro → Configurações).");
            }
        } else {
            $empresas = FinanceiroConferir::empresasComCorte();
        }

        foreach ($empresas as $companyUuid => $corte) {
            session(['company' => $companyUuid]);
            $agora  = now();
            $inicio = Carbon::parse($corte . ' 00:00:00', Financeiro::fuso($companyUuid))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            $total  = ['lancados' => 0, 'sem_valor' => 0];

            while ($inicio <= $agora) {
                $fim = Carbon::parse($inicio)->modify('+' . static::DIAS_DA_JANELA . ' days')->modify('-1 second');
                $fim = $fim > $agora ? Carbon::parse($agora) : $fim;

                $r = $lancador->lancar($companyUuid, $calculo->pedidosConcluidos($companyUuid, $inicio, $fim));
                $total['lancados']  += $r['lancados'];
                $total['sem_valor'] += $r['sem_valor'];

                $inicio = Carbon::parse($fim)->modify('+1 second');
            }

            $this->info("Empresa {$companyUuid}: {$total['lancados']} lançamentos, {$total['sem_valor']} pedidos sem valor (desde {$corte}).");
        }

        return self::SUCCESS;
    }
}
