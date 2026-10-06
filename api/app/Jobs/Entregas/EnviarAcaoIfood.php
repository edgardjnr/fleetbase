<?php

namespace App\Jobs\Entregas;

use App\Support\Entregas\Ifood\AcoesIfood;
use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\ErroIfood;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: leva um pedido iFood, no iFood, até a etapa em que ele está (AcoesIfood::sincronizar), na
 * fila: nada trava o app nem o console. Enfileirado pelo ObservadorDosPedidosIfood (motoboy definido, iniciado, a
 * caminho, concluído) e pelo entregas:ifood-acompanhar (chegada pelo GPS e reconciliação a cada 30 s).
 *
 * Um job por pedido de cada vez: enfileirar() marca o pedido como "pendente" no cache (PENDENTE) e não enfileira outro
 * enquanto a marca existir. O job apaga a marca antes de ler o pedido, então uma mudança que chega depois da leitura
 * enfileira um job novo, e as que chegam antes são lidas por este (ele lê o estado na hora em que roda). afterCommit:
 * uma mudança gravada dentro de uma transação só é lida depois do commit.
 *
 * Falhas: 429 volta para a fila pelo Retry-After (release, não conta como exceção); trava das ações ocupada volta em
 * ESPERA_DA_TRAVA s; 5xx, 408 e rede sobem e a fila tenta de novo pelo $backoff, até $maxExceptions (5) vezes, dentro de
 * PRAZO_MINUTOS. Esgotadas, failed() registra `[entregas] ifood: ação não enviada` e transforma a próxima ação numa
 * recusa (AcoesIfood::desistir: aviso à central). 409 e outros 4xx são recusas tratadas no AcoesIfood, sem nova
 * tentativa.
 */
class EnviarAcaoIfood implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const PENDENTE = 'entregas:ifood-acao-pendente:';

    /** Validade da marca de pendente, em segundos (se o job se perder, a marca some sozinha). */
    public const VALIDADE_DO_PENDENTE = 600;

    public const ESPERA_DA_TRAVA = 5;

    public const PRAZO_MINUTOS = 30;

    public $afterCommit = true;

    /** Abaixo do retry_after da conexão redis (90 s). */
    public int $timeout = 80;

    public int $maxExceptions = 5;

    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(public string $orderUuid, public ?string $alvoMinimo = null) {}

    /**
     * Enfileira, a não ser que este pedido já tenha um job esperando (devolve false). $alvoMinimo: a chegada pelo GPS
     * (o estado do Fleetbase não a conhece).
     */
    public static function enfileirar(string $orderUuid, ?string $alvoMinimo = null): bool
    {
        if (!Cache::add(static::PENDENTE . $orderUuid, true, static::VALIDADE_DO_PENDENTE)) {
            return false;
        }

        try {
            static::dispatch($orderUuid, $alvoMinimo);
        } catch (\Throwable $e) {
            // fila fora do ar: sem a marca, a próxima mudança (ou o entregas:ifood-acompanhar) tenta de novo
            Cache::forget(static::PENDENTE . $orderUuid);
            throw $e;
        }

        return true;
    }

    /** Tentativas pelo prazo: os release() (429, trava ocupada) contariam como tentativa no Laravel. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(static::PRAZO_MINUTOS);
    }

    public function handle(AcoesIfood $acoes): void
    {
        Cache::forget(static::PENDENTE . $this->orderUuid);
        if (!ClienteIfood::ligada()) {
            return;
        }

        try {
            $acoes->sincronizar($this->orderUuid, $this->alvoMinimo);
        } catch (LockTimeoutException $e) {
            $this->release(static::ESPERA_DA_TRAVA);
        } catch (ErroIfood $e) {
            if ($e->limiteExcedido()) {
                $this->release($e->retryAfter ?? ClienteIfood::ESPERA_PADRAO_429);

                return;
            }
            // temporário (5xx, 408, rede): nova tentativa pelo $backoff, sem o corpo da resposta no failed_jobs
            throw new ErroIfood($e->operacao, $e->status);
        }
    }

    public function failed(\Throwable $erro): void
    {
        $status = $erro instanceof ErroIfood ? $erro->status : 0;
        Log::error('[entregas] ifood: ação não enviada; tentativas esgotadas', [
            'order_uuid' => $this->orderUuid,
            'erro'       => get_class($erro),
            'status'     => $status,
        ]);

        try {
            app(AcoesIfood::class)->desistir($this->orderUuid, $status);
        } catch (\Throwable $e) {
            Log::warning('[entregas] ifood: falha ao registrar a desistência da ação', ['order_uuid' => $this->orderUuid, 'erro' => get_class($e)]);
        }
    }
}
