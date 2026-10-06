<?php

// Integração iFood: tradução do pedido do Logistics (PedidoDoIfood).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-pedido.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/fixtures-ifood.php';

use App\Support\Entregas\Ifood\PedidoDoIfood;

// Local da loja (coleta) em Ribeirão Preto
const LAT_COLETA = -21.1775;
const LNG_COLETA = -47.8103;
// o now() do app, em hora de Brasília (18:00 UTC); as datas do iFood vêm em UTC ("Z") e as gravadas saem em hora de Brasília
$agora = new DateTimeImmutable('2026-10-05 15:00:00', new DateTimeZone('America/Sao_Paulo'));

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
confere($linha['telefone_0800'] === '0800 000 0002' && $linha['localizador'] === '33334444' && $linha['telefone_expira_em'] === '2026-10-05 18:58:00', '0800, localizador e expiração (21:58Z em hora de Brasília)');
confere($linha['observacoes'] === 'Interfone quebrado, ligar ao chegar.' && $linha['complemento'] === 'Apto 501' && $linha['referencia'] === 'Perto da praça', 'observações, complemento e referência');
confere($linha['despachar_em'] === '2026-10-05 15:00:00' && $linha['agendado'] === false && $linha['teste'] === false && $linha['exige_codigo'] === false, 'despachar_em = agora');

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
confere($dados['notas'] === 'iFood #4821 [SEM LOCALIZAÇÃO]', '[SEM LOCALIZAÇÃO] nas notas, para a central ver na lista');

echo '== Agendado' . PHP_EOL;
$dados = PedidoDoIfood::mapear(pedidoAgendado(), LAT_COLETA, LNG_COLETA, $agora);
confere($dados['agendado'] === true && $dados['despachar_agora'] === false, 'agendado: não despacha agora');
confere($dados['scheduled_at'] === '2026-10-05 16:20:00' && $dados['linha']['despachar_em'] === '2026-10-05 16:20:00', 'vai aos motoboys 40 min antes da janela (20:00Z → 19:20Z, 16:20 em Brasília)');
$dados = PedidoDoIfood::mapear(pedidoAgendado('2026-10-05T18:30:00.000Z'), LAT_COLETA, LNG_COLETA, $agora);
confere($dados['agendado'] === false && $dados['despachar_agora'] === true && $dados['linha']['despachar_em'] === '2026-10-05 15:00:00', 'menos de 40 min para a janela: despacha na hora');
confere(PedidoDoIfood::mapear(pedidoAgendado(), LAT_COLETA, LNG_COLETA, $agora)['sem_janela'] === false, 'com janela: sem_janela = false');

echo '== Agendado sem janela legível: não despacha e avisa no log' . PHP_EOL;
$semSchedule = pedidoAgendado();
unset($semSchedule['schedule']);
$semSchedule['delivery']['deliveryDateTime'] = '2026-10-05T21:00:00.000Z';
$naoIso                                      = pedidoAgendado('tomorrow');
$ilegivel                                    = pedidoAgendado('amanhã à noite');
$scheduleLista                               = pedidoAgendado();
$scheduleLista['schedule']                   = ['deliveryDateTimeStart' => ['2026-10-05T20:00:00Z']];
foreach (['sem schedule (o deliveryDateTime é só estimativa)' => $semSchedule, 'início "tomorrow" (não é data ISO)' => $naoIso, 'início ilegível' => $ilegivel, 'início em lista' => $scheduleLista] as $caso => $pedido) {
    reiniciarIfood();
    $dados = PedidoDoIfood::mapear($pedido, LAT_COLETA, LNG_COLETA, $agora);
    confere($dados['agendado'] === true && $dados['sem_janela'] === true && $dados['despachar_agora'] === false && $dados['scheduled_at'] === null && $dados['linha']['despachar_em'] === null && $dados['linha']['agendado'] === true, "{$caso}: agendado, sem despacho nem scheduled_at");
    confere($dados['notas'] === 'iFood #5150 [AGENDADO SEM HORÁRIO]', "{$caso}: marca nas notas para a central");
    confere(logou('[entregas] ifood: agendado sem janela', 'warning') && logsSem(['Cliente Ficticio', 'Rua Ficticia', '0800 000 0002', '33334444']), "{$caso}: log sem dado do cliente");
}
reiniciarIfood();
PedidoDoIfood::mapear(pedidoAgendado(), LAT_COLETA, LNG_COLETA, $agora);
PedidoDoIfood::mapear(pedidoEmDinheiroComTroco(), LAT_COLETA, LNG_COLETA, $agora);
confere(!logou('agendado sem janela'), 'agendado com janela e imediato: sem log');

