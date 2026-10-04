<?php

namespace App\Support\Entregas;

use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: o motoboy da requisição nas rotas do app do motoboy (MotoboyController, API v1).
 *
 * Vem do token, nunca de parâmetro: o Driver da empresa da sessão cujo usuário é o da sessão (o
 * AuthenticateOnceWithBasicAuth do core grava `company` e `user` na sessão ao autenticar). Só vale token de usuário
 * (Sanctum, `id|token`): uma chave de API (a `flb_live_` do APK, a da integração iFood) autentica como o admin que a
 * criou, e esse admin pode ter cadastro de motoboy.
 */
class MotoboyDaSessao
{
    public static function motoboy(Request $request): ?Driver
    {
        // chave de API não tem "|"; o token que o login do motoboy devolve é "id|token" (plainTextToken do Sanctum)
        if (!str_contains((string) $request->bearerToken(), '|')) {
            return null;
        }

        $usuario = session('user');
        $empresa = session('company');
        if (!$usuario || !$empresa) {
            return null;
        }

        return Driver::where('company_uuid', $empresa)->where('user_uuid', $usuario)->first();
    }
}
