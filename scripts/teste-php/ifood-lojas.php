<?php

// Integração iFood: as rotas do vínculo na tela Lojas (IfoodLojasController) e o bloco `ifood` da loja.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-lojas.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Http\Controllers\Entregas\IfoodLojasController;
use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\VinculosIfood;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;
use Teste\Banco;
use Teste\Config;
use Teste\Http;
use Teste\RespostaJson;
use Teste\Sessao;

// o formatar() de verdade lê usuários e endereço pelo Eloquent; aqui fica só o id e o bloco do iFood
class ControllerDeTeste extends IfoodLojasController
{
    protected function formatar(Vendor $vendor): array
    {
        return ['id' => $vendor->public_id, 'ifood' => $this->resumoIfood($vendor)];
    }
}

class Usuario
{
    public function __construct(private bool $admin) {}
    public function isNotAdmin(): bool { return !$this->admin; }
}

// os logs de todos os casos (o reiniciarIfood zera o Log a cada preparar), para conferir no fim que nenhum traz segredo
$GLOBALS['logsDosCasos'] = [];

function preparar(bool $admin = true): ControllerDeTeste
{
    $GLOBALS['logsDosCasos'] = array_merge($GLOBALS['logsDosCasos'], \Illuminate\Support\Facades\Log::$registros);
    reiniciarIfood();
    reiniciarFleetbase();
    Sessao::$dados = ['company' => 'empresa-1'];
    Auth::$usuario = new Usuario($admin);

    return new ControllerDeTeste();
}

function vinculos(): VinculosIfood
{
    return new VinculosIfood(new ClienteIfood());
}

function respostaDoCodigo(): void
{
    Http::responder(200, ['userCode' => 'ABCD-EFGH', 'authorizationCodeVerifier' => 'verificador-1', 'verificationUrl' => 'https://portal.ifood.com.br/apps/code', 'verificationUrlComplete' => 'https://portal.ifood.com.br/apps/code?c=ABCD-EFGH', 'expiresIn' => 600]);
}

echo '== Só administradores' . PHP_EOL;
$controller = preparar(false);
foreach (['codigo' => [], 'vincular' => ['authorizationCode' => 'AUTH-1'], 'desvincular' => []] as $metodo => $dados) {
    $resposta = $controller->$metodo(new Request($dados), vinculos(), 'vendor_a');
    confere($resposta instanceof RespostaJson && $resposta->status === 403, "{$metodo}: 403 para quem não é admin");
}
confere(Http::$chamadas === [], 'sem chamar o iFood');

echo '== Integração desligada' . PHP_EOL;
$controller                              = preparar();
Config::$valores['services.ifood.ativo'] = '';
confere($controller->codigo(new Request(), vinculos(), 'vendor_a')->status === 409, 'código: 409');
$resposta = $controller->vincular(new Request(['authorizationCode' => 'AUTH-1']), vinculos(), 'vendor_a');
confere($resposta->status === 409 && $resposta->dados['errors'] === ['Integração iFood desligada.'], 'vínculo: 409 "Integração iFood desligada."');

echo '== Código de vínculo' . PHP_EOL;
$controller = preparar();
respostaDoCodigo();
$resposta = $controller->codigo(new Request(), vinculos(), 'vendor_a');
confere($resposta->status === 200 && $resposta->dados === ['codigo' => 'ABCD-EFGH', 'link' => 'https://portal.ifood.com.br/apps/code?c=ABCD-EFGH', 'expira_em_segundos' => 600], 'devolve código, link e validade (pela loja do public_id)');
confere(isset(\Illuminate\Support\Facades\Cache::$dados[VinculosIfood::chaveDoVerificador('vendor-a')]), 'verificador guardado pelo uuid da loja');
Http::responder(503, 'fora do ar');
confere($controller->codigo(new Request(), vinculos(), 'vendor_a')->status === 502, 'iFood fora do ar: 502');
confere(excecao(fn () => $controller->codigo(new Request(), vinculos(), 'vendor_x'))?->getMessage() === '404: registro não encontrado', 'loja inexistente: 404 (firstOrFail)');

