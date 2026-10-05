<?php

// Integração iFood: tradução do pedido do Logistics (PedidoDoIfood).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-pedido.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/fixtures-ifood.php';

use App\Support\Entregas\Ifood\PedidoDoIfood;

// Local da loja (coleta) em Ribeirão Preto
const LAT_COLETA = -21.1775;
const LNG_COLETA = -47.8103;
$agora = new DateTimeImmutable('2026-10-05 18:00:00', new DateTimeZone('UTC'));

echo '== Pedido real, dinheiro com troco' . PHP_EOL;
$dados = PedidoDoIfood::mapear(pedidoEmDinheiroComTroco(), LAT_COLETA, LNG_COLETA, $agora);
confere($dados['numero'] === '4821' && $dados['notas'] === 'iFood #4821', 'número curto e notas "iFood #4821"');
confere($dados['teste'] === false && $dados['agendado'] === false && $dados['despachar_agora'] === true && $dados['scheduled_at'] === null, 'imediato: despacha na hora');
$entrega = $dados['entrega'];
confere($entrega['latitude'] === -21.17 && $entrega['longitude'] === -47.81, 'coordenadas do iFood');
confere($entrega['street1'] === 'Rua Ficticia, 123' && $entrega['street2'] === 'Apto 501 · Ref.: Perto da praça', 'rua e número; complemento e referência no street2');
confere($entrega['neighborhood'] === 'Centro' && $entrega['city'] === 'Ribeirão Preto' && $entrega['province'] === 'SP' && $entrega['postal_code'] === '14000000' && $entrega['country'] === 'BR', 'bairro, cidade, estado, CEP e país');
confere($entrega['nome'] === 'Cliente Ficticio', 'nome do cliente no Local');
$linha = $dados['linha'];
confere($linha['cobrar_centavos'] === 5890 && $linha['forma_pagamento'] === 'CASH' && $linha['troco_para_centavos'] === 10000, 'cobrar R$ 58,90 em dinheiro, troco para R$ 100');
confere($linha['telefone_0800'] === '0800 000 0002' && $linha['localizador'] === '33334444' && $linha['telefone_expira_em'] === '2026-10-05 21:58:00', '0800, localizador e expiração (UTC)');
confere($linha['observacoes'] === 'Interfone quebrado, ligar ao chegar.' && $linha['complemento'] === 'Apto 501' && $linha['referencia'] === 'Perto da praça', 'observações, complemento e referência');
confere($linha['despachar_em'] === '2026-10-05 18:00:00' && $linha['agendado'] === false && $linha['teste'] === false && $linha['exige_codigo'] === false, 'despachar_em = agora');

echo '== Pedido de teste pago online (como os da sonda)' . PHP_EOL;
$dados = PedidoDoIfood::mapear(pedidoDeTestePagoOnline(), LAT_COLETA, LNG_COLETA, $agora);
confere($dados['teste'] === true && $dados['notas'] === 'iFood #9753 [TESTE]', 'marcado [TESTE]');
confere($dados['despachar_agora'] === false && $dados['linha']['despachar_em'] === null, 'sem aviso aos motoboys (só a central atribui)');
$metros = PedidoDoIfood::metrosEntre(LAT_COLETA, LNG_COLETA, $dados['entrega']['latitude'], $dados['entrega']['longitude']);
confere(abs($metros - 1000) < 15 && $dados['entrega']['longitude'] === LNG_COLETA, 'entrega ~1 km ao norte da loja (' . round($metros) . ' m), não em 0,0');
confere($dados['entrega']['country'] === 'BR', 'país "XX" do teste vira BR');
confere($dados['linha']['cobrar_centavos'] === 0 && $dados['linha']['forma_pagamento'] === null && $dados['linha']['troco_para_centavos'] === null, 'sem payments = nada a cobrar');

