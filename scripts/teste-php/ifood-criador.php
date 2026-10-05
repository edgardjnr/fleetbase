<?php

// Integração iFood: criação e despacho do pedido no Fleetbase (CriadorDoPedidoIfood), com models do Fleetbase falsos.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-criador.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';
require __DIR__ . '/fixtures-ifood.php';

use App\Support\Entregas\Ifood\CriadorDoPedidoIfood;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Teste\Banco;
use Teste\Sessao;
use Teste\Trava;

function preparar(): object
{
    reiniciarIfood();
    reiniciarFleetbase();

    return vinculoDaLojaA();
}

echo '== Pedido real: cria e despacha' . PHP_EOL;
$vinculo = preparar();
$linha   = (new CriadorDoPedidoIfood())->criar($vinculo, pedidoEmDinheiroComTroco());
$pedido  = Order::$criados[0] ?? null;
confere(count(Order::$criados) === 1 && $pedido->customer_uuid === 'vendor-a' && $pedido->customer_type === 'fleet-ops:vendor', 'um pedido, cliente = a Loja');
confere($pedido->company_uuid === 'empresa-1' && $pedido->empresaNaSessao === 'empresa-1', 'empresa no pedido e na sessão antes do create');
confere($pedido->type === 'transport' && $pedido->order_config_uuid === 'config-transport' && $pedido->status === 'dispatched', 'tipo transport (despachado em seguida)');
confere($pedido->internal_id === '4821' && $pedido->notes === 'iFood #4821' && $pedido->scheduled_at === null, 'número do iFood no internal_id e nas notas');
$destino = Place::$criados[0] ?? null;
confere($destino && $destino->location->getLat() === -21.17 && $destino->location->getLng() === -47.81, 'entrega = Place novo nas coordenadas do iFood');
confere($destino->name === 'CLIENTE FICTICIO' && $destino->street1 === 'RUA FICTICIA, 123' && $destino->street2 === 'APTO 501 · REF.: PERTO DA PRAÇA' && $destino->city === 'RIBEIRÃO PRETO', 'nome e endereço em maiúsculas (com acento)');
confere(!isset($destino->owner_uuid), 'sem dono: não entra nos endereços salvos da loja');
$payload = Payload::$salvos[0] ?? null;
confere($payload && $payload->pickup->uuid === 'place-loja' && $payload->dropoff === $destino && $payload->atual->uuid === 'place-loja' && $pedido->payload_uuid === $payload->uuid, 'coleta = Local da loja; entrega = o Place novo');
confere($linha && $linha->order_uuid === $pedido->uuid && $linha->pedido_ifood_id === 'pedido-real-1' && $linha->merchant_id === 'merchant-1' && $linha->vendor_uuid === 'vendor-a', 'linha do iFood ligada ao pedido');
confere($linha->cobrar_centavos === 5890 && $linha->forma_pagamento === 'CASH' && $linha->numero === '4821', 'cobrança na linha (fora do meta)');
confere($pedido->adhocAoCriar === true, 'nasce adhoc: vai aos motoboys agora');
confere($pedido->adhoc === true && $pedido->chamadas === ['saveQuietly', 'firstDispatchWithActivity'], 'despacho como o do portal: adhoc + firstDispatchWithActivity');
confere($pedido->travadoNoDespacho === [true] && Trava::$ocupadas === [], 'despacho com a trava do pedido (solta no fim)');
confere($linha->despachado_em === '2026-10-05 18:00:00', 'despachado_em marcado');
confere(!logou('coleta diverge'), 'coleta a menos de 300 m do endereço do iFood: sem aviso');
confere(logou('[entregas] ifood: pedido criado', 'info') && logsSem(['Cliente Ficticio', 'CLIENTE FICTICIO', '0800 000 0002', 'Rua Ficticia', '33334444']), 'log sem nome, telefone, endereço nem localizador');

echo '== Pedido de teste: não despacha' . PHP_EOL;
$vinculo = preparar();
$linha   = (new CriadorDoPedidoIfood())->criar($vinculo, pedidoDeTestePagoOnline());
$pedido  = Order::$criados[0];
confere($pedido->notes === 'iFood #9753 [TESTE]' && $pedido->chamadas === [] && $pedido->status === 'created', '[TESTE], sem aviso aos motoboys');
confere($pedido->adhocAoCriar === false && $pedido->adhoc === false, 'não nasce adhoc: a central atribui (um despacho manual não vai a todos os motoboys)');
confere($linha->teste === true && $linha->despachar_em === null && ($linha->despachado_em ?? null) === null, 'linha de teste, fora do agendador');
confere(abs(Place::$criados[0]->location->getLat() - (-21.1775 + 0.009)) < 0.000001, 'entrega ~1 km ao norte da loja');
confere(!logou('coleta diverge'), 'loja de teste (Acre) não gera aviso de divergência');

