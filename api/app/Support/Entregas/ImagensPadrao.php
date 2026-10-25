<?php

namespace App\Support\Entregas;

/**
 * Entregas RestaurantePro: as imagens padrão do Fleetbase (avatar sem foto, ícone de motoboy, de local, de veículo,
 * placeholders) servidas pelo próprio console, e não pelo bucket S3 do Fleetbase (flb-assets). Com o bucket, todo
 * navegador e todo celular que abria uma tela baixava a imagem de lá: o Fleetbase (AWS) via o IP de quem usa e o
 * domínio do sistema nos logs.
 *
 * Os arquivos ficam em console/public/images/entregas/padrao/<caminho no bucket> (fora do fingerprint). O core-api e o
 * Fleet-Ops montam essas URLs nos acessores dos modelos (User, Company, Contact, Vendor, Driver, Place, Vehicle, Entity,
 * Category) e gravam o avatar_url de motoboy e local com elas. Quem troca:
 * - TrocarImagensDoFleetbase: as respostas JSON da API (o que vem do acessor sem valor gravado);
 * - AppServiceProvider::imagensPadraoLocais: o avatar_url ao salvar motoboy, local e veículo (vale também nos eventos
 *   do socket, que serializam o modelo);
 * - a migration 2026_10_25_130000_imagens_padrao_locais: os registros antigos.
 *
 * Só as imagens da lista ARQUIVOS são trocadas: outro caminho do bucket fica como está (não há cópia local).
 */
final class ImagensPadrao
{
    /** Pasta pública do console (console/public/images/entregas/padrao). */
    public const PASTA = '/images/entregas/padrao/';

    /** Caminhos no bucket flb-assets que têm cópia local, iguais aos do console/public/images/entregas/padrao. */
    public const ARQUIVOS = [
        'static/no-avatar.png',
        'static/image-file-icon.png',
        'static/default-storefront-backdrop.png',
        'images/fallback-placeholder-1.png',
        'images/fallback-placeholder-2.png',
        'static/parcels/medium.png',
        'static/vehicle-icons/mini_bus.svg',
        'static/vehicle-placeholder.png',
        'static/driver-icons/moto-driver.png',
        'static/place-icons/basic-building.png',
        'static/default-extension-icon.svg',
    ];

    /**
     * Os três jeitos de o Fleetbase escrever o bucket (virtual host com "." ou "-" antes da região, e o caminho com o
     * bucket depois do host), com as barras normais ou escapadas pelo json_encode ("\/").
     */
    private const URL = '#https?:(\\\\?/)\\\\?/(?:flb-assets\.s3[.-]ap-southeast-1\.amazonaws\.com|s3\.ap-southeast-1\.amazonaws\.com\\\\?/flb-assets)\\\\?/([A-Za-z0-9._\\\\/-]+?\.(?:png|svg))#';

    /** Endereço do console sem a barra final (config fleetbase.console.host = CONSOLE_HOST do stack). */
    public static function console(): string
    {
        $host = (string) (config('fleetbase.console.host') ?: 'https://entregas.restaurantepro.com.br');
        if (!preg_match('#^https?://#', $host)) {
            $host = 'https://' . $host;
        }

        return rtrim($host, '/');
    }

    /** Troca, no texto (JSON ou não), as URLs do bucket que têm cópia local pela URL do console. */
    public static function trocar(string $texto, ?string $console = null): string
    {
        if (!str_contains($texto, 'amazonaws.com')) {
            return $texto;
        }

        $console = rtrim($console ?? static::console(), '/');

        return preg_replace_callback(static::URL, function (array $m) use ($console) {
            $escapada = $m[1] === '\\/';
            $caminho  = str_replace('\\/', '/', $m[2]);
            if (!in_array($caminho, static::ARQUIVOS, true)) {
                return $m[0];
            }

            $url = $console . static::PASTA . $caminho;

            return $escapada ? str_replace('/', '\\/', $url) : $url;
        }, $texto) ?? $texto;
    }

    /** O avatar_url (ou outro atributo) do modelo com a URL local, se for uma imagem do bucket. Para o saving(). */
    public static function corrigirAtributo(object $modelo, string $atributo = 'avatar_url'): void
    {
        $atributos = method_exists($modelo, 'getAttributes') ? $modelo->getAttributes() : [];
        $valor     = $atributos[$atributo] ?? null;
        if (is_string($valor) && $valor !== '') {
            $novo = static::trocar($valor);
            if ($novo !== $valor) {
                $modelo->setAttribute($atributo, $novo);
            }
        }
    }
}
