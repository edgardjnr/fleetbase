<?php

// Integrações da loja no portal: o catálogo (CatalogoDeIntegracoes), a situação de cada sistema como a loja vê e as rotas
// da própria loja (IntegracoesDaLojaController): vínculo do iFood e chave dos sistemas por chave (RestaurantePro).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/integracoes-da-loja.php

require __DIR__ . '/stubs-integracao.php';

use App\Http\Controllers\Entregas\IntegracoesDaLojaController;
use App\Http\Middleware\ProtegerPortalLoja;
use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\VinculosIfood;
use App\Support\Entregas\Integracao\ChavesDeIntegracao;
use App\Support\Entregas\Integracoes\CatalogoDeIntegracoes;
use App\Support\Entregas\Integracoes\IntegracaoIfood;
use App\Support\Entregas\Integracoes\IntegracaoPorChave;
use Fleetbase\FleetOps\Models\Vendor;
use Illuminate\Http\Request;
use Teste\Banco;
use Teste\Config;
use Teste\Http;
use Teste\Sessao;

// a loja da sessão vem do LojaDoUsuario (Eloquent com whereHas); aqui ela é fixa, sem loja = 404 como o real
class ControllerDaLoja extends IntegracoesDaLojaController
{
    public ?Vendor $loja = null;

    protected function lojaDaSessao(): Vendor
    {
        if (!$this->loja) {
            throw new \RuntimeException('404: Usuário sem loja.');
        }

        return $this->loja;
    }
}

$GLOBALS['logsDosCasos'] = [];

function preparar(): ControllerDaLoja
{
    $GLOBALS['logsDosCasos'] = array_merge($GLOBALS['logsDosCasos'], \Illuminate\Support\Facades\Log::$registros);
    reiniciarIntegracao();
    // a sessão do portal pode ter outra empresa: o vínculo e a chave usam sempre a empresa da loja
    Sessao::$dados = ['company' => 'empresa-da-sessao', 'user' => 'usuario-da-loja'];
    $controller       = new ControllerDaLoja();
    $controller->loja = new Vendor(['uuid' => 'vendor-a', 'public_id' => 'vendor_a', 'company_uuid' => 'empresa-1', 'type' => 'customer', 'name' => 'Loja A']);

    return $controller;
}

function vinculos(): VinculosIfood
{
    return new VinculosIfood(new ClienteIfood());
}

function respostaDoCodigo(): void
{
    Http::responder(200, ['userCode' => 'ABCD-EFGH', 'authorizationCodeVerifier' => 'verificador-1', 'verificationUrl' => 'https://portal.ifood.com.br/apps/code', 'verificationUrlComplete' => 'https://portal.ifood.com.br/apps/code?c=ABCD-EFGH', 'expiresIn' => 600]);
}

function cartaoDe(array $integracoes, string $id): ?array
{
    foreach ($integracoes as $cartao) {
        if ($cartao['id'] === $id) {
            return $cartao;
        }
    }

    return null;
}

echo '== Catálogo' . PHP_EOL;
preparar();
confere(array_map(fn ($i) => [$i->id(), $i->fluxo(), $i->nome()], CatalogoDeIntegracoes::todas()) === [['ifood', 'ifood', 'iFood'], ['restaurantepro', 'chave', 'RestaurantePro']], 'iFood e RestaurantePro, nessa ordem, com o fluxo de cada um');
confere(CatalogoDeIntegracoes::porId('restaurantepro')?->id() === 'restaurantepro' && CatalogoDeIntegracoes::porId('saipos') === null && CatalogoDeIntegracoes::porId('') === null, 'porId: acha pelo id; desconhecido = null');

echo '== Situação do iFood como a loja vê' . PHP_EOL;
confere(IntegracaoIfood::doResumo(['situacao' => 'vinculada', 'nome' => 'Pizzaria Um', 'merchant_id' => 'merchant-1']) === ['estado' => 'conectada', 'loja_no_ifood' => 'Pizzaria Um'], 'vinculada = conectada, com o nome no iFood e sem o merchant_id');
confere(IntegracaoIfood::doResumo(['situacao' => 'vinculo_perdido', 'nome' => 'Pizzaria Um', 'merchant_id' => 'merchant-1']) === ['estado' => 'atencao', 'loja_no_ifood' => 'Pizzaria Um'], 'vínculo perdido = atenção');
confere(IntegracaoIfood::doResumo(['situacao' => null, 'nome' => null, 'merchant_id' => null]) === ['estado' => 'desconectada', 'loja_no_ifood' => null] && IntegracaoIfood::doResumo([]) === ['estado' => 'desconectada', 'loja_no_ifood' => null], 'sem vínculo = desconectada');

