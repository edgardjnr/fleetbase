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
confere($resultado === ['enviadas' => ['assignDriver', 'goingToOrigin'], 'recusada' => null], 'resultado com as enviadas');
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

echo '== 409: recusa, sem nova tentativa, com aviso à central' . PHP_EOL;
pedidoIfood();
Http::responder(202);
Http::responder(409, ['errorType' => 'CONFLICT', 'description' => 'Invalid state', 'code' => '409']);
$resultado = acoes()->sincronizar('order-1');
confere(linha()->ultima_acao === 'assignDriver' && linha()->recusa_acao === 'goingToOrigin' && linha()->recusa_status === 409 && linha()->recusa_em === '2026-10-05 15:00:00', 'a aceita fica; a recusa é gravada');
confere($resultado['recusada'] === ['acao' => 'goingToOrigin', 'status' => 409], 'resultado com a recusa');
$recusa = null;
foreach (\Illuminate\Support\Facades\Log::$registros as [$nivel, $mensagem, $contexto]) {
    if ($mensagem === '[entregas] ifood: ação recusada') {
        $recusa = [$nivel, $contexto];
    }
}
confere(($recusa[0] ?? null) === 'warning' && ($recusa[1]['acao'] ?? null) === 'goingToOrigin' && ($recusa[1]['status'] ?? null) === 409 && str_contains($recusa[1]['corpo'] ?? '', 'Invalid state'), 'log com a ação, o status e o corpo');
$aviso = Socket::$transmitidos[0] ?? null;
confere(($aviso['canal'] ?? null) === 'company.empresa-1' && ($aviso['dados']['event'] ?? null) === 'entregas.ifood_acao_recusada', 'aviso no canal da empresa');
confere(($aviso['dados']['data'] ?? null) === ['id' => 'order_1', 'uuid' => 'order-1', 'numero' => '4821', 'acao' => 'goingToOrigin', 'status' => 409], 'aviso com o pedido, o número, a ação e o status');
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
Http::responder(409, ['description' => 'Driver already assigned']);
acoes()->sincronizar('order-1');
confere(count(Http::$chamadas) === 1 && linha()->recusa_acao === 'assignDriver' && linha()->motoboy_no_ifood === 'driver-1', 'troca recusada (409): para, sem mandar o dispatch; aviso à central');

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

resumo();
