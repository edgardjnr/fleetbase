<?php

namespace App\Support\Entregas\Saques;

/** Entregas RestaurantePro: o Docker secret com a chave do Cofre não está no container (o controller responde 503). */
class CofreIndisponivel extends \RuntimeException
{
    public function __construct(string $mensagem = 'Saque Pix indisponível: chave de criptografia não configurada no servidor.')
    {
        parent::__construct($mensagem);
    }
}
