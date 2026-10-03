<?php

namespace App\Notifications\Entregas;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\OrderPing;

/**
 * Entregas RestaurantePro: aviso repetido de pedido aberto (ver ReenviarPedidosAbertos).
 *
 * É o OrderPing do Fleet-Ops, com o mesmo tipo (`order_ping` no push, `order.ping` no socket), que o app trata como
 * pedido novo. Só o título muda; o texto da coleta é o mesmo do primeiro aviso (AvisosDoMotoboy).
 */
class LembretePedidoAberto extends OrderPing
{
    public function __construct(Order $order, $distance = null)
    {
        parent::__construct($order, $distance);

        $this->title   = 'Pedido ainda sem motoboy';
        $this->message = AvisosDoMotoboy::textoDaColeta($distance);
    }
}
