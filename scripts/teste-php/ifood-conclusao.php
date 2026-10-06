<?php

// Integração iFood (etapa 3): rotas do motoboy (dados do pedido, conclusão e código de entrega: MotoboyController,
// DadosIfoodDoMotoboy, ConclusaoIfood) e o painel do console (IfoodPedidosController).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-conclusao.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Http\Controllers\Entregas\IfoodPedidosController;
use App\Http\Controllers\Entregas\MotoboyController;
use App\Support\Entregas\Ifood\AcoesIfood;
use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\ConclusaoIfood;
use App\Support\Entregas\Ifood\DadosIfoodDoMotoboy;
use App\Support\Entregas\Ifood\VinculosIfood;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Http\Request;
use Teste\Banco;
use Teste\Http;
use Teste\Trava;

/** Loja A vinculada, o motoboy driver-1 (usuário user-1) e o Order order-1 já a caminho do cliente, com a linha iFood. */
function pedidoIfood(array $order = [], array $linha = []): Order
{
    reiniciarFleetbase();
    reiniciarIfood();
    vinculoDaLojaA();
    session(['company' => 'empresa-1', 'user' => 'user-1']);
    Driver::$todos[] = new Driver(['uuid' => 'driver-1', 'public_id' => 'driver_1', 'company_uuid' => 'empresa-1', 'user_uuid' => 'user-1', 'name' => 'Motoboy Ficticio', 'phone' => '+5516999990000']);
    $pedido          = new Order($order + ['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'status' => 'enroute', 'started' => true, 'driver_assigned_uuid' => 'driver-1']);
    Order::$todos[]  = $pedido;
    Banco::inserir('entregas_ifood_pedidos', $linha + [
        'company_uuid' => 'empresa-1', 'order_uuid' => 'order-1', 'pedido_ifood_id' => 'pedido-real-1', 'numero' => '4821', 'merchant_id' => 'merchant-1',
        'ultima_acao' => 'dispatch', 'motoboy_no_ifood' => 'driver-1', 'exige_codigo' => true, 'cobrar_centavos' => 5890, 'forma_pagamento' => 'CASH',
        'troco_para_centavos' => 10000, 'observacoes' => 'Sem cebola', 'complemento' => 'Apto 501', 'referencia' => 'Perto da praça',
        'telefone_0800' => '08007000000', 'localizador' => '12345678', 'telefone_expira_em' => '2026-10-05 18:00:00',
        'created_at' => '2026-10-05 14:50:00', 'updated_at' => '2026-10-05 14:50:00',
    ], false);

    return $pedido;
}

function linha(): object
{
    return (new Teste\Consulta('entregas_ifood_pedidos'))->where('order_uuid', 'order-1')->first();
}

function conclusao(): ConclusaoIfood
{
    return new ConclusaoIfood(new AcoesIfood(new VinculosIfood(new ClienteIfood()), new ClienteIfood()), new VinculosIfood(new ClienteIfood()), new ClienteIfood());
}

function doMotoboy(array $dados = []): Request
{
    $request        = new Request($dados);
    $request->token = '12|token-do-motoboy';

    return $request;
}

echo '== Dados do pedido iFood para o app' . PHP_EOL;
pedidoIfood();
$dados = (new MotoboyController())->ifood(doMotoboy(), 'order_1')->dados;
confere($dados['ifood'] === true && $dados['numero'] === '4821' && $dados['exige_codigo'] === true, 'número e código exigido');
confere($dados['cobranca'] === ['centavos' => 5890, 'forma' => 'dinheiro', 'troco_para_centavos' => 10000, 'texto' => 'Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100'], 'cobrança com o texto');
confere($dados['observacoes'] === 'Sem cebola' && $dados['complemento'] === 'Apto 501' && $dados['referencia'] === 'Perto da praça', 'observações, complemento e referência');
confere($dados['telefone'] === ['numero' => '08007000000', 'localizador' => '12345678', 'expira_em' => '2026-10-05T18:00:00-03:00'], 'motoboy do pedido: 0800 e localizador');
$aberto = new Order(['uuid' => 'order-1', 'status' => 'dispatched', 'driver_assigned_uuid' => null, 'adhoc' => true]);
confere(DadosIfoodDoMotoboy::resposta(linha(), $aberto, 'driver-1', '2026-10-05 15:00:00')['telefone'] === null, 'pedido ainda aberto: sem o 0800');
confere(DadosIfoodDoMotoboy::resposta(linha(), Order::$todos[0], 'driver-1', '2026-10-05 18:00:01')['telefone'] === null, 'localizador expirado: sem o 0800');
confere(DadosIfoodDoMotoboy::resposta(linha(), new Order(['status' => 'completed', 'driver_assigned_uuid' => 'driver-1']), 'driver-1', '2026-10-05 15:00:00')['telefone'] === null, 'pedido encerrado: sem o 0800');
confere(DadosIfoodDoMotoboy::resposta(null, Order::$todos[0], 'driver-1', '2026-10-05 15:00:00') === ['ifood' => false], 'pedido que não é do iFood: ifood falso');
$semMotoboy        = doMotoboy();
$semMotoboy->token = 'flb_live_chave';
confere((new MotoboyController())->ifood($semMotoboy, 'order_1')->status === 403, 'chave de API: 403');
Order::$todos[0]->driver_assigned_uuid = 'outro-motoboy';
confere((new MotoboyController())->ifood(doMotoboy(), 'order_1')->status === 404, 'pedido de outro motoboy: 404');

echo '== Concluir: avisa a chegada e pede o código' . PHP_EOL;
pedidoIfood();
Http::responder(202);
$resposta = (new MotoboyController())->concluirIfood(doMotoboy(), 'order_1', conclusao());
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido-real-1/arrivedAtDestination'] && linha()->ultima_acao === 'arrivedAtDestination', 'arrivedAtDestination enviado na hora');
confere($resposta->status === 200 && $resposta->dados === ['resultado' => 'precisa_codigo'] && linha()->conclusao_liberada_em === null, 'exige código: precisa_codigo, ainda não liberado');

echo '== Concluir: sem código exigido, libera' . PHP_EOL;
pedidoIfood(['status' => 'started'], ['ultima_acao' => 'goingToOrigin', 'exige_codigo' => false]);
Http::responder(202);
Http::responder(202);
Http::responder(202);
$resposta = (new MotoboyController())->concluirIfood(doMotoboy(), 'order_1', conclusao());
confere(count(Http::$chamadas) === 3 && linha()->ultima_acao === 'arrivedAtDestination', 'completa arrivedAtOrigin, dispatch e arrivedAtDestination');
confere($resposta->dados === ['resultado' => 'pode_concluir'] && linha()->conclusao_liberada_em === '2026-10-05 15:00:00' && !linha()->conclusao_sem_codigo, 'libera a conclusão comum');

echo '== Concluir: pedido de teste segue o exige_codigo (o iFood pediu o código no teste)' . PHP_EOL;
pedidoIfood([], ['teste' => true]);
Http::responder(202);
confere((new MotoboyController())->concluirIfood(doMotoboy(), 'order_1', conclusao())->dados === ['resultado' => 'precisa_codigo'], 'teste com DDCR: precisa_codigo');

echo '== Concluir: iFood fora do ar e recusa' . PHP_EOL;
pedidoIfood();
Http::responder(503, 'indisponível');
$resposta = (new MotoboyController())->concluirIfood(doMotoboy(), 'order_1', conclusao());
confere($resposta->status === 503 && $resposta->dados['resultado'] === 'tente_de_novo' && str_contains($resposta->dados['errors'][0], 'Tente de novo'), '503: tente de novo');
pedidoIfood();
Http::responder(400, ['errorType' => 'BAD_REQUEST', 'description' => 'Invalid state']);
$resposta = (new MotoboyController())->concluirIfood(doMotoboy(), 'order_1', conclusao());
confere($resposta->dados === ['resultado' => 'precisa_codigo'] && linha()->recusa_acao === 'arrivedAtDestination', 'recusa (400) na chegada: não prende o motoboy (segue para o código), com a recusa registrada');
pedidoIfood();
Http::responder(409, ['errorType' => 'CONFLICT', 'description' => 'Invalid state']);
$resposta = (new MotoboyController())->concluirIfood(doMotoboy(), 'order_1', conclusao());
confere($resposta->dados === ['resultado' => 'precisa_codigo'] && linha()->recusa_acao === null && linha()->ultima_acao === 'arrivedAtDestination', '409 na chegada (reenvio): conta como aceita, sem recusa');

echo '== Concluir: só o pedido iFood dele, iniciado e aberto' . PHP_EOL;
pedidoIfood(['started' => false, 'status' => 'dispatched']);
confere((new MotoboyController())->concluirIfood(doMotoboy(), 'order_1', conclusao())->status === 409, 'não iniciado: 409');
pedidoIfood([], ['cancelado_pelo_ifood_em' => '2026-10-05 14:59:00']);
confere((new MotoboyController())->concluirIfood(doMotoboy(), 'order_1', conclusao())->status === 409, 'cancelado pelo iFood: 409');
pedidoIfood();
Banco::$tabelas['entregas_ifood_pedidos'] = [];
confere((new MotoboyController())->concluirIfood(doMotoboy(), 'order_1', conclusao())->status === 422, 'pedido que não é do iFood: 422');
pedidoIfood(['driver_assigned_uuid' => 'outro-motoboy']);
confere((new MotoboyController())->concluirIfood(doMotoboy(), 'order_1', conclusao())->status === 404, 'pedido de outro motoboy: 404');

echo '== Código de entrega' . PHP_EOL;
pedidoIfood([], ['ultima_acao' => 'arrivedAtDestination']);
Http::responder(200, ['success' => true]);
$resposta = (new MotoboyController())->codigoIfood(doMotoboy(['codigo' => ' 1234 ']), 'order_1', conclusao());
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido-real-1/verifyDeliveryCode'] && Http::$chamadas[0]['dados'] === ['code' => '1234'], 'verifyDeliveryCode com o código');
confere($resposta->dados === ['resultado' => 'pode_concluir'] && linha()->conclusao_liberada_em !== null && !linha()->conclusao_sem_codigo, 'certo: libera');
confere(logou('código de entrega conferido', 'info') && logsSem(['1234']), 'log sem o código');
$resposta = (new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '1234']), 'order_1', conclusao());
confere($resposta->dados === ['resultado' => 'pode_concluir'] && count(Http::$chamadas) === 1, 'de novo, já liberado: não chama o iFood (o código já conferido daria erro)');

