<?php

namespace App\Support\Entregas\Integracao;

/** Entregas RestaurantePro: erro da integração com o status HTTP da resposta (o IntegracaoController responde com ele). */
final class ErroDaIntegracao extends \RuntimeException
{
    public function __construct(public readonly int $status, string $mensagem)
    {
        parent::__construct($mensagem);
    }
}
