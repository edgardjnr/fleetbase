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
use Teste\Banco;
use Teste\Sessao;

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
confere($pedido->adhoc === true && $pedido->chamadas === ['saveQuietly', 'firstDispatchWithActivity'], 'despacho como o do portal: adhoc + firstDispatchWithActivity');
confere($linha->despachado_em === '2026-10-05 18:00:00', 'despachado_em marcado');
confere(!logou('coleta diverge'), 'coleta a menos de 300 m do endereço do iFood: sem aviso');
confere(logou('[entregas] ifood: pedido criado', 'info') && logsSem(['Cliente Ficticio', 'CLIENTE FICTICIO', '0800 000 0002', 'Rua Ficticia', '33334444']), 'log sem nome, telefone, endereço nem localizador');

echo '== Pedido de teste: não despacha' . PHP_EOL;
$vinculo = preparar();
$linha   = (new CriadorDoPedidoIfood())->criar($vinculo, pedidoDeTestePagoOnline());
$pedido  = Order::$criados[0];
confere($pedido->notes === 'iFood #9753 [TESTE]' && $pedido->chamadas === [] && $pedido->status === 'created', '[TESTE], sem aviso aos motoboys');
confere($linha->teste === true && $linha->despachar_em === null && ($linha->despachado_em ?? null) === null, 'linha de teste, fora do agendador');
confere(abs(Place::$criados[0]->location->getLat() - (-21.1775 + 0.009)) < 0.000001, 'entrega ~1 km ao norte da loja');
confere(!logou('coleta diverge'), 'loja de teste (Acre) não gera aviso de divergência');

echo '== Agendado: não despacha agora' . PHP_EOL;
$vinculo = preparar();
$linha   = (new CriadorDoPedidoIfood())->criar($vinculo, pedidoAgendado());
$pedido  = Order::$criados[0];
confere($pedido->scheduled_at === '2026-10-05 19:20:00' && $pedido->chamadas === [], 'scheduled_at 40 min antes da janela, sem despacho');
confere($linha->agendado === true && $linha->despachar_em === '2026-10-05 19:20:00' && ($linha->despachado_em ?? null) === null, 'na fila do entregas:ifood-agendados');

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

echo '== Falha na linha desfaz o pedido' . PHP_EOL;
$vinculo = preparar();
Banco::inserir('entregas_ifood_pedidos', ['pedido_ifood_id' => 'pedido-real-1', 'order_uuid' => 'outro', 'merchant_id' => 'merchant-1', 'company_uuid' => 'empresa-1'], false);
$erro = excecao(fn () => (new CriadorDoPedidoIfood())->criar($vinculo, pedidoEmDinheiroComTroco()));
confere($erro instanceof Teste\ErroDeBanco && count(Banco::linhas('entregas_ifood_pedidos')) === 1, 'pedido_ifood_id repetido: a transação falha');
confere(Order::$criados[0]->chamadas === [], 'e nada é despachado');

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

resumo();
