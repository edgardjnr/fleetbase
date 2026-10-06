<?php

namespace App\Support\Entregas\Ifood;

use RuntimeException;

/**
 * Entregas RestaurantePro: o refresh token da loja venceu ou foi revogado (ou nunca veio). A loja já foi marcada
 * `vinculo_perdido` e saiu do polling; só um vínculo novo pela tela Lojas a traz de volta.
 */
class VinculoPerdido extends RuntimeException
{
}
