<?php

namespace App\Support\Entregas;

use Illuminate\Support\Collection;

/**
 * Entregas RestaurantePro: geocoder que nunca consulta ninguém. Toda consulta encadeada (reverse, geocode, limit,
 * using…) devolve ele mesmo, e o get() devolve uma lista vazia, como uma busca sem resultado.
 *
 * Fica no lugar da facade Geocoder (geocoder-laravel) durante o track() do motoboy: ver
 * DriverControllerSemGeocodificacao.
 */
class GeocoderDesligado
{
    public function get(): Collection
    {
        return collect();
    }

    public function __call($metodo, $argumentos)
    {
        return $this;
    }
}