echo '== Loja de outra empresa ou Fornecedor que não é loja: 404' . PHP_EOL;
$controller        = preparar();
Vendor::$todos[]   = new Vendor(['uuid' => 'vendor-b', 'public_id' => 'vendor_b', 'company_uuid' => 'empresa-2', 'type' => 'customer', 'name' => 'Loja de Outra Empresa']);
Vendor::$todos[]   = new Vendor(['uuid' => 'vendor-c', 'public_id' => 'vendor_c', 'company_uuid' => 'empresa-1', 'type' => 'vendor', 'name' => 'Fornecedor Comum']);
foreach (['vendor_b' => 'loja de outra empresa', 'vendor-b' => 'loja de outra empresa (pelo uuid)', 'vendor_c' => 'Fornecedor com type vendor', 'vendor-c' => 'Fornecedor com type vendor (pelo uuid)'] as $id => $nome) {
    foreach (['codigo' => [], 'vincular' => ['authorizationCode' => 'AUTH-1'], 'desvincular' => []] as $metodo => $dados) {
        $erro = excecao(fn () => $controller->$metodo(new Request($dados), vinculos(), $id));
        confere($erro?->getMessage() === '404: registro não encontrado', "{$metodo}, {$nome}: 404 (firstOrFail)");
    }
}
confere(Http::$chamadas === [] && Banco::linhas('entregas_ifood_lojas') === [] && \Illuminate\Support\Facades\Cache::$dados === [], 'sem chamar o iFood nem gravar nada');

echo '== Vincular' . PHP_EOL;
confere($controller->vincular(new Request([]), vinculos(), 'vendor_a')->status === 422, 'sem código: 422');
respostaDoCodigo();
$controller->codigo(new Request(), vinculos(), 'vendor_a');
Http::responder(200, ['accessToken' => 'token-1', 'type' => 'bearer', 'expiresIn' => 21600, 'refreshToken' => 'refresh-1']);
Http::responder(200, [['id' => 'merchant-1', 'name' => 'Pizzaria Um']]);
$resposta = $controller->vincular(new Request(['authorizationCode' => 'AUTH-1']), vinculos(), 'vendor_a');
confere($resposta->status === 200 && $resposta->dados === ['loja' => ['id' => 'vendor_a', 'ifood' => ['situacao' => 'vinculada', 'nome' => 'Pizzaria Um', 'merchant_id' => 'merchant-1']]], 'devolve a loja com o bloco ifood (sem tokens)');
confere(Banco::linhas('entregas_ifood_lojas')[0]->company_uuid === 'empresa-1', 'gravado na empresa da sessão');

echo '== Validação no formato da casa' . PHP_EOL;
$controller = preparar();
$codigoInvalido   = 'O código de autorização precisa ser um texto de até 500 caracteres.';
$merchantInvalido = 'A loja do iFood escolhida é inválida. Gere um código de vínculo novo.';
foreach ([
    'código que não é texto'   => [['authorizationCode' => ['AUTH-1']], [$codigoInvalido]],
    'código longo demais'      => [['authorizationCode' => str_repeat('A', 501)], [$codigoInvalido]],
    'merchant que não é texto' => [['merchant_id' => ['merchant-1']], [$merchantInvalido]],
    'merchant longo demais'    => [['merchant_id' => str_repeat('m', 65)], [$merchantInvalido]],
    'os dois inválidos'        => [['authorizationCode' => 123, 'merchant_id' => ['x']], [$codigoInvalido, $merchantInvalido]],
] as $caso => [$dados, $esperados]) {
    $resposta = $controller->vincular(new Request($dados), vinculos(), 'vendor_a');
    confere($resposta->status === 422 && $resposta->dados === ['errors' => $esperados], "{$caso}: 422 {\"errors\": [texto em pt-BR]} (sem o message nem o objeto por campo do Laravel)");
}
confere(Http::$chamadas === [], 'sem chamar o iFood');

echo '== Várias lojas no iFood' . PHP_EOL;
$controller = preparar();
respostaDoCodigo();
$controller->codigo(new Request(), vinculos(), 'vendor_a');
Http::responder(200, ['accessToken' => 'token-1', 'type' => 'bearer', 'expiresIn' => 21600, 'refreshToken' => 'refresh-1']);
Http::responder(200, [['id' => 'merchant-1', 'name' => 'Centro'], ['id' => 'merchant-2', 'name' => 'Bairro']]);
$resposta = $controller->vincular(new Request(['authorizationCode' => 'AUTH-1']), vinculos(), 'vendor_a');
confere($resposta->dados === ['escolher' => [['id' => 'merchant-1', 'nome' => 'Centro'], ['id' => 'merchant-2', 'nome' => 'Bairro']]], 'pede a escolha');
$resposta = $controller->vincular(new Request(['merchant_id' => 'merchant-2']), vinculos(), 'vendor_a');
confere($resposta->dados['loja']['ifood']['merchant_id'] === 'merchant-2', 'a escolha conclui o vínculo');

