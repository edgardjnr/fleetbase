<?php

namespace App\Console\Commands\Entregas;

use App\Support\Entregas\Sistema\CorridasFinalizadas;
use Fleetbase\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: conferência das corridas do sistema (relatório do dono, todas as organizações), a cada 5 min
 * (Kernel), sem depender de chave nenhuma. Grava as corridas finalizadas dos últimos 2 dias que o observador não gravou
 * (saveQuietly, query builder, banco fora do ar no momento da conclusão).
 *
 * Histórico: enquanto a marca MARCA_DO_HISTORICO (tabela settings) não existir, ou sempre com --historico, importa antes
 * tudo o que já foi concluído e ainda está no banco, inclusive pedido cancelado ou excluído depois. A marca (hora de
 * Brasília) só é gravada quando a importação termina sem exceção: se ela cair no meio, a rodada seguinte importa de novo.
 * Não dá para usar "tabela vazia" como gatilho: o observador grava a primeira corrida antes da primeira rodada e o
 * histórico nunca seria importado. Repetir é seguro (insertOrIgnore pelo order_uuid).
 *
 * Uma etapa por try (como o tentar() do FinanceiroConferir): falha no histórico não impede a conferência. Sai com
 * FAILURE se alguma falhou. Log só com contagens e a classe do erro.
 */
class CorridasConferir extends Command
{
    public const DIAS = 2;

    public const MARCA_DO_HISTORICO = 'entregas.sistema.historico_importado';

    protected $signature   = 'entregas:corridas-conferir {--historico : importa tudo o que já foi concluído, sem limite de data}';
    protected $description = 'Entregas: grava no relatório do dono as corridas finalizadas que faltaram (e o histórico, até importá-lo inteiro)';

    public function handle(): int
    {
        $ok = $this->tentar('historico', function () {
            if (!$this->option('historico') && !empty(Setting::lookup(static::MARCA_DO_HISTORICO))) {
                return;
            }
            $n = CorridasFinalizadas::importarHistorico();
            Setting::configure(static::MARCA_DO_HISTORICO, now()->format('Y-m-d H:i:s'));
            Log::info('[entregas] sistema: histórico das corridas importado', ['corridas' => $n]);
        });

        $ok = $this->tentar('conferencia', function () {
            $n = CorridasFinalizadas::conferir(now()->subDays(static::DIAS)->format('Y-m-d H:i:s'));
            if ($n > 0) {
                Log::info('[entregas] sistema: conferência gravou corridas', ['corridas' => $n]);
            }
        }) && $ok;

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    protected function tentar(string $etapa, \Closure $fazer): bool
    {
        try {
            $fazer();

            return true;
        } catch (\Throwable $e) {
            Log::warning('[entregas] sistema: conferência das corridas falhou', ['etapa' => $etapa, 'erro' => get_class($e)]);

            return false;
        }
    }
}
