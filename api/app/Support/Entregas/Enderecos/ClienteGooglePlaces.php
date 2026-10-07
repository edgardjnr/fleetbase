<?php

namespace App\Support\Entregas\Enderecos;

use Closure;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Entregas RestaurantePro: única porta HTTP para a Places API (New) do Google (busca de endereço do console e do portal).
 *
 * A chave é a de Admin → Serviços → Google Maps, lida a cada chamada em `services.google_maps.api_key` (o core mescla as
 * configurações do banco a cada requisição; não depende do config:cache). Ela fica no servidor, restrita ao IP da VPS.
 * Sugestões: POST places:autocomplete, só Brasil, pt-BR, com preferência num raio em volta da referência. Detalhes: GET
 * places/{id} só com os campos usados (FieldMask). As duas levam o token de sessão: o Google cobra as sugestões de uma
 * sessão que termina em detalhes como uma busca só.
 *
 * Toda resposta fora de 2xx vira ErroGooglePlaces com o status; falha de rede ou sem chave, status 0. Não registra nada
 * no log (quem chama registra só o status, nunca o texto digitado nem a chave).
 */
class ClienteGooglePlaces
{
    public const BASE = 'https://places.googleapis.com/v1';

    /** Segundos de espera por resposta (a pessoa está digitando). */
    public const TEMPO_LIMITE = 8;

    /** Raio da preferência em volta de quem digita, em metros (o Google aceita até 50 km). */
    public const RAIO_EM_METROS = 30000.0;

    public const CAMPOS_DOS_DETALHES = 'id,formattedAddress,addressComponents,location';

    public static function chave(): string
    {
        return (string) config('services.google_maps.api_key');
    }

    public static function configurado(): bool
    {
        return static::chave() !== '';
    }

    /** @return array<int, array{place_id: string, principal: string, secundario: string}> */
    public function sugestoes(string $texto, float $latitude, float $longitude, string $sessao): array
    {
        $corpo = [
            'input'               => $texto,
            'includedRegionCodes' => ['br'],
            'languageCode'        => 'pt-BR',
            'regionCode'          => 'br',
            'locationBias'        => ['circle' => ['center' => ['latitude' => $latitude, 'longitude' => $longitude], 'radius' => static::RAIO_EM_METROS]],
            'sessionToken'        => $sessao,
        ];

        $resposta = $this->enviar(fn () => Http::withHeaders(['X-Goog-Api-Key' => static::chave()])
            ->acceptJson()
            ->timeout(static::TEMPO_LIMITE)
            ->post(static::BASE . '/places:autocomplete', $corpo));

        $sugestoes = [];
        foreach ((array) $resposta->json('suggestions', []) as $item) {
            $previsao = $item['placePrediction'] ?? null;
            if (!is_array($previsao) || empty($previsao['placeId'])) {
                continue;
            }

            $sugestoes[] = [
                'place_id'   => (string) $previsao['placeId'],
                'principal'  => (string) ($previsao['structuredFormat']['mainText']['text'] ?? $previsao['text']['text'] ?? ''),
                'secundario' => (string) ($previsao['structuredFormat']['secondaryText']['text'] ?? ''),
            ];
        }

        return $sugestoes;
    }

    public function detalhes(string $placeId, string $sessao): array
    {
        $resposta = $this->enviar(fn () => Http::withHeaders([
            'X-Goog-Api-Key'   => static::chave(),
            'X-Goog-FieldMask' => static::CAMPOS_DOS_DETALHES,
        ])
            ->acceptJson()
            ->timeout(static::TEMPO_LIMITE)
            ->get(static::BASE . '/places/' . rawurlencode($placeId), ['languageCode' => 'pt-BR', 'regionCode' => 'br', 'sessionToken' => $sessao]));

        return (array) $resposta->json();
    }

    protected function enviar(Closure $chamada): Response
    {
        if (!static::configurado()) {
            throw new ErroGooglePlaces(0, 'chave do Google não configurada');
        }

        try {
            $resposta = $chamada();
        } catch (ConnectionException|TransferException) {
            throw new ErroGooglePlaces(0, 'falha de rede');
        }

        if (!$resposta->successful()) {
            throw new ErroGooglePlaces($resposta->status());
        }

        return $resposta;
    }
}