echo '== Agendado que não vai aos motoboys: sem scheduled_at (o fleetops:dispatch-orders despacharia)' . PHP_EOL;
$agendadoTeste           = pedidoAgendado();
$agendadoTeste['isTest'] = true;
$dados                   = PedidoDoIfood::mapear($agendadoTeste, LAT_COLETA, LNG_COLETA, $agora);
confere($dados['teste'] === true && $dados['agendado'] === true && $dados['scheduled_at'] === null && $dados['despachar_agora'] === false && $dados['linha']['despachar_em'] === null, 'agendado de teste: scheduled_at nulo, fora do agendador');
confere($dados['notas'] === 'iFood #5150 [TESTE]', 'agendado de teste: [TESTE] nas notas');
$agendadoSemLugar                                                = pedidoAgendado();
$agendadoSemLugar['delivery']['deliveryAddress']['coordinates'] = ['latitude' => 0, 'longitude' => 0];
$dados                                                          = PedidoDoIfood::mapear($agendadoSemLugar, LAT_COLETA, LNG_COLETA, $agora);
confere($dados['sem_coordenadas'] === true && $dados['agendado'] === true && $dados['scheduled_at'] === null && $dados['despachar_agora'] === false && $dados['linha']['despachar_em'] === null, 'agendado em 0,0: scheduled_at nulo, fora do agendador');
confere($dados['notas'] === 'iFood #5150 [SEM LOCALIZAÇÃO]', 'agendado em 0,0: [SEM LOCALIZAÇÃO] nas notas');

echo '== Coordenadas inválidas = sem coordenadas' . PHP_EOL;
$coordenadas = [
    'latitude 0'                  => ['latitude' => 0, 'longitude' => -47.81],
    'longitude 0'                 => ['latitude' => -21.17, 'longitude' => 0.0],
    'sem coordinates'             => null,
    'só a latitude'               => ['latitude' => -21.17],
    'não numérica'                => ['latitude' => 'abc', 'longitude' => -47.81],
    'em lista'                    => ['latitude' => [-21.17], 'longitude' => -47.81],
    'latitude fora de ±90'        => ['latitude' => -95, 'longitude' => -47.81],
    'longitude fora de ±180'      => ['latitude' => -21.17, 'longitude' => 190],
    'a mais de 50 km (São Paulo)' => ['latitude' => -23.55, 'longitude' => -46.63],
];
foreach ($coordenadas as $caso => $valor) {
    $pedido = pedidoEmDinheiroComTroco();
    if ($valor === null) {
        unset($pedido['delivery']['deliveryAddress']['coordinates']);
    } else {
        $pedido['delivery']['deliveryAddress']['coordinates'] = $valor;
    }
    $dados = PedidoDoIfood::mapear($pedido, LAT_COLETA, LNG_COLETA, $agora);
    confere($dados['sem_coordenadas'] === true && $dados['despachar_agora'] === false && $dados['linha']['despachar_em'] === null && $dados['notas'] === 'iFood #4821 [SEM LOCALIZAÇÃO]' && abs($dados['entrega']['latitude'] - (LAT_COLETA + 0.009)) < 0.000001 && $dados['entrega']['longitude'] === LNG_COLETA, "{$caso}: sem coordenadas, entrega perto da loja, sem despacho");
}
$texto                                                = pedidoEmDinheiroComTroco();
$texto['delivery']['deliveryAddress']['coordinates'] = ['latitude' => '-21.17', 'longitude' => '-47.81'];
$dados                                                = PedidoDoIfood::mapear($texto, LAT_COLETA, LNG_COLETA, $agora);
confere($dados['sem_coordenadas'] === false && $dados['entrega']['latitude'] === -21.17 && $dados['despachar_agora'] === true, 'número em texto vale');
$perto                                                = pedidoEmDinheiroComTroco();
$perto['delivery']['deliveryAddress']['coordinates'] = ['latitude' => LAT_COLETA - 0.40, 'longitude' => LNG_COLETA];
$dados                                                = PedidoDoIfood::mapear($perto, LAT_COLETA, LNG_COLETA, $agora);
confere($dados['sem_coordenadas'] === false && $dados['despachar_agora'] === true && $dados['notas'] === 'iFood #4821', 'a ~44 km da loja ainda vale');

