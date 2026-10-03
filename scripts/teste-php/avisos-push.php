<?php

// Avisos push do motoboy (AvisosDoMotoboy e CanalFcmEntregas): texto em pt-BR, canal e formato.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/avisos-push.php

require __DIR__ . '/stubs.php';
require __DIR__ . '/stubs-avisos.php';
require '/repo/packages/fleetops/server/src/Notifications/OrderPing.php';
// as demais notificações também são as reais: Fleet-Ops e core-api (cópias em packages/, nas versões da produção)
require '/repo/packages/fleetops/server/src/Notifications/OrderAssigned.php';
require '/repo/packages/fleetops/server/src/Notifications/OrderDispatched.php';
require '/repo/packages/fleetops/server/src/Notifications/OrderCanceled.php';
require '/repo/packages/fleetops/server/src/Notifications/OrderFailed.php';
require '/repo/packages/fleetops/server/src/Notifications/OrderCompleted.php';
require '/repo/packages/fleetops/server/src/Notifications/WaypointCompleted.php';
require '/repo/packages/core-api/src/Notifications/ChatMessageReceived.php';
require '/repo/packages/core-api/src/Notifications/TestPushNotification.php';

use App\Notifications\Entregas\AvisosDoMotoboy;
use App\Notifications\Entregas\LembretePedidoAberto;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\OrderAssigned;
use Fleetbase\FleetOps\Notifications\OrderCanceled;
use Fleetbase\FleetOps\Notifications\OrderCompleted;
use Fleetbase\FleetOps\Notifications\OrderDispatched;
use Fleetbase\FleetOps\Notifications\OrderFailed;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Fleetbase\FleetOps\Notifications\WaypointCompleted;
use Fleetbase\Notifications\ChatMessageReceived;
use Fleetbase\Notifications\TestPushNotification;

function pedidoDoTeste(): Order
{
    $pedido            = new Order();
    $pedido->uuid      = 'uuid-1';
    $pedido->public_id = 'order_abc';

    return $pedido;
}

// aviso real do Fleet-Ops ou do core com o título e o texto originais (em inglês) e os dados que ele manda; a instância
// nasce sem o construtor real, que pede os modelos (pedido, parada, mensagem) e monta o texto a partir deles
function aviso(string $classe, string $titulo, string $texto, array $dados)
{
    $notificacao          = (new ReflectionClass($classe))->newInstanceWithoutConstructor();
    $notificacao->title   = $titulo;
    $notificacao->message = $texto;
    $notificacao->data    = $dados;

    return $notificacao;
}

function avisosDoFleetOps(): array
{
    return [
        'atribuído' => aviso(OrderAssigned::class, 'New order RP-1 assigned!', 'You have a new order assigned, tap for details.', ['id' => 'order_abc', 'type' => 'order_assigned']),
        'liberado'  => aviso(OrderDispatched::class, 'Order RP-1 has been dispatched!', 'An order has just been dispatched to you and is ready to be started.', ['id' => 'order_abc', 'type' => 'order_dispatched']),
        'cancelado' => aviso(OrderCanceled::class, 'Order RP-1 was canceled', 'Order RP-1 has been canceled.', ['id' => 'order_abc', 'type' => 'order_canceled']),
        'falhou'    => aviso(OrderFailed::class, 'Order RP-1 delivery has has failed', 'Order RP-1 delivery has failed.', ['id' => 'order_abc', 'type' => 'order_canceled']),
        'concluído' => aviso(OrderCompleted::class, 'Order RP-1 has been completed.', 'Order RP-1 has been completed by agent.', ['id' => 'order_abc', 'type' => 'order_completed']),
        'parada'    => aviso(WaypointCompleted::class, 'Order RP-1 driver has arrived', 'Driver has arrived', ['id' => 'waypoint_1', 'type' => 'waypoint_completed']),
        'chat'      => aviso(ChatMessageReceived::class, 'Message from Edgard Junior', 'preciso de motoca', ['id' => 'chat_message_1', 'type' => 'chat_message_received', 'channel' => 'chat_channel_1']),
        'teste'     => aviso(TestPushNotification::class, 'Teste', 'Push de teste do admin', ['id' => 'abc123', 'message' => 'Test Push Notification', 'type' => 'test']),
    ];
}

echo '== Textos em pt-BR' . PHP_EOL;
$avisos    = avisosDoFleetOps();
$esperados = [
    'atribuído' => ['Novo pedido para você', 'Pedido RP-1. Toque para ver os detalhes.'],
    'liberado'  => ['Pedido RP-1 liberado para você', 'Toque para ver e iniciar a entrega.'],
    'cancelado' => ['Pedido RP-1 cancelado', 'O pedido RP-1 foi cancelado.'],
    'falhou'    => ['Entrega do pedido RP-1 não concluída', 'A entrega do pedido RP-1 falhou.'],
    'concluído' => ['Pedido RP-1 concluído', 'O pedido RP-1 foi concluído.'],
    'parada'    => ['Pedido RP-1: parada concluída', 'Uma parada do pedido RP-1 foi concluída.'],
    'chat'      => ['Mensagem de Edgard Junior', 'preciso de motoca'],
    'teste'     => ['Teste', 'Push de teste do admin'],
];
foreach ($esperados as $nome => $esperado) {
    $obtido = AvisosDoMotoboy::texto($avisos[$nome]);
    confere($obtido === $esperado, $nome . ': ' . json_encode($obtido, JSON_UNESCAPED_UNICODE));
}

