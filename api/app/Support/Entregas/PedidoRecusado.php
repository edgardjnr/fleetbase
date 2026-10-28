<?php

namespace App\Support\Entregas;

/**
 * Entregas RestaurantePro: pedido recusado antes de nascer, com o status HTTP e a mensagem para a tela (sem crédito,
 * trava da carteira ocupada, ponto da entrega na loja). Quem responde à requisição transforma em {"errors": [mensagem]}.
 */
class PedidoRecusado extends \RuntimeException
{
    public function __construct(public readonly int $status, string $mensagem)
    {
        parent::__construct($mensagem);
    }
}
