<?php

namespace App\Support\Entregas\Integracao;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: reserva para o pedido que chega sem coordenadas. Uma consulta ao Google Geocoding (paga, na
 * chave de Admin → Serviços, `services.google_maps.api_key`, a mesma da busca de endereço), sem o Place::createFromMixed
 * do Fleet-Ops (que geocodifica sempre). O RestaurantePro manda as coordenadas, então isso é para terceiros e para a
 * falha de geocodificação dele. Sem chave, sem resultado ou Google fora do ar: null (o pedido nasce sem localização e
 * a central atribui). O log não leva o endereço nem a chave.
 */
final class GeocodificadorDaIntegracao
{
    public const URL = 'https://maps.googleapis.com/maps/api/geocode/json';

    /** @return array{0: float, 1: float}|null [latitude, longitude] */
    public function coordenadas(array $dados): ?array
    {
        $chave = (string) config('services.google_maps.api_key');
        if ($chave === '') {
            return null;
        }

        $partes   = [$dados['endereco'] ?? null, $dados['bairro'] ?? null, $dados['cidade'] ?? null, $dados['uf'] ?? null, $dados['cep'] ?? null, 'Brasil'];
        $endereco = implode(', ', array_filter($partes, fn ($parte) => is_string($parte) && $parte !== ''));

        try {
            $resposta = Http::timeout(5)->get(static::URL, ['address' => $endereco, 'region' => 'br', 'language' => 'pt-BR', 'key' => $chave]);
        } catch (\Throwable $e) {
            Log::warning('[entregas] integração: geocodificação falhou', ['erro' => get_class($e)]);

            return null;
        }

        $resultados = $resposta->successful() && $resposta->json('status') === 'OK' ? $resposta->json('results') : null;
        $local      = is_array($resultados) ? ($resultados[0]['geometry']['location'] ?? null) : null;
        if (!is_array($local) || !is_numeric($local['lat'] ?? null) || !is_numeric($local['lng'] ?? null)) {
            Log::warning('[entregas] integração: endereço não geocodificado', ['status' => $resposta->status(), 'google' => (string) $resposta->json('status')]);

            return null;
        }

        Log::info('[entregas] integração: endereço geocodificado');

        return [(float) $local['lat'], (float) $local['lng']];
    }
}
