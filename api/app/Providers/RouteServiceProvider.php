<?php

namespace App\Providers;

use App\Http\Controllers\Entregas\LojasController;
use App\Http\Controllers\Entregas\PagamentoMotoboysController;
use App\Http\Controllers\Entregas\PortalLojaController;
use App\Http\Middleware\BarrarAceiteDePedidoEncerrado;
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
        // Entregas RestaurantePro: aceite do motoboy (v1/orders/{id}/start) de pedido encerrado é barrado. O core
        // preenche o grupo fleetbase.api no boot() dele, e os pacotes sobem antes dos providers do app: este entra
        // no fim do grupo, depois da autenticação (AuthenticateOnceWithBasicAuth) e com a sessão da empresa montada
        $this->app['router']->pushMiddlewareToGroup('fleetbase.api', BarrarAceiteDePedidoEncerrado::class);

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

                        // cadastro das lojas e dos usuários do portal (Fleet-Ops → Recursos → Lojas)
                        Route::get('lojas', [LojasController::class, 'index']);
                        Route::post('lojas', [LojasController::class, 'store']);
                        Route::put('lojas/{id}', [LojasController::class, 'update']);
                        Route::post('lojas/{id}/usuarios', [LojasController::class, 'adicionarUsuario']);
                        Route::put('lojas/{id}/usuarios/{contato}/senha', [LojasController::class, 'trocarSenha']);
                        Route::put('lojas/{id}/usuarios/{contato}/ativo', [LojasController::class, 'alterarAcesso']);

                        // portal da loja (usuário type=customer; o ProtegerPortalLoja só libera estas), até 60 chamadas por minuto por usuário
                        Route::get('loja/minha-loja', [PortalLojaController::class, 'minhaLoja'])->middleware('throttle:60,1');
                        Route::get('loja/extrato', [PortalLojaController::class, 'extrato'])->middleware('throttle:60,1');
                        Route::get('loja/pedidos/{id}/motoboy', [PortalLojaController::class, 'motoboy'])->middleware('throttle:60,1');
                    });
            }
        );
    }
}