$casos = [
    'pedido novo'               => [new OrderPing(pedidoDoTeste(), 1234), ['Novo pedido disponível', 'Coleta a 1,2 km de você. Toque para ver o pedido.']],
    'pedido novo sem distância' => [new OrderPing(pedidoDoTeste()), ['Novo pedido disponível', 'Toque para ver o pedido.']],
    'reenvio (já em pt-BR)'     => [new LembretePedidoAberto(pedidoDoTeste(), 800), ['Pedido ainda sem motoboy', 'Coleta a 800 m de você. Toque para ver o pedido.']],
    'cancelado sem código'      => [aviso(OrderCanceled::class, 'Order  was canceled', 'Order  has been canceled.', ['type' => 'order_canceled']), ['Pedido cancelado', 'O pedido foi cancelado.']],
    'parada sem código'         => [aviso(WaypointCompleted::class, 'Order  driver has arrived', 'x', ['type' => 'waypoint_completed']), ['Parada concluída', 'Uma parada do pedido foi concluída.']],
    'atribuído sem código'      => [aviso(OrderAssigned::class, 'New order  assigned!', 'You have a new order assigned, tap for details.', ['type' => 'order_assigned']), ['Novo pedido para você', 'Toque para ver os detalhes.']],
    'liberado sem código'       => [aviso(OrderDispatched::class, 'Order  has been dispatched!', 'An order has just been dispatched to you and is ready to be started.', ['type' => 'order_dispatched']), ['Pedido liberado para você', 'Toque para ver e iniciar a entrega.']],
    'falhou sem código'         => [aviso(OrderFailed::class, 'Order  delivery has has failed', 'Order  delivery has failed.', ['type' => 'order_canceled']), ['Entrega não concluída', 'A entrega do pedido falhou.']],
    'concluído sem código'      => [aviso(OrderCompleted::class, 'Order  has been completed.', 'Order  has been completed by agent.', ['type' => 'order_completed']), ['Pedido concluído', 'O pedido foi concluído.']],
    'chat com outro título'     => [aviso(ChatMessageReceived::class, 'Something', 'oi', ['type' => 'chat_message_received']), ['Nova mensagem', 'oi']],
];
foreach ($casos as $nome => [$notificacao, $esperado]) {
    $obtido = AvisosDoMotoboy::texto($notificacao);
    confere($obtido === $esperado, $nome . ': ' . json_encode($obtido, JSON_UNESCAPED_UNICODE));
}

// distância da coleta: os metros são arredondados antes de escolher a unidade
confere(AvisosDoMotoboy::textoDaColeta(999.6) === 'Coleta a 1,0 km de você. Toque para ver o pedido.', 'coleta a 999,6 m arredonda para 1,0 km (e não "1000 m")');
confere(AvisosDoMotoboy::textoDaColeta(0.4) === 'Toque para ver o pedido.', 'coleta a 0,4 m (arredonda para 0) fica só com o convite');
confere(AvisosDoMotoboy::textoDaColeta('0.0') === 'Toque para ver o pedido.', 'coleta a "0.0" fica só com o convite');

$agendado        = aviso(OrderAssigned::class, 'New order RP-2 assigned!', 'You have a new order scheduled for 2026-10-03 18:00:00', ['type' => 'order_assigned']);
$agendado->order = (object) ['scheduled_at' => new DateTimeImmutable('2026-10-03 18:00:00', new DateTimeZone('UTC')), 'company' => (object) ['timezone' => 'America/Sao_Paulo']];
$obtido          = AvisosDoMotoboy::texto($agendado);
confere($obtido === ['Novo pedido para você', 'Pedido RP-2 agendado para 03/10 às 15:00.'], 'atribuído agendado, no fuso da organização: ' . json_encode($obtido, JSON_UNESCAPED_UNICODE));
$agendado->order = (object) ['scheduled_at' => new DateTimeImmutable('2026-10-03 18:00:00', new DateTimeZone('UTC')), 'company' => null];
confere((AvisosDoMotoboy::texto($agendado)[1] ?? null) === 'Pedido RP-2 agendado para 03/10 às 15:00.', 'agendado sem fuso usa America/Sao_Paulo');
$agendado->order = null;
confere((AvisosDoMotoboy::texto($agendado)[1] ?? null) === 'Pedido RP-2. Toque para ver os detalhes.', 'agendado sem data cai no texto comum');

$agendadoSemCodigo        = aviso(OrderAssigned::class, 'New order  assigned!', 'You have a new order scheduled for 2026-10-03 18:00:00', ['type' => 'order_assigned']);
$agendadoSemCodigo->order = (object) ['scheduled_at' => new DateTimeImmutable('2026-10-03 18:00:00', new DateTimeZone('UTC')), 'company' => (object) ['timezone' => 'America/Sao_Paulo']];
confere((AvisosDoMotoboy::texto($agendadoSemCodigo)[1] ?? null) === 'Pedido agendado para 03/10 às 15:00.', 'agendado sem código de rastreamento: frase sem o código');

confere(AvisosDoMotoboy::texto(new Illuminate\Notifications\Notification()) === null, 'classe sem tradução devolve null');

resumo();