pedidoIfood([], ['ultima_acao' => 'arrivedAtDestination']);
Http::responder(400, ['errorType' => 'NOT_FOUND', 'description' => 'Confirmation code is invalid', 'code' => '400']);
$resposta = (new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '9999']), 'order_1', conclusao());
confere($resposta->status === 422 && $resposta->dados['resultado'] === 'codigo_incorreto' && str_contains($resposta->dados['errors'][0], 'Código incorreto') && linha()->conclusao_liberada_em === null, '400 "Confirmation code is invalid" (sonda): código incorreto');
Http::responder(422, ['message' => 'Verification failed']);
confere((new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '9999']), 'order_1', conclusao())->status === 422, '422 (documentação): código incorreto');
Http::responder(200, ['success' => false]);
confere((new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '9999']), 'order_1', conclusao())->dados['resultado'] === 'codigo_incorreto', '200 com success falso: código incorreto');
Http::responder(412, ['message' => 'Order not eligible']);
$resposta = (new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '9999']), 'order_1', conclusao());
confere($resposta->status === 409 && $resposta->dados['resultado'] === 'codigo_nao_conferido' && $resposta->dados['errors'] === ['Não foi possível conferir o código neste pedido. Peça à central para liberar.'], '412 (outro 4xx): não conferido, peça à central');
Http::responder(409, ['errorType' => 'CONFLICT', 'description' => 'Invalid state']);
confere((new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '9999']), 'order_1', conclusao())->dados['resultado'] === 'codigo_nao_conferido', '409: não conferido (não "tente de novo" sem fim)');
Http::responder(400, ['errorType' => 'BAD_REQUEST', 'description' => 'Order is not in a valid state', 'code' => '400']);
confere((new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '9999']), 'order_1', conclusao())->dados['resultado'] === 'codigo_nao_conferido', '400 sem indicar código inválido: não conferido');
Http::responder(422, ['errorType' => 'UNPROCESSABLE', 'description' => 'Missing field']);
confere((new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '9999']), 'order_1', conclusao())->dados['resultado'] === 'codigo_nao_conferido', '422 sem indicar código inválido: não conferido');
Http::responder(400, ['statusCode' => 400, 'message' => 'Invalid delivery code']);
confere((new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '9999']), 'order_1', conclusao())->dados['resultado'] === 'codigo_incorreto', '400 "Invalid delivery code" (documentação): código incorreto');
confere(logou('código de entrega não conferido', 'warning'), 'não conferido: aviso no log');
Http::responder(500, ['errorType' => 'INTERNAL', 'description' => 'falhou', 'trace' => 'segredo-do-corpo']);
confere((new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '9999']), 'order_1', conclusao())->status === 503, '500: tente de novo');
confere(logou('falha ao conferir o código de entrega', 'warning') && str_contains(json_encode(Illuminate\Support\Facades\Log::$registros), 'INTERNAL') && logsSem(['segredo-do-corpo']), 'log da falha só com o resumo (errorType/code/description), sem o corpo cru');
Http::responder(400, ['errorType' => 'BAD_REQUEST', 'description' => 'Code 9999 rejected for order', 'extra' => 'segredo-2']);
(new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '9999']), 'order_1', conclusao());
confere(logsSem(['segredo-2', '9999']), 'log do não conferido: resumo sem o código digitado');
Http::falharConexao();
confere((new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '9999']), 'order_1', conclusao())->status === 503, 'rede fora: tente de novo');
confere((new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '12a4']), 'order_1', conclusao())->status === 422 && count(Http::$respostas) === 0, 'código com letra: 422 sem chamar o iFood');

