<?php

namespace App\Support\Entregas\Integracoes;

/**
 * Entregas RestaurantePro: os pedidos de delivery do cardápio do RestaurantePro (ver
 * api/app/Support/Entregas/Integracao/CLAUDE.md). A loja gera a chave no portal e cola no RestaurantePro
 * (Configurações de delivery → "Entregas por" → Entregas RestaurantePro).
 */
class IntegracaoRestaurantePro extends IntegracaoPorChave
{
    public function id(): string
    {
        return 'restaurantepro';
    }

    public function nome(): string
    {
        return 'RestaurantePro';
    }
}
