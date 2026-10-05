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

function preparar(bool $admin = true): ControllerDeTeste
{
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
confere(excecao(fn () => $controller->codigo(new Request(), vinculos(), 'vendor_x')) !== null, 'loja de outra empresa ou inexistente: 404');

echo '== Vincular' . PHP_EOL;
confere($controller->vincular(new Request([]), vinculos(), 'vendor_a')->status === 422, 'sem código: 422');
respostaDoCodigo();
$controller->codigo(new Request(), vinculos(), 'vendor_a');
Http::responder(200, ['accessToken' => 'token-1', 'type' => 'bearer', 'expiresIn' => 21600, 'refreshToken' => 'refresh-1']);
Http::responder(200, [['id' => 'merchant-1', 'name' => 'Pizzaria Um']]);
$resposta = $controller->vincular(new Request(['authorizationCode' => 'AUTH-1']), vinculos(), 'vendor_a');
confere($resposta->status === 200 && $resposta->dados === ['loja' => ['id' => 'vendor_a', 'ifood' => ['situacao' => 'vinculada', 'nome' => 'Pizzaria Um', 'merchant_id' => 'merchant-1']]], 'devolve a loja com o bloco ifood (sem tokens)');
confere(Banco::linhas('entregas_ifood_lojas')[0]->company_uuid === 'empresa-1', 'gravado na empresa da sessão');

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

resumo();
