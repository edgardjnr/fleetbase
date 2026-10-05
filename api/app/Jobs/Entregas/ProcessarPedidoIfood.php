<?php

namespace App\Jobs\Entregas;

use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\CriadorDoPedidoIfood;
use App\Support\Entregas\Ifood\ErroIfood;
use App\Support\Entregas\Ifood\EventosIfood;
use App\Support\Entregas\Ifood\VinculoPerdido;
use App\Support\Entregas\Ifood\VinculosIfood;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
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
 *   criado de novo; sem pedido e sem evento que cria, os eventos só ficam processados (log "evento sem pedido");
 * - pedido cancelado (CAN) antes de entrar não é criado, nem por um evento atrasado numa rodada seguinte (o CAN é
 *   procurado entre todos os eventos do pedido na tabela, não só entre os pendentes);
 * - código ignorado: log warning quando exige ação da loja no iFood (EventosIfood::nivelDoIgnorado), info nos outros;
 * - DDCR marca exige_codigo; CAN só registra cancelado_pelo_ifood_em (o cancelamento no Entregas é da etapa 3);
 * - código desconhecido, loja não vinculada (ou vínculo perdido) e loja sem Local de coleta ficam como ignorados.
 *
 * Erro do iFood ao buscar o pedido (404 ainda indisponível, 5xx, 429, rede) sobe: os eventos continuam pendentes e a
 * fila tenta de novo ($backoff). Logs sem dados do cliente (só ids e o número do pedido).
 */
class ProcessarPedidoIfood implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const EVENTOS = 'entregas_ifood_eventos';
    public const PEDIDOS = 'entregas_ifood_pedidos';

    /** Segundos até tentar de novo quando outro job do mesmo pedido está rodando. */
    public const ESPERA_DA_TRAVA = 15;

    public int $tries = 8;

    public array $backoff = [15, 30, 60, 120, 300];

    public function __construct(public string $pedidoIfoodId)
    {
    }

    public function handle(VinculosIfood $vinculos, ClienteIfood $cliente, CriadorDoPedidoIfood $criador): void
    {
        $trava = Cache::lock("entregas:ifood-pedido:{$this->pedidoIfoodId}", 120);
        if (!$trava->get()) {
            $this->release(static::ESPERA_DA_TRAVA);

            return;
        }

        try {
            $this->processar($vinculos, $cliente, $criador);
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
            $this->marcar(array_column($eventos, 'id'), false);

            return;
        }

        if (!$pedido && !array_filter($eventos, fn (array $evento) => EventosIfood::criaPedido($evento['code']))) {
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
                Log::warning('[entregas] ifood: pedido cancelado pelo iFood (o cancelamento no Entregas é da etapa 3)', ['pedido' => $pedido->order_uuid, 'numero' => $pedido->numero]);
            }

            if ($acao === EventosIfood::IGNORA) {
                // warning quando o código exige ação da loja no iFood (HSD), info nos outros
                $nivel = EventosIfood::nivelDoIgnorado($evento['code']);
                Log::{$nivel}('[entregas] ifood: evento ignorado', ['codigo' => $evento['code'], 'pedido_ifood' => $this->pedidoIfoodId]);
            }

            $this->marcar([$evento['id']], $acao === EventosIfood::IGNORA);
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
            return null;
        }

        return $criador->criar($vinculo, $dados);
    }

    /** Algum CAN deste pedido na tabela, processado ou não (não só entre os pendentes desta rodada). */
    protected function foiCancelado(): bool
    {
        return DB::table(static::EVENTOS)->where('pedido_ifood_id', $this->pedidoIfoodId)->where('codigo', 'CAN')->exists();
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
