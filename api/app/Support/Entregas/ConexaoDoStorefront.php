<?php

namespace App\Support\Entregas;

/**
 * Entregas RestaurantePro: a conexão "storefront" que o `mysql:createdb` (core-api, primeiro comando do deploy.sh) ainda
 * pede, com o Storefront fora do package:discover (api/composer.json, `extra.laravel.dont-discover`).
 *
 * O storefront-api continua instalado (está no composer.lock), e o comando lê a lista de bancos a criar do
 * `extra.fleetbase.create-database` dos pacotes do vendor/composer/installed.json, não dos providers. Sem o provider do
 * Storefront, ninguém mescla a conexão dele: o comando montaria um `CREATE DATABASE IF NOT EXISTS  CHARACTER SET ...`
 * sem nome, o MySQL recusaria e o deploy.sh pararia ali (set -e), antes das migrations.
 *
 * Nada abre essa conexão. Ela só dá ao comando o nome do banco (`<banco>_storefront`, como no config do Storefront; em
 * produção o `fleetbase_storefront` já existe e o comando não faz nada).
 */
final class ConexaoDoStorefront
{
    public const NOME = 'storefront';

    public const SUFIXO_DO_BANCO = '_storefront';

    /**
     * A conexão a gravar em `database.connections.storefront`: a `mysql`, com o banco `<banco da mysql>_storefront`.
     * Null quando ela já existe (Storefront religado, ou o config:cache) ou quando não há a `mysql`.
     *
     * @param array<string, mixed> $conexoes o config('database.connections')
     */
    public static function configuracao(array $conexoes): ?array
    {
        if (isset($conexoes[static::NOME]) || !is_array($conexoes['mysql'] ?? null)) {
            return null;
        }

        $mysql = $conexoes['mysql'];
        $banco = is_string($mysql['database'] ?? null) && trim($mysql['database']) !== '' ? trim($mysql['database']) : 'fleetbase';

        return array_merge($mysql, ['database' => $banco . static::SUFIXO_DO_BANCO]);
    }
}
