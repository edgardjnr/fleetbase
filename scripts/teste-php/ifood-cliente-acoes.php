<?php

// Integração iFood (etapa 3): as ações de logística e o código de entrega no ClienteIfood.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-cliente-acoes.php

require __DIR__ . '/stubs-ifood.php';

use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\ErroIfood;
use Teste\Http;

echo '== Ações de logística' . PHP_EOL;
reiniciarIfood();
Http::responder(202);
(new ClienteIfood())->acaoLogistica('token-a', 'pedido-real-1', 'goingToOrigin');
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido-real-1/goingToOrigin'], 'POST na URL da ação');
confere(Http::$chamadas[0]['dados'] === null && Http::$chamadas[0]['token'] === 'token-a' && Http::$chamadas[0]['timeout'] === 15, 'sem corpo, com o token e o tempo limite');

reiniciarIfood();
Http::responder(202);
(new ClienteIfood())->acaoLogistica('token-a', 'pedido/estranho', 'assignDriver', ['workerName' => 'Fulano', 'workerPhone' => '16999990000', 'workerVehicleType' => 'MOTORCYCLE']);
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido%2Festranho/assignDriver'], 'id do pedido codificado na URL');
confere(Http::$chamadas[0]['dados'] === ['workerName' => 'Fulano', 'workerPhone' => '16999990000', 'workerVehicleType' => 'MOTORCYCLE'], 'assignDriver com o corpo');

reiniciarIfood();
$erro = excecao(fn () => (new ClienteIfood())->acaoLogistica('token-a', 'pedido-real-1', 'cancel'));
confere($erro instanceof InvalidArgumentException && Http::$chamadas === [], 'ação desconhecida: nem chama o iFood');

reiniciarIfood();
Http::responder(409, ['errorType' => 'CONFLICT', 'description' => 'Invalid state']);
$erro = excecao(fn () => (new ClienteIfood())->acaoLogistica('token-a', 'pedido-real-1', 'dispatch'));
confere($erro instanceof ErroIfood && $erro->status === 409 && $erro->operacao === 'dispatch' && str_contains($erro->corpo, 'Invalid state'), '409: ErroIfood com a ação e o corpo');

reiniciarIfood();
Http::responder(429, null, ['Retry-After' => '30']);
$erro = excecao(fn () => (new ClienteIfood())->acaoLogistica('token-a', 'pedido-real-1', 'dispatch'));
confere($erro instanceof ErroIfood && $erro->limiteExcedido() && $erro->retryAfter === 30, '429 com o Retry-After');

echo '== Código de entrega' . PHP_EOL;
reiniciarIfood();
Http::responder(200, ['success' => true]);
$resposta = (new ClienteIfood())->verificarCodigo('token-a', 'pedido-real-1', '1234');
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido-real-1/verifyDeliveryCode'] && Http::$chamadas[0]['dados'] === ['code' => '1234'], 'verifyDeliveryCode com {code}');
confere($resposta === ['success' => true], 'devolve o JSON');

reiniciarIfood();
Http::responder(400, ['errorType' => 'NOT_FOUND', 'description' => 'Confirmation code is invalid', 'code' => '400']);
$erro = excecao(fn () => (new ClienteIfood())->verificarCodigo('token-a', 'pedido-real-1', '9999'));
confere($erro instanceof ErroIfood && $erro->status === 400 && $erro->operacao === 'verifyDeliveryCode', 'código errado (sonda): ErroIfood 400');
$rastro = (new ReflectionMethod(ClienteIfood::class, 'verificarCodigo'))->getParameters()[2]->getAttributes(SensitiveParameter::class);
confere(count($rastro) === 1, 'o código fica fora do stack trace (#[\SensitiveParameter])');

echo '== Trava "Atualize o app"' . PHP_EOL;
reiniciarIfood();
confere(ClienteIfood::exigeAppNovo() === false, 'desligada por padrão');
\Teste\Config::$valores['services.ifood.exige_app_novo'] = '1';
confere(ClienteIfood::exigeAppNovo() === true, 'ENTREGAS_IFOOD_EXIGE_APP_NOVO=1 liga');

resumo();
