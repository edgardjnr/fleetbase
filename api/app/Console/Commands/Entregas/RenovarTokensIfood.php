<?php

namespace App\Console\Commands\Entregas;

use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\VinculosIfood;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: renovação proativa dos tokens do iFood (agendada a cada 30 min no App\Console\Kernel):
 * renova os que vencem em menos de 1 h. Refresh recusado = vínculo perdido (VinculosIfood). Só com a integração ligada.
 */
class RenovarTokensIfood extends Command
{
    protected $signature = 'entregas:ifood-tokens';

    protected $description = 'iFood: renova os tokens das lojas vinculadas que vencem em menos de 1 h';

    public function handle(VinculosIfood $vinculos): int
    {
        if (!ClienteIfood::ligada()) {
            return self::SUCCESS;
        }

        $resultado = $vinculos->renovarVencendo();
        if ($resultado['renovados'] || $resultado['perdidos'] || $resultado['falhas']) {
            Log::info('[entregas] ifood: renovação dos tokens', $resultado);
        }

        return self::SUCCESS;
    }
}
