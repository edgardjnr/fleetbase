<?php

namespace App\Providers;

use App\Http\Controllers\Entregas\CarteiraDaLojaController;
use App\Http\Controllers\Entregas\ChamarMotoboyController;
use App\Http\Controllers\Entregas\ComprovantesController;
use App\Http\Controllers\Entregas\ConversasDaLojaController;
use App\Http\Controllers\Entregas\CorridasDoSistemaController;
use App\Http\Controllers\Entregas\DistribuicaoController;
use App\Http\Controllers\Entregas\EnderecosController;
use App\Http\Controllers\Entregas\FinanceiroLojasController;
use App\Http\Controllers\Entregas\FinanceiroController;
use App\Http\Controllers\Entregas\IfoodLojasController;
use App\Http\Controllers\Entregas\IfoodPedidosController;
use App\Http\Controllers\Entregas\IntegracaoController;
use App\Http\Controllers\Entregas\IntegracaoLojasController;
use App\Http\Controllers\Entregas\IntegracoesDaLojaController;
use App\Http\Controllers\Entregas\LiderController;
use App\Http\Controllers\Entregas\LojasController;
use App\Http\Controllers\Entregas\MapaController;
use App\Http\Controllers\Entregas\MotoboyController;
use App\Http\Controllers\Entregas\MultiplicadoresController;
use App\Http\Controllers\Entregas\PagamentoMotoboysController;
use App\Http\Controllers\Entregas\PedidoAbertoController;
use App\Http\Controllers\Entregas\PainelInicialController;
use App\Http\Controllers\Entregas\PortalLojaController;
use App\Http\Controllers\Entregas\SaquePixController;
use App\Http\Controllers\Entregas\SaquesController;
use App\Http\Controllers\Entregas\ValidacaoAsaasController;
use App\Http\Middleware\AvisarOnlineDoMotoboy;
use App\Http\Middleware\BarrarAceiteDePedidoEncerrado;
use App\Http\Middleware\BarrarOrganizacaoParaNaoAdmin;
use App\Http\Middleware\ExigirAppAtualizado;
use App\Http\Middleware\FiltrarPedidosAbertosDoMotoboy;
use App\Http\Middleware\IncluirNotasNaListaDePedidos;
use App\Http\Middleware\ProtegerPortalLoja;
use App\Http\Middleware\RegrasDoPedidoIfood;
use App\Support\Entregas\Financeiro\Pagamentos;
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
        // Entregas RestaurantePro: trava "Atualize o app" (ExigirAppAtualizado, AppDoMotoboy). O primeiro dos nossos no grupo
        // fleetbase.api: o app desatualizado é barrado antes das outras regras (aceite, iFood, lista de pedidos)
        $this->app['router']->pushMiddlewareToGroup('fleetbase.api', ExigirAppAtualizado::class);

        // Entregas RestaurantePro: pedido iFood não se cancela do nosso lado (API v1, console) e, com a trava
        // ENTREGAS_IFOOD_EXIGE_APP_NOVO, só se conclui pela rota concluir-ifood do APK novo (ver RegrasDoPedidoIfood). Antes
        // do BarrarAceiteDePedidoEncerrado: o cancelamento recusado nem pega a trava do pedido
        $this->app['router']->pushMiddlewareToGroup('fleetbase.api', RegrasDoPedidoIfood::class);
        $this->app['router']->pushMiddlewareToGroup('fleetbase.protected', RegrasDoPedidoIfood::class);

        // Entregas RestaurantePro: as listas de pedidos do console (tabela, quadro e painel do mapa) usam o recurso enxuto, sem
        // `notes`; o selo "iFood #N" precisa delas (ver IncluirNotasNaListaDePedidos). Só GET, só nas duas rotas de lista
        $this->app['router']->pushMiddlewareToGroup('fleetbase.protected', IncluirNotasNaListaDePedidos::class);

        // Entregas RestaurantePro: criar e entrar em organização (auth/create-organization e auth/join-organization) só para o
        // administrador do sistema. No fim do grupo: depois da autenticação, com o usuário da sessão (ver
        // BarrarOrganizacaoParaNaoAdmin)
        $this->app['router']->pushMiddlewareToGroup('fleetbase.protected', BarrarOrganizacaoParaNaoAdmin::class);

        // Entregas RestaurantePro: aceite do motoboy (v1/orders/{id}/start) de pedido encerrado é barrado, e o
        // cancelamento da API v1 roda com a mesma trava do aceite. O core preenche o grupo fleetbase.api no boot()
        // dele, e os pacotes sobem antes dos providers do app: este entra no fim do grupo, depois da autenticação
        // (AuthenticateOnceWithBasicAuth) e com a sessão da empresa montada
        $this->app['router']->pushMiddlewareToGroup('fleetbase.api', BarrarAceiteDePedidoEncerrado::class);

        // Entregas RestaurantePro: o motoboy ligou/desligou o online no app → aviso no socket da empresa, para o mapa
        // ao vivo trocar o capacete na hora (o toggle-online do Fleet-Ops grava sem disparar evento)
        $this->app['router']->pushMiddlewareToGroup('fleetbase.api', AvisarOnlineDoMotoboy::class);

        // distribuição de pedidos abertos: a lista "Novos pedidos" do app só com a oferta dele e os abertos a todos
        $this->app['router']->pushMiddlewareToGroup('fleetbase.api', FiltrarPedidosAbertosDoMotoboy::class);

        // Entregas RestaurantePro: rotas do app do motoboy, até 60 chamadas por minuto por usuário. O throttle do grupo
        // fleetbase.api conta por IP (roda antes da autenticação); este roda depois dela, com o usuário na sessão
        RateLimiter::for('entregas-motoboy', fn (Request $request) => Limit::perMinute(60)->by('entregas-motoboy:' . (session('user') ?: $request->ip())));

        // Entregas RestaurantePro: mapa de motoboys do portal da loja (cada aba com o mapa aberto consulta a cada 5 s), até 60
        // chamadas por minuto por usuário, num balde separado do throttle:60,1 que as outras rotas da loja dividem
        RateLimiter::for('entregas-loja-mapa', fn (Request $request) => Limit::perMinute(60)->by('entregas-loja-mapa:' . (session('user') ?: $request->ip())));

        // Entregas RestaurantePro: buzina do portal da loja (cada aba do portal lê os aceites a cada 10 s, em qualquer tela),
        // até 30 chamadas por minuto por usuário, num balde separado do throttle:60,1 das outras rotas da loja
        RateLimiter::for('entregas-loja-aceites', fn (Request $request) => Limit::perMinute(30)->by('entregas-loja-aceites:' . (session('user') ?: $request->ip())));

        // Entregas RestaurantePro: chat da loja com os motoboys no portal (a lista a cada 20 s e a conversa aberta a cada 5 s, por
        // aba), até 120 chamadas por minuto por usuário, num balde separado das outras rotas da loja
        RateLimiter::for('entregas-loja-conversas', fn (Request $request) => Limit::perMinute(120)->by('entregas-loja-conversas:' . (session('user') ?: $request->ip())));

        // Entregas RestaurantePro: "Minha carteira" da loja pré-paga. A leitura (o portal pergunta a cada 5 s com o QR na
        // tela) até 60 por minuto por usuário; pedir e cancelar o Pix, cada um cria ou apaga uma cobrança no Asaas da
        // central, até 6 por minuto por usuário e 20 por minuto por IP
        RateLimiter::for('entregas-loja-carteira', fn (Request $request) => Limit::perMinute(60)->by('entregas-loja-carteira:' . (session('user') ?: $request->ip())));
        RateLimiter::for('entregas-loja-recarga', fn (Request $request) => [
            Limit::perMinute(6)->by('entregas-loja-recarga:' . (session('user') ?: $request->ip())),
            Limit::perMinute(20)->by('entregas-loja-recarga-ip:' . $request->ip()),
        ]);
        // Entregas RestaurantePro: "Chamar motoboy" do portal da loja (cada chamada cria um pedido), até 20 por minuto por
        // usuário, num balde separado: o throttle sem nome conta por usuário em todas as rotas que o usam, e o 60,1 das outras
        // rotas da loja (lidas a cada poucos segundos) esgotaria um 20,1 antes de a loja chamar
        RateLimiter::for('entregas-loja-chamar', fn (Request $request) => Limit::perMinute(20)->by('entregas-loja-chamar:' . (session('user') ?: $request->ip())));

        // Entregas RestaurantePro: traçado da rota no mapa do pedido do app (cada card de pedido da lista pede o seu), até 120
        // chamadas por minuto por motoboy, num balde separado do entregas-motoboy (ganhos e valor)
        RateLimiter::for('entregas-motoboy-rota', fn (Request $request) => Limit::perMinute(120)->by('entregas-motoboy-rota:' . (session('user') ?: $request->ip())));
        // distribuição de pedidos abertos: a recusa da oferta pelo app (30 por minuto por motoboy)
        RateLimiter::for('entregas-motoboy-recusa', fn (Request $request) => Limit::perMinute(30)->by('entregas-motoboy-recusa:' . (session('user') ?: $request->ip())));
        // saque Pix pelo app: pedir e acompanhar (o app consulta a cada 3 s por até 1 min), 30 por minuto por motoboy
        RateLimiter::for('entregas-motoboy-saque', fn (Request $request) => Limit::perMinute(30)->by('entregas-motoboy-saque:' . (session('user') ?: $request->ip())));
        // validação de saque do Asaas (sem usuário): 120 por minuto por token (o hash, nunca o token no cache) e 1000 por
        // minuto por IP (os IPs do Asaas são compartilhados por todas as centrais: um balde só por IP travaria todas)
        RateLimiter::for('entregas-asaas-validacao', fn (Request $request) => [
            Limit::perMinute(120)->by('entregas-asaas-validacao:t:' . hash('sha256', (string) $request->header('asaas-access-token'))),
            Limit::perMinute(1000)->by('entregas-asaas-validacao:ip:' . $request->ip()),
        ]);

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

        // Entregas RestaurantePro: aba Mapa do líder dos motoboys no app (o mapa relido a cada 10 s com a tela aberta, o acesso
        // ao abrir o app e ao voltar para ele, e a troca do motoboy), até 120 chamadas por minuto por usuário, num balde só dele
        RateLimiter::for('entregas-lider', fn (Request $request) => Limit::perMinute(120)->by('entregas-lider:' . (session('user') ?: $request->ip())));

        // busca de endereço pelo Google Places (console e portal): até 120 por minuto por usuário (a tela já espera 300 ms
        // entre teclas; o teto segura um script)
        RateLimiter::for('entregas-enderecos', fn (Request $request) => Limit::perMinute(120)->by('entregas-enderecos:' . (session('user') ?: $request->ip())));

        // integração de pedidos de outros sistemas (RestaurantePro): até 60 chamadas por minuto por chave (o hash do Bearer;
        // sem Bearer, o IP). As rotas ficam fora do fleetbase.api: a chave da loja é conferida no IntegracaoController
        RateLimiter::for('entregas-integracao', fn (Request $request) => Limit::perMinute(60)
            ->by('entregas-integracao:' . hash('sha256', (string) ($request->bearerToken() ?: $request->ip())))
            // o 429 no formato da casa e com o Retry-After (o handler do core responderia em inglês e sem os cabeçalhos)
            ->response(fn ($request, array $headers) => response()->json(['errors' => ['Muitas chamadas. Tente de novo em instantes.']], 429, $headers)));

        // dados do pedido da integração no app (card de aceitar e detalhes), num balde próprio por motoboy
        RateLimiter::for('entregas-motoboy-integracao', fn (Request $request) => Limit::perMinute(120)->by('entregas-motoboy-integracao:' . (session('user') ?: $request->ip())));

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

                // Entregas RestaurantePro: a lista de extensões instaladas, que o console pede ao abrir cada página (ember-core,
                // utils/load-installed-extensions.js). Era do registry-bridge, fora do package:discover (api/composer.json):
                // sem esta rota, 404 em toda página. Sempre vazia: as extensões do console estão na lista fixa do ember-core
                Route::get(ProtegerPortalLoja::CAMINHO_DAS_EXTENSOES, fn () => response()->json([]));

                // Entregas RestaurantePro: pagamento dos motoboys e cobrança das lojas por faixa de km (console → Fleet-Ops → Pagamento e cobrança)
                Route::prefix('int/v1/entregas')
                    ->middleware(['fleetbase.protected'])
                    ->group(function () {
                        Route::get('pagamento-motoboys', [PagamentoMotoboysController::class, 'relatorio']);
                        Route::put('pagamento-motoboys/faixas', [PagamentoMotoboysController::class, 'salvarFaixas']);
                        Route::put('pagamento-motoboys/faixas-juntas', [PagamentoMotoboysController::class, 'salvarFaixasJuntas']);

                        // Painel inicial do console (console.home → componente home/painel-inicial do Fleet-Ops): resumo do
                        // período, ao vivo (pedidos + motoboys, relido a cada 20 s) e atribuir motoboy (só admin)
                        Route::get('painel-inicial', [PainelInicialController::class, 'resumo']);
                        Route::get('painel-inicial/ao-vivo', [PainelInicialController::class, 'aoVivo']);
                        Route::post('painel-inicial/pedidos/{id}/motoboy', [PainelInicialController::class, 'atribuir']);

                        // Financeiro (console → Fleet-Ops → Financeiro, só admin: FinanceiroController): painel, motoboys (lista,
                        // extrato, Pix), lançamentos manuais e estornos, pagamentos com comprovante (multipart) e configurações
                        Route::get('financeiro/painel', [FinanceiroController::class, 'painel']);
                        Route::get('financeiro/motoboys', [FinanceiroController::class, 'motoboys']);
                        Route::get('financeiro/motoboys/{id}/extrato', [FinanceiroController::class, 'extratoDoMotoboy']);
                        Route::put('financeiro/motoboys/{id}/pix', [FinanceiroController::class, 'salvarPix']);
                        Route::post('financeiro/lancamentos', [FinanceiroController::class, 'lancar']);
                        Route::post('financeiro/lancamentos/{id}/estornar', [FinanceiroController::class, 'estornarLancamento']);
                        Route::post('financeiro/pagamentos', [FinanceiroController::class, 'registrarPagamento']);
                        Route::post('financeiro/pagamentos/{id}/estornar', [FinanceiroController::class, 'estornarPagamento']);
                        Route::get('financeiro/configuracoes', [FinanceiroController::class, 'configuracoes']);
                        Route::put('financeiro/configuracoes', [FinanceiroController::class, 'salvarConfiguracoes']);

                        // multiplicadores por período (chuva): MultiplicadoresController, só admin
                        Route::get('financeiro/multiplicadores', [MultiplicadoresController::class, 'lista']);
                        Route::post('financeiro/multiplicadores', [MultiplicadoresController::class, 'criar']);
                        Route::put('financeiro/multiplicadores/{id}', [MultiplicadoresController::class, 'alterar']);
                        Route::post('financeiro/multiplicadores/{id}/encerrar', [MultiplicadoresController::class, 'encerrar']);
                        Route::delete('financeiro/multiplicadores/{id}', [MultiplicadoresController::class, 'apagar']);

                        // saque Pix do motoboy pelo app (SaquePixController; admin do sistema ou da organização): conta Asaas
                        // da central, validação de saque, limites, lista dos saques e bloqueio por motoboy
                        Route::get('financeiro/saque-pix', [SaquePixController::class, 'situacao']);
                        Route::put('financeiro/saque-pix', [SaquePixController::class, 'configurar']);
                        Route::post('financeiro/saque-pix/conexao', [SaquePixController::class, 'conectar'])->middleware('throttle:10,1');
                        Route::delete('financeiro/saque-pix/conexao', [SaquePixController::class, 'desconectar'])->middleware('throttle:10,1');
                        Route::post('financeiro/saque-pix/token', [SaquePixController::class, 'gerarToken'])->middleware('throttle:10,1');
                        Route::post('financeiro/saque-pix/teste', [SaquePixController::class, 'testar'])->middleware('throttle:10,1');
                        Route::get('financeiro/saques', [SaquePixController::class, 'saques']);
                        Route::put('financeiro/motoboys/{id}/saque', [SaquePixController::class, 'bloquear']);

                        // corridas do sistema (relatório do dono, todas as organizações): CorridasDoSistemaController, só o
                        // administrador do sistema (negarSeNaoDonoDoSistema)
                        Route::get('sistema/corridas', [CorridasDoSistemaController::class, 'corridas']);
                        Route::get('sistema/precos', [CorridasDoSistemaController::class, 'precos']);
                        Route::post('sistema/precos', [CorridasDoSistemaController::class, 'criarPreco']);
                        Route::delete('sistema/precos/{id}', [CorridasDoSistemaController::class, 'apagarPreco']);

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
                        // integração de pedidos de outros sistemas: a chave da loja (gerar, revogar, situação)
                        Route::get('lojas/{id}/integracao', [IntegracaoLojasController::class, 'situacao']);
                        Route::post('lojas/{id}/integracao/chave', [IntegracaoLojasController::class, 'gerarChave']);
                        Route::delete('lojas/{id}/integracao', [IntegracaoLojasController::class, 'revogar']);
                        // painel iFood no detalhe do pedido e a liberação da conclusão sem o código do cliente
                        Route::get('pedidos/{id}/ifood', [IfoodPedidosController::class, 'painel']);
                        Route::post('pedidos/{id}/ifood/liberar-sem-codigo', [IfoodPedidosController::class, 'liberarSemCodigo']);
                        // painel "Integração" no detalhe do pedido
                        Route::get('pedidos/{id}/integracao', [IntegracaoLojasController::class, 'painel']);
                        // painel "Distribuição" no detalhe do pedido e o "Abrir a todos agora"
                        Route::get('pedidos/{id}/distribuicao', [DistribuicaoController::class, 'painel']);
                        Route::post('pedidos/{id}/distribuicao/abrir', [DistribuicaoController::class, 'abrir']);
                        // folha do pedido no console, no estilo da Comanda do app (cliente, cobrança, produtos, loja, linha do
                        // tempo, rota e valores): qualquer usuário da central; os valores só para admin
                        Route::get('pedidos/{id}/comanda', [PedidoAbertoController::class, 'comanda']);

                        // mapa ao vivo do console: só os locais de coleta (lojas) e a situação de cada motoboy (cor do capacete)
                        Route::get('mapa/locais-de-coleta', [MapaController::class, 'locaisDeColeta']);
                        Route::get('mapa/motoboys', [MapaController::class, 'motoboys']);

                        // financeiro: lojas e faturas (FinanceiroLojasController, só admin): ciclo de cobrança, extrato da loja,
                        // rascunho (gerar e apagar), conferência, emissão, cancelamento e reenvio do e-mail da fatura
                        Route::get('financeiro/lojas', [FinanceiroLojasController::class, 'lojas']);
                        Route::put('financeiro/lojas/{id}/ciclo', [FinanceiroLojasController::class, 'salvarCiclo']);
                        Route::get('financeiro/lojas/{id}/extrato', [FinanceiroLojasController::class, 'extratoDaLoja']);
                        Route::get('financeiro/faturas', [FinanceiroLojasController::class, 'faturas']);
                        Route::post('financeiro/faturas', [FinanceiroLojasController::class, 'gerarRascunho']);
                        Route::get('financeiro/faturas/{id}', [FinanceiroLojasController::class, 'fatura']);
                        Route::put('financeiro/faturas/{id}', [FinanceiroLojasController::class, 'mudarVencimento']);
                        Route::delete('financeiro/faturas/{id}', [FinanceiroLojasController::class, 'apagar']);
                        Route::post('financeiro/faturas/{id}/ajustes', [FinanceiroLojasController::class, 'ajuste']);
                        Route::post('financeiro/faturas/{id}/nao-cobrar', [FinanceiroLojasController::class, 'naoCobrar']);
                        Route::post('financeiro/faturas/{id}/emitir', [FinanceiroLojasController::class, 'emitir']);
                        Route::post('financeiro/faturas/{id}/cancelar', [FinanceiroLojasController::class, 'cancelar']);
                        Route::post('financeiro/faturas/{id}/reenviar', [FinanceiroLojasController::class, 'reenviar']);

                        // portal da loja (usuário type=customer; o ProtegerPortalLoja só libera estas), até 60 chamadas por minuto por usuário
                        Route::get('loja/minha-loja', [PortalLojaController::class, 'minhaLoja'])->middleware('throttle:60,1');
                        Route::get('loja/resumo-do-dia', [PortalLojaController::class, 'resumoDoDia'])->middleware('throttle:60,1');
                        Route::get('loja/extrato', [PortalLojaController::class, 'extrato'])->middleware('throttle:60,1');
                        Route::get('loja/pedidos/{id}/motoboy', [PortalLojaController::class, 'motoboy'])->middleware('throttle:60,1');
                        Route::get('loja/pedidos/{id}/itens', [PortalLojaController::class, 'itens'])->middleware('throttle:60,1');
                        Route::get('loja/motoboys', [PortalLojaController::class, 'motoboysNoMapa'])->middleware('throttle:entregas-loja-mapa');
                        Route::get('loja/aceites', [PortalLojaController::class, 'aceites'])->middleware('throttle:entregas-loja-aceites');
                        // chamar motoboy (o painel de 3 passos do portal; spec 2026-10-28-chamar-motoboy-portal-design.md)
                        Route::get('loja/clientes', [ChamarMotoboyController::class, 'clientes'])->middleware('throttle:60,1');
                        Route::post('loja/chamar-motoboy', [ChamarMotoboyController::class, 'chamar'])->middleware('throttle:entregas-loja-chamar');
                        // Financeiro da loja: ciclo em andamento e faturas emitidas e canceladas; o detalhe de uma fatura (uuid)
                        Route::get('loja/financeiro', [PortalLojaController::class, 'financeiro'])->middleware('throttle:60,1');
                        Route::get('loja/faturas/{id}', [PortalLojaController::class, 'fatura'])->middleware('throttle:60,1');
                        // Integrações da loja (IntegracoesDaLojaController): a própria loja conecta o iFood (o mesmo vínculo da
                        // tela Lojas, com 10 por minuto: cada código vai ao iFood) e os sistemas que mandam pedidos pela chave da loja
                        Route::get('loja/integracoes', [IntegracoesDaLojaController::class, 'index'])->middleware('throttle:60,1');
                        Route::post('loja/integracoes/ifood/codigo', [IntegracoesDaLojaController::class, 'ifoodCodigo'])->middleware('throttle:10,1');
                        Route::post('loja/integracoes/ifood/vincular', [IntegracoesDaLojaController::class, 'ifoodVincular'])->middleware('throttle:10,1');
                        Route::delete('loja/integracoes/ifood', [IntegracoesDaLojaController::class, 'ifoodDesvincular'])->middleware('throttle:10,1');
                        Route::post('loja/integracoes/{id}/chave', [IntegracoesDaLojaController::class, 'gerarChave'])->middleware('throttle:10,1');
                        Route::delete('loja/integracoes/{id}/chave', [IntegracoesDaLojaController::class, 'revogarChave'])->middleware('throttle:10,1');
                        // "Minha carteira" da loja pré-paga: crédito, recarga por Pix (cobrança no Asaas da central) e cancelamento
                        Route::get('loja/carteira', [CarteiraDaLojaController::class, 'index'])->middleware('throttle:entregas-loja-carteira');
                        Route::get('loja/carteira/recargas/{id}', [CarteiraDaLojaController::class, 'mostrar'])->middleware('throttle:entregas-loja-carteira');
                        Route::post('loja/carteira/recargas', [CarteiraDaLojaController::class, 'pedir'])->middleware('throttle:entregas-loja-recarga');
                        Route::delete('loja/carteira/recargas/{id}', [CarteiraDaLojaController::class, 'cancelar'])->middleware('throttle:entregas-loja-recarga');

                        // chat da loja com os motoboys (a central participa; o motoboy conversa pelo app)
                        Route::middleware('throttle:entregas-loja-conversas')->group(function () {
                            Route::get('loja/conversas', [ConversasDaLojaController::class, 'index']);
                            Route::post('loja/conversas', [ConversasDaLojaController::class, 'abrir']);
                            Route::get('loja/conversas/{id}/mensagens', [ConversasDaLojaController::class, 'mensagens']);
                            Route::post('loja/conversas/{id}/mensagens', [ConversasDaLojaController::class, 'enviar']);
                        });

                        // busca de endereço pelo Google Places (EnderecosController): sugestões e detalhes valem para a central e
                        // para a loja (ProtegerPortalLoja); a busca com os locais salvos é só da central
                        Route::get('enderecos/sugestoes', [EnderecosController::class, 'sugestoes'])->middleware('throttle:entregas-enderecos');
                        Route::get('enderecos/detalhes/{placeId}', [EnderecosController::class, 'detalhes'])->middleware('throttle:entregas-enderecos');
                        Route::get('enderecos/busca', [EnderecosController::class, 'busca'])->middleware('throttle:entregas-enderecos');
                    });

                // Entregas RestaurantePro: ganhos do motoboy no app (tela Início, card de aceitar e detalhes), na API v1 com o
                // token dele (MotoboyController; só token de motoboy, nunca com o valor cobrado da loja)
                Route::prefix('v1/entregas/motoboy')
                    ->middleware(['fleetbase.api', 'throttle:entregas-motoboy'])
                    ->group(function () {
                        // versão mínima do app e link do APK (trava "Atualize o app")
                        Route::get('app', [MotoboyController::class, 'app']);
                        Route::get('ganhos', [MotoboyController::class, 'ganhos']);
                        Route::get('pedidos/{id}/valor', [MotoboyController::class, 'valor']);
                        // Financeiro no app: o extrato (Início), a "Minha carteira" e o recibo de um pagamento
                        Route::get('extrato', [MotoboyController::class, 'extrato']);
                        Route::get('carteira', [MotoboyController::class, 'carteira']);
                        Route::get('pagamentos/{id}', [MotoboyController::class, 'pagamento']);
                        // conversa do motoboy com a central (botão "Chat" do cliente nos detalhes do pedido)
                        Route::post('chat-central', [MotoboyController::class, 'chatComACentral']);
                        // conversa do motoboy com a loja do pedido (ou com a central, em pedido sem loja)
                        Route::post('pedidos/{id}/chat', [MotoboyController::class, 'chatDoPedido']);
                        // pedido iFood: conclusão e código de entrega do cliente (os dados ficam no grupo de baixo)
                        Route::post('pedidos/{id}/concluir-ifood', [MotoboyController::class, 'concluirIfood']);
                        Route::post('pedidos/{id}/codigo-ifood', [MotoboyController::class, 'codigoIfood'])->middleware('throttle:entregas-ifood-codigo');
                        // distribuição de pedidos abertos: recusar a oferta
                        Route::post('pedidos/{id}/recusar', [MotoboyController::class, 'recusar'])->middleware('throttle:entregas-motoboy-recusa');
                    });

                // Entregas RestaurantePro: comprovante de pagamento do Financeiro (ComprovantesController), sem token: só pela URL
                // assinada de 30 min (Pagamentos::urlDoComprovante) que o console e o app recebem
                Route::prefix('v1/entregas/comprovantes')
                    ->middleware(['signed', 'throttle:60,1'])
                    ->group(function () {
                        Route::get('{uuid}', [ComprovantesController::class, 'mostrar'])->name(Pagamentos::ROTA_DO_COMPROVANTE);
                    });

                // Entregas RestaurantePro: saque Pix do motoboy pelo app (SaquesController), com o token dele
                Route::prefix('v1/entregas/motoboy')
                    ->middleware(['fleetbase.api', 'throttle:entregas-motoboy-saque'])
                    ->group(function () {
                        Route::post('saques', [SaquesController::class, 'pedir']);
                        Route::get('saques/{id}', [SaquesController::class, 'mostrar']);
                    });

                // Entregas RestaurantePro: validação de saque do Asaas (o Asaas pergunta antes de cada transferência), sem token
                // de usuário: a central é achada pelo token do cabeçalho asaas-access-token (ValidacaoDeSaque)
                Route::prefix('v1/entregas/asaas')
                    ->middleware(['throttle:entregas-asaas-validacao'])
                    ->group(function () {
                        Route::post('validar-saque', [ValidacaoAsaasController::class, 'validar']);
                    });

                // Entregas RestaurantePro: traçado loja → cliente e situação do motoboy no mapa do pedido do app (MotoboyController@rota)
                Route::prefix('v1/entregas/motoboy')
                    ->middleware(['fleetbase.api', 'throttle:entregas-motoboy-rota'])
                    ->group(function () {
                        Route::get('pedidos/{id}/rota', [MotoboyController::class, 'rota']);
                    });

                // Entregas RestaurantePro: aba Mapa do líder dos motoboys no app (LiderController; o líder é o LiderDosMotoboys)
                Route::prefix('v1/entregas/lider')
                    ->middleware(['fleetbase.api', 'throttle:entregas-lider'])
                    ->group(function () {
                        Route::get('acesso', [LiderController::class, 'acesso']);
                        Route::get('mapa', [LiderController::class, 'mapa']);
                        Route::post('pedidos/{id}/motoboy', [LiderController::class, 'trocarMotoboy']);
                    });

                // Entregas RestaurantePro: dados do pedido iFood no app (card de aceitar e detalhes; MotoboyController@ifood)
                Route::prefix('v1/entregas/motoboy')
                    ->middleware(['fleetbase.api', 'throttle:entregas-motoboy-ifood'])
                    ->group(function () {
                        Route::get('pedidos/{id}/ifood', [MotoboyController::class, 'ifood']);
                    });

                // Entregas RestaurantePro: dados do pedido de outro sistema no app (card de aceitar e detalhes; MotoboyController@integracao)
                Route::prefix('v1/entregas/motoboy')
                    ->middleware(['fleetbase.api', 'throttle:entregas-motoboy-integracao'])
                    ->group(function () {
                        Route::get('pedidos/{id}/integracao', [MotoboyController::class, 'integracao']);
                    });

                // Entregas RestaurantePro: pedidos de outros sistemas (RestaurantePro), com a chave da loja (IntegracaoController)
                Route::prefix('v1/entregas/integracao')
                    ->middleware(['throttle:entregas-integracao'])
                    ->group(function () {
                        Route::post('conexao', [IntegracaoController::class, 'conexao']);
                        Route::post('pedidos', [IntegracaoController::class, 'criar']);
                        Route::get('pedidos/{id}', [IntegracaoController::class, 'consultar']);
                        Route::post('pedidos/{id}/cancelar', [IntegracaoController::class, 'cancelar']);
                    });
            }
        );
    }
}
