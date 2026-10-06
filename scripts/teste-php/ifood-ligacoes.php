<?php

// Integração iFood (etapa 3): o que liga as peças no Laravel (providers, rotas, middlewares, agenda e stack), conferido
// no texto dos arquivos: os providers não sobem no php-wasm. A ligação de verdade é conferida na produção (última task).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-ligacoes.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

$app    = file_get_contents('/repo/api/app/Providers/AppServiceProvider.php');
$rotas  = file_get_contents('/repo/api/app/Providers/RouteServiceProvider.php');
$portal = file_get_contents('/repo/api/app/Http/Middleware/RegrasPortalLoja.php');
$config = file_get_contents('/repo/api/config/services.php');
$stack  = file_get_contents('/repo/deploy/docker-stack.yml');
$modelo = file_get_contents('/repo/deploy/stack.env.example');
$kernel = file_get_contents('/repo/api/app/Console/Kernel.php');

echo '== AppServiceProvider' . PHP_EOL;
confere(str_contains($app, 'Order::updated(fn ($pedido) => ObservadorDosPedidosIfood::aoAtualizar($pedido));'), 'Order::updated → ObservadorDosPedidosIfood');
confere(str_contains($app, 'Event::listen(OrderDriverAssigned::class, fn ($evento) => ObservadorDosPedidosIfood::aoAtribuirMotoboy($evento));'), 'OrderDriverAssigned → ObservadorDosPedidosIfood');
confere(str_contains($app, 'use Fleetbase\FleetOps\Events\OrderDriverAssigned;') && str_contains($app, 'use Fleetbase\FleetOps\Models\Order;'), 'imports do evento e do model');
confere(str_contains($app, '$this->acompanharPedidosIfood();'), 'chamado no boot');

echo '== Agenda' . PHP_EOL;
confere(str_contains($kernel, "\$schedule->command('entregas:ifood-acompanhar')->everyThirtySeconds()->when(\$ligada)->withoutOverlapping(5)->runInBackground()"), 'entregas:ifood-acompanhar a cada 30 s, em segundo plano e sem sobreposição');
confere(class_exists(\App\Console\Commands\Entregas\AcompanharIfood::class) && str_contains(file_get_contents('/repo/api/app/Console/Commands/Entregas/AcompanharIfood.php'), "'entregas:ifood-acompanhar'"), 'o comando existe com a assinatura da agenda');

echo '== RouteServiceProvider' . PHP_EOL;
$regrasApi = strpos($rotas, "pushMiddlewareToGroup('fleetbase.api', RegrasDoPedidoIfood::class)");
$regrasInt = strpos($rotas, "pushMiddlewareToGroup('fleetbase.protected', RegrasDoPedidoIfood::class)");
$barrar    = strpos($rotas, "pushMiddlewareToGroup('fleetbase.api', BarrarAceiteDePedidoEncerrado::class)");
confere($regrasApi !== false && $regrasInt !== false, 'RegrasDoPedidoIfood na API v1 e no console');
confere($regrasApi !== false && $barrar !== false && $regrasApi < $barrar, 'antes do BarrarAceiteDePedidoEncerrado');
foreach ([
    "Route::get('pedidos/{id}/ifood', [MotoboyController::class, 'ifood']);",
    "Route::post('pedidos/{id}/concluir-ifood', [MotoboyController::class, 'concluirIfood']);",
    "Route::post('pedidos/{id}/codigo-ifood', [MotoboyController::class, 'codigoIfood'])->middleware('throttle:entregas-ifood-codigo');",
    "Route::get('pedidos/{id}/ifood', [IfoodPedidosController::class, 'painel']);",
    "Route::post('pedidos/{id}/ifood/liberar-sem-codigo', [IfoodPedidosController::class, 'liberarSemCodigo']);",
    "RateLimiter::for('entregas-ifood-codigo'",
    // os dados do pedido iFood (card e detalhes do app) num balde próprio, fora do entregas-motoboy do concluir-ifood
    "RateLimiter::for('entregas-motoboy-ifood'",
    "->middleware(['fleetbase.api', 'throttle:entregas-motoboy-ifood'])",
] as $trecho) {
    confere(str_contains($rotas, $trecho), "rota/limitador: {$trecho}");
}
// os controllers estendem o Controller do Laravel (não carrega no php-wasm): confere o método no texto
$painel  = file_get_contents('/repo/api/app/Http/Controllers/Entregas/IfoodPedidosController.php');
$motoboy = file_get_contents('/repo/api/app/Http/Controllers/Entregas/MotoboyController.php');
foreach (['painel', 'liberarSemCodigo'] as $metodo) {
    confere(str_contains($painel, "public function {$metodo}("), "IfoodPedidosController::{$metodo} existe");
}
foreach (['ifood', 'concluirIfood', 'codigoIfood'] as $metodo) {
    confere(str_contains($motoboy, "public function {$metodo}("), "MotoboyController::{$metodo} existe");
}

