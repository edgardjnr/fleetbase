<?php

namespace App\Support\Entregas;

/**
 * Entregas RestaurantePro: os status de pedido (orders.status, gravados pelo Fleet-Ops) que as regras do Entregas
 * conferem, numa lista só: o cancelamento pela loja (RegrasPortalLoja), o aceite do motoboy
 * (BarrarAceiteDePedidoEncerrado) e a posição do motoboy no portal (PortalLojaController).
 */
class StatusDoPedido
{
    /** Pedido encerrado: a loja não cancela, o motoboy não aceita e a loja não vê mais o motoboy. */
    public const ENCERRADOS = ['completed', 'done', 'canceled', 'cancelled', 'order_canceled', 'expired'];

    /** Os encerrados por cancelamento (o aceite barrado tem mensagem própria para eles). */
    public const CANCELADOS = ['canceled', 'cancelled', 'order_canceled'];
}
