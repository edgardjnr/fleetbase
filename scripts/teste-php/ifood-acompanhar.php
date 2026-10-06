<?php

// Integração iFood (etapa 3): o comando entregas:ifood-acompanhar (chegada pelo GPS e reconciliação).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-acompanhar.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Console\Commands\Entregas\AcompanharIfood;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Teste\Banco;
use Teste\Config;
use Teste\Fila;

/**
 * Pedido iFood order-1 com a loja em (-21.1775, -47.8103) e o cliente ~1 km ao norte; o motoboy driver-1 na posição
 * dada. Devolve o Order.
 */
function pedidoIfood(array $order = [], array $linha = [], ?array $posicao = null): Order
{
    reiniciarFleetbase();
    reiniciarIfood();
    Driver::$todos[]  = new Driver(['uuid' => 'driver-1', 'company_uuid' => 'empresa-1', 'location' => $posicao ? new Point($posicao[0], $posicao[1]) : null, 'updated_at' => '2026-10-05 14:59:30']);
    $payload          = new Payload();
    $payload->pickup  = new Place(['location' => new Point(-21.1775, -47.8103)]);
    $payload->dropoff = new Place(['location' => new Point(-21.1685, -47.8103)]);
    $pedido           = new Order($order + ['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'status' => 'started', 'started' => true, 'driver_assigned_uuid' => 'driver-1', 'payload' => $payload]);
    Order::$todos[]   = $pedido;
    Banco::inserir('entregas_ifood_pedidos', $linha + [
        'company_uuid' => 'empresa-1', 'order_uuid' => 'order-1', 'pedido_ifood_id' => 'pedido-real-1', 'numero' => '4821', 'merchant_id' => 'merchant-1',
        'ultima_acao' => 'goingToOrigin', 'motoboy_no_ifood' => 'driver-1', 'created_at' => '2026-10-05 14:30:00', 'updated_at' => '2026-10-05 14:30:00',
    ], false);

    return $pedido;
}

function rodar(): void
{
    (new AcompanharIfood())->handle();
}

function enfileirados(): array
{
    return array_map(fn ($job) => [$job->orderUuid, $job->alvoMinimo], Fila::$jobs);
}

echo '== Chegada pelo GPS' . PHP_EOL;
pedidoIfood([], [], [-21.1780, -47.8103]);
rodar();
confere(enfileirados() === [['order-1', 'arrivedAtOrigin']] && logou('chegada pelo GPS', 'info'), 'a ~56 m da loja indo para lá: arrivedAtOrigin');
pedidoIfood([], [], [-21.1800, -47.8103]);
rodar();
confere(enfileirados() === [], 'a ~280 m da loja: nada');
pedidoIfood(['status' => 'enroute'], ['ultima_acao' => 'dispatch'], [-21.1690, -47.8103]);
rodar();
confere(enfileirados() === [['order-1', 'arrivedAtDestination']], 'a caminho e a ~56 m do cliente: arrivedAtDestination');
pedidoIfood(['status' => 'enroute'], ['ultima_acao' => 'dispatch'], [-21.1775, -47.8103]);
rodar();
confere(enfileirados() === [], 'a caminho, ainda na loja: nada');
pedidoIfood(['status' => 'started'], ['ultima_acao' => 'assignDriver'], [-21.1775, -47.8103]);
rodar();
confere(enfileirados() === [['order-1', 'arrivedAtOrigin']], 'aceitou já na loja (goingToOrigin ainda não saiu): arrivedAtOrigin (o job manda goingToOrigin antes)');

echo '== Reconciliação (o que o observador não pegou)' . PHP_EOL;
pedidoIfood(['status' => 'enroute'], [], [-21.1730, -47.8103]);
rodar();
confere(enfileirados() === [['order-1', null]], 'estado a caminho, ultima_acao goingToOrigin: enfileira (sem alvo do GPS)');
pedidoIfood(['driver_assigned_uuid' => 'driver-2', 'status' => 'started'], [], null);
rodar();
confere(enfileirados() === [['order-1', null]], 'motoboy trocado: enfileira');
pedidoIfood(['status' => 'completed'], ['ultima_acao' => 'dispatch'], [-21.1690, -47.8103]);
rodar();
confere(enfileirados() === [['order-1', null]], 'concluído sem arrivedAtDestination: enfileira (sem GPS)');

echo '== Fica de fora' . PHP_EOL;
pedidoIfood([], ['recusa_acao' => 'arrivedAtOrigin', 'recusa_status' => 409], [-21.1780, -47.8103]);
rodar();
confere(enfileirados() === [], 'com recusa registrada: não repete sozinho');
pedidoIfood([], ['cancelado_pelo_ifood_em' => '2026-10-05 14:59:00'], [-21.1780, -47.8103]);
rodar();
confere(enfileirados() === [], 'cancelado pelo iFood');
pedidoIfood(['status' => 'canceled'], [], [-21.1780, -47.8103]);
rodar();
confere(enfileirados() === [], 'Order cancelado');
pedidoIfood(['status' => 'completed'], ['ultima_acao' => 'arrivedAtDestination'], null);
rodar();
confere(enfileirados() === [], 'já com arrivedAtDestination');
pedidoIfood([], ['created_at' => '2026-10-04 14:59:59'], [-21.1780, -47.8103]);
rodar();
confere(enfileirados() === [], 'criado há mais de 24 h');
pedidoIfood(['driver_assigned_uuid' => null, 'started' => false, 'status' => 'dispatched'], ['ultima_acao' => null, 'motoboy_no_ifood' => null], null);
rodar();
confere(enfileirados() === [], 'aberto, sem motoboy');
pedidoIfood([], [], [-21.1780, -47.8103]);
\Illuminate\Support\Facades\Cache::$dados['entregas:ifood-acao-pendente:order-1'] = true;
rodar();
confere(enfileirados() === [], 'já há um job do pedido esperando: não enfileira outro');
pedidoIfood([], [], [-21.1780, -47.8103]);
Config::$valores['services.ifood.ativo'] = '';
rodar();
confere(enfileirados() === [], 'integração desligada: nada');

echo '== Posição velha do GPS' . PHP_EOL;
pedidoIfood([], [], [-21.1780, -47.8103]);
Driver::$todos[0]->updated_at = '2026-10-05 14:54:00';
rodar();
confere(enfileirados() === [], 'posição de 6 min atrás (drivers.updated_at): não vale como chegada');
pedidoIfood([], [], [-21.1780, -47.8103]);
Driver::$todos[0]->updated_at = '2026-10-05 14:56:00';
rodar();
confere(enfileirados() === [['order-1', 'arrivedAtOrigin']], 'posição de 4 min atrás: vale');

echo '== Janela e limite da rodada no SQL' . PHP_EOL;
pedidoIfood([], ['created_at' => '2026-10-04 10:00:00', 'agendado' => true, 'despachado_em' => '2026-10-05 14:40:00'], [-21.1780, -47.8103]);
rodar();
confere(enfileirados() === [['order-1', 'arrivedAtOrigin']], 'agendado criado há mais de 24 h, despachado há 20 min: acompanhado');
pedidoIfood([], [], [-21.1780, -47.8103]);
$nossa = Banco::$tabelas['entregas_ifood_pedidos'][1];
Banco::$tabelas['entregas_ifood_pedidos'] = [];
for ($i = 0; $i < AcompanharIfood::POR_RODADA; $i++) {
    Order::$todos[] = new Order(['uuid' => "fechado-{$i}", 'company_uuid' => 'empresa-1', 'status' => $i % 2 ? 'canceled' : 'completed', 'driver_assigned_uuid' => 'driver-1']);
    Banco::inserir('entregas_ifood_pedidos', [
        'company_uuid' => 'empresa-1', 'order_uuid' => "fechado-{$i}", 'pedido_ifood_id' => "pedido-{$i}", 'merchant_id' => 'merchant-1',
        'ultima_acao' => $i % 2 ? 'goingToOrigin' : 'arrivedAtDestination', 'created_at' => '2026-10-05 14:00:00', 'updated_at' => '2026-10-05 14:00:00',
    ], false);
}
unset($nossa['id']);
Banco::inserir('entregas_ifood_pedidos', $nossa, false);
rodar();
confere(enfileirados() === [['order-1', 'arrivedAtOrigin']], 'os encerrados (Order cancelado, já com arrivedAtDestination) ficam fora no SQL e não gastam o limite da rodada');

echo '== Um pedido com erro não para a rodada' . PHP_EOL;
pedidoIfood([], [], [-21.1780, -47.8103]);
Order::$todos[0]->payload = new class {
    public function getPickupOrFirstWaypoint() { throw new RuntimeException('falhou'); }
};
rodar();
confere(logou('falha ao acompanhar o pedido', 'warning'), 'registra e segue');

resumo();
