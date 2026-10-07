<?php

namespace App\Jobs\Entregas;

use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Distribuidor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: vence a oferta de um pedido aberto Distribuicao::segundosDaOferta() depois de enviada
 * (30 s; 20 s em rodadas) e passa ao próximo motoboy (Distribuidor::vencer). Fila `default` (o worker `queue`), com
 * atraso. Um job de oferta já respondida (aceita, recusada, cancelada) sai sem fazer nada. Trava do pedido ocupada: volta à fila em ESPERA_DA_TRAVA s, até
 * $tries vezes. Reserva para job perdido (Redis sem persistência) ou esgotado: o comando entregas:distribuicao-varrer.
 */
class AvancarOferta implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const ESPERA_DA_TRAVA = 2;

    public int $tries = 3;

    /** Abaixo do retry_after da conexão redis (90 s). */
    public int $timeout = 60;

    public function __construct(public int $ofertaId)
    {
        $this->afterCommit = true;
    }

    public static function agendar(int $ofertaId): void
    {
        static::dispatch($ofertaId)->delay(now()->addSeconds(Distribuicao::segundosDaOferta()));
    }

    public function handle(Distribuidor $distribuidor): void
    {
        if (!Distribuicao::ligada()) {
            return;
        }
        try {
            $distribuidor->vencer($this->ofertaId);
        } catch (LockTimeoutException $e) {
            Log::info('[entregas] distribuição: trava ocupada ao vencer a oferta; tentando de novo', ['oferta' => $this->ofertaId]);
            $this->release(static::ESPERA_DA_TRAVA);
        }
    }
}