echo '== isTest tolerante' . PHP_EOL;
foreach ([true, 'true', 'TRUE', 1, '1'] as $valor) {
    $pedido           = pedidoEmDinheiroComTroco();
    $pedido['isTest'] = $valor;
    $dados            = PedidoDoIfood::mapear($pedido, LAT_COLETA, LNG_COLETA, $agora);
    confere($dados['teste'] === true && $dados['despachar_agora'] === false && $dados['notas'] === 'iFood #4821 [TESTE]', 'isTest ' . var_export($valor, true) . ' = teste');
}
foreach ([false, 'false', 0, '0', null, 'sim'] as $valor) {
    $pedido           = pedidoEmDinheiroComTroco();
    $pedido['isTest'] = $valor;
    confere(PedidoDoIfood::mapear($pedido, LAT_COLETA, LNG_COLETA, $agora)['teste'] === false, 'isTest ' . var_export($valor, true) . ' = real');
}

echo '== Endereço com campos estranhos' . PHP_EOL;
$endereco = function (array $mudancas, array $remover = []) use ($agora): array {
    $pedido = pedidoEmDinheiroComTroco();
    foreach ($remover as $campo) {
        unset($pedido['delivery']['deliveryAddress'][$campo]);
    }
    $pedido['delivery']['deliveryAddress'] = $mudancas + $pedido['delivery']['deliveryAddress'];

    return PedidoDoIfood::mapear($pedido, LAT_COLETA, LNG_COLETA, $agora)['entrega'];
};
confere($endereco(['streetName' => ['Rua'], 'formattedAddress' => 'Rua Formatada, 77'])['street1'] === 'Rua Formatada, 77', 'streetName em lista: usa o formattedAddress');
confere($endereco(['streetNumber' => ['123']])['street1'] === 'Rua Ficticia', 'streetNumber em lista: só a rua');
confere($endereco(['formattedAddress' => 'Rua Formatada, 77'], ['streetName'])['street1'] === 'Rua Formatada, 77', 'sem streetName: o formattedAddress, não só o número');
confere($endereco([], ['streetName', 'formattedAddress'])['street1'] === 'Endereço do iFood', 'sem streetName nem formattedAddress: texto padrão');
confere($endereco(['country' => ['BR']])['country'] === 'BR' && $endereco(['country' => 'br'])['country'] === 'BR', 'país em lista ou minúsculo');
confere($endereco(['postalCode' => '00000000'])['postal_code'] === null && $endereco(['postalCode' => '00000-000'])['postal_code'] === null && $endereco(['postalCode' => '14000-000'])['postal_code'] === '14000-000', 'CEP só de zeros = nulo');
confere($endereco(['state' => 'XX'])['province'] === null && $endereco(['state' => 'ZZ'])['province'] === null && $endereco(['state' => ['SP']])['province'] === null, 'estado inválido = nulo');
confere($endereco(['state' => 'sp'])['province'] === 'SP', 'sigla minúscula vira maiúscula');
confere(PedidoDoIfood::mapear(pedidoDeTestePagoOnline(), LAT_COLETA, LNG_COLETA, $agora)['entrega']['province'] === null, 'estado "XX" do pedido de teste = nulo');

echo '== Cobrança' . PHP_EOL;
confere(PedidoDoIfood::cobranca(null) === [0, null, null], 'sem payments');
confere(PedidoDoIfood::cobranca(['prepaid' => 27, 'pending' => 0, 'methods' => [['method' => 'CREDIT', 'prepaid' => true, 'type' => 'ONLINE']]]) === [0, null, null], 'pending 0 = pago online');
confere(PedidoDoIfood::cobranca(['prepaid' => 0, 'pending' => 323.99, 'methods' => [['value' => 323.99, 'method' => 'CREDIT', 'prepaid' => false, 'type' => 'OFFLINE']]]) === [32399, 'CREDIT', null], 'cartão na porta, sem troco');
confere(PedidoDoIfood::cobranca(['pending' => 30, 'methods' => [['method' => 'PIX', 'prepaid' => true, 'type' => 'ONLINE'], ['method' => 'CASH', 'prepaid' => false, 'type' => 'OFFLINE', 'cash' => ['changeFor' => 50]]]]) === [3000, 'CASH', 5000], 'pago em parte: a forma é a do método não pago');
confere(PedidoDoIfood::cobranca(['pending' => 10.1, 'methods' => [['method' => 'CASH', 'prepaid' => false]]]) === [1010, 'CASH', null], 'dinheiro sem troco; centavos arredondados');
confere(PedidoDoIfood::cobranca(['pending' => '12.5', 'methods' => [['method' => 'DEBIT', 'prepaid' => false]]]) === [1250, 'DEBIT', null], 'pending em texto');

