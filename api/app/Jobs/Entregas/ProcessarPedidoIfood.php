<?php

namespace App\Jobs\Entregas;

use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\CriadorDoPedidoIfood;
use App\Support\Entregas\Ifood\ErroIfood;
use App\Support\Entregas\Ifood\EventosIfood;
use App\Support\Entregas\Ifood\VinculoPerdido;
use App\Support\Entregas\Ifood\VinculosIfood;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: processa os eventos ainda pendentes de um pedido do iFood, em ordem de createdAt
 * (EventosIfood), um job por pedido (enfileirado pelo entregas:ifood-polling).
 *
 * Com uma trava por pedido do iFood (Cache::lock no Redis): dois jobs do mesmo pedido nunca rodam juntos; o segundo
 * volta para a fila. Na etapa 2:
 * - o primeiro evento que cria (PLC ou, se ele se perdeu, outro anterior à coleta: EventosIfood::CRIAM_PEDIDO) busca o
 *   pedido no Logistics e cria o pedido (CriadorDoPedidoIfood). Pedido já existente (pedido_ifood_id único) nunca é
 *   criado de novo; sem pedido e sem evento que cria, os eventos só ficam processados e ignorados (log "evento sem
 *   pedido");
 * - pedido cancelado (CAN) ou que já passou da coleta (POS_COLETA: a coleta, a entrega ou um entregador do iFood já
 *   aconteceram) antes de entrar não é criado, nem por um evento atrasado numa rodada seguinte: os dois são procurados
 *   entre todos os eventos do pedido na tabela, processados ou não, e não só entre os pendentes. São definitivos;
 * - código ignorado: log warning quando exige ação da loja no iFood (EventosIfood::nivelDoIgnorado), info nos outros;
 * - DDCR marca exige_codigo; CAN registra cancelado_pelo_ifood_em e, se ainda não despachado, tira da fila do
 *   agendador (despachar_em nulo); o cancelamento do pedido no Entregas é da etapa 3;
 * - código desconhecido, loja não vinculada, vínculo perdido e loja sem Local de coleta ficam processados e ignorados.
 *   Nos três últimos isso não é definitivo: um evento de criação posterior do mesmo pedido (CFM, RTP…) tenta de novo.
 *
 * Erros na busca do pedido no iFood: 404 (ainda indisponível), 5xx, 408 e rede sobem e a fila tenta de novo pelo
 * $backoff; 429 volta para a fila pelo Retry-After (release, sem contar como exceção); os outros do GET do pedido
 * (operação "pedido": 400, 403, 401 depois da renovação) não melhoram com o tempo: fail() na hora. Erro de outra
 * operação (a renovação do token no 401: 403, 404, 409, 200 sem accessToken, que não derrubam a loja) sobe e a fila
 * tenta de novo pelo $backoff. Depois do fail() ou de esgotar as tentativas, os eventos continuam pendentes e o
 * próximo evento do pedido no polling enfileira de novo. Erro ao criar o pedido sobe como RuntimeException sem a
 * mensagem original (a do QueryException traz o SQL com nome e endereço do cliente). Logs sem dados do cliente (só ids,
 * códigos e o número do pedido).
 */
class ProcessarPedidoIfood implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const EVENTOS = 'entregas_ifood_eventos';
    public const PEDIDOS = 'entregas_ifood_pedidos';

    /** Códigos de depois da coleta: na entrega própria, GTO, ADR e AAO só vêm de entregador do iFood. */
    public const POS_COLETA = ['DSP', 'CON', 'CLT', 'DDD', 'AAD', 'DDCS', 'GTO', 'ADR', 'AAO'];

    /** Operação do ErroIfood no GET do pedido (ClienteIfood::pedidoLogistics): só ela leva ao fail(). */
    public const OPERACAO_DO_PEDIDO = 'pedido';

    /** Segundos até tentar de novo quando outro job do mesmo pedido está rodando. */
    public const ESPERA_DA_TRAVA = 15;

    /**
     * Validade da trava do pedido, em segundos: maior que o $timeout, para não vencer com o job ainda rodando. Se o
     * worker matar o job no $timeout, o finally não roda e a trava fica até vencer; o job seguinte volta para a fila
     * (ESPERA_DA_TRAVA) até lá.
     */
    public const VALIDADE_DA_TRAVA = 120;

    /** Prazo total do job, em minutos, contado do dispatch: os release() (trava ocupada, 429) não gastam tentativas. */
    public const PRAZO_MINUTOS = 30;

    /**
     * Abaixo do retry_after da conexão redis (90 s, api/config/queue.php): com mais, o Redis entregaria o job de novo
     * enquanto ele ainda roda. O caso comum leva poucos segundos (um GET de até 15 s, ClienteIfood::TEMPO_LIMITE, e a
     * criação); o pior (renovar o token esperando a trava da loja por 20 s, + 15 s do refresh, GET com 401, nova
     * renovação e novo GET) passa de 80 s e é cortado: a transação da criação desfaz o que estava pela metade e o
     * pedido_ifood_id único impede a duplicata na tentativa seguinte.
     */
    public int $timeout = 80;

    /** Exceções (5xx, rede, 404, banco) antes de desistir; os release() não contam. */
    public int $maxExceptions = 6;

    public array $backoff = [15, 30, 60, 120, 300];

    public function __construct(public string $pedidoIfoodId)
    {
    }

    /** Tentativas pelo prazo (e não por $tries): o release() da trava ocupada e do 429 conta como tentativa no Laravel. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(static::PRAZO_MINUTOS);
    }

    public function handle(VinculosIfood $vinculos, ClienteIfood $cliente, CriadorDoPedidoIfood $criador): void
    {
        $trava = Cache::lock("entregas:ifood-pedido:{$this->pedidoIfoodId}", static::VALIDADE_DA_TRAVA);
        if (!$trava->get()) {
            $this->release(static::ESPERA_DA_TRAVA);

            return;
        }

        try {
            $this->processar($vinculos, $cliente, $criador);
        } catch (ErroIfood $e) {
            if ($e->limiteExcedido()) {
                $this->release($e->retryAfter ?? ClienteIfood::ESPERA_PADRAO_429);
            } elseif ($e->status === 404 || $e->temporario() || $e->operacao !== static::OPERACAO_DO_PEDIDO) {
                // de outra operação (ex.: a renovação do token com 403, 409 ou 200 sem accessToken, que não derrubam a
                // loja): nova tentativa pelo $backoff
                throw $e;
            } else {
                // sem o corpo da resposta (o failed_jobs guarda a exceção inteira)
                $this->fail(new ErroIfood($e->operacao, $e->status));
            }
        } finally {
            $trava->release();
        }
    }

    public function processar(VinculosIfood $vinculos, ClienteIfood $cliente, CriadorDoPedidoIfood $criador): void
    {
        $linhas = DB::table(static::EVENTOS)->where('pedido_ifood_id', $this->pedidoIfoodId)->whereNull('processado_em')->get()->all();
        if (!$linhas) {
            return;
        }

        $eventos = EventosIfood::ordenar(array_map(fn (object $linha) => [
            'id'         => $linha->evento_id,
            'code'       => $linha->codigo,
            'merchantId' => $linha->merchant_id,
            'createdAt'  => $linha->criado_no_ifood,
        ], $linhas));
        $pedido = DB::table(static::PEDIDOS)->where('pedido_ifood_id', $this->pedidoIfoodId)->first();

        if (!$pedido && $this->foiCancelado()) {
            // cancelado antes de entrar (ex.: a loja recusou antes do polling): não vai aos motoboys, nem com um evento
            // atrasado (CFM, CAR…) numa rodada seguinte, depois de o CAN já ter sido processado
            Log::info('[entregas] ifood: pedido cancelado antes de entrar; não foi criado', ['pedido_ifood' => $this->pedidoIfoodId]);
            $this->marcar(array_column($eventos, 'id'), true);

            return;
        }

        $criaPedido = (bool) array_filter($eventos, fn (array $evento) => EventosIfood::criaPedido($evento['code']));

        if (!$pedido && $criaPedido && ($codigos = $this->codigosDepoisDaColeta())) {
            // o pedido entrou atrasado (integração parada, busca falhando): já foi coletado, entregue ou está com um
            // entregador do iFood. Criar agora mandaria aos motoboys um pedido que não existe mais
            Log::warning('[entregas] ifood: pedido já passou da coleta; não foi criado', ['pedido_ifood' => $this->pedidoIfoodId, 'codigos' => $codigos]);
            $this->marcar(array_column($eventos, 'id'), true);

            return;
        }

        if (!$pedido && !$criaPedido) {
            // só eventos de etapa da entrega, cancelamento ou alteração: nunca criam o pedido (EventosIfood::CRIAM_PEDIDO)
            Log::info('[entregas] ifood: evento sem pedido; não cria', [
                'pedido_ifood' => $this->pedidoIfoodId,
                'eventos'      => array_column($eventos, 'id'),
                'codigos'      => array_column($eventos, 'code'),
            ]);
        }

        foreach ($eventos as $evento) {
            $acao = EventosIfood::acao($evento['code']);

            if (!$pedido && EventosIfood::criaPedido($evento['code'])) {
                $pedido = $this->criar($evento['merchantId'], $vinculos, $cliente, $criador);
                if (!$pedido) {
                    $this->marcar(array_column($eventos, 'id'), true);

                    return;
                }
            }

            if ($acao === EventosIfood::EXIGE_CODIGO && $pedido && !$pedido->exige_codigo) {
                $this->atualizarPedido($pedido, ['exige_codigo' => true]);
                $pedido->exige_codigo = true;
            }

            if ($acao === EventosIfood::CANCELA && $pedido && !$pedido->cancelado_pelo_ifood_em) {
                $quando = $evento['createdAt'] ? substr((string) $evento['createdAt'], 0, 19) : now()->toDateTimeString();
                $this->atualizarPedido($pedido, ['cancelado_pelo_ifood_em' => $quando]);
                $pedido->cancelado_pelo_ifood_em = $quando;
                // ainda não despachado (agendado ou despacho que falhou): sai da fila do entregas:ifood-agendados, sem
                // ir aos motoboys. Condicional no banco: o agendador pode ter despachado depois de a linha ser lida
                DB::table(static::PEDIDOS)->where('id', $pedido->id)->whereNotNull('despachar_em')->whereNull('despachado_em')
                    ->update(['despachar_em' => null, 'updated_at' => now()->toDateTimeString()]);
                Log::warning('[entregas] ifood: pedido cancelado pelo iFood (o cancelamento no Entregas é da etapa 3)', [
                    'pedido'     => $this->publicIdDoOrder($pedido),
                    'order_uuid' => $pedido->order_uuid,
                    'numero'     => $pedido->numero,
                ]);
            }

            if ($acao === EventosIfood::IGNORA) {
                // warning quando o código exige ação da loja no iFood (HSD), info nos outros
                $nivel = EventosIfood::nivelDoIgnorado($evento['code']);
                Log::{$nivel}('[entregas] ifood: evento ignorado', ['codigo' => $evento['code'], 'pedido_ifood' => $this->pedidoIfoodId]);
            }

            // sem pedido (evento que não cria), nada se aplica: ignorado
            $this->marcar([$evento['id']], $acao === EventosIfood::IGNORA || !$pedido);
        }
    }

    /** A fila desistiu (tentativas esgotadas): só a classe do erro e o status, nunca a mensagem (pode trazer dados). */
    public function failed(\Throwable $erro): void
    {
        Log::error('[entregas] ifood: pedido não processado', [
            'pedido_ifood' => $this->pedidoIfoodId,
            'erro'         => get_class($erro),
            'status'       => $erro instanceof ErroIfood ? $erro->status : null,
        ]);
    }

    protected function criar(string $merchantId, VinculosIfood $vinculos, ClienteIfood $cliente, CriadorDoPedidoIfood $criador): ?object
    {
        $vinculo = $vinculos->porMerchant($merchantId);
        if (!$vinculo) {
            Log::warning('[entregas] ifood: pedido de loja não vinculada', ['merchant' => $merchantId, 'pedido_ifood' => $this->pedidoIfoodId]);

            return null;
        }

        try {
            $dados = $vinculos->comToken($vinculo, fn (string $token) => $cliente->pedidoLogistics($token, $this->pedidoIfoodId));
        } catch (VinculoPerdido) {
            Log::warning('[entregas] ifood: vínculo perdido; pedido não criado', ['merchant' => $merchantId, 'pedido_ifood' => $this->pedidoIfoodId]);

            return null;
        }

        try {
            return $criador->criar($vinculo, $dados);
        } catch (ErroIfood|VinculoPerdido $e) {
            // sem dados do cliente; tratados no handle (ErroIfood) ou pela fila
            throw $e;
        } catch (\Throwable $e) {
            // a mensagem do QueryException traz o SQL com os valores (nome, endereço, telefone do cliente): sobe só a
            // classe e os códigos, sem a original encadeada (o failed_jobs guardaria a cadeia inteira)
            $detalhe  = ['pedido_ifood' => $this->pedidoIfoodId, 'erro' => get_class($e)];
            $mensagem = 'falha ao criar o pedido do iFood: ' . get_class($e);
            if ($e instanceof QueryException) {
                $detalhe['sqlstate'] = preg_replace('/[^0-9A-Z]/', '', (string) $e->getCode());
                $detalhe['driver']   = is_numeric($e->errorInfo[1] ?? null) ? (int) $e->errorInfo[1] : null;
                $mensagem .= " (SQLSTATE {$detalhe['sqlstate']}, driver " . ($detalhe['driver'] ?? '-') . ')';
            }
            Log::error('[entregas] ifood: falha ao criar o pedido', $detalhe);

            throw new \RuntimeException($mensagem);
        }
    }

    /** Algum CAN deste pedido na tabela, processado ou não (não só entre os pendentes desta rodada). */
    protected function foiCancelado(): bool
    {
        return DB::table(static::EVENTOS)->where('pedido_ifood_id', $this->pedidoIfoodId)->where('codigo', 'CAN')->exists();
    }

    /** Códigos de depois da coleta (POS_COLETA) deste pedido na tabela, processados ou não; [] se nenhum. */
    protected function codigosDepoisDaColeta(): array
    {
        $codigos = DB::table(static::EVENTOS)->where('pedido_ifood_id', $this->pedidoIfoodId)->whereIn('codigo', static::POS_COLETA)->pluck('codigo')->all();

        return array_values(array_unique($codigos));
    }

    /** O public_id do Order (o que a central vê), ou null se o Order sumiu. */
    protected function publicIdDoOrder(object $pedido): ?string
    {
        return $pedido->order_uuid ? Order::where('uuid', $pedido->order_uuid)->first()?->public_id : null;
    }

    protected function atualizarPedido(object $pedido, array $valores): void
    {
        DB::table(static::PEDIDOS)->where('id', $pedido->id)->update($valores + ['updated_at' => now()->toDateTimeString()]);
    }

    protected function marcar(array $ids, bool $ignorado): void
    {
        $agora = now()->toDateTimeString();
        DB::table(static::EVENTOS)->whereIn('evento_id', $ids)->update(['processado_em' => $agora, 'ignorado' => $ignorado, 'updated_at' => $agora]);
    }
}
