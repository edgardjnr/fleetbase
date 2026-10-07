<?php

namespace App\Listeners\Entregas;

use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Distribuidor;
use Fleetbase\FleetOps\Events\OrderDispatched;
use Fleetbase\FleetOps\Listeners\HandleOrderDispatched;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: no lugar do HandleOrderDispatched do Fleet-Ops (trocado no
 * AppServiceProvider::distribuirPedidosAbertos). Como o original, é um listener na fila (herda o ShouldQueue) e relê o
 * pedido no worker (getModelRecord).
 *
 * - Pedido que não é aberto, distribuição desligada ou pedido aberto que já tem motoboy no despacho (agendado que a
 *   central atribuiu com o "pedido aberto" ainda ligado): o original, sem mudança (no último caso, o Fleet-Ops avisa
 *   todos do raio, como antes; com a distribuição, ela seria encerrada como `atribuida` e ninguém seria avisado).
 * - Pedido aberto sem motoboy: a mesma parte do despacho (atividade DISPATCHED, dispatched_at) e, em vez do alarme a
 *   todos do raio, a distribuição (Distribuidor::iniciar). Se ela falhar (banco, trava do pedido ocupada), o alarme
 *   geral do próprio Fleet-Ops, para o pedido não ficar sem ninguém (o Distribuidor já deixou a distribuição aberta).
 *
 * Ao atualizar o fleetops-api, confira o handle() do HandleOrderDispatched (a parte repetida aqui) e os métodos
 * protegidos usados: doesntHaveDispatchActivity, getDispatchActivity, nearbyAvailableDrivers e notifyAdhocDriver
 * (scripts/teste-php/distribuicao-ciclo.php confere na cópia de packages/fleetops).
 */
class DistribuirPedidoAberto extends HandleOrderDispatched
{
    public function handle(OrderDispatched $event)
    {
        /** @var Order|null $order */
        $order = $event->getModelRecord();
        if (!$order) {
            return; // apagado antes de o worker pegar o evento
        }
        if (!$order->adhoc || $order->driver_assigned_uuid || !Distribuicao::ligada()) {
            return parent::handle($event);
        }

        session(['company' => $order->company_uuid]);

        // como o original: a atividade de despacho, se ainda não existe
        if ($this->doesntHaveDispatchActivity($order)) {
            $activity = $this->getDispatchActivity($order);
            if ($activity) {
                $location = $order->getLastLocation();
                $order->setStatus($activity->code);
                $order->createActivity($activity, $location);
            }
        }
        $order->dispatched    = true;
        $order->dispatched_at = now();
        $order->save();
        $order->flushAttributesCache();
        $order->load(['company', 'payload.pickup', 'payload.dropoff']);

        $this->distribuir($order);
    }

    /** Inicia a distribuição; em falha, o alarme geral. Público para o teste. */
    public function distribuir(Order $order): void
    {
        try {
            app(Distribuidor::class)->iniciar($order);
        } catch (\Throwable $e) {
            Log::error('[entregas] distribuição: falha ao iniciar a distribuição; alarme geral', ['pedido' => $order->public_id, 'erro' => get_class($e)]);
            $this->alarmeGeral($order);
        }
    }

    /** O que o Fleet-Ops faz no pedido aberto: OrderPing a todos os motoboys livres no raio. */
    protected function alarmeGeral(Order $order): void
    {
        $pickup = $order->getPickupLocation();
        if (!Utils::isPoint($pickup)) {
            return;
        }
        foreach ($this->nearbyAvailableDrivers($pickup, $order->getAdhocDistance()) as $driver) {
            try {
                $this->notifyAdhocDriver($driver, $order);
            } catch (\Throwable $e) {
                // como o original: segue com os outros
            }
        }
    }
}
