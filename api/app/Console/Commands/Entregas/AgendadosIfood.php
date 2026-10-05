<?php

namespace App\Console\Commands\Entregas;

use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\CriadorDoPedidoIfood;
use App\Support\Entregas\StatusDoPedido;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: despacha aos motoboys os pedidos iFood com `despachar_em` vencido e ainda não despachados
 * (agendado a cada minuto no App\Console\Kernel). Pega:
 * - o agendado (despachar_em = início da janela − 40 min). O fleetops:dispatch-orders não basta: só despacha quem cai
 *   a ±1 min do scheduled_at na rodada, e um minuto perdido deixaria o pedido parado. Quando ele despacha antes, já
 *   despacha com a atividade "dispatched" (o HandleOrderDispatched a cria quando falta) e o
 *   CriadorDoPedidoIfood::despachar só marca despachado_em; a atividade só é inserida por ele, como reserva, se ainda
 *   faltar (listener atrasado na fila ou com falha), sem avisar os motoboys de novo;
 * - o imediato cujo despacho falhou no job (1 min de folga, para não correr junto com o job).
 * Fica de fora: pedido de teste (sem despachar_em), cancelado pelo iFood e pedido encerrado ou apagado (sai da fila).
 *
 * O CriadorDoPedidoIfood::despachar relê o pedido com a trava (TravaDoPedido) e decide: true = despachado agora ou já
 * estava (só aí o log "despachado pelo agendador"); false = não despachou. No false, ou ele mesmo tirou a linha da fila
 * (pedido encerrado, aceito ou apagado), ou foi falha temporária (trava ocupada, erro no despacho) e a linha fica para
 * a rodada seguinte. Para um despacho que falha sempre não voltar para sempre: com despachar_em vencido há mais de
 * DESISTIR_DEPOIS_MINUTOS e o despacho ainda sem sair nesta tentativa, a linha sai da fila (despachar_em nulo) e o log
 * `[entregas] ifood: despacho desistiu` (warning, só com ids) avisa; a central despacha à mão. Sempre há ao menos uma
 * tentativa: um agendador parado por mais de 30 min não descarta o pedido sem tentar.
 * A chegada pelo GPS entra aqui na etapa 3 (entregas:ifood-acompanhar da spec).
 */
class AgendadosIfood extends Command
{
    protected $signature = 'entregas:ifood-agendados';

    protected $description = 'iFood: despacha os pedidos agendados (e os de despacho falho) quando chega a hora';

    public const PEDIDOS = 'entregas_ifood_pedidos';

    public const POR_RODADA = 50;

    /** Depois de quantos minutos do despachar_em um despacho que ainda falha sai da fila. */
    public const DESISTIR_DEPOIS_MINUTOS = 30;

    public function handle(CriadorDoPedidoIfood $criador): int
    {
        if (!ClienteIfood::ligada()) {
            return self::SUCCESS;
        }

        $limite   = now()->subMinute()->toDateTimeString();
        $desistir = now()->subMinutes(static::DESISTIR_DEPOIS_MINUTOS)->toDateTimeString();
        $linhas   = DB::table(static::PEDIDOS)
            ->whereNull('despachado_em')
            ->whereNull('cancelado_pelo_ifood_em')
            ->whereNotNull('order_uuid')
            ->whereNotNull('despachar_em')
            ->where('despachar_em', '<=', $limite)
            ->where('teste', false)
            ->orderBy('despachar_em')
            ->limit(static::POR_RODADA)
            ->get()
            ->all();

        foreach ($linhas as $linha) {
            $pedido = Order::where('uuid', $linha->order_uuid)->first();
            if (!$pedido || in_array($pedido->status, StatusDoPedido::ENCERRADOS, true)) {
                // apagado, cancelado ou concluído pela central: sai da fila
                $this->tirarDaFila($linha);
                continue;
            }

            if ($criador->despachar($pedido)) {
                Log::info('[entregas] ifood: pedido despachado pelo agendador', ['pedido' => $pedido->public_id, 'numero' => $linha->numero, 'agendado' => (bool) $linha->agendado]);
                continue;
            }

            // não saiu nesta tentativa: se a linha ainda está na fila (o despachar não a tirou) e já passou do prazo, desiste
            if ((string) $linha->despachar_em <= $desistir && $this->tirarDaFila($linha)) {
                Log::warning('[entregas] ifood: despacho desistiu', ['pedido' => $pedido->public_id, 'pedido_ifood' => $linha->pedido_ifood_id]);
            }
        }

        return self::SUCCESS;
    }

    /** despachar_em nulo (se ainda não estava); devolve se a linha saiu da fila agora. */
    protected function tirarDaFila(object $linha): bool
    {
        return DB::table(static::PEDIDOS)
            ->where('id', $linha->id)
            ->whereNotNull('despachar_em')
            ->whereNull('despachado_em')
            ->update(['despachar_em' => null, 'updated_at' => now()->toDateTimeString()]) > 0;
    }
}
