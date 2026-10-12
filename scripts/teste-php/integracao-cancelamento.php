<?php

// Integração: cancelamento pedido pela origem (CancelamentoPelaOrigem) e o texto do push ao motoboy.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-cancelamento.php

require __DIR__ . '/stubs-integracao.php';

use App\Support\Entregas\Integracao\CancelamentoPelaOrigem;
use Fleetbase\FleetOps\Models\Order;
use Teste\Relogio;
use Teste\Trava;

function cancelar(): string
{
    return (new CancelamentoPelaOrigem())->cancelar(linhaDaIntegracao());
}

echo '== Sem aceite: cancela e sai da distribuição' . PHP_EOL;
reiniciarIntegracao();
$pedido = pedidoDaIntegracao(['status' => 'dispatched', 'started' => false, 'adhoc' => true, 'driver_assigned_uuid' => null]);
confere(cancelar() === CancelamentoPelaOrigem::CANCELADO, 'resultado: cancelado');
confere($pedido->chamadas === ['saveQuietly', 'cancel'] && $pedido->status === 'canceled' && $pedido->dispatched === false && $pedido->adhoc === false, 'sai dos abertos (saveQuietly) e cancel()');
confere($pedido->travadoNoSalvar === [true] && $pedido->travadoNoCancelar[0][0] === true && Trava::$ocupadas === [], 'com a trava do pedido, solta no fim');
confere(linhaDaIntegracao()->cancelado_em === '2026-10-05 15:00:00' && linhaDaIntegracao()->cancelado_por === 'loja' && linhaDaIntegracao()->pago_mesmo_cancelado === false, 'linha: cancelado pela loja, sem pagamento');
confere(\Teste\Sessao::$dados['company'] === 'empresa-1' && logou('pedido cancelado pela origem', 'warning'), 'empresa na sessão e log');

echo '== Aceito, antes de "A caminho": cancela sem pagar' . PHP_EOL;
reiniciarIntegracao();
$pedido = pedidoDaIntegracao(['status' => 'started']);
confere(cancelar() === CancelamentoPelaOrigem::CANCELADO && $pedido->status === 'canceled' && linhaDaIntegracao()->pago_mesmo_cancelado === false, 'cancelado, sem pagamento');

echo '== "A caminho": cancela e paga' . PHP_EOL;
reiniciarIntegracao();
$pedido = pedidoDaIntegracao(['status' => 'enroute']);
confere(cancelar() === CancelamentoPelaOrigem::CANCELADO_PAGO && $pedido->status === 'canceled' && linhaDaIntegracao()->pago_mesmo_cancelado === true, 'cancelado_pago');

echo '== Outros status de entrega: paga' . PHP_EOL;
reiniciarIntegracao();
$pedido = pedidoDaIntegracao(['status' => 'picked_up']);
confere(cancelar() === CancelamentoPelaOrigem::CANCELADO_PAGO && linhaDaIntegracao()->pago_mesmo_cancelado === true, 'picked_up: cancelado_pago');

echo '== Concluído: nada muda' . PHP_EOL;
reiniciarIntegracao();
$pedido = pedidoDaIntegracao(['status' => 'completed']);
confere(cancelar() === CancelamentoPelaOrigem::JA_CONCLUIDO && $pedido->chamadas === [] && linhaDaIntegracao()->cancelado_em === null, 'ja_concluido, sem mexer');

echo '== Já cancelado: idempotente' . PHP_EOL;
reiniciarIntegracao();
$pedido = pedidoDaIntegracao(['status' => 'enroute']);
cancelar();
Relogio::$agora = '2026-10-05 15:10:00';
confere(cancelar() === CancelamentoPelaOrigem::CANCELADO_PAGO && $pedido->chamadas === ['saveQuietly', 'cancel'] && linhaDaIntegracao()->cancelado_em === '2026-10-05 15:00:00', 'segunda vez: mesmo resultado, sem outro cancel(), mesma data');
reiniciarIntegracao();
$pedido = pedidoDaIntegracao(['status' => 'canceled'], ['cancelado_em' => '2026-10-05 14:55:00', 'cancelado_por' => 'central']);
confere(cancelar() === CancelamentoPelaOrigem::CANCELADO && linhaDaIntegracao()->cancelado_por === 'central', 'cancelado antes pela central: cancelado, sem trocar quem cancelou');

echo '== Expirado: registra como cancelado' . PHP_EOL;
reiniciarIntegracao();
$pedido = pedidoDaIntegracao(['status' => 'expired']);
confere(cancelar() === CancelamentoPelaOrigem::CANCELADO && $pedido->chamadas === [] && linhaDaIntegracao()->cancelado_em !== null, 'sem cancel(), linha marcada');

confere(linhaDaIntegracao()->cancelado_por === 'sistema' && linhaDaIntegracao()->pago_mesmo_cancelado === false, 'expirado sem registro anterior: cancelado_por sistema, sem pagamento');

echo '== Linha sem order_uuid' . PHP_EOL;
reiniciarIntegracao();
pedidoDaIntegracao();
$sem             = linhaDaIntegracao();
$sem->order_uuid = null;
confere((new CancelamentoPelaOrigem())->cancelar($sem) === CancelamentoPelaOrigem::CANCELADO && $sem->cancelado_em !== null && Trava::$ocupadas === [], 'order_uuid nulo: cancelado, sem TypeError');

echo '== Sem Order: só registra' . PHP_EOL;
reiniciarIntegracao();
pedidoDaIntegracao();
Order::$todos = [];
confere(cancelar() === CancelamentoPelaOrigem::CANCELADO && linhaDaIntegracao()->cancelado_em !== null && logou('cancelamento de pedido sem Order', 'info'), 'cancelado e registrado');

echo '== Trava ocupada' . PHP_EOL;
reiniciarIntegracao();
$pedido                                     = pedidoDaIntegracao();
Trava::$ocupadas['entregas:pedido:order-1'] = true;
confere(excecao(fn () => cancelar()) instanceof \Illuminate\Contracts\Cache\LockTimeoutException && $pedido->chamadas === [] && linhaDaIntegracao()->cancelado_em === null, 'LockTimeoutException, nada gravado');

echo '== Texto do push' . PHP_EOL;
confere(CancelamentoPelaOrigem::textoDoPush((object) ['numero' => '4821', 'cancelado_em' => '2026-10-05 15:00:00', 'cancelado_por' => 'loja', 'pago_mesmo_cancelado' => true]) === ['Pedido #4821 cancelado pela loja', 'Você recebe por esta entrega. Combine com a loja a devolução.'], 'pago');
confere(CancelamentoPelaOrigem::textoDoPush((object) ['numero' => '4821', 'cancelado_em' => '2026-10-05 15:00:00', 'cancelado_por' => 'loja', 'pago_mesmo_cancelado' => false]) === ['Pedido #4821 cancelado pela loja', 'Não precisa mais fazer esta entrega.'], 'sem pagamento');
confere(CancelamentoPelaOrigem::textoDoPush((object) ['numero' => '4821', 'cancelado_em' => '2026-10-05 15:00:00', 'cancelado_por' => 'central', 'pago_mesmo_cancelado' => false]) === null, 'cancelado pela central: texto comum');
confere(CancelamentoPelaOrigem::textoDoPush((object) ['numero' => '4821', 'cancelado_em' => null, 'cancelado_por' => null, 'pago_mesmo_cancelado' => false]) === null, 'não cancelado: null');

resumo();
