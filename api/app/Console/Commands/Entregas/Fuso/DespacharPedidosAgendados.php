<?php

namespace App\Console\Commands\Entregas\Fuso;

use Fleetbase\FleetOps\Console\Commands\DispatchOrders;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Support\Carbon;

/**
 * Entregas RestaurantePro: o `fleetops:dispatch-orders` do Fleet-Ops sem o
 * `date_default_timezone_set('UTC')` do início do handle().
 *
 * O comando despacha os pedidos agendados na hora do scheduled_at.
 *
 * O servidor roda no horário de Brasília e a sessão do MySQL em -03:00: com o PHP em UTC dentro do comando, as datas
 * sem fuso lidas do banco (DATETIME, como o scheduled_at) viravam instantes 3 h errados e o now() gravado ia 3 h para
 * o futuro. Ver CLAUDE.md, "Fuso (horário de Brasília)".
 *
 * Roda no lugar do original, com o mesmo nome e o mesmo agendamento (a troca fica no AppServiceProvider). O handle()
 * é cópia do original (fleetops-api 0.6.65) sem a linha do fuso.
 *
 * Ao atualizar o fleetops-api, confira se o handle() do original mudou (o teste scripts/teste-php/fuso.php compara os
 * dois, com a cópia de packages/fleetops) e se outro comando agendado passou a chamar date_default_timezone_set.
 */
class DespacharPedidosAgendados extends DispatchOrders
{
    public function handle(): void
    {
        $sandboxMode = Utils::castBoolean($this->option('sandbox'));
        $this->info('Running in ' . ($sandboxMode ? 'sandbox' : 'production') . ' mode.');

        // Get all scheduled dispatchable orders
        $orders = $this->getScheduledOrders($sandboxMode);

        $this->alert('Found ' . $orders->count() . ' orders scheduled for dispatch. Current Time: ' . Carbon::now()->toDateTimeString());

        // Dispatch each order
        foreach ($orders as $order) {
            if ($order->shouldDispatch()) {
                $order->dispatch();
                $this->info('Order ' . $order->public_id . ' dispatched successfully (' . $order->scheduled_at . ').');
            } else {
                $this->warn('Order ' . $order->public_id . ' is not ready for dispatch (' . $order->scheduled_at . ').');
            }
        }
    }
}