echo '== Situação por chave como a loja vê' . PHP_EOL;
confere(IntegracaoPorChave::doPainel(['ativa' => false, 'chave_final' => null]) === ['estado' => 'desconectada'], 'sem chave = desconectada, sem mais nada');
$aguardando = IntegracaoPorChave::doPainel(['ativa' => true, 'conectada' => false, 'chave_final' => 'a1B2', 'webhook_host' => null, 'gerada_em' => '2026-10-27T10:00:00-03:00']);
confere($aguardando['estado'] === 'aguardando' && $aguardando['chave_final'] === 'a1B2' && $aguardando['gerada_em'] === '2026-10-27T10:00:00-03:00', 'chave gerada sem conexão = aguardando, com o final da chave');
$conectada = IntegracaoPorChave::doPainel(['ativa' => true, 'conectada' => true, 'chave_final' => 'a1B2', 'sistema' => 'RestaurantePro', 'webhook_host' => 'api.restaurantepro.com.br', 'ultima_falha_aviso' => 'HTTP 500']);
confere($conectada['estado'] === 'conectada' && $conectada['sistema'] === 'RestaurantePro' && $conectada['ultima_falha_aviso'] === 'HTTP 500', 'conectada, com o sistema e a última falha de aviso');
confere(!array_key_exists('webhook_host', $conectada) && !array_key_exists('ativa', $conectada), 'sem o host do webhook');

echo '== Lista da loja' . PHP_EOL;
$controller = preparar();
$resposta   = $controller->index();
$lista      = $resposta->dados['integracoes'];
confere($resposta->status === 200 && array_column($lista, 'id') === ['ifood', 'restaurantepro'], '200 com os dois cartões');
confere(cartaoDe($lista, 'ifood') === ['id' => 'ifood', 'nome' => 'iFood', 'fluxo' => 'ifood', 'disponivel' => true, 'estado' => 'desconectada', 'loja_no_ifood' => null], 'iFood: disponível e desconectado');
confere(cartaoDe($lista, 'restaurantepro') === ['id' => 'restaurantepro', 'nome' => 'RestaurantePro', 'fluxo' => 'chave', 'disponivel' => true, 'estado' => 'desconectada'], 'RestaurantePro: disponível e sem chave');
Config::$valores['services.ifood.ativo']           = '';
Config::$valores['services.entregas.integracao']   = '';
$lista = $controller->index()->dados['integracoes'];
confere(cartaoDe($lista, 'ifood')['disponivel'] === false && cartaoDe($lista, 'restaurantepro')['disponivel'] === false, 'interruptores desligados: indisponíveis');
$controller->loja = null;
confere(excecao(fn () => $controller->index())?->getMessage() === '404: Usuário sem loja.', 'usuário sem loja: 404');

echo '== Lista da loja: vínculos e chaves de outras lojas não aparecem' . PHP_EOL;
$controller = preparar();
lojaIntegradaA();
Banco::inserir('entregas_ifood_lojas', ['company_uuid' => 'empresa-1', 'vendor_uuid' => 'vendor-b', 'merchant_id' => 'merchant-b', 'nome_ifood' => 'Loja B no iFood', 'situacao' => 'vinculada', 'access_token' => encrypt('token-b'), 'refresh_token' => encrypt('refresh-b'), 'expira_em' => '2026-10-27 20:00:00', 'created_at' => '2026-10-27 08:00:00', 'updated_at' => '2026-10-27 08:00:00'], false);
$lista = $controller->index()->dados['integracoes'];
confere(cartaoDe($lista, 'ifood')['estado'] === 'desconectada', 'o vínculo da loja B não vale para a loja A');
confere(cartaoDe($lista, 'restaurantepro')['estado'] === 'conectada' && cartaoDe($lista, 'restaurantepro')['sistema'] === 'RestaurantePro', 'a chave da loja A (empresa da loja) aparece conectada');
confere(!str_contains(json_encode($lista), 'segredo') && !str_contains(json_encode($lista), 'cifrado') && !str_contains(json_encode($lista), 'webhook'), 'sem segredo, token nem webhook na resposta');

