<?php

namespace App\Notifications\Entregas\Email;

/**
 * Entregas RestaurantePro: logo e link do cabeçalho dos e-mails (vendor/mail/html/message.blade.php). O logo vem de
 * Admin → Marca (Setting::getBrandingLogoUrl do core-api); sem imagem lá, cai em config('fleetbase.branding.logo_url'),
 * que o api/config/fleetbase.php aponta para o logo do RestaurantePro.
 */
class MarcaDoEmail
{
    public static function logo(): string
    {
        try {
            if (class_exists(\Fleetbase\Models\Setting::class)) {
                return (string) \Fleetbase\Models\Setting::getBrandingLogoUrl();
            }
        } catch (\Throwable $erro) {
            // sem banco (comando, teste): fica o logo da config
        }

        return (string) config('fleetbase.branding.logo_url');
    }

    public static function urlDoConsole(): string
    {
        try {
            if (class_exists(\Fleetbase\Support\Utils::class)) {
                return \Fleetbase\Support\Utils::consoleUrl();
            }
        } catch (\Throwable $erro) {
            // sem config do console: fica a URL da aplicação
        }

        return (string) config('app.url');
    }
}
