<?php

// Portal da loja (RegrasPortalLoja): a loja cancela o pedido até o motoboy sair para a entrega (decisão de 2026-10-28).
// Aceito e indo à loja (started) cancela, avisa o motoboy (cancel() → OrderCanceled → push) e limpa o pedido atual dele;
// a caminho do cliente (SituacaoDoMotoboy::STATUS_EM_ENTREGA) → 422; encerrado → 422; iFood → 400.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/portal-cancelamento.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Http\Middleware\RegrasPortalLoja;
use App\Support\Entregas\SituacaoDoMotoboy;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Http\Request;
use Teste\Banco;

// a loja do usuário falsa: a loja é o Vendor vendor-a
eval('namespace App\Support\Entregas; class LojaDoUsuario { public static function vendor($usuario) { return \Fleetbase\FleetOps\Models\Vendor::$todos[0] ?? null; } public static function contato($usuario) { return null; } }');
if (!class_exists('Fleetbase\Models\User')) {
    eval('namespace Fleetbase\Models; #[\AllowDynamicProperties] class User { public $uuid = "user-loja"; public $company_uuid = "empresa-1"; }');
}

/** O pedido order-1 da loja com o status dado; com motoboy, o driver-1 com o pedido atual apontando para ele. */
function pedidoDaLoja(string $status, bool $comMotoboy = false, ?string $pedidoAtual = 'order-1'): Order
{
    reiniciarFleetbase();
    reiniciarIfood();
    session(['company' => 'empresa-1']);
    $pedido = new Order([
        'uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'customer_uuid' => 'vendor-a', 'status' => $status,
        'dispatched' => true, 'adhoc' => !$comMotoboy, 'started' => $comMotoboy, 'driver_assigned_uuid' => $comMotoboy ? 'driver-1' : null,
    ]);
    Order::$todos[] = $pedido;
    if ($comMotoboy) {
        Driver::$todos[] = new Driver(['uuid' => 'driver-1', 'public_id' => 'driver_1', 'company_uuid' => 'empresa-1', 'current_job_uuid' => $pedidoAtual]);
    }

    return $pedido;
}

/** O cancelamento do portal da loja; o "portal" (o $next) grava status = canceled e responde 200, como o cancelOrder. */
function cancelarNoPortal(string $id = 'order_1')
{
    $GLOBALS['portalChamado'] = false;
    $portal                   = function () {
        $GLOBALS['portalChamado'] = true;
        Order::$todos[0]->status  = 'canceled';

        return new class() {
            public $status = 200;
            public function isSuccessful(): bool { return true; }
        };
    };
    $metodo = new ReflectionMethod(RegrasPortalLoja::class, 'cancelamento');

    return $metodo->invoke(new RegrasPortalLoja(), new Request(), $portal, new \Fleetbase\Models\User(), $id);
}

function recusou($resposta, int $status, string $mensagem): bool
{
    return ($resposta->status ?? null) === $status && ($resposta->dados ?? null) === ['errors' => [$mensagem]] && !$GLOBALS['portalChamado'];
}

const SAIU = 'O motoboy já saiu para a entrega. Para cancelar, fale com a central.';

echo '== Antes do aceite: cancela' . PHP_EOL;
foreach (['created', 'dispatched'] as $status) {
    $pedido = pedidoDaLoja($status);
    cancelarNoPortal();
    confere($GLOBALS['portalChamado'] && $pedido->status === 'canceled' && in_array('cancel', $pedido->chamadas, true) && !$pedido->dispatched && !$pedido->adhoc, "{$status}: cancela, com o cancel() (atividade e OrderCanceled) e fora dos abertos");
}

echo '== Aceito, indo à loja: cancela e limpa o pedido atual do motoboy' . PHP_EOL;
$pedido = pedidoDaLoja('started', true);
cancelarNoPortal();
confere($GLOBALS['portalChamado'] && $pedido->status === 'canceled', 'started com motoboy: o portal cancela');
confere(in_array('cancel', $pedido->chamadas, true) && $pedido->driver_assigned_uuid === 'driver-1', 'cancel() com o motoboy ainda atribuído (o HandleOrderCanceled avisa o atribuído)');
confere(Driver::$todos[0]->current_job_uuid === null, 'pedido atual do motoboy limpo');
confere($pedido->travadoNoCancelar === [[true, false]], 'cancel() com a trava do pedido');

