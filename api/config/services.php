<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'sendgrid' => [
        'api_key' => env('SENDGRID_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY', env('STRIPE_API_KEY')),
        'secret' => env('STRIPE_SECRET', env('STRIPE_API_SECRET')),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'callpromn' => [
        'api_key' => env('CALLPROMN_API_KEY', ''),
        'from' => env('CALLPROMN_FROM', ''),
        'base_url' => env('CALLPROMN_BASE_URL', 'https://api-text.callpro.mn/v1/sms'),
    ],

    'google_maps' => [
        'locale' => env('GOOGLE_MAPS_LOCALE', 'us'),
        'api_key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    // Entregas RestaurantePro: chave pública do app do motoboy (Navigator). Vazia, nada muda; preenchida, a
    // chave só serve para o login do app (App\Http\Middleware\RestringirChaveDoApp). Fica aqui, e não num env()
    // solto, porque o deploy.sh roda config:cache.
    'entregas' => [
        'chave_app_motoboy' => env('ENTREGAS_CHAVE_APP_MOTOBOY'),
        // distribuição de pedidos abertos (oferta um a um): 1 liga; vazio volta ao alarme geral do Fleet-Ops
        'distribuicao' => env('ENTREGAS_DISTRIBUICAO'),
        // 1 = matriz de tempos pelo OSRM_HOST; vazio = linha reta. Ligar só com um OSRM próprio
        'distribuicao_osrm' => env('ENTREGAS_DISTRIBUICAO_OSRM'),
        // 1 = distribuição em rodadas (30 s, raio crescente, voltas até aceitar, sem alarme a todos); só vale com a
        // distribuição ligada. Vazio = o ciclo de 30 s que abre a todos. Ligar só com o APK 30 em todos os celulares
        'distribuicao_rodadas' => env('ENTREGAS_DISTRIBUICAO_RODADAS'),
        // trava "Atualize o app" (AppDoMotoboy): 1 liga. Ligar só com o APK da trava (35+) em todos os celulares
        'app_trava' => env('ENTREGAS_APP_TRAVA'),
        // JSON {versao, url} que o GitHub Actions do entregas-navigator publica no MinIO a cada build
        'app_versao_url' => env('ENTREGAS_APP_VERSAO_URL', 'https://s3arquivos.restaurantepro.com.br/entregas/APK/entregas-motoboy.json'),
        // emergência (build quebrado): fixa a versão mínima à mão, ignorando a publicada; vazio = a publicada
        'app_versao_minima' => env('ENTREGAS_APP_VERSAO_MINIMA'),
    ],

    // Entregas RestaurantePro: integração iFood Logistics, app distribuído (App\Support\Entregas\Ifood). ENTREGAS_IFOOD
    // vazio ou 0 = desligada: os comandos agendados saem sem fazer nada e o vínculo na tela Lojas responde 409. As
    // credenciais ficam só no stack.env (nunca no banco nem no código). Aqui, e não em env() solto, porque o deploy.sh
    // roda config:cache.
    'ifood' => [
        'ativo'         => env('ENTREGAS_IFOOD'),
        'client_id'     => env('IFOOD_CLIENT_ID'),
        'client_secret' => env('IFOOD_CLIENT_SECRET'),
        'base_url'      => env('IFOOD_BASE_URL', 'https://merchant-api.ifood.com.br'),
        // trava "Atualize o app" (RegrasDoPedidoIfood): 1 só depois do APK com a conclusão iFood em todos os celulares
        'exige_app_novo' => env('ENTREGAS_IFOOD_EXIGE_APP_NOVO'),
        // modo homologação (ClienteIfood): log de cada chamada ao iFood e erros simulados; só durante a homologação
        'homologacao' => env('ENTREGAS_IFOOD_HOMOLOGACAO'),
    ],
];
