<?php

// Integração iFood: a porta HTTP (ClienteIfood) e os erros tipados (ErroIfood).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-cliente.php

require __DIR__ . '/stubs-ifood.php';

use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\ErroIfood;
use Illuminate\Support\Facades\Log;
use Teste\Config;
use Teste\Http;

const BASE = 'https://merchant-api.ifood.com.br';

echo '== Interruptor' . PHP_EOL;
reiniciarIfood();
confere(ClienteIfood::ligada(), 'ligada com ENTREGAS_IFOOD=1 e as credenciais');
foreach (['' => 'vazio', '0' => '0', 'false' => 'false'] as $valor => $nome) {
    Config::$valores['services.ifood.ativo'] = $valor;
    confere(!ClienteIfood::ligada(), "ENTREGAS_IFOOD {$nome} = desligada");
}
Config::$valores['services.ifood.ativo'] = 'true';
confere(ClienteIfood::ligada(), 'ENTREGAS_IFOOD=true também liga');
Config::$valores['services.ifood.client_secret'] = '';
confere(!ClienteIfood::ligada(), 'sem o client secret = desligada');

echo '== Código de vínculo (userCode)' . PHP_EOL;
reiniciarIfood();
$cliente = new ClienteIfood();
Http::responder(200, ['userCode' => 'ABCD-EFGH', 'authorizationCodeVerifier' => 'verificador-1', 'verificationUrl' => 'https://portal.ifood.com.br/apps/code', 'verificationUrlComplete' => 'https://portal.ifood.com.br/apps/code?c=ABCD-EFGH', 'expiresIn' => 600]);
$codigo  = $cliente->pedirCodigoDeVinculo();
$chamada = Http::$chamadas[0];
confere($chamada['metodo'] === 'POST' && $chamada['url'] === BASE . '/authentication/v1.0/oauth/userCode', 'POST no /oauth/userCode');
confere($chamada['form'] === true && $chamada['dados'] === ['clientId' => 'cliente-teste'], 'form-urlencoded só com o clientId');
confere($chamada['timeout'] === ClienteIfood::TEMPO_LIMITE, 'com tempo limite');
confere($codigo['userCode'] === 'ABCD-EFGH' && $codigo['authorizationCodeVerifier'] === 'verificador-1', 'devolve o userCode e o verificador');

echo '== Token (authorization_code e refresh_token)' . PHP_EOL;
Http::responder(200, ['accessToken' => 'token-1', 'type' => 'bearer', 'expiresIn' => 21600, 'refreshToken' => 'refresh-1']);
$tokens  = $cliente->trocarCodigo('AUTH-1', 'verificador-1');
$chamada = Http::$chamadas[1];
confere($chamada['url'] === BASE . '/authentication/v1.0/oauth/token' && $chamada['form'] === true, 'POST form no /oauth/token');
confere($chamada['dados'] === ['grantType' => 'authorization_code', 'clientId' => 'cliente-teste', 'clientSecret' => 'segredo-teste', 'authorizationCode' => 'AUTH-1', 'authorizationCodeVerifier' => 'verificador-1'], 'campos do authorization_code');
confere($tokens['accessToken'] === 'token-1' && $tokens['refreshToken'] === 'refresh-1', 'devolve os tokens como vieram');
Http::responder(200, ['accessToken' => 'token-2', 'type' => 'bearer', 'expiresIn' => 21600]);
$cliente->renovar('refresh-1');
confere(Http::$chamadas[2]['dados'] === ['grantType' => 'refresh_token', 'clientId' => 'cliente-teste', 'clientSecret' => 'segredo-teste', 'refreshToken' => 'refresh-1'], 'campos do refresh_token');
Http::responder(200, ['type' => 'bearer']);
$erro = excecao(fn () => $cliente->renovar('refresh-1'));
confere($erro instanceof ErroIfood && $erro->operacao === 'refresh', 'resposta sem accessToken vira ErroIfood');

echo '== Lojas do token' . PHP_EOL;
reiniciarIfood();
Http::responder(200, [['id' => 'merchant-1', 'name' => 'Pizzaria Um', 'corporateName' => 'Pizzaria Um LTDA']]);
$lojas = $cliente->lojasDoToken('token-1');
confere(Http::urls() === ['GET /merchant/v1.0/merchants'] && Http::$chamadas[0]['token'] === 'token-1', 'GET /merchant/v1.0/merchants com Bearer');
confere($lojas === [['id' => 'merchant-1', 'name' => 'Pizzaria Um', 'corporateName' => 'Pizzaria Um LTDA']], 'devolve as lojas');

