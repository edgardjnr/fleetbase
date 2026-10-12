<?php

// Integração: o que liga as peças no Laravel (providers, rotas, agenda, pagamento, push, alarme, stack), conferido no
// texto dos arquivos (os providers não sobem no php-wasm), e as funções puras do app do motoboy e do alarme.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-ligacoes.php

require __DIR__ . '/stubs-integracao.php';

use App\Support\Entregas\CartaoDoAlarme;
use App\Support\Entregas\CalculoEntregas;
use App\Support\Entregas\Integracao\DadosDaIntegracaoDoMotoboy;

$app     = file_get_contents('/repo/api/app/Providers/AppServiceProvider.php');
$rotas   = file_get_contents('/repo/api/app/Providers/RouteServiceProvider.php');
$kernel  = file_get_contents('/repo/api/app/Console/Kernel.php');
$calculo = file_get_contents('/repo/api/app/Support/Entregas/CalculoEntregas.php');
$avisos  = file_get_contents('/repo/api/app/Notifications/Entregas/AvisosDoMotoboy.php');
$motoboy = file_get_contents('/repo/api/app/Http/Controllers/Entregas/MotoboyController.php');
$stack   = file_get_contents('/repo/deploy/docker-stack.yml');
$modelo  = file_get_contents('/repo/deploy/stack.env.example');

echo '== AppServiceProvider' . PHP_EOL;
confere(str_contains($app, 'Order::updated(fn ($pedido) => ObservadorDosPedidosDaIntegracao::aoAtualizar($pedido));'), 'Order::updated → ObservadorDosPedidosDaIntegracao');
confere(str_contains($app, 'Event::listen(OrderDriverAssigned::class, fn ($evento) => ObservadorDosPedidosDaIntegracao::aoAtribuirMotoboy($evento));'), 'OrderDriverAssigned → ObservadorDosPedidosDaIntegracao');
confere(str_contains($app, 'use App\Listeners\Entregas\ObservadorDosPedidosDaIntegracao;') && str_contains($app, '$this->acompanharPedidosDaIntegracao();'), 'import e chamada no boot');

echo '== Rotas' . PHP_EOL;
foreach ([
    "Route::prefix('v1/entregas/integracao')",
    "->middleware(['throttle:entregas-integracao'])",
    "Route::post('conexao', [IntegracaoController::class, 'conexao']);",
    "Route::post('pedidos', [IntegracaoController::class, 'criar']);",
    "Route::get('pedidos/{id}', [IntegracaoController::class, 'consultar']);",
    "Route::post('pedidos/{id}/cancelar', [IntegracaoController::class, 'cancelar']);",
    "Route::get('lojas/{id}/integracao', [IntegracaoLojasController::class, 'situacao']);",
    "Route::post('lojas/{id}/integracao/chave', [IntegracaoLojasController::class, 'gerarChave']);",
    "Route::delete('lojas/{id}/integracao', [IntegracaoLojasController::class, 'revogar']);",
    "Route::get('pedidos/{id}/integracao', [IntegracaoLojasController::class, 'painel']);",
    "Route::get('pedidos/{id}/integracao', [MotoboyController::class, 'integracao']);",
    "RateLimiter::for('entregas-integracao'",
    "RateLimiter::for('entregas-motoboy-integracao'",
    "->middleware(['fleetbase.api', 'throttle:entregas-motoboy-integracao'])",
] as $trecho) {
    confere(str_contains($rotas, $trecho), "rota/limitador: {$trecho}");
}
$externas = substr($rotas, strpos($rotas, "Route::prefix('v1/entregas/integracao')"), 400);
confere(!str_contains($externas, 'fleetbase.api') && !str_contains($externas, 'fleetbase.protected'), 'as rotas externas ficam fora dos grupos do Fleetbase');
confere(str_contains($motoboy, 'public function integracao(Request $request, string $id)'), 'MotoboyController@integracao existe');

echo '== Agenda' . PHP_EOL;
confere(str_contains($kernel, "\$schedule->command('entregas:integracao-acompanhar')->everyMinute()->when(fn () => Integracao::ligada())->withoutOverlapping(5)->runInBackground()"), 'a cada minuto, em segundo plano, sem sobreposição');
confere(str_contains(file_get_contents('/repo/api/app/Console/Commands/Entregas/AcompanharIntegracao.php'), "'entregas:integracao-acompanhar'"), 'o comando tem a assinatura da agenda');

