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
$resposta = rodar(API . 'update', ['id' => 'order_1'], ['status' => 'canceled']);
confere($resposta->status === 400 && $resposta->dados === ['error' => 'Pedido do iFood: o cancelamento é feito no iFood.'], 'PUT v1/orders/{id} com status cancelado: 400');
confere(rodar(API . 'update', ['id' => 'order_1'], ['status' => 'order_canceled'])->status === 400, 'PUT v1/orders/{id} com order_canceled: 400');
confere(rodar(API . 'update', ['id' => 'order_1'], ['notes' => 'portão azul']) === 'passou', 'PUT v1/orders/{id} sem mudar para cancelado: passa');
confere(rodar(API . 'update', ['id' => 'order_2'], ['status' => 'canceled']) === 'passou', 'PUT v1/orders/{id} de outro pedido: passa');
$resposta = rodar(CONSOLE . 'updateRecord', ['id' => 'order-1'], ['order' => ['status' => 'canceled']]);
confere($resposta->status === 400 && $resposta->dados === ['errors' => ['Pedido do iFood: o cancelamento é feito no iFood.']], 'PUT int/v1/orders/{id} (console) com status cancelado: 400 no formato do console');
confere(rodar(CONSOLE . 'updateRecord', ['id' => 'order-1'], ['status' => 'cancelled'])->status === 400, 'console com o status fora da chave order: 400');
confere(rodar(CONSOLE . 'updateRecord', ['id' => 'order-1'], ['order' => ['notes' => 'x', 'driver_assigned_uuid' => 'driver-2']]) === 'passou', 'console sem cancelar (troca de motoboy): passa');
session(['company' => 'outra-empresa']);
confere(rodar(API . 'cancelOrder', ['id' => 'order_1']) === 'passou', 'pedido de outra empresa: passa (o Fleet-Ops responde 404)');

echo '== Cancelamento pela central liberado quando o iFood já cancelou ou a integração está desligada' . PHP_EOL;
pedidos(['cancelado_pelo_ifood_em' => '2026-10-05 14:59:00']);
confere(rodar(API . 'cancelOrder', ['id' => 'order_1']) === 'passou', 'iFood já cancelou (CAN perdido ou cancelamento local que falhou): API v1 passa');
confere(rodar(CONSOLE . 'cancel', [], ['order' => 'order-1']) === 'passou', 'iFood já cancelou: console passa');
confere(rodar(CONSOLE . 'bulkCancel', [], ['ids' => ['order-2', 'order-1']]) === 'passou', 'iFood já cancelou: lote passa');
confere(rodar(CONSOLE . 'updateActivity', ['id' => 'order-1'], ['activity' => ['code' => 'canceled']]) === 'passou', 'iFood já cancelou: atividade "canceled" no console passa');
confere(rodar(CONSOLE . 'updateRecord', ['id' => 'order-1'], ['order' => ['status' => 'canceled']]) === 'passou', 'iFood já cancelou: PUT do console passa');
confere(logou('cancelamento liberado no pedido do iFood', 'info') && logsSem(['pedido-real-1']), 'liberação registrada no log, só com ids nossos');
pedidos();
Config::$valores['services.ifood.ativo'] = '';
confere(rodar(API . 'cancelOrder', ['id' => 'order_1']) === 'passou', 'integração desligada (emergência): API v1 passa');
confere(rodar(API . 'update', ['id' => 'order_1'], ['status' => 'canceled']) === 'passou', 'integração desligada: PUT v1 passa');
confere(rodar(CONSOLE . 'cancel', [], ['order' => 'order-1']) === 'passou', 'integração desligada: console passa');

echo '== Ação barrada registrada no log (console)' . PHP_EOL;
pedidos();
rodar(CONSOLE . 'cancel', [], ['order' => 'order-1']);
confere(logou('ação barrada no pedido do iFood', 'info'), 'console: "ação barrada no pedido do iFood" no log');
confere(logsSem(['pedido-real-1', '4821']), 'log só com ids nossos (sem o id nem o número do iFood)');

echo '== Só consulta o pedido quando a ação pode ser barrada' . PHP_EOL;
class RegrasQueContam extends RegrasDoPedidoIfood
{
    public int $consultas = 0;

