<?php

namespace App\Jobs\Entregas;

use App\Support\Entregas\Financeiro\Financeiro;
use App\Support\Entregas\Financeiro\LancadorDeCorridas;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: lança no financeiro a corrida de um pedido que acabou de concluir (ou foi cancelado), na fila
 * `default`. Enfileirado pelo ObservadorFinanceiroDosPedidos, depois do commit e com ATRASO_S de folga (o status
 * COMPLETED do rastreio, que dá a data da conclusão, é gravado junto com a mudança do pedido). Recebe só o uuid; o
 * LancadorDeCorridas::lancarPedido põe a empresa do pedido na sessão (as faixas são lidas por ela). Lançar de novo é
 * seguro (chave_unica). Se falhar, a conferência a cada 5 min (entregas:financeiro-conferir) lança depois.
 */
class LancarCorridaDoPedido implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const ATRASO_S = 15;

    public int $timeout = 60;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public string $orderUuid)
    {
        // afterCommit no construtor: o trait Queueable já declara a propriedade (ver EnviarAcaoIfood)
        $this->afterCommit = true;
    }

    public static function enfileirar(string $orderUuid): void
    {
        static::dispatch($orderUuid)->delay(now()->addSeconds(static::ATRASO_S));
    }

    public function handle(LancadorDeCorridas $lancador): void
    {
        if (!Financeiro::ligada()) {
            return;
        }

        $lancador->lancarPedido($this->orderUuid);
    }

    public function failed(\Throwable $erro): void
    {
        Log::warning('[entregas] financeiro: corrida não lançada pelo job (a conferência tenta de novo)', ['order_uuid' => $this->orderUuid, 'erro' => get_class($erro)]);
    }
}