echo '== Agendado: não despacha agora' . PHP_EOL;
$vinculo = preparar();
$linha   = (new CriadorDoPedidoIfood())->criar($vinculo, pedidoAgendado());
$pedido  = Order::$criados[0];
confere($pedido->scheduled_at === '2026-10-05 19:20:00' && $pedido->chamadas === [], 'scheduled_at 40 min antes da janela, sem despacho');
confere($linha->agendado === true && $linha->despachar_em === '2026-10-05 19:20:00' && ($linha->despachado_em ?? null) === null, 'na fila do entregas:ifood-agendados');
confere($pedido->adhocAoCriar === true, 'nasce adhoc: o fleetops:dispatch-orders despacha o agendado sem ligar o adhoc');

echo '== Agendado sem janela: não nasce adhoc' . PHP_EOL;
$vinculo  = preparar();
$semJanela = pedidoAgendado();
unset($semJanela['schedule']);
$linha  = (new CriadorDoPedidoIfood())->criar($vinculo, $semJanela);
$pedido = Order::$criados[0];
confere($pedido->scheduled_at === null && $pedido->chamadas === [] && $linha->despachar_em === null, 'sem scheduled_at, sem despacho');
confere($pedido->adhocAoCriar === false && $pedido->adhoc === false, 'adhoc falso: a central atribui');

echo '== Sem coordenadas de entrega: não nasce adhoc' . PHP_EOL;
$vinculo                                                     = preparar();
$semCoordenadas                                              = pedidoEmDinheiroComTroco();
$semCoordenadas['delivery']['deliveryAddress']['coordinates'] = ['latitude' => 0, 'longitude' => 0];
$linha  = (new CriadorDoPedidoIfood())->criar($vinculo, $semCoordenadas);
$pedido = Order::$criados[0];
confere($pedido->chamadas === [] && $linha->despachar_em === null && logou('sem coordenadas de entrega', 'warning'), 'não despacha e avisa no log');
confere($pedido->adhocAoCriar === false && $pedido->adhoc === false, 'adhoc falso: a central atribui');

echo '== Coleta longe do endereço do iFood' . PHP_EOL;
$vinculo                                          = preparar();
$longe                                            = pedidoEmDinheiroComTroco();
$longe['merchant']['merchantAddress']['latitude'] = -21.1900;
(new CriadorDoPedidoIfood())->criar($vinculo, $longe);
confere(logou('[entregas] ifood: coleta diverge do iFood (1390 m)', 'warning'), 'aviso com a distância (1390 m)');
confere(count(Order::$criados) === 1, 'o pedido é criado mesmo assim (a coleta é o Local da loja)');

echo '== Loja sem Local de coleta' . PHP_EOL;
$vinculo                    = preparar();
Vendor::$todos[0]->place_uuid = null;
confere((new CriadorDoPedidoIfood())->criar($vinculo, pedidoEmDinheiroComTroco()) === null && Order::$criados === [], 'não cria');
confere(logou('loja sem local de coleta', 'error'), 'erro no log');

echo '== Local de coleta inválido: como loja sem Local' . PHP_EOL;
$invalidos = [
    'coleta em (0, 0), o "sem GPS" do Fleetbase' => fn ($local) => $local->location = new Point(0.0, 0.0),
    'coleta sem location'                         => fn ($local) => $local->location = null,
    'coleta de outra empresa'                     => fn ($local) => $local->company_uuid = 'empresa-2',
    'coleta sem dono (Local antigo da loja)'      => fn ($local) => $local->owner_uuid = null,
    'coleta de outro Fornecedor'                  => fn ($local) => $local->owner_uuid = 'vendor-b',
    'coleta com dono de outro tipo'               => fn ($local) => $local->owner_type = 'fleet-ops:contact',
];
foreach ($invalidos as $caso => $estragar) {
    $vinculo = preparar();
    $estragar(Place::$todos[0]);
    confere((new CriadorDoPedidoIfood())->criar($vinculo, pedidoEmDinheiroComTroco()) === null && Order::$criados === [] && Place::$criados === [], "{$caso}: não cria");
    confere(logou('loja sem local de coleta', 'error') && Banco::linhas('entregas_ifood_pedidos') === [], "{$caso}: erro no log, sem linha");
}