echo '== iFood: conectar pela loja' . PHP_EOL;
$controller = preparar();
Config::$valores['services.ifood.ativo'] = '';
confere($controller->ifoodCodigo(vinculos())->status === 409, 'desligado: código 409');
confere($controller->ifoodVincular(new Request(['authorizationCode' => 'AUTH-1']), vinculos())->status === 409, 'desligado: vínculo 409');
confere(Http::$chamadas === [], 'desligado: sem chamar o iFood');
$controller = preparar();
respostaDoCodigo();
$resposta = $controller->ifoodCodigo(vinculos());
confere($resposta->status === 200 && $resposta->dados === ['codigo' => 'ABCD-EFGH', 'link' => 'https://portal.ifood.com.br/apps/code?c=ABCD-EFGH', 'expira_em_segundos' => 600], 'código, link e validade');
confere(isset(\Illuminate\Support\Facades\Cache::$dados[VinculosIfood::chaveDoVerificador('vendor-a')]), 'verificador guardado pela loja da sessão');
confere($controller->ifoodVincular(new Request([]), vinculos())->status === 422, 'sem o código de autorização: 422');
Http::responder(200, ['accessToken' => 'token-1', 'type' => 'bearer', 'expiresIn' => 21600, 'refreshToken' => 'refresh-1']);
Http::responder(200, [['id' => 'merchant-1', 'name' => 'Pizzaria Um']]);
$resposta = $controller->ifoodVincular(new Request(['authorizationCode' => 'AUTH-1']), vinculos());
confere($resposta->status === 200 && $resposta->dados === ['integracao' => ['id' => 'ifood', 'nome' => 'iFood', 'fluxo' => 'ifood', 'disponivel' => true, 'estado' => 'conectada', 'loja_no_ifood' => 'Pizzaria Um']], 'vinculada: devolve o cartão do iFood conectado');
$linha = Banco::linhas('entregas_ifood_lojas')[0];
confere($linha->vendor_uuid === 'vendor-a' && $linha->company_uuid === 'empresa-1', 'gravado na loja da sessão e na empresa da loja (não na da sessão)');
confere(logou('[entregas] integrações: iFood conectado pela loja', 'info'), 'log da ação da loja');

echo '== iFood: várias lojas na conta' . PHP_EOL;
$controller = preparar();
respostaDoCodigo();
$controller->ifoodCodigo(vinculos());
Http::responder(200, ['accessToken' => 'token-1', 'type' => 'bearer', 'expiresIn' => 21600, 'refreshToken' => 'refresh-1']);
Http::responder(200, [['id' => 'merchant-1', 'name' => 'Centro'], ['id' => 'merchant-2', 'name' => 'Bairro']]);
$resposta = $controller->ifoodVincular(new Request(['authorizationCode' => 'AUTH-1']), vinculos());
confere($resposta->dados === ['escolher' => [['id' => 'merchant-1', 'nome' => 'Centro'], ['id' => 'merchant-2', 'nome' => 'Bairro']]], 'pede a escolha');
$resposta = $controller->ifoodVincular(new Request(['merchant_id' => 'merchant-2']), vinculos());
confere($resposta->dados['integracao']['estado'] === 'conectada' && $resposta->dados['integracao']['loja_no_ifood'] === 'Bairro', 'a escolha conclui');

echo '== iFood: erro do iFood chega à loja' . PHP_EOL;
$controller = preparar();
respostaDoCodigo();
$controller->ifoodCodigo(vinculos());
Http::responder(401, ['error' => ['code' => 'Unauthorized', 'message' => 'Bad credentials']]);
$resposta = $controller->ifoodVincular(new Request(['authorizationCode' => 'ERRADO']), vinculos());
confere($resposta->status === 422 && str_contains($resposta->dados['errors'][0], 'recusou o código') && str_contains($resposta->dados['errors'][0], '(iFood 401'), 'código recusado: 422 com o motivo do iFood');
Http::responder(503, 'fora do ar');
confere($controller->ifoodCodigo(vinculos())->status === 502, 'iFood fora do ar: 502');

echo '== iFood: desconectar pela loja' . PHP_EOL;
$controller = preparar();
vinculoDaLojaA();
Config::$valores['services.ifood.ativo'] = '';
$resposta = $controller->ifoodDesvincular(vinculos());
confere($resposta->status === 200 && $resposta->dados['integracao']['estado'] === 'desconectada' && $resposta->dados['integracao']['disponivel'] === false, 'desvincula mesmo com a integração desligada');
confere(logou('[entregas] integrações: iFood desconectado pela loja', 'info'), 'log da ação da loja');

