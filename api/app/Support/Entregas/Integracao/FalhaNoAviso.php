<?php

namespace App\Support\Entregas\Integracao;

/**
 * Entregas RestaurantePro: o sistema de origem não respondeu 2xx a um aviso (rede = status 0). O job tenta de novo. A
 * mensagem leva só o status (o failed_jobs guarda a exceção; o corpo da resposta pode ter dados do cliente).
 */
final class FalhaNoAviso extends \RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct("aviso da integração não entregue (status {$status})");
    }
}