pedidoIfood();
Http::responder(202);
Http::responder(200, ['success' => true]);
(new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '1234']), 'order_1', conclusao());
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido-real-1/arrivedAtDestination', 'POST /logistics/v1.0/orders/pedido-real-1/verifyDeliveryCode'], 'código antes da chegada: avisa a chegada antes de conferir');

pedidoIfood([], ['ultima_acao' => 'arrivedAtDestination']);
Trava::$ocupadas['entregas:ifood-acao:order-1'] = true;
confere((new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '1234']), 'order_1', conclusao())->status === 503, 'trava das ações ocupada: tente de novo');

echo '== Código de entrega: dois cliques e teto de erros' . PHP_EOL;
pedidoIfood([], ['ultima_acao' => 'arrivedAtDestination']);
$linhaVelha = linha();
Banco::$tabelas['entregas_ifood_pedidos'][1]['conclusao_liberada_em'] = '2026-10-05 14:59:50';
$resultado  = conclusao()->conferirCodigo($linhaVelha, Order::$todos[0], '1234');
confere($resultado === 'pode_concluir' && Http::$chamadas === [], 'outro clique já liberou (linha lida antes): pode concluir, sem chamar o verifyDeliveryCode');

pedidoIfood([], ['ultima_acao' => 'arrivedAtDestination']);
for ($i = 0; $i < 10; $i++) {
    Http::responder(400, ['errorType' => 'NOT_FOUND', 'description' => 'Confirmation code is invalid', 'code' => '400']);
    (new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '9999']), 'order_1', conclusao());
}
$chamadas = count(Http::$chamadas);
$resposta = (new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '9999']), 'order_1', conclusao());
confere($chamadas === 10 && count(Http::$chamadas) === 10, '10 códigos errados no pedido: a 11ª tentativa nem chama o iFood');
confere($resposta->status === 429 && $resposta->dados['resultado'] === 'muitas_tentativas' && str_contains($resposta->dados['errors'][0], 'Muitas tentativas') && str_contains($resposta->dados['errors'][0], 'central'), 'resposta "Muitas tentativas: peça à central para liberar"');
Banco::$tabelas['entregas_ifood_pedidos'][1]['conclusao_liberada_em'] = '2026-10-05 15:00:00';
confere((new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '9999']), 'order_1', conclusao())->dados === ['resultado' => 'pode_concluir'], 'liberado pela central depois do teto: pode concluir');
pedidoIfood([], ['ultima_acao' => 'arrivedAtDestination']);
for ($i = 0; $i < 10; $i++) {
    Http::responder(500, 'erro');
    (new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '9999']), 'order_1', conclusao());
}
Http::responder(200, ['success' => true]);
confere((new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '1234']), 'order_1', conclusao())->dados === ['resultado' => 'pode_concluir'], 'iFood fora do ar não conta como código errado');
pedidoIfood([], ['ultima_acao' => 'arrivedAtDestination']);
for ($i = 0; $i < 10; $i++) {
    Http::responder(400, ['errorType' => 'BAD_REQUEST', 'description' => 'Order is not in a valid state']);
    (new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '9999']), 'order_1', conclusao());
}
Http::responder(200, ['success' => true]);
confere((new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '1234']), 'order_1', conclusao())->dados === ['resultado' => 'pode_concluir'], '4xx que não indica código inválido não conta para o teto');