echo '== Cobrança com formato divergente' . PHP_EOL;
reiniciarIfood();
$semPending = ['methods' => [['value' => 20, 'method' => 'CASH', 'prepaid' => false, 'type' => 'OFFLINE', 'cash' => ['changeFor' => 50]], ['value' => 10.5, 'method' => 'PIX', 'prepaid' => true, 'type' => 'ONLINE']]];
confere(PedidoDoIfood::cobranca($semPending) === [2000, 'CASH', 5000], 'sem pending: soma dos métodos não pagos');
confere(PedidoDoIfood::cobranca(['methods' => [['value' => 20, 'method' => 'CASH', 'type' => 'OFFLINE'], ['value' => 15.25, 'method' => 'CREDIT', 'type' => 'OFFLINE']]]) === [3525, 'CASH+CREDIT', null], 'sem pending, dois OFFLINE (sem o campo prepaid): soma e forma mista');
confere(!logou('pagamento inconsistente'), 'sem pending, com métodos na porta: sem aviso');

reiniciarIfood();
confere(PedidoDoIfood::cobranca(['pending' => 30, 'methods' => [['value' => 30, 'method' => 'CREDIT', 'prepaid' => true, 'type' => 'ONLINE']]], ['pedido_ifood' => 'p-1', 'numero' => '4821']) === [3000, null, null], 'pending > 0 sem método na porta: cobra o pending, forma desconhecida');
confere(logou('[entregas] ifood: pagamento inconsistente', 'warning'), 'pending > 0 sem método na porta: aviso');
reiniciarIfood();
confere(PedidoDoIfood::cobranca(['pending' => 12, 'methods' => []]) === [1200, null, null] && logou('pagamento inconsistente', 'warning'), 'pending > 0 sem métodos: cobra e avisa');

reiniciarIfood();
confere(PedidoDoIfood::cobranca(['pending' => 0, 'methods' => [['value' => 30, 'method' => 'CASH', 'prepaid' => false, 'type' => 'OFFLINE']]], ['pedido_ifood' => 'p-1', 'numero' => '4821']) === [0, null, null], 'pending 0 com método não pago: o pending explícito vale, nada a cobrar');
confere(logou('[entregas] ifood: pagamento inconsistente', 'warning'), 'pending 0 com método não pago: aviso');
$contexto = Illuminate\Support\Facades\Log::$registros[0][2] ?? [];
confere(($contexto['pedido_ifood'] ?? null) === 'p-1' && ($contexto['numero'] ?? null) === '4821' && !array_key_exists('methods', $contexto), 'aviso com os ids do pedido, sem o bloco de pagamento');

reiniciarIfood();
confere(PedidoDoIfood::cobranca(['pending' => 35, 'methods' => [['value' => 20, 'method' => 'CASH', 'prepaid' => false, 'cash' => ['changeFor' => 50]], ['value' => 15, 'method' => 'CREDIT', 'prepaid' => false]]]) === [3500, 'CASH+CREDIT', 5000], 'dois métodos na porta: forma mista, troco do dinheiro');
confere(PedidoDoIfood::cobranca(['pending' => 35, 'methods' => [['value' => 20, 'method' => 'DEBIT', 'prepaid' => false, 'cash' => ['changeFor' => 50]], ['value' => 15, 'method' => 'CREDIT', 'prepaid' => false]]]) === [3500, 'DEBIT+CREDIT', null], 'sem dinheiro: sem troco');
confere(PedidoDoIfood::cobranca(['pending' => 40, 'methods' => [['value' => 20, 'method' => 'CASH', 'prepaid' => false], ['value' => 20, 'method' => 'CASH', 'prepaid' => false, 'cash' => ['changeFor' => 50]]]]) === [4000, 'CASH', 5000], 'o mesmo método duas vezes: uma forma só');
confere(PedidoDoIfood::cobranca(['pending' => 9, 'methods' => [['method' => 'DIGITAL_WALLET', 'prepaid' => false], ['method' => 'MEAL_VOUCHER', 'prepaid' => false], ['method' => 'FOOD_VOUCHER', 'prepaid' => false]]])[1] === 'MISTO', 'formas demais para a coluna (30): MISTO');
confere(!logou('pagamento inconsistente'), 'pending > 0 com método na porta: sem aviso');