echo '== Ações do Fleet-Ops que o RegrasDoPedidoIfood e o BarrarAceiteDePedidoEncerrado casam (cópia em packages/)' . PHP_EOL;
$api     = file_get_contents('/repo/packages/fleetops/server/src/Http/Controllers/Api/v1/OrderController.php');
$console = file_get_contents('/repo/packages/fleetops/server/src/Http/Controllers/Internal/v1/OrderController.php');
$basico  = file_get_contents('/repo/packages/core-api/src/Traits/HasApiControllerBehavior.php');
foreach (['cancelOrder', 'update', 'updateActivity', 'completeOrder', 'startOrder'] as $metodo) {
    confere(str_contains($api, "public function {$metodo}("), "Api\\v1\\OrderController@{$metodo} existe");
}
foreach (['cancel', 'bulkCancel', 'updateActivity'] as $metodo) {
    confere(str_contains($console, "public function {$metodo}("), "Internal\\v1\\OrderController@{$metodo} existe");
}
confere(str_contains($basico, 'public function updateRecord('), 'Internal\v1\OrderController@updateRecord (do HasApiControllerBehavior) existe');
foreach ([
    'CANCELAR_NA_API' => 'cancelOrder', 'ATUALIZAR_NA_API' => 'update', 'ATIVIDADE_NA_API' => 'updateActivity', 'CONCLUIR_NA_API' => 'completeOrder',
    'CANCELAR_NO_CONSOLE' => 'cancel', 'CANCELAR_EM_LOTE' => 'bulkCancel', 'ATIVIDADE_NO_CONSOLE' => 'updateActivity', 'ATUALIZAR_NO_CONSOLE' => 'updateRecord',
] as $constante => $metodo) {
    confere(str_ends_with(constant('App\Http\Middleware\RegrasDoPedidoIfood::' . $constante), '@' . $metodo), "RegrasDoPedidoIfood::{$constante} aponta para @{$metodo}");
}

echo '== Portal, configuração e stack' . PHP_EOL;
$ifood = strpos($portal, 'PedidosIfood::ehDoIfood((string) $pedido->uuid)');
$trava = strpos($portal, 'TravaDoPedido::executar($pedido->uuid, fn () => $this->cancelarComATrava');
confere($ifood !== false && $trava !== false && $ifood < $trava, 'portal: pedido iFood recusado antes da trava do cancelamento');
confere(str_contains($config, "'exige_app_novo' => env('ENTREGAS_IFOOD_EXIGE_APP_NOVO'),"), 'services.ifood.exige_app_novo');
confere(str_contains($stack, 'ENTREGAS_IFOOD_EXIGE_APP_NOVO: ${ENTREGAS_IFOOD_EXIGE_APP_NOVO:-}'), 'variável no x-api-env do stack');
confere(str_contains($modelo, 'ENTREGAS_IFOOD_EXIGE_APP_NOVO='), 'documentada no stack.env.example');

echo '== Relatório, cobrança e ganhos: o pago mesmo cancelado' . PHP_EOL;
$calculo = file_get_contents('/repo/api/app/Support/Entregas/CalculoEntregas.php');
confere(str_contains($calculo, "->leftJoin('entregas_ifood_pedidos as entregas_ifood', 'entregas_ifood.order_uuid', '=', 'orders.uuid')"), 'junta a linha do iFood');
confere(str_contains($calculo, "where('entregas_ifood.pago_mesmo_cancelado', true)->whereNotNull('entregas_ifood.cancelado_pelo_ifood_em')"), 'concluído ou pago mesmo cancelado');
confere(str_contains(\App\Support\Entregas\CalculoEntregas::DATA_DA_ENTREGA, 'ELSE entregas_ifood.cancelado_pelo_ifood_em END'), 'o pago mesmo cancelado conta pela data do cancelamento');

resumo();