echo '== Pagamento e cobrança' . PHP_EOL;
confere(str_contains($calculo, "->leftJoin('entregas_integracao_pedidos as entregas_integracao', 'entregas_integracao.order_uuid', '=', 'orders.uuid')"), 'junta a linha da integração');
confere(str_contains($calculo, "where('entregas_integracao.pago_mesmo_cancelado', true)->whereNotNull('entregas_integracao.cancelado_em')"), 'pago mesmo cancelado pela origem entra');
confere(str_contains($calculo, "WHEN entregas_integracao.cancelado_em IS NOT NULL THEN 'integracao' END as entregas_cancelado_origem"), 'a origem do cancelamento pago no select');
confere(str_contains($calculo, "'cancelado_origem' => (\$pedido->entregas_cancelado_pago ?? false) ? (\$pedido->entregas_cancelado_origem ?? null) : null"), 'a origem na linha das entregas');
confere(str_contains(CalculoEntregas::DATA_DA_ENTREGA, 'ELSE COALESCE(entregas_ifood.cancelado_pelo_ifood_em, entregas_integracao.cancelado_em) END'), 'conta pela data do cancelamento');

echo '== Push' . PHP_EOL;
confere(str_contains($avisos, 'static::canceladoPeloIfood($notificacao) ?? static::canceladoPelaOrigem($notificacao) ??'), 'OrderCanceled: o texto do cancelamento pela origem');
confere(str_contains($avisos, 'CancelamentoPelaOrigem::textoDoPush($linha)'), 'usa o CancelamentoPelaOrigem::textoDoPush');

echo '== Alarme' . PHP_EOL;
confere(CartaoDoAlarme::integracao(null) === [], 'sem linha: nada');
confere(CartaoDoAlarme::integracao((object) ['sistema' => 'RestaurantePro', 'numero' => '4821', 'cobranca_forma' => 'dinheiro', 'cobranca_centavos' => 5890, 'troco_para_centavos' => 10000]) === ['entregas_origem' => 'RestaurantePro #4821', 'entregas_cobrar' => 'Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100'], 'origem e cobrança');
confere(CartaoDoAlarme::integracao((object) ['sistema' => 'RestaurantePro', 'numero' => '4821', 'cobranca_forma' => 'pago', 'cobranca_centavos' => 0, 'troco_para_centavos' => null]) === ['entregas_origem' => 'RestaurantePro #4821'], 'pago: só a origem');

echo '== App do motoboy' . PHP_EOL;
$linha  = (object) ['sistema' => 'RestaurantePro', 'numero' => '4821', 'cobranca_forma' => 'dinheiro', 'cobranca_centavos' => 5890, 'troco_para_centavos' => 10000, 'observacoes' => 'Sem cebola', 'complemento' => 'Ap 12', 'referencia' => 'Perto da praça', 'cliente_telefone' => '16999990000', 'cancelado_em' => null, 'pago_mesmo_cancelado' => false];
$dados  = DadosDaIntegracaoDoMotoboy::resposta($linha, (object) ['status' => 'enroute', 'driver_assigned_uuid' => 'driver-1'], 'driver-1');
confere($dados['integracao'] === true && $dados['sistema'] === 'RestaurantePro' && $dados['numero'] === '4821', 'sistema e número');
confere($dados['cobranca'] === ['forma' => 'dinheiro', 'centavos' => 5890, 'troco_para_centavos' => 10000, 'texto' => 'Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100'], 'cobrança');
confere($dados['observacoes'] === 'Sem cebola' && $dados['complemento'] === 'Ap 12' && $dados['referencia'] === 'Perto da praça' && $dados['telefone'] === '16999990000', 'textos e telefone do motoboy do pedido');
confere(DadosDaIntegracaoDoMotoboy::resposta($linha, (object) ['status' => 'dispatched', 'driver_assigned_uuid' => null], 'driver-1')['telefone'] === null, 'pedido aberto: sem telefone');
confere(DadosDaIntegracaoDoMotoboy::resposta($linha, (object) ['status' => 'enroute', 'driver_assigned_uuid' => 'driver-2'], 'driver-1')['telefone'] === null, 'pedido de outro motoboy: sem telefone');
confere(DadosDaIntegracaoDoMotoboy::resposta($linha, (object) ['status' => 'completed', 'driver_assigned_uuid' => 'driver-1'], 'driver-1')['telefone'] === null, 'encerrado: sem telefone');
confere(DadosDaIntegracaoDoMotoboy::resposta(null, (object) ['status' => 'enroute', 'driver_assigned_uuid' => 'driver-1'], 'driver-1') === ['integracao' => false], 'não é da integração');

echo '== Stack' . PHP_EOL;
confere(str_contains($stack, 'ENTREGAS_INTEGRACAO: ${ENTREGAS_INTEGRACAO:-}') && str_contains($stack, 'ENTREGAS_CONSOLE_URL: ${ENTREGAS_CONSOLE_URL:-https://entregas.restaurantepro.com.br}'), 'as duas variáveis no x-api-env');
confere(preg_match('/^ENTREGAS_INTEGRACAO=\r?$/m', $modelo) === 1, 'no modelo do stack.env');

resumo();
