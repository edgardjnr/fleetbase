<?php

namespace App\Console\Commands\Entregas;

use App\Support\Entregas\Saques\AcompanhamentoDeSaques;
use App\Support\Entregas\Saques\Saque;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: acompanha no Asaas os saques Pix em andamento dos motoboys, a cada minuto (Kernel, só com
 * ENTREGAS_SAQUE). Ver AcompanhamentoDeSaques.
 */
class SaquesConferir extends Command
{
    protected $signature   = 'entregas:saques-conferir';
    protected $description = 'Entregas: acompanha no Asaas os saques Pix em andamento dos motoboys';

    public function handle(): int
    {
        if (!Saque::ligada()) {
            return self::SUCCESS;
        }
        $terminaram = AcompanhamentoDeSaques::conferir();
        if ($terminaram > 0) {
            Log::info('[entregas] saque: rotina atualizou saques', ['saques' => $terminaram]);
        }

        return self::SUCCESS;
    }
}