echo '== Chave: gerar e revogar pela loja' . PHP_EOL;
$controller = preparar();
foreach (['ifood' => 'iFood (fluxo de código)', 'saipos' => 'sistema desconhecido', '' => 'id vazio'] as $id => $caso) {
    confere($controller->gerarChave($id)->status === 404 && $controller->revogarChave($id)->status === 404, "{$caso}: 404 ao gerar e revogar");
}
confere(Banco::linhas('entregas_integracao_lojas') === [], 'nada gravado');
Config::$valores['services.entregas.integracao'] = '';
$resposta = $controller->gerarChave('restaurantepro');
confere($resposta->status === 409 && Banco::linhas('entregas_integracao_lojas') === [], 'desligada: 409 sem gerar');
Config::$valores['services.entregas.integracao'] = '1';
$resposta = $controller->gerarChave('restaurantepro');
$chave    = $resposta->dados['chave'];
confere($resposta->status === 200 && preg_match('/^ent_live_[0-9A-Za-z]{40}$/', $chave) === 1, 'gera a chave ent_live_');
confere($resposta->dados['integracao']['estado'] === 'aguardando' && $resposta->dados['integracao']['chave_final'] === substr($chave, -4), 'cartão aguardando o sistema, com o final da chave');
$ativa = ChavesDeIntegracao::ativa('empresa-1', 'vendor-a');
confere($ativa !== null && ChavesDeIntegracao::daChave($chave)?->vendor_uuid === 'vendor-a', 'chave da loja da sessão, na empresa da loja');
$outra = $controller->gerarChave('restaurantepro')->dados['chave'];
confere(ChavesDeIntegracao::daChave($chave) === null && ChavesDeIntegracao::daChave($outra)?->vendor_uuid === 'vendor-a', 'gerar de novo revoga a anterior');
Config::$valores['services.entregas.integracao'] = '';
$resposta = $controller->revogarChave('restaurantepro');
confere($resposta->status === 200 && $resposta->dados['integracao']['estado'] === 'desconectada' && ChavesDeIntegracao::daChave($outra) === null, 'revoga (mesmo desligada)');
confere(logou('[entregas] integrações: chave gerada pela loja', 'info') && logou('[entregas] integrações: chave revogada pela loja', 'info'), 'logs das ações da loja');
confere(logsSem([$chave, $outra]), 'nenhum log traz a chave');

echo '== Logs sem segredos' . PHP_EOL;
$GLOBALS['logsDosCasos'] = array_merge($GLOBALS['logsDosCasos'], \Illuminate\Support\Facades\Log::$registros);
\Illuminate\Support\Facades\Log::$registros = $GLOBALS['logsDosCasos'];
confere(logsSem(['token-1', 'refresh-1', 'AUTH-1', 'ERRADO', 'ABCD-EFGH', 'verificador-1']), 'nenhum log traz token, refresh, código de autorização, código de vínculo ou verificador');

echo '== Rotas e portal' . PHP_EOL;
$rotas = file_get_contents('/repo/api/app/Providers/RouteServiceProvider.php');
foreach ([
    "Route::get('loja/integracoes', [IntegracoesDaLojaController::class, 'index'])->middleware('throttle:60,1');",
    "Route::post('loja/integracoes/ifood/codigo', [IntegracoesDaLojaController::class, 'ifoodCodigo'])->middleware('throttle:entregas-ifood-vinculo');",
    "Route::post('loja/integracoes/ifood/vincular', [IntegracoesDaLojaController::class, 'ifoodVincular'])->middleware('throttle:entregas-ifood-vinculo');",
    "Route::delete('loja/integracoes/ifood', [IntegracoesDaLojaController::class, 'ifoodDesvincular'])->middleware('throttle:10,1');",
    "Route::post('loja/integracoes/{id}/chave', [IntegracoesDaLojaController::class, 'gerarChave'])->middleware('throttle:10,1');",
    "Route::delete('loja/integracoes/{id}/chave', [IntegracoesDaLojaController::class, 'revogarChave'])->middleware('throttle:10,1');",
] as $rota) {
    confere(str_contains($rotas, $rota), 'rota com limitador: ' . substr($rota, 0, 60) . '…');
}
$liberada = fn (string $rota) => (bool) array_filter(ProtegerPortalLoja::PERMITIDAS_INTERNAS, fn ($padrao) => preg_match($padrao, $rota) === 1);
confere($liberada('GET entregas/loja/integracoes') && $liberada('POST entregas/loja/integracoes/ifood/vincular') && $liberada('DELETE entregas/loja/integracoes/restaurantepro/chave'), 'o ProtegerPortalLoja libera as rotas à loja (entregas/loja/*)');
confere(!$liberada('POST entregas/lojas/vendor_a/ifood/vincular') && !$liberada('POST entregas/lojas/vendor_a/integracao/chave'), 'as rotas da tela Lojas (central) continuam negadas à loja');

resumo();