echo '== Cobrança pelo mapear (com os ids no aviso)' . PHP_EOL;
reiniciarIfood();
$divergente             = pedidoEmDinheiroComTroco();
$divergente['payments'] = ['pending' => 0, 'methods' => [['value' => 58.9, 'method' => 'CASH', 'prepaid' => false, 'type' => 'OFFLINE']]];
$linha                  = PedidoDoIfood::mapear($divergente, LAT_COLETA, LNG_COLETA, $agora)['linha'];
confere($linha['cobrar_centavos'] === 0 && $linha['forma_pagamento'] === null && $linha['troco_para_centavos'] === null, 'pending 0 explícito: nada a cobrar');
confere(logou('pagamento inconsistente', 'warning') && (Illuminate\Support\Facades\Log::$registros[0][2]['numero'] ?? null) === '4821' && logsSem(['Cliente Ficticio', 'Rua Ficticia', '0800 000 0002']), 'aviso com o número do pedido e sem dado do cliente');

echo '== Campos faltando' . PHP_EOL;
$minimo = ['id' => 'abcdef123456', 'isTest' => false, 'delivery' => ['deliveryAddress' => ['coordinates' => ['latitude' => -21.17, 'longitude' => -47.81]]]];
$dados  = PedidoDoIfood::mapear($minimo, LAT_COLETA, LNG_COLETA, $agora);
confere($dados['numero'] === 'abcdef12' && $dados['entrega']['nome'] === 'Cliente iFood' && $dados['entrega']['street1'] === 'Endereço do iFood' && $dados['entrega']['street2'] === null, 'sem displayId, nome e rua: valores padrão');
confere($dados['linha']['telefone_0800'] === null && $dados['linha']['telefone_expira_em'] === null, 'sem telefone');

echo '== Cobrança: pending ausente, método não pago e valor ilegível' . PHP_EOL;
reiniciarIfood();
confere(PedidoDoIfood::cobranca(['methods' => [['value' => 'abc', 'method' => 'CASH', 'prepaid' => false]]], ['numero' => '4821']) === [0, null, null], 'sem pending, método não pago com value ilegível: nada a cobrar');
confere(logou('[entregas] ifood: pagamento inconsistente', 'warning'), '... e avisa "pagamento inconsistente"');
reiniciarIfood();
confere(PedidoDoIfood::cobranca(['methods' => [['method' => 'CASH', 'prepaid' => false, 'type' => 'OFFLINE']]]) === [0, null, null] && logou('pagamento inconsistente', 'warning'), 'sem pending, método não pago sem value: avisa também');
reiniciarIfood();
PedidoDoIfood::cobranca(['methods' => [['value' => 20, 'method' => 'CASH', 'prepaid' => false]]]);
PedidoDoIfood::cobranca(['methods' => [['value' => 20, 'method' => 'PIX', 'prepaid' => true]]]);
PedidoDoIfood::cobranca(['methods' => []]);
confere(!logou('pagamento inconsistente'), 'sem pending: com valor, só pagos ou sem métodos não avisam');

echo '== orderTiming tolerante (caixa e espaços)' . PHP_EOL;
foreach (['scheduled', ' SCHEDULED ', "Scheduled\n"] as $valor) {
    $pedido                = pedidoAgendado();
    $pedido['orderTiming'] = $valor;
    $dados                 = PedidoDoIfood::mapear($pedido, LAT_COLETA, LNG_COLETA, $agora);
    confere($dados['agendado'] === true && $dados['scheduled_at'] === '2026-10-05 16:20:00', 'orderTiming ' . json_encode($valor) . ' = agendado');
}
$pedido                = pedidoAgendado();
$pedido['orderTiming'] = ['SCHEDULED'];
confere(PedidoDoIfood::mapear($pedido, LAT_COLETA, LNG_COLETA, $agora)['agendado'] === false, 'orderTiming em lista: imediato, sem erro');

