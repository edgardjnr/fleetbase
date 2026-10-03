<?php

namespace App\Notifications\Entregas;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\OrderPing;

/**
 * Entregas RestaurantePro: aviso repetido de pedido aberto (ver ReenviarPedidosAbertos).
 *
 * É o OrderPing do Fleet-Ops, com o mesmo tipo (`order_ping` no push, `order.ping` no socket), que o app trata como
 * pedido novo. Só o texto muda, em pt-BR.
 */
class LembretePedidoAberto extends OrderPing
{
    public function __construct(Order $order, $distance = null)
    {
        parent::__construct($order, $distance);

        $this->title   = 'Pedido ainda sem motoboy';
        $this->message = $distance ? 'Coleta a ' . static::formatarDistancia((float) $distance) . ' de você. Toque para ver o pedido.' : 'Toque para ver o pedido.';
    }

    protected static function formatarDistancia(float $metros): string
    {
        if ($metros < 1000) {
            return (int) round($metros) . ' m';
        }

        return number_format($metros / 1000, 1, ',', '.') . ' km';
    }
}
