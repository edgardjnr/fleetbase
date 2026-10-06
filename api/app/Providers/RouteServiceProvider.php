<?php

namespace App\Providers;

use App\Http\Controllers\Entregas\ConversasDaLojaController;
use App\Http\Controllers\Entregas\IfoodLojasController;
use App\Http\Controllers\Entregas\IfoodPedidosController;
use App\Http\Controllers\Entregas\LojasController;
use App\Http\Controllers\Entregas\MapaController;
use App\Http\Controllers\Entregas\MotoboyController;
use App\Http\Controllers\Entregas\PagamentoMotoboysController;
use App\Http\Controllers\Entregas\PortalLojaController;
use App\Http\Middleware\AvisarOnlineDoMotoboy;
use App\Http\Middleware\BarrarAceiteDePedidoEncerrado;
use App\Http\Middleware\IncluirNotasNaListaDePedidos;
use App\Http\Middleware\RegrasDoPedidoIfood;
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
        // Entregas RestaurantePro: pedido iFood não se cancela do nosso lado (API v1, console) e, com a trava
        // ENTREGAS_IFOOD_EXIGE_APP_NOVO, só se conclui pela rota concluir-ifood do APK novo (ver RegrasDoPedidoIfood). Antes
        // do BarrarAceiteDePedidoEncerrado: o cancelamento recusado nem pega a trava do pedido
        $this->app['router']->pushMiddlewareToGroup('fleetbase.api', RegrasDoPedidoIfood::class);
        $this->app['router']->pushMiddlewareToGroup('fleetbase.protected', RegrasDoPedidoIfood::class);

        // Entregas RestaurantePro: as listas de pedidos do console (tabela, quadro e painel do mapa) usam o recurso enxuto, sem
        // `notes`; o selo "iFood #N" precisa delas (ver IncluirNotasNaListaDePedidos). Só GET, só nas duas rotas de lista
        $this->app['router']->pushMiddlewareToGroup('fleetbase.protected', IncluirNotasNaListaDePedidos::class);

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

        // Entregas RestaurantePro: dados do pedido iFood no app (cada card de pedido aberto e os detalhes, relidos a cada
        // mudança do pedido), até 120 chamadas por minuto por motoboy, num balde separado do entregas-motoboy: assim a lista
        // de pedidos não gasta o limite do concluir-ifood
        RateLimiter::for('entregas-motoboy-ifood', fn (Request $request) => Limit::perMinute(120)->by('entregas-motoboy-ifood:' . (session('user') ?: $request->ip())));

        // Entregas RestaurantePro: código de entrega do iFood digitado pelo motoboy, até 10 tentativas por minuto por motoboy
        // (contra tentar todos os códigos), além do entregas-motoboy e do teto de erros por pedido (ConclusaoIfood)
        RateLimiter::for('entregas-ifood-codigo', fn (Request $request) => Limit::perMinute(10)->by('entregas-ifood-codigo:' . (session('user') ?: $request->ip())));

        // Entregas RestaurantePro: vínculo da loja com o iFood na tela Lojas (código de vínculo e troca do código de
        // autorização), até 20 chamadas por minuto por usuário, para um clique repetido não martelar o iFood
        RateLimiter::for('entregas-ifood-vinculo', fn (Request $request) => Limit::perMinute(20)->by('entregas-ifood-vinculo:' . (session('user') ?: $request->ip())));

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
                        // vínculo da loja com o iFood: código de vínculo, troca do código de autorização (ou escolha da loja) e desvínculo
                        Route::post('lojas/{id}/ifood/codigo', [IfoodLojasController::class, 'codigo'])->middleware('throttle:entregas-ifood-vinculo');
                        Route::post('lojas/{id}/ifood/vincular', [IfoodLojasController::class, 'vincular'])->middleware('throttle:entregas-ifood-vinculo');
                        Route::delete('lojas/{id}/ifood', [IfoodLojasController::class, 'desvincular']);
                        // painel iFood no detalhe do pedido e a liberação da conclusão sem o código do cliente
                        Route::get('pedidos/{id}/ifood', [IfoodPedidosController::class, 'painel']);
                        Route::post('pedidos/{id}/ifood/liberar-sem-codigo', [IfoodPedidosController::class, 'liberarSemCodigo']);

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
                        // pedido iFood: conclusão e código de entrega do cliente (os dados ficam no grupo de baixo)
                        Route::post('pedidos/{id}/concluir-ifood', [MotoboyController::class, 'concluirIfood']);
                        Route::post('pedidos/{id}/codigo-ifood', [MotoboyController::class, 'codigoIfood'])->middleware('throttle:entregas-ifood-codigo');
                    });

                // Entregas RestaurantePro: traçado loja → cliente e situação do motoboy no mapa do pedido do app (MotoboyController@rota)
                Route::prefix('v1/entregas/motoboy')
                    ->middleware(['fleetbase.api', 'throttle:entregas-motoboy-rota'])
                    ->group(function () {
                        Route::get('pedidos/{id}/rota', [MotoboyController::class, 'rota']);
                    });

                // Entregas RestaurantePro: dados do pedido iFood no app (card de aceitar e detalhes; MotoboyController@ifood)
                Route::prefix('v1/entregas/motoboy')
                    ->middleware(['fleetbase.api', 'throttle:entregas-motoboy-ifood'])
                    ->group(function () {
                        Route::get('pedidos/{id}/ifood', [MotoboyController::class, 'ifood']);
                    });
            }
        );
    }
}
