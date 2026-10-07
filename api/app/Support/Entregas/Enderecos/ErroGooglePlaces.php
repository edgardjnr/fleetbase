<?php

namespace App\Support\Entregas\Enderecos;

use RuntimeException;

/** Entregas RestaurantePro: falha na Places API (New) do Google. Status 0 = rede ou chave não configurada. */
class ErroGooglePlaces extends RuntimeException
{
    public function __construct(public readonly int $status, string $mensagem = '')
    {
        parent::__construct($mensagem !== '' ? $mensagem : "Places API respondeu {$status}");
    }
}