echo '== Erros do vínculo' . PHP_EOL;
$controller = preparar();
$resposta   = $controller->vincular(new Request(['authorizationCode' => 'AUTH-1']), vinculos(), 'vendor_a');
confere($resposta->status === 422 && str_contains($resposta->dados['errors'][0], 'venceu'), 'sem código de vínculo gerado: 422 "venceu"');
respostaDoCodigo();
$controller->codigo(new Request(), vinculos(), 'vendor_a');
Http::responder(401, ['error' => ['code' => 'Unauthorized', 'message' => 'Bad credentials']]);
$resposta = $controller->vincular(new Request(['authorizationCode' => 'ERRADO']), vinculos(), 'vendor_a');
confere($resposta->status === 422 && str_contains($resposta->dados['errors'][0], 'recusou o código'), 'código recusado pelo iFood: 422');
confere(logou('[entregas] ifood: vínculo falhou', 'warning') && logsSem(['ERRADO', 'verificador-1']), 'log sem o código');
respostaDoCodigo();
$controller->codigo(new Request(), vinculos(), 'vendor_a');
Http::responder(200, ['accessToken' => 'token-1', 'type' => 'bearer', 'expiresIn' => 21600, 'refreshToken' => 'refresh-1']);
Http::responder(403, ['error' => ['code' => 'Forbidden', 'message' => 'Forbidden']]);
$resposta = $controller->vincular(new Request(['authorizationCode' => 'AUTH-2']), vinculos(), 'vendor_a');
confere($resposta->status === 422 && str_contains($resposta->dados['errors'][0], 'consulta das lojas'), 'lista de lojas recusada: 422 com a mensagem da lista (não a do código)');
confere(!isset(\Illuminate\Support\Facades\Cache::$dados[VinculosIfood::chaveDosTokens('vendor-a')]), 'lista recusada: tokens descartados (precisa de código novo)');
respostaDoCodigo();
$controller->codigo(new Request(), vinculos(), 'vendor_a');
Http::responder(200, ['accessToken' => 'token-1', 'type' => 'bearer', 'expiresIn' => 21600, 'refreshToken' => 'refresh-1']);
Http::responder(503, 'fora do ar');
confere($controller->vincular(new Request(['authorizationCode' => 'AUTH-3']), vinculos(), 'vendor_a')->status === 502, 'lista de lojas fora do ar: 502');
Http::responder(200, [['id' => 'merchant-1', 'name' => 'Pizzaria Um']]);
$resposta = $controller->vincular(new Request(['authorizationCode' => 'AUTH-3']), vinculos(), 'vendor_a');
confere($resposta->status === 200 && $resposta->dados['loja']['ifood']['situacao'] === 'vinculada', 'repetir depois da lista fora do ar vincula com os tokens guardados');
Http::responder(401, ['error' => ['code' => 'Unauthorized', 'message' => 'Bad credentials']]);
$resposta = $controller->codigo(new Request(), vinculos(), 'vendor_a');
confere($resposta->status === 422 && str_contains($resposta->dados['errors'][0], 'credenciais'), 'pedido do código recusado: 422 "credenciais"');

echo '== Desvincular' . PHP_EOL;
$controller = preparar();
vinculoDaLojaA();
Config::$valores['services.ifood.ativo'] = '';
$resposta = $controller->desvincular(new Request(), vinculos(), 'vendor_a');
confere($resposta->status === 200 && $resposta->dados['loja']['ifood'] === ['situacao' => null, 'nome' => null, 'merchant_id' => null], 'desvincula (mesmo com a integração desligada)');

echo '== Logs sem segredos' . PHP_EOL;
$GLOBALS['logsDosCasos'] = array_merge($GLOBALS['logsDosCasos'], \Illuminate\Support\Facades\Log::$registros);
\Illuminate\Support\Facades\Log::$registros = $GLOBALS['logsDosCasos'];
confere(count($GLOBALS['logsDosCasos']) > 0, 'houve logs para conferir');
confere(logsSem(['token-1', 'refresh-1', 'token-a', 'refresh-a', 'AUTH-1', 'AUTH-2', 'AUTH-3', 'ERRADO', 'ABCD-EFGH', 'verificador-1']), 'nenhum log traz token, refresh, código de autorização, código de vínculo ou verificador');

echo '== Limitador das rotas do vínculo' . PHP_EOL;
$rotas = file_get_contents('/repo/api/app/Providers/RouteServiceProvider.php');
// o RouteServiceProvider não roda nos stubs: confere o texto (mesmo padrão do entregas-loja-mapa e do entregas-motoboy-rota)
$limitador = 'RateLimiter::for(\'entregas-ifood-vinculo\', fn (Request $request) => Limit::perMinute(20)->by(\'entregas-ifood-vinculo:\' . (session(\'user\') ?: $request->ip())));';
confere(str_contains($rotas, $limitador), 'limitador nomeado entregas-ifood-vinculo: 20 por minuto por usuário');
foreach (['codigo', 'vincular'] as $metodo) {
    $rota = "Route::post('lojas/{id}/ifood/{$metodo}', [IfoodLojasController::class, '{$metodo}'])->middleware('throttle:entregas-ifood-vinculo');";
    confere(str_contains($rotas, $rota), "{$metodo}: usa o limitador nomeado");
}
confere(!str_contains($rotas, 'throttle:20,1'), 'sem o throttle:20,1 sem nome');

resumo();
