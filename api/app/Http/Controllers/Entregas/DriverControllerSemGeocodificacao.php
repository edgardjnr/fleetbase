<?php

namespace App\Http\Controllers\Entregas;

use App\Support\Entregas\FiltroDePosicaoDoMotoboy;
use App\Support\Entregas\GeocoderDesligado;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController;
use Geocoder\Laravel\Facades\Geocoder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: o Api\v1\DriverController do Fleet-Ops com o track() sem geocodificação e sem posição
 * velha ou grosseira. Trocado no container pelo AppServiceProvider (a rota e o Internal\v1\DriverController@track
 * resolvem o controller por lá).
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
 * Antes dele, a posição passa pelo FiltroDePosicaoDoMotoboy (anterior à última aceita ou grosseira com uma boa
 * recente = descartada; a última aceita fica no cache por motoboy). A descartada não grava nada nem vai ao socket, e a
 * resposta é a de sempre (200 com o motoboy): o plugin de GPS reenviaria uma posição recusada com erro. Duas posições
 * do mesmo celular ao mesmo tempo podem passar as duas (sem trava): o plugin manda uma por vez.
 *
 * Ao atualizar o fleetops-api, confira se o track() ainda geocodifica só pelo Geocoder::reverse e se o findDriver, o
 * driverResource e o apiError continuam no DriverController (o scripts/teste-php/track-sem-geocodificacao.php confere
 * na cópia em packages/fleetops).
 */
class DriverControllerSemGeocodificacao extends DriverController
{
    public function track(string $id, Request $request)
    {
        // sem latitude e longitude o original só devolve o motoboy: nada a filtrar
        if (empty((float) $request->input('latitude')) && empty((float) $request->input('longitude'))) {
            return $this->trackSemGeocodificacao($id, $request);
        }

        $chave = FiltroDePosicaoDoMotoboy::CHAVE . $id;

        // cache fora do ar: a posição passa sem filtro (nunca derrubar a posição do motoboy por causa do filtro)
        try {
            $ultima = Cache::get($chave);
        } catch (\Throwable $e) {
            $ultima = null;
        }

        $avaliacao = FiltroDePosicaoDoMotoboy::avaliar(
            is_array($ultima) ? $ultima : null,
            FiltroDePosicaoDoMotoboy::instante($request->input('timestamp')),
            FiltroDePosicaoDoMotoboy::precisao($request->input('accuracy')),
            (int) floor(microtime(true) * 1000)
        );

        if (!$avaliacao['aceita']) {
            Log::info('[entregas] posição do motoboy descartada (' . $avaliacao['motivo'] . ')', [
                'motoboy'  => $id,
                'precisao' => FiltroDePosicaoDoMotoboy::precisao($request->input('accuracy')),
            ]);

            try {
                return $this->driverResource($this->findDriver($id));
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
                return $this->apiError('Driver resource not found.', 404);
            }
        }

        $resposta = $this->trackSemGeocodificacao($id, $request);

        try {
            Cache::put($chave, $avaliacao['ultima'], FiltroDePosicaoDoMotoboy::GUARDA_S);
        } catch (\Throwable $e) {
            Log::warning('[entregas] posição do motoboy: cache da última posição falhou: ' . get_class($e), ['motoboy' => $id]);
        }

        return $resposta;
    }

    private function trackSemGeocodificacao(string $id, Request $request)
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