    protected function pedidoPeloId($id): ?Order
    {
        $this->consultas++;

        return parent::pedidoPeloId($id);
    }
}
function consultas(string $acao, array $parametros = [], array $dados = []): int
{
    $request       = new Request($dados);
    $request->rota = new Route($acao, $parametros);
    $regras        = new RegrasQueContam();
    $regras->handle($request, fn () => 'passou');

    return $regras->consultas;
}
pedidos();
confere(consultas(API . 'updateActivity', ['id' => 'order_1'], ['activity' => ['code' => 'enroute']]) === 0, 'update-activity neutra (API v1): não consulta o pedido');
confere(consultas(API . 'updateActivity', ['id' => 'order_1'], ['activity' => ['code' => 'completed', 'complete' => true]]) === 0, 'conclusão com a trava desligada: não consulta');
confere(consultas(API . 'completeOrder', ['id' => 'order_1']) === 0, 'POST complete com a trava desligada: não consulta');
confere(consultas(CONSOLE . 'updateActivity', ['id' => 'order-1'], ['activity' => ['code' => 'completed']]) === 0, 'atividade neutra no console: não consulta');
confere(consultas(API . 'updateActivity', ['id' => 'order_1'], ['activity' => ['code' => 'canceled']]) === 1, 'cancelamento: consulta uma vez');
Config::$valores['services.ifood.exige_app_novo'] = '1';
confere(consultas(API . 'updateActivity', ['id' => 'order_1'], ['activity' => ['code' => 'completed', 'complete' => true]]) === 1, 'conclusão com a trava ligada: consulta uma vez');

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

echo '== Portal da loja (RegrasPortalLoja): pedido do iFood não se cancela' . PHP_EOL;
// só o ramo do cancelamento (método protegido), com a loja do usuário falsa: a loja é o Vendor vendor-a
eval('namespace App\Support\Entregas; class LojaDoUsuario { public static function vendor($usuario) { return \Fleetbase\FleetOps\Models\Vendor::$todos[0] ?? null; } public static function contato($usuario) { return null; } }');
if (!class_exists('Fleetbase\Models\User')) {
    eval('namespace Fleetbase\Models; #[\AllowDynamicProperties] class User { public $uuid = "user-loja"; public $company_uuid = "empresa-1"; }');
}
function cancelarNoPortal(string $id)
{
    $metodo = new ReflectionMethod(\App\Http\Middleware\RegrasPortalLoja::class, 'cancelamento');

    return $metodo->invoke(new \App\Http\Middleware\RegrasPortalLoja(), new Request(), fn () => 'passou', new \Fleetbase\Models\User(), $id);
}
pedidos();
Order::$todos[0]->customer_uuid = 'vendor-a';
Order::$todos[1]->customer_uuid = 'vendor-b';
$resposta = cancelarNoPortal('order_1');
confere(($resposta->status ?? null) === 400 && $resposta->dados === ['errors' => ['Pedido do iFood: o cancelamento é feito no iFood.']] && Order::$todos[0]->status === 'enroute', 'pedido iFood da loja: 400, sem cancelar');
confere(cancelarNoPortal('order_2') === 'passou', 'pedido de outra loja: segue para o portal (404)');
confere(logou('ação barrada no pedido do iFood', 'info') && logsSem(['pedido-real-1', '4821']), 'portal: "ação barrada no pedido do iFood" no log, só com ids nossos');
pedidos(['cancelado_pelo_ifood_em' => '2026-10-05 14:59:00']);
Config::$valores['services.ifood.ativo'] = '';
Order::$todos[0]->customer_uuid = 'vendor-a';
confere((cancelarNoPortal('order_1')->status ?? null) === 400 && Order::$todos[0]->status === 'enroute', 'portal continua barrado mesmo com o iFood já cancelado e a integração desligada');

echo '== Outras ações' . PHP_EOL;
pedidos();
confere(rodar(API . 'startOrder', ['id' => 'order_1']) === 'passou', 'aceite: passa (fica com o BarrarAceiteDePedidoEncerrado)');
confere((new RegrasDoPedidoIfood())->handle(new Request(), fn () => 'passou') === 'passou', 'requisição sem rota: passa');

resumo();