echo '== Falha na linha desfaz o pedido' . PHP_EOL;
$vinculo = preparar();
Banco::inserir('entregas_ifood_pedidos', ['pedido_ifood_id' => 'pedido-real-1', 'order_uuid' => 'outro', 'merchant_id' => 'merchant-1', 'company_uuid' => 'empresa-1'], false);
$erro = excecao(fn () => (new CriadorDoPedidoIfood())->criar($vinculo, pedidoEmDinheiroComTroco()));
confere($erro instanceof Teste\ErroDeBanco && count(Banco::linhas('entregas_ifood_pedidos')) === 1, 'pedido_ifood_id repetido: a transação falha');
confere(Order::$criados === [] && Order::$todos === [] && Place::$criados === [] && count(Place::$todos) === 1 && Payload::$salvos === [], 'o rollback desfaz o Order, o Place da entrega e o Payload');
confere(Banco::linhas('entregas_ifood_pedidos')[0]->order_uuid === 'outro', 'a linha que já existia fica');

echo '== Despacho' . PHP_EOL;
preparar();
$pedido = Order::create(['company_uuid' => 'empresa-1', 'dispatched' => true]);
Banco::inserir('entregas_ifood_pedidos', ['pedido_ifood_id' => 'p-1', 'order_uuid' => $pedido->uuid, 'merchant_id' => 'merchant-1', 'company_uuid' => 'empresa-1'], false);
Sessao::$dados = [];
confere((new CriadorDoPedidoIfood())->despachar($pedido) && $pedido->chamadas === ['insertDispatchActivity'], 'já despachado pelo fleetops:dispatch-orders: só a atividade');
confere(Sessao::$dados['company'] === 'empresa-1' && Banco::linhas('entregas_ifood_pedidos')[0]->despachado_em === '2026-10-05 18:00:00', 'com a empresa na sessão; marca despachado_em');
$pedido->chamadas = [];
(new CriadorDoPedidoIfood())->despachar($pedido);
confere($pedido->chamadas === [], 'atividade já existe: nada a fazer');
$outro                 = Order::create(['company_uuid' => 'empresa-1']);
Order::$falharDespacho = true;
confere((new CriadorDoPedidoIfood())->despachar($outro) === false && logou('falha ao despachar o pedido', 'error'), 'falha no despacho: false e log (o agendador tenta de novo)');
confere(Trava::$ocupadas === [], 'a trava é solta mesmo com erro');

/** Pedido na tabela, com a linha do iFood na fila do agendador; devolve o Order. */
function pedidoNaFila(array $atributos = []): Order
{
    $pedido = Order::create($atributos + ['company_uuid' => 'empresa-1']);
    Banco::inserir('entregas_ifood_pedidos', ['pedido_ifood_id' => 'p-' . $pedido->uuid, 'order_uuid' => $pedido->uuid, 'merchant_id' => 'merchant-1', 'company_uuid' => 'empresa-1', 'despachar_em' => '2026-10-05 17:50:00'], false);

    return $pedido;
}

function linhaDoPedido(Order $pedido): object
{
    return (new Teste\Consulta('entregas_ifood_pedidos'))->where('order_uuid', $pedido->uuid)->first();
}

/** Uma cópia do pedido como ele estava antes (o que o chamador tem em mãos), fora da tabela. */
function copiaVelha(Order $pedido, array $antes): Order
{
    return new Order(['uuid' => $pedido->uuid, 'public_id' => $pedido->public_id, 'company_uuid' => $pedido->company_uuid] + $antes);
}

echo '== Despacho: trava ocupada' . PHP_EOL;
preparar();
$pedido                                          = pedidoNaFila();
Trava::$ocupadas['entregas:pedido:' . $pedido->uuid] = true;
confere((new CriadorDoPedidoIfood())->despachar($pedido) === false && $pedido->chamadas === [], 'não despacha: false (falha temporária)');
confere(linhaDoPedido($pedido)->despachar_em === '2026-10-05 17:50:00' && (linhaDoPedido($pedido)->despachado_em ?? null) === null, 'fica na fila: o agendador tenta no próximo minuto');
confere(logou('[entregas] ifood: trava do pedido ocupada', 'warning') && isset(Trava::$ocupadas['entregas:pedido:' . $pedido->uuid]), 'aviso no log; a trava do outro fica');

