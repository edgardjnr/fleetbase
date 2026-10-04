<?php

namespace App\Support\Entregas;

/**
 * Entregas RestaurantePro: os status de pedido (orders.status, gravados pelo Fleet-Ops) que as regras do Entregas
 * conferem, numa lista só: o cancelamento pela loja (RegrasPortalLoja), o aceite do motoboy
 * (BarrarAceiteDePedidoEncerrado), o motoboy do pedido no portal (PortalLojaController), os pedidos em andamento dos
 * mapas de motoboys (SituacaoDoMotoboy) e o reenvio do aviso de pedido aberto (ReenviarPedidosAbertos).
 */
class StatusDoPedido
{
    /**
     * Pedido encerrado: a loja não cancela, o motoboy não aceita, a loja não vê mais o motoboy e o aviso de
     * pedido aberto não é reenviado.
     */
    public const ENCERRADOS = ['completed', 'done', 'canceled', 'cancelled', 'order_canceled', 'expired'];

    /** Os encerrados por cancelamento (o aceite barrado tem mensagem própria para eles). */
    public const CANCELADOS = ['canceled', 'cancelled', 'order_canceled'];
}