echo '== Pedido real sem coordenadas' . PHP_EOL;
$semCoordenadas                                                = pedidoEmDinheiroComTroco();
$semCoordenadas['delivery']['deliveryAddress']['coordinates'] = ['latitude' => 0, 'longitude' => 0];
$dados                                                         = PedidoDoIfood::mapear($semCoordenadas, LAT_COLETA, LNG_COLETA, $agora);
confere($dados['sem_coordenadas'] === true && $dados['despachar_agora'] === false && $dados['linha']['despachar_em'] === null, 'não despacha: a central confere');

echo '== Agendado' . PHP_EOL;
$dados = PedidoDoIfood::mapear(pedidoAgendado(), LAT_COLETA, LNG_COLETA, $agora);
confere($dados['agendado'] === true && $dados['despachar_agora'] === false, 'agendado: não despacha agora');
confere($dados['scheduled_at'] === '2026-10-05 19:20:00' && $dados['linha']['despachar_em'] === '2026-10-05 19:20:00', 'vai aos motoboys 40 min antes da janela (20:00 → 19:20)');
$dados = PedidoDoIfood::mapear(pedidoAgendado('2026-10-05T18:30:00.000Z'), LAT_COLETA, LNG_COLETA, $agora);
confere($dados['agendado'] === false && $dados['despachar_agora'] === true && $dados['linha']['despachar_em'] === '2026-10-05 18:00:00', 'menos de 40 min para a janela: despacha na hora');
$semJanela = pedidoAgendado();
unset($semJanela['schedule']);
$semJanela['delivery']['deliveryDateTime'] = '2026-10-05T21:00:00.000Z';
confere(PedidoDoIfood::mapear($semJanela, LAT_COLETA, LNG_COLETA, $agora)['scheduled_at'] === '2026-10-05 20:20:00', 'sem schedule: usa o deliveryDateTime');

echo '== Cobrança' . PHP_EOL;
confere(PedidoDoIfood::cobranca(null) === [0, null, null], 'sem payments');
confere(PedidoDoIfood::cobranca(['prepaid' => 27, 'pending' => 0, 'methods' => [['method' => 'CREDIT', 'prepaid' => true, 'type' => 'ONLINE']]]) === [0, null, null], 'pending 0 = pago online');
confere(PedidoDoIfood::cobranca(['prepaid' => 0, 'pending' => 323.99, 'methods' => [['value' => 323.99, 'method' => 'CREDIT', 'prepaid' => false, 'type' => 'OFFLINE']]]) === [32399, 'CREDIT', null], 'cartão na porta, sem troco');
confere(PedidoDoIfood::cobranca(['pending' => 30, 'methods' => [['method' => 'PIX', 'prepaid' => true, 'type' => 'ONLINE'], ['method' => 'CASH', 'prepaid' => false, 'type' => 'OFFLINE', 'cash' => ['changeFor' => 50]]]]) === [3000, 'CASH', 5000], 'pago em parte: a forma é a do método não pago');
confere(PedidoDoIfood::cobranca(['pending' => 10.1, 'methods' => [['method' => 'CASH', 'prepaid' => false]]]) === [1010, 'CASH', null], 'dinheiro sem troco; centavos arredondados');

echo '== Campos faltando' . PHP_EOL;
$minimo = ['id' => 'abcdef123456', 'isTest' => false, 'delivery' => ['deliveryAddress' => ['coordinates' => ['latitude' => -21.17, 'longitude' => -47.81]]]];
$dados  = PedidoDoIfood::mapear($minimo, LAT_COLETA, LNG_COLETA, $agora);
confere($dados['numero'] === 'abcdef12' && $dados['entrega']['nome'] === 'Cliente iFood' && $dados['entrega']['street1'] === 'Endereço do iFood' && $dados['entrega']['street2'] === null, 'sem displayId, nome e rua: valores padrão');
confere($dados['linha']['telefone_0800'] === null && $dados['linha']['telefone_expira_em'] === null, 'sem telefone');

resumo();
