<?php

namespace App\Notifications\Entregas\Email;

use Fleetbase\Support\Utils;

/**
 * Entregas RestaurantePro: texto de usuário (nome, e-mail, empresa) para entrar em assunto e linhas dos e-mails.
 * O delinkify do core-api escapa HTML (&amp;, &#039;) e insere entidades &#8203;, mas as views imprimem com {{ }}, que
 * escapa de novo: "Bob's" saía "Bob&#039;s". Aqui o resultado volta a texto puro, com o espaço de largura zero (U+200B)
 * de verdade, que ainda quebra o autolink; o Blade escapa uma vez só.
 */
class TextoDoEmail
{
    public static function semLink(?string $texto): string
    {
        return html_entity_decode(Utils::delinkify($texto), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
