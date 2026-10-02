<?php

namespace App\Providers;

use App\Http\Controllers\Entregas\PagamentoMotoboysController;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * Where to send an already-authenticated request that hits a guest-only route.
     *
     * App\Http\Middleware\RedirectIfAuthenticated (the `guest` alias in
     * App\Http\Kernel) redirects to this constant. It was dropped when the stock
     * Laravel provider was replaced, so the alias would fatal with "Undefined
     * constant" the moment any route actually used it. No route does today,
     * which is why nothing caught it — the console is served separately, so the
     * only sensible in-app target is the API root.
     */
    public const HOME = '/';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->routes(
            function () {
                Route::get(
                    '/health',
                    function (Request $request) {
                        return response()->json(
                            [
                                'status' => 'ok',
                                'time' => microtime(true) - $request->attributes->get('request_start_time')
                            ]
                        );
                    }
                );

                // Entregas RestaurantePro: pagamento dos motoboys e cobrança das lojas por faixa de km (console → Fleet-Ops → Pagamento e cobrança)
                Route::prefix('int/v1/entregas')
                    ->middleware(['fleetbase.protected'])
                    ->group(function () {
                        Route::get('pagamento-motoboys', [PagamentoMotoboysController::class, 'relatorio']);
                        Route::put('pagamento-motoboys/faixas', [PagamentoMotoboysController::class, 'salvarFaixas']);
                    });
            }
        );
    }
}