echo '== Concluir: orçamento de tempo das ações estourado' . PHP_EOL;
pedidoIfood([], ['ultima_acao' => 'assignDriver']);
for ($i = 0; $i < 4; $i++) {
    Http::responder(202);
}
$acoesLentas = new class (new VinculosIfood(new ClienteIfood()), new ClienteIfood()) extends AcoesIfood {
    public float $relogio = 0;
    protected function agora(): float { return $this->relogio += 30; }
};
$resultado = (new ConclusaoIfood($acoesLentas, new VinculosIfood(new ClienteIfood()), new ClienteIfood()))->concluir(linha(), Order::$todos[0]);
confere($resultado === 'tente_de_novo' && linha()->conclusao_liberada_em === null && linha()->ultima_acao !== 'arrivedAtDestination', 'arrivedAtDestination ainda não enviado: tente de novo');

echo '== Rota síncrona: orçamento menor que o do job' . PHP_EOL;
function acoesComRelogio(): AcoesIfood
{
    return new class (new VinculosIfood(new ClienteIfood()), new ClienteIfood()) extends AcoesIfood {
        public float $relogio = 0;
        protected function agora(): float { return $this->relogio += 25; }
    };
}
pedidoIfood([], ['ultima_acao' => 'assignDriver']);
for ($i = 0; $i < 4; $i++) {
    Http::responder(202);
}
(new ConclusaoIfood(acoesComRelogio(), new VinculosIfood(new ClienteIfood()), new ClienteIfood()))->concluir(linha(), Order::$todos[0]);
confere(count(Http::$chamadas) === 1, 'concluir-ifood (rota): para no orçamento da rota (uma ação de 25 s)');
confere(defined(ConclusaoIfood::class . '::ORCAMENTO_NA_ROTA') && ConclusaoIfood::ORCAMENTO_NA_ROTA === 20 && AcoesIfood::ORCAMENTO_SEGUNDOS === 45, 'rota com 20 s, job com 45 s');
pedidoIfood([], ['ultima_acao' => 'assignDriver']);
for ($i = 0; $i < 4; $i++) {
    Http::responder(202);
}
acoesComRelogio()->sincronizar('order-1', 'arrivedAtDestination');
confere(count(Http::$chamadas) === 2, 'job (sincronizar sem orçamento): segue até os 45 s (duas ações de 25 s)');

