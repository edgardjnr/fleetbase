<?php

namespace App\Support\Entregas\Distribuicao;

use App\Listeners\Entregas\DistribuirPedidoAberto;
use Fleetbase\FleetOps\Events\OrderDispatched;
use Fleetbase\FleetOps\Listeners\HandleOrderDispatched;
use Fleetbase\FleetOps\Listeners\NotifyOrderEvent;
use Fleetbase\Listeners\SendResourceLifecycleWebhook;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: põe o DistribuirPedidoAberto no lugar do HandleOrderDispatched do Fleet-Ops no
 * OrderDispatched, preservando os outros listeners do evento (o SendResourceLifecycleWebhook e o NotifyOrderEvent do
 * Fleet-Ops, o HandleOrderDispatched do Storefront, que segue no composer, e o que mais houver). O Laravel não tira um
 * listener só: lê a lista crua (getRawListeners), esquece o evento e registra o nosso primeiro (no lugar do original,
 * que era o primeiro do Fleet-Ops) e depois os outros, na ordem em que estavam. Chamado pelo
 * AppServiceProvider::distribuirPedidosAbertos no booted(), depois do boot de todos os providers.
 *
 * Sem o original na lista (o fleetops-api mudou): aviso no log e o nosso registrado mesmo assim (ele chama o original
 * quando não deve distribuir; se o original reaparecer por outro caminho, rodaria em dobro). Dispatcher sem
 * getRawListeners (outra implementação): a lista fixa do EventServiceProvider do Fleet-Ops (perderia o do Storefront).
 */
class TrocaDoListenerDoDespacho
{
    /** Os outros dois do EventServiceProvider do Fleet-Ops, para o dispatcher sem getRawListeners. */
    public const LISTA_FIXA = [SendResourceLifecycleWebhook::class, NotifyOrderEvent::class];

    /** @param object $eventos o dispatcher do Laravel (app('events')) */
    public static function aplicar(object $eventos): void
    {
        if (!method_exists($eventos, 'getRawListeners')) {
            $outros = static::LISTA_FIXA;
        } else {
            $atuais = $eventos->getRawListeners()[OrderDispatched::class] ?? [];
            $outros = array_values(array_filter($atuais, fn ($listener) => !static::ehOOriginal($listener)));
            if (count($outros) === count($atuais)) {
                Log::warning('[entregas] distribuição: listener do Fleet-Ops não encontrado no OrderDispatched');
            }
        }

        $eventos->forget(OrderDispatched::class);
        $eventos->listen(OrderDispatched::class, DistribuirPedidoAberto::class);
        foreach ($outros as $listener) {
            $eventos->listen(OrderDispatched::class, $listener);
        }
    }

    /** O HandleOrderDispatched do Fleet-Ops, registrado como 'Classe', '\Classe', 'Classe@handle' ou [Classe, 'handle']. */
    public static function ehOOriginal(mixed $listener): bool
    {
        if (is_array($listener)) {
            $listener = $listener[0] ?? null;
        }
        if (!is_string($listener)) {
            return false; // closure ou objeto: nunca é o original
        }

        return ltrim(explode('@', $listener, 2)[0], '\\') === HandleOrderDispatched::class;
    }
}
