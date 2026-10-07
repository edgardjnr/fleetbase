<?php

namespace App\Http\Controllers\Entregas;

use App\Support\Entregas\GeocoderDesligado;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController;
use Geocoder\Laravel\Facades\Geocoder;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: o Api\v1\DriverController do Fleet-Ops com o track() sem geocodificação. Trocado no
 * container pelo AppServiceProvider (a rota e o Internal\v1\DriverController@track resolvem o controller por lá).
 *
 * O track() original (POST v1/drivers/{id}/track, cada posição do app do motoboy) faz um Geocoder::reverse no Google
 * quando a cidade do motoboy está vazia ou a última atualização tem mais de 10 min. No Brasil o Google não devolve a
 * `locality`, a cidade nunca era gravada e cada posição virava uma consulta paga (~16 mil em 5 dias, out/2026). A
 * cidade do motoboy não aparece em nenhuma tela do Entregas.
 *
 * Aqui o track() é o do Fleet-Ops, com o geocoder trocado por um desligado só durante a chamada: posição, veículo,
 * socket e cercas seguem iguais. No fim (inclusive com exceção), a facade e o container voltam a resolver o geocoder
 * de verdade, que continua valendo para a busca de endereços.
 *
 * Ao atualizar o fleetops-api, confira se o track() ainda geocodifica só pelo Geocoder::reverse (o
 * scripts/teste-php/track-sem-geocodificacao.php confere na cópia em packages/fleetops).
 */
class DriverControllerSemGeocodificacao extends DriverController
{
    public function track(string $id, Request $request)
    {
        Geocoder::swap(new GeocoderDesligado());

        try {
            return parent::track($id, $request);
        } finally {
            Geocoder::clearResolvedInstance('geocoder');
            app()->forgetInstance('geocoder');
        }
    }
}
