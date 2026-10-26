<?php

// Saque Pix: o AsaasCliente (único ponto de HTTP com o Asaas) e a RespostaAsaas, com o Http falso.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-asaas.php

require __DIR__ . '/stubs-saque.php';

use App\Support\Entregas\Saques\AsaasCliente;
use App\Support\Entregas\Saques\RespostaAsaas;
use Illuminate\Support\Facades\Log;
use Teste\Http as HttpFalso;

reiniciarSaque();
$asaas = new AsaasCliente(CHAVE_ASAAS);

echo '== Chamadas' . PHP_EOL;
asaas(200, ['name' => 'Central Teste', 'cpfCnpj' => '12345678000199']);
$r = $asaas->dadosComerciais();
$c = HttpFalso::$chamadas[0];
confere($r->ok() && ($r->dados['name'] ?? null) === 'Central Teste', 'dados comerciais');
confere($c['metodo'] === 'GET' && $c['url'] === 'https://asaas.teste/v3/myAccount/commercialInfo/', 'GET /myAccount/commercialInfo/ na URL da configuração: ' . $c['url']);
confere(($c['headers']['access_token'] ?? null) === CHAVE_ASAAS && ($c['headers']['User-Agent'] ?? null) === 'Entregas RestaurantePro', 'cabeçalhos access_token e User-Agent');
confere($c['timeout'] === 20, 'tempo limite de 20 s');
confere(($c['options']['allow_redirects'] ?? null) === false, 'não segue redirecionamento (o access_token iria junto): ' . json_encode($c['options']));

asaas(200, ['data' => [['object' => 'wallet', 'id' => 'wallet-1']]]);
$r = $asaas->carteiras();
confere(HttpFalso::$chamadas[1]['url'] === 'https://asaas.teste/v3/wallets' && AsaasCliente::walletDa($r) === 'wallet-1', 'GET /wallets e a wallet da conta');
asaas(200, ['data' => []]);
confere(AsaasCliente::walletDa($asaas->carteiras()) === null, 'sem wallet: null');

asaas(200, ['id' => 'tra_1', 'status' => 'PENDING']);
$asaas->transferirPix(150.5, '11999994321', 'PHONE', 'Saque Entregas', 'saque:abc');
$c = HttpFalso::$chamadas[3];
confere($c['metodo'] === 'POST' && $c['url'] === 'https://asaas.teste/v3/transfers', 'POST /transfers');
confere($c['dados'] === ['value' => 150.5, 'operationType' => 'PIX', 'pixAddressKey' => '11999994321', 'pixAddressKeyType' => 'PHONE', 'description' => 'Saque Entregas', 'externalReference' => 'saque:abc'], 'corpo da transferência Pix: ' . json_encode($c['dados']));

asaas(200, ['id' => 'tra_1', 'status' => 'DONE']);
$asaas->transferencia('tra/1');
confere(HttpFalso::$chamadas[4]['url'] === 'https://asaas.teste/v3/transfers/tra%2F1', 'GET /transfers/{id} com o id codificado');
asaas(200, ['data' => []]);
$asaas->transferencias(100);
confere(HttpFalso::$chamadas[5]['url'] === 'https://asaas.teste/v3/transfers?offset=100&limit=100', 'lista paginada');

echo '== Respostas' . PHP_EOL;
HttpFalso::$respostas[] = 'conexao';
$r = $asaas->transferencia('tra_1');
confere($r->status === 0 && $r->semResposta() && !$r->ok(), 'fora do ar: status 0, sem resposta');
confere(!str_contains(json_encode(Log::$registros), CHAVE_ASAAS), 'o log nunca traz a chave');
asaas(502, '<html>erro</html>');
$r = $asaas->transferencia('tra_1');
confere($r->semResposta() && $r->dados === null, '5xx: sem resposta, sem dados');
asaas(400, ['errors' => [['code' => 'invalid_value', 'description' => 'Saldo insuficiente.'], ['code' => 'x', 'description' => 'Outro.']]]);
$r = $asaas->transferencia('tra_1');
confere(!$r->ok() && !$r->semResposta() && !$r->chaveRecusada() && $r->mensagem('padrão') === 'Saldo insuficiente.; Outro.', '4xx: a mensagem do Asaas');
asaas(401, null);
$r = $asaas->transferencia('tra_1');
confere($r->chaveRecusada() && $r->mensagem('Chave recusada.') === 'Chave recusada.', '401: chave recusada, mensagem padrão');
confere((new RespostaAsaas(403, null))->chaveRecusada(), '403: chave recusada');

echo '== Só https' . PHP_EOL;
foreach (['http://asaas.teste/v3', 'HTTP://asaas.teste/v3', 'asaas.teste/v3', ''] as $base) {
    \Teste\Config::$valores['services.entregas.asaas_url'] = $base;
    $chamadas = count(HttpFalso::$chamadas);
    $log      = count(Log::$registros);
    $r        = (new AsaasCliente(CHAVE_ASAAS))->transferirPix(10, '11999994321', 'PHONE', 'Saque Entregas', 'saque:abc');
    confere($r->status === 0 && $r->dados === null && count(HttpFalso::$chamadas) === $chamadas, "ASAAS_API_URL \"{$base}\": não chama, sem resposta");
    confere((Log::$registros[$log] ?? null) === ['warning', '[entregas] saque: ASAAS_API_URL precisa ser https', []], "ASAAS_API_URL \"{$base}\": avisa no log");
}

resumo();
