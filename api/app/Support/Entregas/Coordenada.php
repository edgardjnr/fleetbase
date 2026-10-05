<?php

namespace App\Support\Entregas;

/**
 * Entregas RestaurantePro: coordenada que dá para pôr no mapa. Números dentro da faixa e fora do (0, 0), que é o "sem
 * GPS" do Fleetbase (mesmo critério do CalculoEntregas). Usada pelos capacetes do portal (MotoboysNoMapaDaLoja) e pelos
 * alfinetes dos pedidos (PedidosNoMapa).
 */
class Coordenada
{
    public static function valida($latitude, $longitude): bool
    {
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return false;
        }

        $latitude  = (float) $latitude;
        $longitude = (float) $longitude;

        if (abs($latitude) > 90 || abs($longitude) > 180) {
            return false;
        }

        return !(abs($latitude) <= 0.0001 && abs($longitude) <= 0.0001);
    }
}
