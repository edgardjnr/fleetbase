<?php

namespace App\Support\Entregas\Ifood;

/**
 * Entregas RestaurantePro: a fila própria dos jobs iFood (ProcessarPedidoIfood e EnviarAcaoIfood), para uma rajada de
 * push ou de notificações não atrasar a entrada dos pedidos e as ações de logística (e vice-versa).
 * - worker queue-ifood (deploy/docker-stack.yml): `queue:work --queue=ifood`;
 * - worker queue: `queue:work --queue=default,ifood` (os push primeiro; ajuda no iFood quando está livre, e assim nada
 *   fica parado se o queue-ifood ainda não existir no stack).
 * Com dois workers, a conexão redis tem `after_commit` (api/config/queue.php): o broadcast do pedido criado dentro da
 * transação do CriadorDoPedidoIfood só entra na fila depois do commit.
 */
class FilaIfood
{
    public const NOME = 'ifood';
}
