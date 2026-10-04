<?php

/*
 * Entregas RestaurantePro: marca padrão (ícone e logo) do console, do portal da loja e dos e-mails.
 *
 * O core-api junta a config dele com esta por mergeConfigFrom, e a chave daqui vence: só a "branding" muda, o resto
 * continua vindo do pacote. Imagens enviadas em Admin → Marca continuam tendo prioridade (Setting::getBranding).
 * Os arquivos ficam no console (console/public/images), servidos sem fingerprint em /images/.
 */
return [
    'branding' => [
        'logo_url' => env('ENTREGAS_LOGO_URL', 'https://entregas.restaurantepro.com.br/images/logo.png'),
        'icon_url' => env('ENTREGAS_ICON_URL', 'https://entregas.restaurantepro.com.br/images/icon.png'),
    ],
];
