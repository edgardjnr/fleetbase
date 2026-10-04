<?php

namespace App\Providers;

use App\Http\Controllers\Entregas\ConversasDaLojaController;
use App\Http\Controllers\Entregas\LojasController;
use App\Http\Controllers\Entregas\MapaController;
use App\Http\Controllers\Entregas\MotoboyController;
use App\Http\Controllers\Entregas\PagamentoMotoboysController;
use App\Http\Controllers\Entregas\PortalLojaController;
use App\Http\Middleware\AvisarOnlineDoMotoboy;
use App\Http\Middleware\BarrarAceiteDePedidoEncerrado;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // Entregas RestaurantePro: aceite do motoboy (v1/orders/{id}/start) de pedido encerrado é barrado, e o
        // cancelamento da API v1 roda com a mesma trava do aceite. O core preenche o grupo fleetbase.api no boot()
        // dele, e os pacotes sobem antes dos providers do app: este entra no fim do grupo, depois da autenticação
        // (AuthenticateOnceWithBasicAuth) e com a sessão da empresa montada
        $this->app['router']->pushMiddlewareToGroup('fleetbase.api', BarrarAceiteDePedidoEncerrado::class);

        // Entregas RestaurantePro: o motoboy ligou/desligou o online no app → aviso no socket da empresa, para o mapa
        // ao vivo trocar o capacete na hora (o toggle-online do Fleet-Ops grava sem disparar evento)
        $this->app['router']->pushMiddlewareToGroup('fleetbase.api', AvisarOnlineDoMotoboy::class);

        // Entregas RestaurantePro: rotas do app do motoboy, até 60 chamadas por minuto por usuário. O throttle do grupo
        // fleetbase.api conta por IP (roda antes da autenticação); este roda depois dela, com o usuário na sessão
        RateLimiter::for('entregas-motoboy', fn (Request $request) => Limit::perMinute(60)->by('entregas-motoboy:' . (session('user') ?: $request->ip())));

        // Entregas RestaurantePro: mapa de motoboys do portal da loja (cada aba com o mapa aberto consulta a cada 5 s), até 60
        // chamadas por minuto por usuário, num balde separado do throttle:60,1 que as outras rotas da loja dividem
        RateLimiter::for('entregas-loja-mapa', fn (Request $request) => Limit::perMinute(60)->by('entregas-loja-mapa:' . (session('user') ?: $request->ip())));

        // Entregas RestaurantePro: chat da loja com os motoboys no portal (a lista a cada 20 s e a conversa aberta a cada 5 s, por
        // aba), até 120 chamadas por minuto por usuário, num balde separado das outras rotas da loja
        RateLimiter::for('entregas-loja-conversas', fn (Request $request) => Limit::perMinute(120)->by('entregas-loja-conversas:' . (session('user') ?: $request->ip())));

        // Entregas RestaurantePro: traçado da rota no mapa do pedido do app (cada card de pedido da lista pede o seu), até 120
        // chamadas por minuto por motoboy, num balde separado do entregas-motoboy (ganhos e valor)
        RateLimiter::for('entregas-motoboy-rota', fn (Request $request) => Limit::perMinute(120)->by('entregas-motoboy-rota:' . (session('user') ?: $request->ip())));

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

                        // mapa ao vivo do console: só os locais de coleta (lojas) e a situação de cada motoboy (cor do capacete)
                        Route::get('mapa/locais-de-coleta', [MapaController::class, 'locaisDeColeta']);
                        Route::get('mapa/motoboys', [MapaController::class, 'motoboys']);

                        // portal da loja (usuário type=customer; o ProtegerPortalLoja só libera estas), até 60 chamadas por minuto por usuário
                        Route::get('loja/minha-loja', [PortalLojaController::class, 'minhaLoja'])->middleware('throttle:60,1');
                        Route::get('loja/extrato', [PortalLojaController::class, 'extrato'])->middleware('throttle:60,1');
                        Route::get('loja/pedidos/{id}/motoboy', [PortalLojaController::class, 'motoboy'])->middleware('throttle:60,1');
                        Route::get('loja/motoboys', [PortalLojaController::class, 'motoboysNoMapa'])->middleware('throttle:entregas-loja-mapa');

                        // chat da loja com os motoboys (a central participa; o motoboy conversa pelo app)
                        Route::middleware('throttle:entregas-loja-conversas')->group(function () {
                            Route::get('loja/conversas', [ConversasDaLojaController::class, 'index']);
                            Route::post('loja/conversas', [ConversasDaLojaController::class, 'abrir']);
                            Route::get('loja/conversas/{id}/mensagens', [ConversasDaLojaController::class, 'mensagens']);
                            Route::post('loja/conversas/{id}/mensagens', [ConversasDaLojaController::class, 'enviar']);
                        });
                    });

                // Entregas RestaurantePro: ganhos do motoboy no app (tela Início, card de aceitar e detalhes), na API v1 com o
                // token dele (MotoboyController; só token de motoboy, nunca com o valor cobrado da loja)
                Route::prefix('v1/entregas/motoboy')
                    ->middleware(['fleetbase.api', 'throttle:entregas-motoboy'])
                    ->group(function () {
                        Route::get('ganhos', [MotoboyController::class, 'ganhos']);
                        Route::get('pedidos/{id}/valor', [MotoboyController::class, 'valor']);
                        // conversa do motoboy com a central (botão "Chat" do cliente nos detalhes do pedido)
                        Route::post('chat-central', [MotoboyController::class, 'chatComACentral']);
                        // conversa do motoboy com a loja do pedido (ou com a central, em pedido sem loja)
                        Route::post('pedidos/{id}/chat', [MotoboyController::class, 'chatDoPedido']);
                    });

                // Entregas RestaurantePro: traçado loja → cliente e situação do motoboy no mapa do pedido do app (MotoboyController@rota)
                Route::prefix('v1/entregas/motoboy')
                    ->middleware(['fleetbase.api', 'throttle:entregas-motoboy-rota'])
                    ->group(function () {
                        Route::get('pedidos/{id}/rota', [MotoboyController::class, 'rota']);
                    });
            }
        );
    }
}
