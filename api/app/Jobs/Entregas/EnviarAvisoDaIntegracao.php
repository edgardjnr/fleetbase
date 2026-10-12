<?php

namespace App\Jobs\Entregas;

use App\Support\Entregas\Integracao\AvisosDaIntegracao;
use App\Support\Entregas\Integracao\FalhaNoAviso;
use App\Support\Entregas\Integracao\Integracao;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: leva os avisos de um pedido da integração até o sistema de origem (AvisosDaIntegracao), na
 * fila `default` (não disputa com o iFood). Enfileirado pelo ObservadorDosPedidosDaIntegracao e pelo
 * entregas:integracao-acompanhar. Um job por pedido de cada vez, com a marca de pendente no cache (o mesmo esquema do
 * EnviarAcaoIfood): o job apaga a marca antes de ler o pedido; ao voltar para a fila, a marca volta junto.
 *
 * Falhas: trava ocupada volta em ESPERA_DA_TRAVA s; FalhaNoAviso sobe e a fila tenta de novo pelo $backoff (até 5 vezes
 * em 30 min). Esgotadas, failed() registra `[entregas] integração: aviso não entregue` e a falha na loja (tela Lojas); a
 * próxima mudança do pedido ou o acompanhamento (depois de 5 min) tentam de novo.
 */
class EnviarAvisoDaIntegracao implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const PENDENTE = 'entregas:integracao-aviso-pendente:';

    public const VALIDADE_DO_PENDENTE = 120;

    public const ESPERA_DA_TRAVA = 5;

    public const PRAZO_MINUTOS = 30;

    public int $timeout = 60;

    public int $maxExceptions = 5;

    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(public string $orderUuid)
    {
        // afterCommit no construtor: o trait Queueable já declara a propriedade (ver EnviarAcaoIfood)
        $this->afterCommit = true;
    }

    /** Enfileira, a não ser que este pedido já tenha um job esperando (devolve false). */
    public static function enfileirar(string $orderUuid): bool
    {
        if (!Cache::add(static::PENDENTE . $orderUuid, true, static::VALIDADE_DO_PENDENTE)) {
            return false;
        }

        try {
            static::dispatch($orderUuid);
        } catch (\Throwable $e) {
            Cache::forget(static::PENDENTE . $orderUuid);
            throw $e;
        }

        return true;
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(static::PRAZO_MINUTOS);
    }

    public function handle(AvisosDaIntegracao $avisos): void
    {
        Cache::forget(static::PENDENTE . $this->orderUuid);
        if (!Integracao::ligada()) {
            return;
        }

        try {
            $avisos->sincronizar($this->orderUuid);
        } catch (LockTimeoutException $e) {
            $this->voltarParaAFila(static::ESPERA_DA_TRAVA);
        } catch (FalhaNoAviso $e) {
            $this->marcarPendente(max($this->backoff));
            throw $e;
        }
    }

    public function failed(\Throwable $erro): void
    {
        Cache::forget(static::PENDENTE . $this->orderUuid);

        $status = $erro instanceof FalhaNoAviso ? $erro->status : 0;
        Log::error('[entregas] integração: aviso não entregue', ['order_uuid' => $this->orderUuid, 'status' => $status, 'erro' => get_class($erro)]);

        try {
            app(AvisosDaIntegracao::class)->registrarFalha($this->orderUuid, $status);
        } catch (\Throwable $e) {
            Log::warning('[entregas] integração: falha ao registrar o aviso não entregue', ['order_uuid' => $this->orderUuid, 'erro' => get_class($e)]);
        }
    }

    protected function voltarParaAFila(int $espera): void
    {
        $this->marcarPendente($espera);
        $this->release($espera);
    }

    protected function marcarPendente(int $espera): void
    {
        Cache::put(static::PENDENTE . $this->orderUuid, true, $espera + static::VALIDADE_DO_PENDENTE);
    }
}
