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
 * Entregas RestaurantePro: distribuição em rodadas. Depois da rodada 3, a volta seguinte só começa
 * SEGUNDOS_ENTRE_VOLTAS depois do início da anterior: o Distribuidor agenda este job para essa hora e ele dá o próximo
 * passo (Distribuidor::avancarOuAbrir). Fila `default` (o worker `queue`), com atraso. Repetido não faz mal: com uma
 * oferta pendente o passo só espera. Trava do pedido ocupada: volta à fila em ESPERA_DA_TRAVA s, até $tries vezes.
 * Reserva para job perdido (Redis sem persistência): o comando entregas:distribuicao-varrer.
 */
class AvancarDistribuicao implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const ESPERA_DA_TRAVA = 2;

    public int $tries = 3;

    /** Abaixo do retry_after da conexão redis (90 s). */
    public int $timeout = 60;

    public function __construct(public string $pedidoUuid)
    {
        $this->afterCommit = true;
    }

    public static function agendar(string $pedidoUuid, int $segundos): void
    {
        static::dispatch($pedidoUuid)->delay(now()->addSeconds(max(1, $segundos)));
    }

    public function handle(Distribuidor $distribuidor): void
    {
        if (!Distribuicao::emRodadas()) {
            return;
        }
        try {
            $distribuidor->avancarOuAbrir($this->pedidoUuid);
        } catch (LockTimeoutException $e) {
            Log::info('[entregas] distribuição: trava ocupada ao avançar a distribuição; tentando de novo');
            $this->release(static::ESPERA_DA_TRAVA);
        }
    }
}
