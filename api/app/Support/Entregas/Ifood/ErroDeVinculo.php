<?php

namespace App\Support\Entregas\Ifood;

use RuntimeException;

/**
 * Entregas RestaurantePro: o vínculo da loja com o iFood não pode seguir (código vencido, loja do iFood já ligada a
 * outra loja, conta sem lojas). A mensagem, em pt-BR, vai para a central (IfoodLojasController responde 422).
 */
class ErroDeVinculo extends RuntimeException
{
}
