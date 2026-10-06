<?php

// Integração iFood (etapa 3): ações de logística (AcoesIfood), o job EnviarAcaoIfood e o ObservadorDosPedidosIfood.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-acoes.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Jobs\Entregas\EnviarAcaoIfood;
use App\Listeners\Entregas\ObservadorDosPedidosIfood;
use App\Support\Entregas\Ifood\AcoesIfood;
use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\ErroIfood;
use App\Support\Entregas\Ifood\VinculosIfood;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\Cache;
use Teste\Banco;
use Teste\Config;
use Teste\Fila;
use Teste\Http;
use Teste\Socket;
use Teste\Trava;

/** Loja A vinculada, motoboys driver-1 e driver-2, o Order order-1 e a linha do pedido iFood; devolve o Order. */
function pedidoIfood(array $order = [], array $linha = []): Order
{
    reiniciarFleetbase();
    reiniciarIfood();
    vinculoDaLojaA();
    Driver::$todos[] = new Driver(['uuid' => 'driver-1', 'public_id' => 'driver_1', 'company_uuid' => 'empresa-1', 'name' => 'Motoboy Ficticio', 'phone' => '+5516999990000']);
    Driver::$todos[] = new Driver(['uuid' => 'driver-2', 'public_id' => 'driver_2', 'company_uuid' => 'empresa-1', 'name' => 'Outro Ficticio', 'phone' => '+5516988880000']);
    $pedido          = new Order($order + ['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'status' => 'started', 'started' => true, 'driver_assigned_uuid' => 'driver-1']);
    Order::$todos[]  = $pedido;
    Banco::inserir('entregas_ifood_pedidos', $linha + [
        'company_uuid' => 'empresa-1', 'order_uuid' => 'order-1', 'pedido_ifood_id' => 'pedido-real-1', 'numero' => '4821',
        'merchant_id' => 'merchant-1', 'vendor_uuid' => 'vendor-a', 'created_at' => '2026-10-05 14:50:00', 'updated_at' => '2026-10-05 14:50:00',
    ], false);

    return $pedido;
}

function linha(): object
{
    return (new Teste\Consulta('entregas_ifood_pedidos'))->where('order_uuid', 'order-1')->first();
}

function acoes(): AcoesIfood
{
    return new AcoesIfood(new VinculosIfood(new ClienteIfood()), new ClienteIfood());
}

echo '== Aceite do pedido aberto: assignDriver e goingToOrigin, em ordem' . PHP_EOL;
pedidoIfood();
Http::responder(202);
Http::responder(202);
$resultado = acoes()->sincronizar('order-1');
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido-real-1/assignDriver', 'POST /logistics/v1.0/orders/pedido-real-1/goingToOrigin'], 'as duas ações, na ordem');
confere(Http::$chamadas[0]['dados'] === ['workerName' => 'Motoboy Ficticio', 'workerPhone' => '16999990000', 'workerVehicleType' => 'MOTORCYCLE'], 'assignDriver com nome, telefone sem o 55 e MOTORCYCLE');
confere(Http::$chamadas[1]['dados'] === null && Http::$chamadas[1]['token'] === 'token-a', 'goingToOrigin sem corpo, com o token da loja');
confere(linha()->ultima_acao === 'goingToOrigin' && linha()->motoboy_no_ifood === 'driver-1', 'ultima_acao e motoboy_no_ifood gravados');
confere($resultado === ['enviadas' => ['assignDriver', 'goingToOrigin'], 'recusada' => null, 'incompleto' => false], 'resultado com as enviadas');
confere(logou('ação enviada', 'info') && logsSem(['Motoboy Ficticio', '16999990000', '5516999990000']), 'log sem o nome e o telefone do motoboy');
confere(!isset(Trava::$ocupadas['entregas:ifood-acao:order-1']) && (Trava::$validades['entregas:ifood-acao:order-1'] ?? null) === 90, 'com a trava das ações do pedido (90 s), solta no fim');

echo '== Só atribuído pela central: só assignDriver' . PHP_EOL;
pedidoIfood(['status' => 'dispatched', 'started' => false]);
Http::responder(202);
acoes()->sincronizar('order-1');
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido-real-1/assignDriver'] && linha()->ultima_acao === 'assignDriver', 'só assignDriver');

echo '== "A caminho" sem a chegada pelo GPS: arrivedAtOrigin e dispatch' . PHP_EOL;
pedidoIfood(['status' => 'enroute'], ['ultima_acao' => 'goingToOrigin', 'motoboy_no_ifood' => 'driver-1']);
Http::responder(202);
Http::responder(202);
acoes()->sincronizar('order-1');
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido-real-1/arrivedAtOrigin', 'POST /logistics/v1.0/orders/pedido-real-1/dispatch'] && linha()->ultima_acao === 'dispatch', 'completa a sequência');

echo '== Chegada pelo GPS (alvo mínimo)' . PHP_EOL;
pedidoIfood([], ['ultima_acao' => 'goingToOrigin', 'motoboy_no_ifood' => 'driver-1']);
Http::responder(202);
acoes()->sincronizar('order-1', 'arrivedAtOrigin');
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido-real-1/arrivedAtOrigin'] && linha()->ultima_acao === 'arrivedAtOrigin', 'arrivedAtOrigin');

echo '== Nada a fazer' . PHP_EOL;
pedidoIfood(['status' => 'enroute'], ['ultima_acao' => 'dispatch', 'motoboy_no_ifood' => 'driver-1']);
acoes()->sincronizar('order-1');
confere(Http::$chamadas === [], 'já em dia: nenhuma chamada');
pedidoIfood(['driver_assigned_uuid' => null, 'started' => false, 'status' => 'dispatched']);
acoes()->sincronizar('order-1', 'goingToOrigin');
confere(Http::$chamadas === [], 'sem motoboy: nada (nem com alvo mínimo)');
pedidoIfood([], ['cancelado_pelo_ifood_em' => '2026-10-05 14:58:00']);
acoes()->sincronizar('order-1');
confere(Http::$chamadas === [], 'cancelado pelo iFood: nada');

echo '== Concluído pela central: completa até arrivedAtDestination' . PHP_EOL;
pedidoIfood(['status' => 'completed'], ['ultima_acao' => 'dispatch', 'motoboy_no_ifood' => 'driver-1']);
Http::responder(202);
acoes()->sincronizar('order-1');
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido-real-1/arrivedAtDestination'], 'arrivedAtDestination');

echo '== Concluído pela central num pedido que exigia o código: registrado' . PHP_EOL;
pedidoIfood(['status' => 'completed'], ['ultima_acao' => 'dispatch', 'motoboy_no_ifood' => 'driver-1', 'exige_codigo' => true]);
Http::responder(202);
acoes()->sincronizar('order-1');
confere(linha()->conclusao_sem_codigo === true && logou('pedido concluído sem o código do cliente', 'warning'), 'conclusao_sem_codigo e log');
pedidoIfood(['status' => 'completed'], ['ultima_acao' => 'arrivedAtDestination', 'motoboy_no_ifood' => 'driver-1', 'exige_codigo' => true, 'conclusao_liberada_em' => '2026-10-05 14:59:00']);
acoes()->sincronizar('order-1');
confere(!linha()->conclusao_sem_codigo && !logou('pedido concluído sem o código do cliente'), 'liberado pelo app (código conferido): não marca');

/** [nível, contexto] do último log com a mensagem exata, ou null. */
function ultimoLog(string $mensagem): ?array
{
    $achado = null;
    foreach (\Illuminate\Support\Facades\Log::$registros as [$nivel, $texto, $contexto]) {
        if ($texto === $mensagem) {
            $achado = [$nivel, $contexto];
        }
    }

    return $achado;
}

echo '== Recusa (4xx): sem nova tentativa, com aviso à central' . PHP_EOL;
pedidoIfood();
Http::responder(202);
Http::responder(400, ['errorType' => 'BAD_REQUEST', 'description' => 'Invalid state', 'code' => '400', 'detalhe' => 'corpo-cru']);
$resultado = acoes()->sincronizar('order-1');
confere(linha()->ultima_acao === 'assignDriver' && linha()->recusa_acao === 'goingToOrigin' && linha()->recusa_status === 400 && linha()->recusa_em === '2026-10-05 15:00:00', 'a aceita fica; a recusa é gravada');
confere($resultado['recusada'] === ['acao' => 'goingToOrigin', 'status' => 400], 'resultado com a recusa');
$recusa = ultimoLog('[entregas] ifood: ação recusada');
confere(($recusa[0] ?? null) === 'warning' && ($recusa[1]['acao'] ?? null) === 'goingToOrigin' && ($recusa[1]['status'] ?? null) === 400 && str_contains($recusa[1]['corpo'] ?? '', 'Invalid state') && str_contains($recusa[1]['corpo'] ?? '', 'BAD_REQUEST'), 'log com a ação, o status, o errorType e a descrição');
confere(!str_contains($recusa[1]['corpo'] ?? '', 'corpo-cru'), 'o log leva só errorType, code e description, nunca o corpo cru');
$aviso = Socket::$transmitidos[0] ?? null;
confere(($aviso['canal'] ?? null) === 'company.empresa-1' && ($aviso['dados']['event'] ?? null) === 'entregas.ifood_acao_recusada', 'aviso no canal da empresa');
confere(($aviso['dados']['data'] ?? null) === ['id' => 'order_1', 'uuid' => 'order-1', 'numero' => '4821', 'acao' => 'goingToOrigin', 'status' => 400], 'aviso com o pedido, o número, a ação e o status');
Http::responder(202);
acoes()->sincronizar('order-1');
confere(linha()->ultima_acao === 'goingToOrigin' && linha()->recusa_acao === null && linha()->recusa_status === null, 'a próxima mudança tenta de novo; aceita, limpa a recusa');

echo '== Socket fora do ar: a recusa fica registrada e o log avisa' . PHP_EOL;
pedidoIfood(['status' => 'dispatched', 'started' => false]);
Socket::$falhar = true;
Http::responder(400, ['errorType' => 'BAD_REQUEST', 'code' => '400']);
acoes()->sincronizar('order-1');
confere(linha()->recusa_acao === 'assignDriver' && logou('aviso de ação recusada não chegou ao socket', 'warning'), 'recusa gravada e log do socket');

echo '== Falha temporária: sobe para quem chamou, guardando o que já foi' . PHP_EOL;
pedidoIfood();
Http::responder(202);
Http::responder(503, 'indisponível');
$erro = excecao(fn () => acoes()->sincronizar('order-1'));
confere($erro instanceof ErroIfood && $erro->status === 503 && $erro->operacao === 'goingToOrigin', '503 sobe como ErroIfood');
confere(linha()->ultima_acao === 'assignDriver' && linha()->recusa_acao === null, 'assignDriver gravado, sem recusa');
pedidoIfood();
Http::falharConexao();
$erro = excecao(fn () => acoes()->sincronizar('order-1'));
confere($erro instanceof ErroIfood && $erro->status === 0 && linha()->ultima_acao === null, 'rede fora: sobe, nada gravado');

echo '== Troca de motoboy pela central' . PHP_EOL;
pedidoIfood(['driver_assigned_uuid' => 'driver-2'], ['ultima_acao' => 'goingToOrigin', 'motoboy_no_ifood' => 'driver-1']);
Http::responder(202);
acoes()->sincronizar('order-1');
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido-real-1/assignDriver'] && Http::$chamadas[0]['dados']['workerName'] === 'Outro Ficticio', 'assignDriver de novo, com o motoboy novo');
confere(linha()->ultima_acao === 'goingToOrigin' && linha()->motoboy_no_ifood === 'driver-2', 'a etapa não volta; motoboy_no_ifood trocado');
pedidoIfood(['driver_assigned_uuid' => 'driver-2', 'status' => 'enroute'], ['ultima_acao' => 'goingToOrigin', 'motoboy_no_ifood' => 'driver-1']);
Http::responder(409, ['errorType' => 'CONFLICT', 'description' => 'Driver already assigned']);
Http::responder(202);
Http::responder(202);
$resultado = acoes()->sincronizar('order-1');
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido-real-1/assignDriver', 'POST /logistics/v1.0/orders/pedido-real-1/arrivedAtOrigin', 'POST /logistics/v1.0/orders/pedido-real-1/dispatch'] && linha()->ultima_acao === 'dispatch', 'troca recusada (409): segue com as ações seguintes (não trava o dispatch)');
confere($resultado['recusada'] === ['acao' => 'assignDriver', 'status' => 409] && count(Socket::$transmitidos) === 1 && logou('ação recusada', 'warning'), 'troca recusada (409): registrada e com aviso à central');
confere(linha()->motoboy_no_ifood === 'driver-2', 'troca recusada (409): o motoboy novo fica gravado como marca');
Http::$chamadas       = [];
Socket::$transmitidos = [];
acoes()->sincronizar('order-1');
confere(Http::$chamadas === [] && Socket::$transmitidos === [], 'na sincronização seguinte a troca não se repete nem avisa de novo');
pedidoIfood(['driver_assigned_uuid' => 'driver-2', 'status' => 'enroute'], ['ultima_acao' => 'goingToOrigin', 'motoboy_no_ifood' => 'driver-1']);
Http::responder(400, ['errorType' => 'BAD_REQUEST', 'description' => 'invalid']);
acoes()->sincronizar('order-1');
confere(count(Http::$chamadas) === 1 && linha()->recusa_acao === 'assignDriver' && linha()->motoboy_no_ifood === 'driver-1', 'troca recusada com outro 4xx: para, sem mandar o dispatch');

echo '== 409 numa ação que já pode ter sido aceita (reenvio): conta como aceita' . PHP_EOL;
pedidoIfood();
Http::responder(409, ['errorType' => 'CONFLICT', 'description' => 'Driver already assigned']);
Http::responder(202);
$resultado = acoes()->sincronizar('order-1');
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido-real-1/assignDriver', 'POST /logistics/v1.0/orders/pedido-real-1/goingToOrigin'] && linha()->ultima_acao === 'goingToOrigin', '409 no assignDriver: avança e segue para o goingToOrigin');
confere(linha()->motoboy_no_ifood === 'driver-1' && linha()->recusa_acao === null && $resultado['recusada'] === null && $resultado['enviadas'] === ['assignDriver', 'goingToOrigin'], 'sem recusa; o motoboy fica gravado');
confere(logou('ação já aceita pelo iFood (409)', 'info') && Socket::$transmitidos === [], 'log info, sem aviso à central');
pedidoIfood(['status' => 'enroute'], ['ultima_acao' => 'arrivedAtOrigin', 'motoboy_no_ifood' => 'driver-1']);
Http::responder(409, ['errorType' => 'CONFLICT', 'description' => 'Invalid state']);
acoes()->sincronizar('order-1');
confere(linha()->ultima_acao === 'dispatch' && linha()->recusa_acao === null, '409 no dispatch reenviado: conta como aceito');

echo '== Log da recusa do assignDriver sem o nome e o telefone do motoboy' . PHP_EOL;
pedidoIfood(['status' => 'dispatched', 'started' => false]);
Http::responder(400, ['errorType' => 'BAD_REQUEST', 'description' => 'invalid workerPhone 16999990000 for Motoboy Ficticio', 'code' => '400']);
acoes()->sincronizar('order-1');
$recusa = json_encode(ultimoLog('[entregas] ifood: ação recusada'), JSON_UNESCAPED_UNICODE);
confere(str_contains($recusa, 'BAD_REQUEST') && !str_contains($recusa, '16999990000') && !str_contains($recusa, 'Motoboy Ficticio'), 'assignDriver recusado: o log mascara o telefone e o nome');

echo '== Orçamento de tempo do laço' . PHP_EOL;
/** AcoesIfood com relógio que anda 30 s a cada leitura. */
function acoesComRelogio(): AcoesIfood
{
    return new class (new VinculosIfood(new ClienteIfood()), new ClienteIfood()) extends AcoesIfood {
        public float $relogio = 0;
        protected function agora(): float { return $this->relogio += 30; }
    };
}
pedidoIfood(['status' => 'enroute']);
for ($i = 0; $i < 4; $i++) {
    Http::responder(202);
}
$resultado = acoesComRelogio()->sincronizar('order-1');
confere(count(Http::$chamadas) === 2 && linha()->ultima_acao === 'goingToOrigin' && $resultado['incompleto'] === true, 'passou de ORCAMENTO_SEGUNDOS: para, e o resto fica para o próximo job');
pedidoIfood();
Http::responder(202);
Http::responder(202);
confere(acoes()->sincronizar('order-1')['incompleto'] === false, 'dentro do orçamento: completo');

echo '== Recusas locais (sem chamar o iFood)' . PHP_EOL;
pedidoIfood();
Driver::$todos[0]->phone = null;
acoes()->sincronizar('order-1');
confere(Http::$chamadas === [] && linha()->recusa_acao === 'assignDriver' && linha()->recusa_status === null, 'motoboy sem telefone: recusa (status 0)');
pedidoIfood();
Banco::$tabelas['entregas_ifood_lojas'][1]['situacao'] = 'vinculo_perdido';
acoes()->sincronizar('order-1');
confere(Http::$chamadas === [] && linha()->recusa_acao === 'assignDriver', 'loja sem vínculo ativo: recusa');

echo '== Trava das ações ocupada' . PHP_EOL;
pedidoIfood();
Trava::$ocupadas['entregas:ifood-acao:order-1'] = true;
$erro = excecao(fn () => acoes()->sincronizar('order-1'));
confere($erro instanceof \Illuminate\Contracts\Cache\LockTimeoutException && Http::$chamadas === [], 'LockTimeoutException, sem chamar o iFood');

echo '== Telefone do motoboy' . PHP_EOL;
confere(AcoesIfood::telefone('+55 (16) 99999-0000') === '16999990000', 'E.164 com máscara: só DDD + número');
confere(AcoesIfood::telefone('1633334444') === '1633334444', 'fixo com DDD');
confere(AcoesIfood::telefone('999990000') === null && AcoesIfood::telefone(null) === null, 'sem DDD ou vazio: null');

echo '== Job EnviarAcaoIfood' . PHP_EOL;
pedidoIfood();
confere(EnviarAcaoIfood::enfileirar('order-1') === true && count(Fila::$jobs) === 1, 'enfileira o primeiro');
confere(EnviarAcaoIfood::enfileirar('order-1') === false && count(Fila::$jobs) === 1, 'não enfileira outro enquanto o primeiro espera');
Http::responder(202);
Http::responder(202);
$job = Fila::$jobs[0];
$job->handle(acoes());
confere(linha()->ultima_acao === 'goingToOrigin' && !isset(Cache::$dados['entregas:ifood-acao-pendente:order-1']), 'o job envia e apaga a marca de pendente');
confere(EnviarAcaoIfood::enfileirar('order-1') === true, 'depois dele, uma mudança nova enfileira de novo');
confere($job->afterCommit === true && $job->timeout < 90 && $job->maxExceptions === 5, 'afterCommit, timeout abaixo do retry_after (90 s) e 5 exceções');
pedidoIfood();
EnviarAcaoIfood::enfileirar('order-1');
confere((Cache::$validades['entregas:ifood-acao-pendente:order-1'] ?? 0) <= 120, 'marca de pendente vale no máximo ~120 s (enfileirado numa transação desfeita, o job não sai e a marca não pode segurar o pedido)');

const PENDENTE = 'entregas:ifood-acao-pendente:order-1';
pedidoIfood();
Http::responder(202);
Http::responder(429, null, ['Retry-After' => '42']);
$job = new EnviarAcaoIfood('order-1');
$job->handle(acoes());
confere($job->liberadoPor === 42 && linha()->ultima_acao === 'assignDriver', '429: volta para a fila pelo Retry-After');
confere(isset(Cache::$dados[PENDENTE]) && Cache::$validades[PENDENTE] > 42, '429: a marca de pendente volta (o job ainda está na fila), valendo mais que a espera');

pedidoIfood();
Trava::$ocupadas['entregas:ifood-acao:order-1'] = true;
$job = new EnviarAcaoIfood('order-1');
$job->handle(acoes());
confere($job->liberadoPor === EnviarAcaoIfood::ESPERA_DA_TRAVA && isset(Cache::$dados[PENDENTE]), 'trava ocupada: volta para a fila, com a marca de pendente');

pedidoIfood();
Http::responder(500, 'erro com detalhes');
$job  = new EnviarAcaoIfood('order-1');
$erro = excecao(fn () => $job->handle(acoes()));
confere($erro instanceof ErroIfood && $erro->status === 500 && $erro->corpo === '', '5xx: sobe sem o corpo (nova tentativa pelo $backoff)');
confere(isset(Cache::$dados[PENDENTE]) && Cache::$validades[PENDENTE] > max($job->backoff), '5xx: a marca de pendente volta, valendo mais que a maior espera do $backoff');
$job->failed($erro);
confere(!isset(Cache::$dados[PENDENTE]), 'failed(): apaga a marca de pendente');

pedidoIfood(['status' => 'enroute']);
for ($i = 0; $i < 4; $i++) {
    Http::responder(202);
}
$job = new EnviarAcaoIfood('order-1');
$job->handle(acoesComRelogio());
confere($job->liberadoPor !== null && isset(Cache::$dados[PENDENTE]) && linha()->ultima_acao === 'goingToOrigin', 'orçamento de tempo estourado: o job volta para a fila (com a marca) para o resto');

pedidoIfood();
Config::$valores['services.ifood.ativo'] = '';
(new EnviarAcaoIfood('order-1'))->handle(acoes());
confere(Http::$chamadas === [], 'integração desligada: nada');

pedidoIfood();
(new EnviarAcaoIfood('order-1'))->failed(new ErroIfood('goingToOrigin', 503));
confere(logou('ação não enviada; tentativas esgotadas', 'error') && linha()->recusa_acao === 'assignDriver' && linha()->recusa_status === 503, 'tentativas esgotadas: log e a próxima ação vira recusa (aviso)');
$avisos = count(Socket::$transmitidos);
(new EnviarAcaoIfood('order-1'))->failed(new ErroIfood('goingToOrigin', 502));
confere(count(Socket::$transmitidos) === $avisos && linha()->recusa_status === 503, 'já com recusa registrada: desistir() não grava nem avisa de novo');
pedidoIfood([], ['ultima_acao' => 'goingToOrigin', 'motoboy_no_ifood' => 'driver-1']);
(new EnviarAcaoIfood('order-1'))->failed(new ErroIfood('goingToOrigin', 503));
confere(linha()->recusa_acao === null && Socket::$transmitidos === [], 'nada faltando: desistir() sai cedo, sem recusa falsa');
pedidoIfood(['driver_assigned_uuid' => null, 'started' => false, 'status' => 'created']);
(new EnviarAcaoIfood('order-1'))->failed(new ErroIfood('assignDriver', 503));
confere(linha()->recusa_acao === null && Socket::$transmitidos === [], 'sem motoboy: desistir() não grava recusa de assignDriver');
pedidoIfood(['status' => 'enroute'], ['ultima_acao' => 'dispatch', 'motoboy_no_ifood' => 'driver-1']);
(new EnviarAcaoIfood('order-1', 'arrivedAtDestination'))->failed(new ErroIfood('arrivedAtDestination', 503));
confere(linha()->recusa_acao === 'arrivedAtDestination', 'chegada pelo GPS esgotada: a recusa é a do alvo mínimo do job');
pedidoIfood();
Trava::$ocupadas['entregas:ifood-acao:order-1'] = true;
$erro = excecao(fn () => (new EnviarAcaoIfood('order-1'))->failed(new ErroIfood('assignDriver', 503)));
confere($erro === null && linha()->recusa_acao === null, 'trava das ações ocupada: desistir() não grava (quem está com a trava registra o resultado)');

echo '== ObservadorDosPedidosIfood' . PHP_EOL;
$pedido            = pedidoIfood();
$pedido->alterados = ['status'];
ObservadorDosPedidosIfood::aoAtualizar($pedido);
confere(count(Fila::$jobs) === 1 && Fila::$jobs[0]->orderUuid === 'order-1', 'status mudou: enfileira');
$pedido            = pedidoIfood();
$pedido->alterados = ['notes', 'updated_at'];
ObservadorDosPedidosIfood::aoAtualizar($pedido);
confere(Fila::$jobs === [], 'outro campo: nada');
$pedido            = pedidoIfood();
$pedido->alterados = ['driver_assigned_uuid'];
Banco::$tabelas['entregas_ifood_pedidos'] = [];
ObservadorDosPedidosIfood::aoAtualizar($pedido);
confere(Fila::$jobs === [], 'pedido que não é do iFood: nada');
$pedido            = pedidoIfood();
$pedido->alterados = ['started'];
Config::$valores['services.ifood.ativo'] = '';
ObservadorDosPedidosIfood::aoAtualizar($pedido);
confere(Fila::$jobs === [], 'integração desligada: nada');
$pedido            = pedidoIfood();
$pedido->alterados = ['started'];
Fila::$falhar      = new RuntimeException('redis fora');
$erro              = excecao(fn () => ObservadorDosPedidosIfood::aoAtualizar($pedido));
confere($erro === null && logou('falha ao enfileirar a ação do pedido', 'warning') && !isset(Cache::$dados['entregas:ifood-acao-pendente:order-1']), 'fila fora: não lança, registra e não deixa a marca de pendente');

pedidoIfood();
$evento = new class () {
    public $modelUuid = 'order-1';
    public function getModelRecord() { throw new LogicException('não deve reler o pedido'); }
};
ObservadorDosPedidosIfood::aoAtribuirMotoboy($evento);
confere(count(Fila::$jobs) === 1 && Fila::$jobs[0]->orderUuid === 'order-1', 'OrderDriverAssigned (bulk-assign-driver): enfileira pelo modelUuid, sem reler o pedido');
resumo();
