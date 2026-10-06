<?php

// Integração iFood (etapa 3): regras dos pedidos iFood nas rotas do Fleet-Ops (RegrasDoPedidoIfood): cancelamento
// proibido (API v1 e console) e a trava "Atualize o app" na conclusão comum.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-regras.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Http\Middleware\RegrasDoPedidoIfood;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Teste\Banco;
use Teste\Config;

const API     = 'Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController@';
const CONSOLE = 'Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController@';

/** Dois pedidos: order-1 é do iFood (número 4821), order-2 não. */
function pedidos(array $linha = []): void
{
    reiniciarFleetbase();
    reiniciarIfood();
    session(['company' => 'empresa-1']);
    Order::$todos[] = new Order(['uuid' => 'order-1', 'public_id' => 'order_1', 'internal_id' => '4821', 'company_uuid' => 'empresa-1', 'status' => 'enroute']);
    Order::$todos[] = new Order(['uuid' => 'order-2', 'public_id' => 'order_2', 'company_uuid' => 'empresa-1', 'status' => 'enroute']);
    Banco::inserir('entregas_ifood_pedidos', $linha + ['company_uuid' => 'empresa-1', 'order_uuid' => 'order-1', 'pedido_ifood_id' => 'pedido-real-1', 'numero' => '4821', 'merchant_id' => 'merchant-1'], false);
}

/** Roda o middleware para a ação; devolve a resposta dele ou 'passou'. */
function rodar(string $acao, array $parametros = [], array $dados = [])
{
    $request       = new Request($dados);
    $request->rota = new Route($acao, $parametros);

    return (new RegrasDoPedidoIfood())->handle($request, fn () => 'passou');
}

echo '== Cancelamento proibido' . PHP_EOL;
pedidos();
$resposta = rodar(API . 'cancelOrder', ['id' => 'order_1']);
confere($resposta->status === 400 && $resposta->dados === ['error' => 'Pedido do iFood: o cancelamento é feito no iFood.'], 'API v1 (DELETE v1/orders/{id}/cancel): 400 no formato da v1');
confere(rodar(API . 'cancelOrder', ['id' => '4821'])->status === 400, 'API v1 pelo internal_id: 400');
confere(rodar(API . 'cancelOrder', ['id' => 'order_2']) === 'passou', 'pedido que não é do iFood: passa');
$resposta = rodar(CONSOLE . 'cancel', [], ['order' => 'order-1']);
confere($resposta->status === 400 && $resposta->dados === ['errors' => ['Pedido do iFood: o cancelamento é feito no iFood.']], 'console (PATCH int/v1/orders/cancel): 400 no formato do console');
confere(rodar(CONSOLE . 'cancel', [], ['order' => 'order-2']) === 'passou', 'console, outro pedido: passa');
confere(rodar(CONSOLE . 'bulkCancel', [], ['ids' => ['order-2', 'order-1']])->status === 400, 'cancelamento em lote com um pedido iFood: 400');
confere(rodar(CONSOLE . 'bulkCancel', [], ['ids' => ['order-2']]) === 'passou', 'lote sem pedido iFood: passa');
confere(rodar(CONSOLE . 'updateActivity', ['id' => 'order-1'], ['activity' => ['code' => 'canceled']])->status === 400, 'atividade "canceled" no console (quadro): 400');
confere(rodar(CONSOLE . 'updateActivity', ['id' => 'order-1'], ['activity' => ['code' => 'completed']]) === 'passou', 'conclusão pelo console: passa (decisão da central)');
confere(rodar(API . 'updateActivity', ['id' => 'order_1'], ['activity' => ['code' => 'canceled']])->status === 400, 'atividade "canceled" na API v1: 400');
session(['company' => 'outra-empresa']);
confere(rodar(API . 'cancelOrder', ['id' => 'order_1']) === 'passou', 'pedido de outra empresa: passa (o Fleet-Ops responde 404)');

echo '== Trava "Atualize o app" (desligada por padrão)' . PHP_EOL;
pedidos();
confere(rodar(API . 'updateActivity', ['id' => 'order_1'], ['activity' => ['code' => 'completed', 'complete' => true]]) === 'passou', 'desligada: a conclusão comum passa');
Config::$valores['services.ifood.exige_app_novo'] = '1';
$resposta = rodar(API . 'updateActivity', ['id' => 'order_1'], ['activity' => ['code' => 'completed', 'complete' => true]]);
confere($resposta->status === 400 && $resposta->dados === ['error' => 'Atualize o app para concluir pedidos do iFood.'], 'ligada: conclusão sem a rota concluir-ifood → 400 "Atualize o app"');
confere(rodar(API . 'updateActivity', ['id' => 'order_1'], ['activity' => ['code' => 'entregue', 'complete' => true]])->status === 400, 'atividade que conclui com outro código: 400');
confere(rodar(API . 'completeOrder', ['id' => 'order_1'])->status === 400, 'POST v1/orders/{id}/complete: 400');
confere(rodar(API . 'updateActivity', ['id' => 'order_1'], ['activity' => ['code' => 'enroute']]) === 'passou', 'outras atividades passam');
confere(rodar(API . 'updateActivity', ['id' => 'order_2'], ['activity' => ['code' => 'completed', 'complete' => true]]) === 'passou', 'pedido que não é do iFood passa');
pedidos(['conclusao_liberada_em' => '2026-10-05 14:59:00']);
Config::$valores['services.ifood.exige_app_novo'] = '1';
confere(rodar(API . 'updateActivity', ['id' => 'order_1'], ['activity' => ['code' => 'completed', 'complete' => true]]) === 'passou', 'liberado pela rota concluir-ifood: passa');

echo '== Outras ações' . PHP_EOL;
pedidos();
confere(rodar(API . 'startOrder', ['id' => 'order_1']) === 'passou', 'aceite: passa (fica com o BarrarAceiteDePedidoEncerrado)');
confere((new RegrasDoPedidoIfood())->handle(new Request(), fn () => 'passou') === 'passou', 'requisição sem rota: passa');

resumo();
