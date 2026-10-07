<?php

namespace App\Support\Entregas\Enderecos;

use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: regras da busca de endereço pelo Google Places (EnderecosController).
 *
 * - referência (centro da preferência): a posição de quem digita; no portal, o Local da loja; senão, o centro padrão
 *   do mapa (Ribeirão Preto, o mesmo fallback do console);
 * - menos de MINIMO_DE_CARACTERES ou sem chave em Admin → Serviços: lista vazia, sem chamar o Google;
 * - falha do Google: lista vazia (sugestões) ou null (detalhes), com log só do status; a tela segue com o mapa;
 * - detalhes sem coordenadas valem como falha (o local precisa do ponto).
 */
class BuscaDeEnderecos
{
    public const MINIMO_DE_CARACTERES = 3;

    public const MAXIMO_DE_CARACTERES = 200;

    public const LIMITE_DE_SUGESTOES = 5;

    /** Token de sessão vindo da tela (o Google aceita até 36 caracteres); fora do formato, o servidor gera um. */
    public const FORMATO_DA_SESSAO = '/^[A-Za-z0-9_-]{8,36}$/';

    /** Centro padrão do mapa no Entregas (Ribeirão Preto). */
    public const CENTRO_PADRAO = [-21.1775, -47.8103];

    public function __construct(protected ClienteGooglePlaces $google)
    {
    }

    /**
     * [texto sem espaços nas pontas e cortado em MAXIMO_DE_CARACTERES, token de sessão válido]. Valor que não é texto
     * (parâmetro em array) vale como vazio.
     */
    public static function entrada($texto, $sessao): array
    {
        $texto = is_string($texto) ? mb_substr(trim($texto), 0, static::MAXIMO_DE_CARACTERES) : '';
        if (!is_string($sessao) || !preg_match(static::FORMATO_DA_SESSAO, $sessao)) {
            $sessao = bin2hex(random_bytes(16));
        }

        return [$texto, $sessao];
    }

    /** [latitude, longitude] da preferência: a posição enviada, a da loja ou o centro padrão. */
    public static function referencia($latitude, $longitude, ?array $daLoja = null): array
    {
        if (static::coordenadaValida($latitude, $longitude)) {
            return [(float) $latitude, (float) $longitude];
        }

        if ($daLoja && static::coordenadaValida($daLoja[0] ?? null, $daLoja[1] ?? null)) {
            return [(float) $daLoja[0], (float) $daLoja[1]];
        }

        return static::CENTRO_PADRAO;
    }

    /** @return array<int, array{place_id: string, principal: string, secundario: string}> */
    public function sugestoes(string $texto, array $referencia, string $sessao): array
    {
        $texto = trim($texto);
        if (mb_strlen($texto) < static::MINIMO_DE_CARACTERES || !ClienteGooglePlaces::configurado()) {
            return [];
        }

        try {
            $sugestoes = $this->google->sugestoes(mb_substr($texto, 0, static::MAXIMO_DE_CARACTERES), $referencia[0], $referencia[1], $sessao);
        } catch (ErroGooglePlaces $erro) {
            Log::warning("[entregas] endereços: sugestões falharam ({$erro->status})");

            return [];
        }

        return array_slice($sugestoes, 0, static::LIMITE_DE_SUGESTOES);
    }

    /** Atributos do Place (EnderecoBrasileiro) ou null quando o Google falha ou não traz o ponto. */
    public function detalhes(string $placeId, string $sessao, ?string $textoDigitado): ?array
    {
        try {
            $endereco = EnderecoBrasileiro::deDetalhes($this->google->detalhes($placeId, $sessao), $textoDigitado);
        } catch (ErroGooglePlaces $erro) {
            Log::warning("[entregas] endereços: detalhes falharam ({$erro->status})");

            return null;
        }

        if ($endereco['location'] === null) {
            Log::warning('[entregas] endereços: detalhes sem coordenadas');

            return null;
        }

        return $endereco;
    }

    /**
     * As sugestões no formato de Place para o ModelSelect do pedido (rota busca): rua na primeira linha, o resto na
     * segunda, e a marca meta.entregas_sugestao, que o console troca pelos detalhes quando a central escolhe.
     */
    public static function comoLocais(array $sugestoes, string $sessao, string $texto): array
    {
        return array_map(fn (array $sugestao) => [
            'street1' => $sugestao['principal'],
            'street2' => $sugestao['secundario'],
            'meta'    => ['entregas_sugestao' => ['place_id' => $sugestao['place_id'], 'sessao' => $sessao, 'texto' => $texto]],
        ], $sugestoes);
    }

    protected static function coordenadaValida($latitude, $longitude): bool
    {
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return false;
        }

        $latitude  = (float) $latitude;
        $longitude = (float) $longitude;

        return abs($latitude) <= 90 && abs($longitude) <= 180 && !($latitude == 0.0 && $longitude == 0.0);
    }
}