echo '== Painel do console e a liberação sem código' . PHP_EOL;
$admin = new class {
    public function isNotAdmin() { return false; }
};
$naoAdmin = new class {
    public function isNotAdmin() { return true; }
};
pedidoIfood([], ['recusa_acao' => 'arrivedAtDestination', 'recusa_status' => 409, 'recusa_em' => '2026-10-05 14:59:00']);
\Fleetbase\Support\Auth::$usuario = $admin;
$painel                           = (new IfoodPedidosController())->painel(new Request(), 'order_1')->dados;
confere($painel['ifood'] === true && $painel['numero'] === '4821' && $painel['ultima_acao'] === 'dispatch', 'número e última ação');
confere($painel['recusa'] === ['acao' => 'arrivedAtDestination', 'status' => 409, 'em' => '2026-10-05T14:59:00-03:00'], 'a última recusa');
confere($painel['exige_codigo'] === true && $painel['conclusao_liberada_em'] === null && $painel['conclusao_sem_codigo'] === false, 'código exigido, ainda não liberado');
confere(!array_key_exists('telefone_0800', $painel) && !str_contains(json_encode($painel), '0800700'), 'sem o 0800 do cliente');
$resposta = (new IfoodPedidosController())->liberarSemCodigo(new Request(), 'order_1', conclusao());
confere($resposta->dados['conclusao_sem_codigo'] === true && $resposta->dados['conclusao_liberada_em'] === '2026-10-05T15:00:00-03:00', 'liberar sem código: liberado e registrado');
confere(logou('conclusão sem código liberada pela central', 'warning'), 'com log (e o usuário)');
pedidoIfood([], ['conclusao_liberada_em' => '2026-10-05 14:58:00']);
\Fleetbase\Support\Auth::$usuario = $admin;
$resposta = (new IfoodPedidosController())->liberarSemCodigo(new Request(), 'order_1', conclusao());
confere($resposta->dados['conclusao_sem_codigo'] === false && $resposta->dados['conclusao_liberada_em'] === '2026-10-05T14:58:00-03:00' && !logou('conclusão sem código liberada pela central'), 'já liberado (código conferido): devolve o estado, sem marcar sem código nem registrar');
\Fleetbase\Support\Auth::$usuario = $naoAdmin;
confere((new IfoodPedidosController())->painel(new Request(), 'order_1')->status === 403, 'não admin: 403');
\Fleetbase\Support\Auth::$usuario = $admin;
Banco::$tabelas['entregas_ifood_pedidos'] = [];
confere((new IfoodPedidosController())->painel(new Request(), 'order_1')->dados === ['ifood' => false], 'pedido que não é do iFood: ifood falso');

resumo();
