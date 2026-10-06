<?php

// Integração iFood (etapa 3): cancelamento pelo iFood (CancelamentoPeloIfood) e o texto do push ao motoboy.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-cancelamento.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Support\Entregas\Ifood\AcoesIfood;
use App\Support\Entregas\Ifood\CancelamentoPeloIfood;
use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\VinculosIfood;
use Fleetbase\FleetOps\Models\Order;
use Teste\Banco;
use Teste\Trava;

/** O Order order-1 (aceito, com motoboy) e a linha do pedido iFood; devolve o Order. */
function pedidoIfood(array $order = [], array $linha = []): Order
{
    reiniciarFleetbase();
    reiniciarIfood();
    $pedido         = new Order($order + ['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'status' => 'enroute', 'started' => true, 'dispatched' => true, 'adhoc' => true, 'driver_assigned_uuid' => 'driver-1']);
    Order::$todos[] = $pedido;
    Banco::inserir('entregas_ifood_pedidos', $linha + [
        'company_uuid' => 'empresa-1', 'order_uuid' => 'order-1', 'pedido_ifood_id' => 'pedido-real-1', 'numero' => '4821', 'merchant_id' => 'merchant-1',
        'created_at' => '2026-10-05 14:50:00', 'updated_at' => '2026-10-05 14:50:00',
    ], false);

    return $pedido;
}

function linha(): object
{
    return (new Teste\Consulta('entregas_ifood_pedidos'))->where('order_uuid', 'order-1')->first();
}

function cancelar(string $quando = '2026-10-05 14:58:00'): string
{
    return (new CancelamentoPeloIfood(new AcoesIfood(new VinculosIfood(new ClienteIfood()), new ClienteIfood())))->aplicar(linha(), $quando);
}

echo '== Dispatch já aceito pelo iFood: cancelado e pago mesmo assim' . PHP_EOL;
$pedido = pedidoIfood([], ['ultima_acao' => 'dispatch']);
confere(cancelar() === CancelamentoPeloIfood::CANCELADO_PAGO, 'resultado: cancelado_pago');
confere($pedido->status === 'canceled' && $pedido->chamadas === ['saveQuietly', 'cancel'], 'sai dos abertos (saveQuietly) e cancel()');
confere($pedido->dispatched === false && $pedido->adhoc === false && $pedido->scheduled_at === null, 'fora dos pedidos abertos e do agendamento');
confere($pedido->travadoNoSalvar === [true] && $pedido->travadoNoCancelar === [[true, true]], 'com a trava do pedido e a das ações do iFood');
confere(linha()->cancelado_pelo_ifood_em === '2026-10-05 14:58:00' && linha()->pago_mesmo_cancelado === true, 'cancelado_pelo_ifood_em e pago_mesmo_cancelado gravados');
confere(\Teste\Sessao::$dados['company'] === 'empresa-1', 'a empresa na sessão para o cancel()');
confere(logou('pedido cancelado pelo iFood', 'warning'), 'log do cancelamento');
confere(!isset(Trava::$ocupadas['entregas:pedido:order-1']) && !isset(Trava::$ocupadas['entregas:ifood-acao:order-1']), 'travas soltas no fim');

echo '== Antes do dispatch: cancelado, sem pagamento' . PHP_EOL;
foreach ([null, 'assignDriver', 'goingToOrigin', 'arrivedAtOrigin'] as $ultima) {
    $pedido = pedidoIfood(['status' => 'started'], ['ultima_acao' => $ultima]);
    confere(cancelar() === CancelamentoPeloIfood::CANCELADO && $pedido->status === 'canceled' && linha()->pago_mesmo_cancelado === false, 'última ação ' . ($ultima ?? 'nenhuma') . ': cancelado, sem pagamento');
}

echo '== Pedido já concluído: nada muda' . PHP_EOL;
$pedido = pedidoIfood(['status' => 'completed'], ['ultima_acao' => 'arrivedAtDestination']);
confere(cancelar() === CancelamentoPeloIfood::JA_CONCLUIDO && $pedido->chamadas === [] && $pedido->status === 'completed', 'não mexe no Order');
confere(linha()->cancelado_pelo_ifood_em === '2026-10-05 14:58:00' && !linha()->pago_mesmo_cancelado && logou('CAN de pedido já concluído', 'info'), 'só registra, com log');

echo '== Idempotente: o CAN repetido não cancela de novo nem troca a data' . PHP_EOL;
$pedido = pedidoIfood([], ['ultima_acao' => 'dispatch']);
cancelar('2026-10-05 14:58:00');
confere(cancelar('2026-10-05 15:10:00') === CancelamentoPeloIfood::JA_CANCELADO && $pedido->chamadas === ['saveQuietly', 'cancel'], 'segunda vez: já cancelado, sem outro cancel()');
confere(linha()->cancelado_pelo_ifood_em === '2026-10-05 14:58:00' && linha()->pago_mesmo_cancelado === true, 'mantém a data e o pago');

echo '== cancel() falhou: a próxima tentativa cancela' . PHP_EOL;
$pedido                       = pedidoIfood([], ['ultima_acao' => 'dispatch']);
Order::$falharCancelamento    = true;
$erro                         = excecao(fn () => cancelar());
confere($erro instanceof RuntimeException && $pedido->dispatched === false && linha()->cancelado_pelo_ifood_em !== null, 'o erro sobe (o CAN fica pendente); já saiu dos abertos');
Order::$falharCancelamento = false;
confere(cancelar() === CancelamentoPeloIfood::CANCELADO_PAGO && $pedido->status === 'canceled', 'na tentativa seguinte, cancela');

echo '== Travas ocupadas: nada gravado' . PHP_EOL;
foreach (['entregas:pedido:order-1' => 'do pedido', 'entregas:ifood-acao:order-1' => 'das ações'] as $trava => $nome) {
    $pedido                 = pedidoIfood([], ['ultima_acao' => 'dispatch']);
    Trava::$ocupadas[$trava] = true;
    $erro                    = excecao(fn () => cancelar());
    confere($erro instanceof \Illuminate\Contracts\Cache\LockTimeoutException && $pedido->chamadas === [] && linha()->cancelado_pelo_ifood_em === null, "trava {$nome} ocupada: LockTimeoutException, nada gravado");
}

echo '== Sem Order' . PHP_EOL;
pedidoIfood([], ['order_uuid' => null]);
$semOrder = (new Teste\Consulta('entregas_ifood_pedidos'))->where('pedido_ifood_id', 'pedido-real-1')->first();
confere((new CancelamentoPeloIfood(app(AcoesIfood::class)))->aplicar($semOrder, '2026-10-05 14:58:00') === CancelamentoPeloIfood::SEM_PEDIDO, 'linha sem order_uuid: só registra');
pedidoIfood();
Order::$todos = [];
confere(cancelar() === CancelamentoPeloIfood::SEM_PEDIDO && linha()->cancelado_pelo_ifood_em === '2026-10-05 14:58:00', 'Order sumiu: só registra');

echo '== Texto do push ao motoboy' . PHP_EOL;
confere(CancelamentoPeloIfood::textoDoPush((object) ['numero' => '4821', 'cancelado_pelo_ifood_em' => '2026-10-05 14:58:00', 'pago_mesmo_cancelado' => true]) === ['Pedido #4821 cancelado pelo iFood', 'Você recebe por esta entrega. Combine com a loja a devolução.'], 'pago: título com o número e o aviso do pagamento');
confere(CancelamentoPeloIfood::textoDoPush((object) ['numero' => '4821', 'cancelado_pelo_ifood_em' => '2026-10-05 14:58:00', 'pago_mesmo_cancelado' => false]) === ['Pedido #4821 cancelado pelo iFood', 'Não precisa mais fazer esta entrega.'], 'sem pagamento');
confere(CancelamentoPeloIfood::textoDoPush((object) ['numero' => null, 'cancelado_pelo_ifood_em' => '2026-10-05 14:58:00', 'pago_mesmo_cancelado' => false])[0] === 'Pedido cancelado pelo iFood', 'sem número');
confere(CancelamentoPeloIfood::textoDoPush((object) ['numero' => '4821', 'cancelado_pelo_ifood_em' => null, 'pago_mesmo_cancelado' => false]) === null, 'não cancelado pelo iFood: null (texto comum)');

resumo();
