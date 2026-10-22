<?php

namespace App\Console\Commands\Entregas;

use App\Support\Entregas\Sistema\CorridasFinalizadas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: conferência das corridas do sistema (relatório do dono, todas as organizações), a cada 5 min
 * (Kernel), sem depender de chave nenhuma. Grava as corridas finalizadas dos últimos 2 dias que o observador não gravou
 * (saveQuietly, query builder, banco fora do ar no momento da conclusão). Com a tabela vazia (a primeira rodada depois
 * do deploy) ou com --historico, importa antes tudo o que já foi concluído e ainda está no banco, inclusive pedido
 * cancelado ou excluído depois. Idempotente: pode repetir. Log só com contagens.
 */
class CorridasConferir extends Command
{
    public const DIAS = 2;

    protected $signature   = 'entregas:corridas-conferir {--historico : importa tudo o que já foi concluído, sem limite de data}';
    protected $description = 'Entregas: grava no relatório do dono as corridas finalizadas que faltaram (e o histórico, na primeira vez)';

    public function handle(): int
    {
        try {
            if ($this->option('historico') || CorridasFinalizadas::vazia()) {
                $n = CorridasFinalizadas::importarHistorico();
                if ($n > 0) {
                    Log::info('[entregas] sistema: histórico das corridas importado', ['corridas' => $n]);
                }
            }

            $n = CorridasFinalizadas::conferir(now()->subDays(static::DIAS)->format('Y-m-d H:i:s'));
            if ($n > 0) {
                Log::info('[entregas] sistema: conferência gravou corridas', ['corridas' => $n]);
            }
        } catch (\Throwable $e) {
            Log::warning('[entregas] sistema: conferência das corridas falhou', ['erro' => get_class($e)]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
