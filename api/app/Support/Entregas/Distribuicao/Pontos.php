<?php

namespace App\Support\Entregas\Distribuicao;

/** Pontos como [lat, lng] e a linha reta entre eles (Haversine). */
class Pontos
{
    /** [lat, lng] de um Point do Fleet-Ops (getLat/getLng), ou null se não é um ponto válido (0,0 e fora da faixa não valem). */
    public static function de($ponto): ?array
    {
        if (!is_object($ponto) || !method_exists($ponto, 'getLat') || !method_exists($ponto, 'getLng')) {
            return null;
        }
        $lat = (float) $ponto->getLat();
        $lng = (float) $ponto->getLng();
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat === 0.0 && $lng === 0.0)) {
            return null;
        }

        return [$lat, $lng];
    }

    public static function metros(array $a, array $b): float
    {
        $raio = 6371000.0;
        $dLat = deg2rad($b[0] - $a[0]);
        $dLng = deg2rad($b[1] - $a[1]);
        $h    = sin($dLat / 2) ** 2 + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * sin($dLng / 2) ** 2;

        return 2 * $raio * asin(min(1.0, sqrt($h)));
    }

    /** Segundos pela linha reta × FATOR_LINHA_RETA a 25 km/h (a reserva quando o OSRM falha). */
    public static function segundos(array $a, array $b): int
    {
        return (int) round(static::metros($a, $b) * Distribuicao::FATOR_LINHA_RETA / Distribuicao::METROS_POR_SEGUNDO);
    }
}
