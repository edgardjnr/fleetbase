<?php

namespace App\Notifications\Entregas\Email;

/**
 * Entregas RestaurantePro: saudação dos e-mails ("Olá, Ana!" ou "Olá!"). O nome passa pelo delinkify do core-api (via
 * TextoDoEmail), como nas views originais, para um nome com cara de link ou e-mail não virar link clicável no leitor.
 */
class Saudacao
{
    public static function para(?string $nome): string
    {
        // o trim também tira o espaço de largura zero (U+200B), que o \s do preg não pega
        $nome = preg_replace('/^[\s\x{200B}]+|[\s\x{200B}]+$/u', '', TextoDoEmail::semLink($nome)) ?? '';

        return $nome === '' ? 'Olá!' : "Olá, {$nome}!";
    }
}