echo '== Polling' . PHP_EOL;
reiniciarIfood();
$evento = ['id' => 'ev-1', 'code' => 'PLC', 'fullCode' => 'PLACED', 'orderId' => 'pedido-1', 'merchantId' => 'merchant-1', 'createdAt' => '2026-10-05T18:00:00.123Z', 'salesChannel' => 'IFOOD'];
Http::responder(200, [$evento]);
$eventos = $cliente->polling('token-1', ['merchant-1', 'merchant-2', 'merchant-1']);
$chamada = Http::$chamadas[0];
confere($chamada['metodo'] === 'GET' && $chamada['url'] === BASE . '/events/v1.0/events:polling', 'GET /events/v1.0/events:polling');
confere($chamada['dados'] === ['excludeHeartbeat' => 'true'], 'com excludeHeartbeat=true (sem ele a loja abre indevidamente)');
confere($chamada['headers'] === ['x-polling-merchants' => 'merchant-1,merchant-2'], 'x-polling-merchants sem repetidos');
confere($chamada['token'] === 'token-1' && $eventos === [$evento], 'Bearer e eventos devolvidos');
Http::responder(204);
confere($cliente->polling('token-1', ['merchant-1']) === [], '204 = nenhum evento');
$cento = array_map(fn ($i) => "merchant-{$i}", range(1, 101));
confere(excecao(fn () => $cliente->polling('token-1', $cento)) instanceof InvalidArgumentException, 'mais de 100 lojas numa chamada é recusado antes de chamar');
confere(excecao(fn () => $cliente->polling('token-1', [])) instanceof InvalidArgumentException, 'nenhuma loja também');
confere(count(Http::$chamadas) === 2, 'nenhuma chamada a mais');

echo '== Ack' . PHP_EOL;
reiniciarIfood();
Http::responder(202);
$cliente->ack('token-1', ['ev-1', 'ev-2', 'ev-1']);
confere(Http::urls() === ['POST /events/v1.0/events/acknowledgment'], 'POST /events/v1.0/events/acknowledgment');
confere(Http::$chamadas[0]['dados'] === [['id' => 'ev-1'], ['id' => 'ev-2']] && Http::$chamadas[0]['form'] === false, 'corpo JSON [{id}] sem repetidos');
Http::responder(202);
Http::responder(202);
$cliente->ack('token-1', array_map(fn ($i) => "ev-{$i}", range(1, 2001)));
confere(count(Http::$chamadas) === 3 && count(Http::$chamadas[1]['dados']) === 2000 && count(Http::$chamadas[2]['dados']) === 1, 'mais de 2000 ids vão em dois acks');

echo '== Pedido do Logistics' . PHP_EOL;
reiniciarIfood();
Http::responder(200, ['id' => 'pedido-1', 'displayId' => '4821']);
$pedido = $cliente->pedidoLogistics('token-1', 'pedido-1');
confere(Http::urls() === ['GET /logistics/v1.0/orders/pedido-1'] && $pedido['displayId'] === '4821', 'GET /logistics/v1.0/orders/{id}');

echo '== Erros' . PHP_EOL;
reiniciarIfood();
Http::responder(429, ['message' => 'Too Many Requests'], ['Retry-After' => '17']);
$erro = excecao(fn () => $cliente->polling('token-1', ['merchant-1']));
confere($erro instanceof ErroIfood && $erro->limiteExcedido() && $erro->retryAfter === 17 && $erro->temporario(), '429 com Retry-After: 17 s');
Http::responder(429, ['code' => '429', 'message' => 'Throttling applied.']);
$erro = excecao(fn () => $cliente->polling('token-1', ['merchant-1']));
confere($erro instanceof ErroIfood && $erro->retryAfter === ClienteIfood::ESPERA_PADRAO_429, '429 sem Retry-After: espera padrão');
Http::responder(401, ['error' => ['code' => 'Unauthorized', 'message' => 'Bad credentials']]);
$erro = excecao(fn () => $cliente->pedidoLogistics('token-1', 'pedido-1'));
confere($erro instanceof ErroIfood && $erro->naoAutorizado() && !$erro->temporario() && $erro->operacao === 'pedido', '401 vira naoAutorizado');
Http::responder(403, ['unauthorizedMerchants' => ['merchant-9']]);
$erro = excecao(fn () => $cliente->polling('token-1', ['merchant-9']));
confere($erro instanceof ErroIfood && $erro->status === 403 && $erro->corpoJson() === ['unauthorizedMerchants' => ['merchant-9']], '403 traz o corpo (unauthorizedMerchants)');
Http::responder(404, ['message' => 'Order not found']);
$erro = excecao(fn () => $cliente->pedidoLogistics('token-1', 'pedido-x'));
confere($erro instanceof ErroIfood && $erro->status === 404 && !$erro->temporario(), '404 não é temporário');
Http::responder(503, 'Service Unavailable');
$erro = excecao(fn () => $cliente->pedidoLogistics('token-1', 'pedido-1'));
confere($erro instanceof ErroIfood && $erro->temporario() && $erro->corpo === 'Service Unavailable', '5xx é temporário');
Http::falharConexao();
$erro = excecao(fn () => $cliente->ack('token-1', ['ev-1']));
confere($erro instanceof ErroIfood && $erro->status === 0 && $erro->temporario(), 'falha de rede vira status 0');
confere(Log::$registros === [], 'a porta HTTP não registra nada no log');

resumo();