$pedido = pedidoDaLoja('STARTED', true);
cancelarNoPortal();
confere($GLOBALS['portalChamado'] && $pedido->status === 'canceled', 'status em maiúsculas: comparado em minúsculas, cancela');

pedidoDaLoja('started', true, 'outro-pedido');
cancelarNoPortal();
confere($GLOBALS['portalChamado'] && Driver::$todos[0]->current_job_uuid === 'outro-pedido', 'pedido atual do motoboy é outro: não mexe');

$pedido = pedidoDaLoja('started', true);
Driver::$falharAtualizacao = new RuntimeException('banco fora');
$resposta = cancelarNoPortal();
confere(($resposta->status ?? null) === 200 && $pedido->status === 'canceled' && in_array('cancel', $pedido->chamadas, true), 'falha ao limpar o pedido atual: o cancelamento vale');
confere(logou('pedido atual do motoboy não foi limpo', 'warning'), 'falha ao limpar: warning no log');

$pedido = pedidoDaLoja('started', true);
Order::$falharCancelamento = true;
cancelarNoPortal();
confere(Driver::$todos[0]->current_job_uuid === null && logou('falha ao registrar o cancelamento', 'error'), 'cancel() falhou: o pedido atual do motoboy é limpo do mesmo jeito');

echo '== A caminho do cliente: 422' . PHP_EOL;
confere(SituacaoDoMotoboy::STATUS_EM_ENTREGA === ['enroute', 'picked_up', 'dropping_off', 'in_progress'], 'lista dos status de entrega (o front copia: utils/entregas-pedido.js)');
foreach ([...SituacaoDoMotoboy::STATUS_EM_ENTREGA, 'ENROUTE'] as $status) {
    $pedido   = pedidoDaLoja($status, true);
    $resposta = cancelarNoPortal();
    confere(recusou($resposta, 422, SAIU) && $pedido->status === $status && $pedido->chamadas === [] && Driver::$todos[0]->current_job_uuid === 'order-1', "{$status}: 422 \"já saiu\", sem cancelar");
}

echo '== Encerrado: 422 de sempre' . PHP_EOL;
foreach (['completed', 'canceled', 'expired'] as $status) {
    $pedido = pedidoDaLoja($status);
    confere(recusou(cancelarNoPortal(), 422, 'Este pedido já foi encerrado.') && $pedido->chamadas === [], "{$status}: 422 \"já foi encerrado\"");
}

echo '== Regra pura (lojaPodeCancelar)' . PHP_EOL;
$pode = fn (string $status) => RegrasPortalLoja::lojaPodeCancelar(new Order(['status' => $status]));
confere($pode('created') && $pode('dispatched') && $pode('started') && $pode('Started'), 'created, dispatched e started: pode');
confere(!$pode('enroute') && !$pode('picked_up') && !$pode('dropping_off') && !$pode('in_progress') && !$pode('completed') && !$pode('canceled'), 'em entrega ou encerrado: não pode');

echo '== Pedido do iFood: 400 de sempre' . PHP_EOL;
$pedido = pedidoDaLoja('started', true);
Banco::inserir('entregas_ifood_pedidos', ['company_uuid' => 'empresa-1', 'order_uuid' => 'order-1', 'pedido_ifood_id' => 'pedido-real-1', 'numero' => '4821', 'merchant_id' => 'merchant-1'], false);
confere(recusou(cancelarNoPortal(), 400, 'Pedido do iFood: o cancelamento é feito no iFood.') && $pedido->status === 'started' && Driver::$todos[0]->current_job_uuid === 'order-1', 'iFood aceito: 400, sem cancelar');

echo '== Outra loja: segue para o portal (404)' . PHP_EOL;
$pedido                = pedidoDaLoja('started', true);
$pedido->customer_uuid = 'vendor-b';
cancelarNoPortal();
confere($GLOBALS['portalChamado'] && $pedido->chamadas === [], 'pedido de outra loja: o portal responde, sem o cancelamento nosso');

resumo();
