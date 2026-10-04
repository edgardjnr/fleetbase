<?php

namespace App\Notifications\Entregas\Email;

use Fleetbase\Support\Utils;

/**
 * Entregas RestaurantePro: saudação dos e-mails ("Olá, Ana!" ou "Olá!"). O nome passa pelo delinkify do core-api, como
 * nas views originais, para um nome com cara de link ou e-mail não virar link clicável no leitor de e-mail.
 */
class Saudacao
{
    public static function para(?string $nome): string
    {
        $nome = trim(Utils::delinkify($nome));

        return $nome === '' ? 'Olá!' : "Olá, {$nome}!";
    }
}