echo '== Despacho: relê o pedido com a trava' . PHP_EOL;
$resolvidos = [
    'aceito (started)'                => ['started' => true, 'driver_assigned_uuid' => 'driver-1', 'status' => 'started'],
    'cancelado pela central'          => ['status' => 'canceled'],
    'concluído'                       => ['status' => 'completed'],
    'apagado'                         => ['deleted_at' => '2026-10-05 17:55:00'],
];
foreach ($resolvidos as $caso => $agora) {
    preparar();
    $pedido = pedidoNaFila();
    $velho  = copiaVelha($pedido, ['status' => 'created']);
    foreach ($agora as $campo => $valor) {
        $pedido->$campo = $valor;
    }
    confere((new CriadorDoPedidoIfood())->despachar($velho) === false && $pedido->chamadas === [] && $velho->chamadas === [], "{$caso}: não despacha");
    confere(linhaDoPedido($pedido)->despachar_em === null && (linhaDoPedido($pedido)->despachado_em ?? null) === null, "{$caso}: sai da fila do agendador (despachar_em nulo)");
    confere(logou('[entregas] ifood: pedido não despachado', 'info') && logsSem(['driver-1']), "{$caso}: log só com ids");
}
preparar();
$pedido = pedidoNaFila();
$velho  = copiaVelha($pedido, []);
Order::$todos = [];
confere((new CriadorDoPedidoIfood())->despachar($velho) === false && $velho->chamadas === [] && linhaDoPedido($pedido)->despachar_em === null, 'pedido que sumiu da tabela: sai da fila');

echo '== Despacho: atividade só com status created/dispatched' . PHP_EOL;
preparar();
$pedido = pedidoNaFila(['dispatched' => true, 'status' => 'dispatched']);
confere((new CriadorDoPedidoIfood())->despachar($pedido) && $pedido->chamadas === ['insertDispatchActivity'] && $pedido->travadoNoDespacho === [true], 'dispatched sem a atividade: insere, com a trava');
preparar();
$pedido = pedidoNaFila(['dispatched' => true, 'status' => 'enroute']);
confere((new CriadorDoPedidoIfood())->despachar($pedido) && $pedido->chamadas === [], 'status já adiante: não insere a atividade');
confere(linhaDoPedido($pedido)->despachado_em === '2026-10-05 18:00:00', 'e marca despachado_em (já estava com os motoboys)');

echo '== Despacho: agendado com motoboy já atribuído pela central' . PHP_EOL;
preparar();
// agendado com janela: nasce adhoc; a central atribui um motoboy antes do despacho
$pedido                       = pedidoNaFila(['adhoc' => true, 'scheduled_at' => '2026-10-05 18:30:00']);
$velho                        = copiaVelha($pedido, ['adhoc' => true]);
$pedido->driver_assigned_uuid = 'driver-1';
confere((new CriadorDoPedidoIfood())->despachar($velho) === true, 'despacha: true');
confere($pedido->chamadas === ['saveQuietly', 'firstDispatchWithActivity'] && $pedido->adhocAoSalvar === [false] && $pedido->adhoc === false, 'adhoc falso antes do despacho: o HandleOrderDispatched avisa só o motoboy atribuído');
confere($pedido->travadoNoDespacho === [true] && $velho->chamadas === [], 'com a trava, no pedido relido');
confere(linhaDoPedido($pedido)->despachado_em === '2026-10-05 18:00:00', 'marca despachado_em (sai da fila do agendador)');
confere(logou('[entregas] ifood: pedido despachado só ao motoboy atribuído', 'info') && logsSem(['driver-1']), 'log só com o id do pedido');
preparar();
$pedido = pedidoNaFila(['adhoc' => true, 'dispatched' => true, 'status' => 'dispatched', 'driver_assigned_uuid' => 'driver-1']);
confere((new CriadorDoPedidoIfood())->despachar($pedido) === true && $pedido->chamadas === ['insertDispatchActivity'] && $pedido->adhoc === true, 'já despachado com motoboy atribuído: só a atividade que falta, sem novo aviso');
preparar();
$pedido                       = pedidoNaFila(['adhoc' => true]);
$pedido->driver_assigned_uuid = 'driver-1';
Order::$falharDespacho        = true;
confere((new CriadorDoPedidoIfood())->despachar($pedido) === false && logou('falha ao despachar o pedido', 'error') && (linhaDoPedido($pedido)->despachado_em ?? null) === null, 'falha no despacho ao atribuído: false, log e fica na fila');
Order::$falharDespacho = false;

echo '== Despacho: usa o pedido relido, não o que recebeu' . PHP_EOL;
preparar();
$pedido = pedidoNaFila();
$velho  = copiaVelha($pedido, ['dispatched' => false]);
confere((new CriadorDoPedidoIfood())->despachar($velho) && $pedido->chamadas === ['saveQuietly', 'firstDispatchWithActivity'] && $pedido->adhoc === true, 'adhoc + firstDispatchWithActivity no pedido relido');

resumo();
