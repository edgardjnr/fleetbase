<?php

namespace App\Http\Middleware;

use App\Events\Entregas\OnlineDoMotoboyMudou;
use Closure;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController;
use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: quando o motoboy liga ou desliga o online no app (POST v1/drivers/{id}/toggle-online,
 * DriverController@toggleOnline do Fleet-Ops), avisa o canal da empresa no socket (OnlineDoMotoboyMudou). O mapa ao
 * vivo do console relê a situação dos motoboys e troca o capacete (verde ↔ cinza) na hora. O Fleet-Ops grava o
 * online com updateQuietly, que não dispara o evento driver.updated.
 *
 * Fica no fim do grupo `fleetbase.api` (RouteServiceProvider), depois da autenticação e com a sessão da empresa.
 * Só age depois da resposta de sucesso e nunca a derruba: falha no socket vira aviso no log.
 * Ao atualizar o fleetops-api, confira se a ação ainda se chama DriverController@toggleOnline.
 */
class AvisarOnlineDoMotoboy
{
    public const ACAO = DriverController::class . '@toggleOnline';

    public function handle(Request $request, Closure $next)
    {
        $resposta = $next($request);

        $rota = $request->route();
        $acao = $rota instanceof Route ? ltrim($rota->getActionName(), '\\') : null;
        if ($acao !== static::ACAO || $resposta->getStatusCode() >= 300 || !session('company')) {
            return $resposta;
        }

        try {
            $id      = (string) $request->route('id');
            $motoboy = Driver::where('company_uuid', session('company'))
                ->where(fn ($q) => $q->where('public_id', $id)->orWhere('uuid', $id))
                ->first(['uuid', 'public_id', 'company_uuid', 'online']);

            if ($motoboy) {
                broadcast(new OnlineDoMotoboyMudou($motoboy->company_uuid, $motoboy->uuid, $motoboy->public_id, (bool) $motoboy->online));
            }
        } catch (\Throwable $e) {
            Log::warning('[entregas] aviso de online do motoboy não chegou ao socket', ['motoboy' => $request->route('id'), 'erro' => $e->getMessage()]);
        }

        return $resposta;
    }
}