echo '== Valores implausíveis não derrubam o insert' . PHP_EOL;
reiniciarIfood();
$implausivel = PedidoDoIfood::cobranca(['pending' => 1.0e15, 'methods' => [['value' => 1.0e15, 'method' => 'CASH', 'prepaid' => false]]], ['numero' => '4821']);
confere($implausivel === [0, null, null] && logou('[entregas] ifood: pagamento inconsistente', 'warning'), 'cobrança acima de R$ 100 mil: 0 e aviso');
reiniciarIfood();
confere(PedidoDoIfood::cobranca(['pending' => 100000, 'methods' => [['value' => 100000, 'method' => 'CASH', 'prepaid' => false]]]) === [10000000, 'CASH', null] && !logou('pagamento inconsistente'), 'exatamente R$ 100 mil ainda vale');
confere(PedidoDoIfood::cobranca(['pending' => 100000.01, 'methods' => [['value' => 100000.01, 'method' => 'CASH', 'prepaid' => false]]]) === [0, null, null], 'um centavo acima: 0');
confere(PedidoDoIfood::cobranca(['pending' => '1e999', 'methods' => [['method' => 'CASH', 'prepaid' => false]]]) === [0, null, null], 'pending infinito: 0');
confere(PedidoDoIfood::cobranca(['methods' => [['value' => 1.0e20, 'method' => 'CASH', 'prepaid' => false]]]) === [0, null, null], 'sem pending, soma gigante: 0');
reiniciarIfood();
confere(PedidoDoIfood::cobranca(['pending' => 50, 'methods' => [['value' => 50, 'method' => 'CASH', 'prepaid' => false, 'cash' => ['changeFor' => 1.0e15]]]], ['numero' => '4821']) === [5000, 'CASH', null], 'troco implausível: zera o troco e mantém a cobrança');
confere(PedidoDoIfood::cobranca(['pending' => 50, 'methods' => [['value' => 50, 'method' => 'CASH', 'prepaid' => false, 'cash' => ['changeFor' => 100000]]]]) === [5000, 'CASH', 10000000], 'troco de R$ 100 mil ainda vale');
$longe                                             = pedidoEmDinheiroComTroco();
$longe['customer']['phone']['localizerExpiration'] = '2099-12-31T23:59:59.000Z';
$dados                                             = PedidoDoIfood::mapear($longe, LAT_COLETA, LNG_COLETA, $agora);
confere($dados['linha']['telefone_expira_em'] === null && $dados['linha']['telefone_0800'] === '0800 000 0002', 'localizerExpiration depois de 2037: nula, o resto do telefone fica');
$longe['customer']['phone']['localizerExpiration'] = '1850-01-01T00:00:00.000Z';
confere(PedidoDoIfood::mapear($longe, LAT_COLETA, LNG_COLETA, $agora)['linha']['telefone_expira_em'] === null, 'localizerExpiration antes de 2000: nula');
$longe['customer']['phone']['localizerExpiration'] = '2037-06-01T00:00:00.000Z';
confere(PedidoDoIfood::mapear($longe, LAT_COLETA, LNG_COLETA, $agora)['linha']['telefone_expira_em'] === '2037-05-31 21:00:00', 'até 2037 vale (em hora de Brasília)');
$dados = PedidoDoIfood::mapear(pedidoAgendado('2099-01-01T00:00:00.000Z'), LAT_COLETA, LNG_COLETA, $agora);
confere($dados['sem_janela'] === true && $dados['scheduled_at'] === null && $dados['linha']['despachar_em'] === null, 'janela do agendado em 2099: sem janela (nenhuma data fora da faixa chega ao banco)');

echo '== Docblocks de até 120 colunas' . PHP_EOL;
$longas = [];
foreach (file(dirname(__DIR__, 2) . '/api/app/Support/Entregas/Ifood/PedidoDoIfood.php') as $n => $linhaDoArquivo) {
    if (preg_match('/^\s*(\*|\/\*\*)/', $linhaDoArquivo) && mb_strlen(rtrim($linhaDoArquivo, "\r\n")) > 120) {
        $longas[] = $n + 1;
    }
}
confere($longas === [], 'nenhuma linha de docblock com mais de 120 colunas' . ($longas ? ' (' . implode(', ', $longas) . ')' : ''));

resumo();
