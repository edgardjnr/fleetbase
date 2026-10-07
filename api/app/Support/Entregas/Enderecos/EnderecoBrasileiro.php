<?php

namespace App\Support\Entregas\Enderecos;

/**
 * Entregas RestaurantePro: o endereço do Google (detalhes da Places API New) nos atributos do Place, no formato
 * brasileiro. O Fleet-Ops grava a rua no padrão dos EUA ("45 Rua Olinda") e a cidade pela `locality`, que o Google
 * quase nunca devolve no Brasil; aqui a rua vem antes do número e a cidade vem da `administrative_area_level_2`.
 *
 * Funções puras (testadas em scripts/teste-php/enderecos.php).
 */
class EnderecoBrasileiro
{
    /**
     * Atributos do Place a partir dos detalhes (addressComponents, location, formattedAddress). Sem `street_number` no
     * Google, usa o número que a pessoa digitou na busca ($textoDigitado).
     */
    public static function deDetalhes(array $detalhes, ?string $textoDigitado = null): array
    {
        $partes    = static::componentes((array) ($detalhes['addressComponents'] ?? []));
        $rua       = $partes['route'] ?? null;
        $numero    = $partes['street_number'] ?? ($textoDigitado ? static::numeroDigitado($textoDigitado, $rua) : null);
        $latitude  = $detalhes['location']['latitude'] ?? null;
        $longitude = $detalhes['location']['longitude'] ?? null;

        return [
            'street1'      => $rua ? ($numero ? "{$rua}, {$numero}" : $rua) : static::primeiraParte((string) ($detalhes['formattedAddress'] ?? '')),
            'neighborhood' => $partes['sublocality_level_1'] ?? $partes['sublocality'] ?? $partes['neighborhood'] ?? null,
            'city'         => $partes['administrative_area_level_2'] ?? $partes['locality'] ?? null,
            'province'     => $partes['administrative_area_level_1_curto'] ?? null,
            'postal_code'  => $partes['postal_code'] ?? null,
            'country'      => $partes['country_curto'] ?? 'BR',
            'location'     => is_numeric($latitude) && is_numeric($longitude)
                ? ['type' => 'Point', 'coordinates' => [(float) $longitude, (float) $latitude]]
                : null,
        ];
    }

    /**
     * O número da casa no texto digitado: o primeiro número que não faz parte do nome da rua ("Rua 7 de Setembro"),
     * ignorando o CEP. "45a" vira "45A".
     */
    public static function numeroDigitado(string $texto, ?string $rua = null): ?string
    {
        $semCep = (string) preg_replace('/\b\d{5}-?\d{3}\b/', ' ', $texto);
        preg_match_all('/(?<![\w-])(\d{1,5}[A-Za-z]?)(?![\w-])/u', $semCep, $achados);
        $numerosDaRua = $rua && preg_match_all('/\d+/', $rua, $daRua) ? $daRua[0] : [];

        foreach ($achados[1] as $numero) {
            if (!in_array((string) preg_replace('/\D/', '', $numero), $numerosDaRua, true)) {
                return strtoupper($numero);
            }
        }

        return null;
    }

    /** tipo => texto longo; o texto curto fica em "<tipo>_curto" (UF e país). O primeiro componente de cada tipo vale. */
    protected static function componentes(array $componentes): array
    {
        $partes = [];
        foreach ($componentes as $componente) {
            foreach ((array) ($componente['types'] ?? []) as $tipo) {
                if (!array_key_exists($tipo, $partes)) {
                    $partes[$tipo]            = $componente['longText'] ?? null;
                    $partes[$tipo . '_curto'] = $componente['shortText'] ?? null;
                }
            }
        }

        return $partes;
    }

    /** "Shopping Santa Úrsula - Jardim Sumaré, Ribeirão Preto - SP" → "Shopping Santa Úrsula". */
    protected static function primeiraParte(string $formatado): ?string
    {
        $parte = trim(explode(' - ', $formatado)[0]);

        return $parte !== '' ? $parte : null;
    }
}
