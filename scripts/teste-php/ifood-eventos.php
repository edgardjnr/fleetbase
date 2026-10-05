<?php

// Integração iFood: funções puras dos eventos (EventosIfood).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-eventos.php

require __DIR__ . '/stubs-ifood.php';

use App\Support\Entregas\Ifood\EventosIfood;

function evento(string $id, string $codigo, string $criado, string $pedido = 'pedido-1'): array
{
    return ['id' => $id, 'code' => $codigo, 'fullCode' => $codigo, 'orderId' => $pedido, 'merchantId' => 'merchant-1', 'createdAt' => $criado, 'salesChannel' => 'IFOOD'];
}

echo '== Deduplicar' . PHP_EOL;
$plc  = evento('ev-1', 'PLC', '2026-10-05T18:24:40.624Z');
$cfm  = evento('ev-2', 'CFM', '2026-10-05T18:26:27.803Z');
$ddcr = evento('ev-3', 'DDCR', '2026-10-05T18:26:28.234Z');
confere(EventosIfood::deduplicar([$plc, $cfm, $plc, ['code' => 'PLC'], 'lixo', $cfm]) === [$plc, $cfm], 'um por id, na ordem em que chegaram; sem id fica de fora');

echo '== Ordenar por createdAt (milissegundos)' . PHP_EOL;
confere(array_column(EventosIfood::ordenar([$ddcr, $plc, $cfm]), 'id') === ['ev-1', 'ev-2', 'ev-3'], 'fora de ordem volta à ordem');
$a = evento('ev-a', 'CFM', '2021-02-17T19:36:55.295Z');
$b = evento('ev-b', 'RTP', '2021-02-17T19:36:55.2Z');
$c = evento('ev-c', 'DSP', '2021-02-17T19:36:55Z');
confere(array_column(EventosIfood::ordenar([$a, $b, $c]), 'id') === ['ev-c', 'ev-b', 'ev-a'], '"55Z" < "55.2Z" (200 ms) < "55.295Z"');
$empate = [evento('ev-z', 'DDCR', '2026-10-05T18:00:00.000Z'), evento('ev-y', 'CFM', '2026-10-05T18:00:00Z')];
confere(array_column(EventosIfood::ordenar($empate), 'id') === ['ev-y', 'ev-z'], 'empate: CFM antes do DDCR (ordem natural)');
$empate = [evento('ev-a', 'CFM', '2026-10-05T18:00:00Z'), evento('ev-b', 'PLC', '2026-10-05T18:00:00Z')];
confere(array_column(EventosIfood::ordenar($empate), 'id') === ['ev-b', 'ev-a'], 'empate: PLC antes do CFM, mesmo com id maior');
$empate = [evento('ev-a', 'CAN', '2026-10-05T18:00:00Z'), evento('ev-b', 'CON', '2026-10-05T18:00:00Z'), evento('ev-c', 'PLC', '2026-10-05T18:00:00Z')];
confere(array_column(EventosIfood::ordenar($empate), 'id') === ['ev-c', 'ev-b', 'ev-a'], 'empate: o CAN fica por último');
$empate = [evento('ev-z', 'CFM', '2026-10-05T18:00:00Z'), evento('ev-y', 'CFM', '2026-10-05T18:00:00Z')];
confere(array_column(EventosIfood::ordenar($empate), 'id') === ['ev-y', 'ev-z'], 'empate no mesmo código: pelo id');
$empate = [evento('ev-a', 'HSD', '2026-10-05T18:00:00Z'), evento('ev-b', 'CAN', '2026-10-05T18:00:00Z')];
confere(array_column(EventosIfood::ordenar($empate), 'id') === ['ev-b', 'ev-a'], 'empate: código desconhecido depois dos conhecidos');
$semData = evento('ev-0', 'CFM', 'não é data');
confere(array_column(EventosIfood::ordenar([$semData, $plc]), 'id') === ['ev-1', 'ev-0'], 'data inválida vai para o fim');
$numero = ['createdAt' => 1759688680624] + evento('ev-n', 'CFM', '');
$lista  = ['createdAt' => ['2026-10-05T18:00:00Z']] + evento('ev-l', 'CFM', '');
$ordem  = null;
try {
    $ordem = array_column(EventosIfood::ordenar([$numero, $lista, $plc]), 'id');
} catch (\TypeError $e) {
    $ordem = 'TypeError';
}
confere($ordem === ['ev-1', 'ev-l', 'ev-n'], 'createdAt que não é texto (número, lista): sem TypeError, vai para o fim');
confere(EventosIfood::instante(1759688680624) === INF && EventosIfood::instante(null) === INF && EventosIfood::instante(['x']) === INF, 'instante() de não texto = INF');
confere(EventosIfood::instante('2026-10-05 18:24:40.624') === EventosIfood::instante('2026-10-05T18:24:40.624Z'), 'o formato gravado no banco (UTC, sem fuso) dá o mesmo instante');

echo '== Ação por código' . PHP_EOL;
confere(EventosIfood::acao('PLC') === EventosIfood::CRIA, 'PLC cria');
confere(EventosIfood::acao('DDCR') === EventosIfood::EXIGE_CODIGO, 'DDCR exige código');
confere(EventosIfood::acao('CAN') === EventosIfood::CANCELA, 'CAN cancela (só registra na etapa 2)');
foreach (['CFM', 'RTP', 'DSP', 'CON', 'CAR', 'CARF', 'ADR', 'GTO', 'AAO', 'DDD', 'CLT', 'AAD', 'DDCS', 'DPCR', 'OPA'] as $codigo) {
    confere(EventosIfood::acao($codigo) === EventosIfood::REGISTRA, "{$codigo} só registra");
}
confere(EventosIfood::acao('HSD') === EventosIfood::IGNORA && EventosIfood::acao('') === EventosIfood::IGNORA, 'desconhecido é ignorado');
confere(EventosIfood::nivelDoIgnorado('HSD') === 'warning', 'HSD ignorado: warning (exige resposta da loja no iFood)');
confere(EventosIfood::nivelDoIgnorado('XYZ') === 'info' && EventosIfood::nivelDoIgnorado('HSS') === 'info', 'outro desconhecido: info');

echo '== Cria o pedido' . PHP_EOL;
foreach (['PLC', 'CFM', 'RTP', 'DDCR', 'DPCR'] as $codigo) {
    confere(EventosIfood::criaPedido($codigo), "{$codigo} cria (anterior à coleta; PLC ou, se ele se perdeu, outro anterior)");
}
foreach (['CAN', 'CAR', 'CARF', 'CON', 'DSP', 'CLT', 'AAD', 'DDCS', 'DDD', 'ADR', 'GTO', 'AAO', 'OPA', 'HSD', ''] as $codigo) {
    confere(!EventosIfood::criaPedido($codigo), "{$codigo} não cria");
}
confere(EventosIfood::temCancelamento([$plc, evento('ev-9', 'CAN', '2026-10-05T18:30:00Z')]) && !EventosIfood::temCancelamento([$plc, $cfm]), 'acha o CAN entre os eventos');

echo '== Linha para gravar' . PHP_EOL;
$comMetadata = evento('ev-4', 'CAN', '2026-10-05T18:26:35.864Z') + ['metadata' => ['CANCEL_ORIGIN' => 'RESTAURANT', 'CANCEL_CODE' => '523']];
$linha       = EventosIfood::paraGravar($comMetadata, '2026-10-05 18:30:00');
confere($linha['evento_id'] === 'ev-4' && $linha['merchant_id'] === 'merchant-1' && $linha['pedido_ifood_id'] === 'pedido-1' && $linha['codigo'] === 'CAN', 'ids e código');
confere($linha['criado_no_ifood'] === '2026-10-05 18:26:35.864', 'createdAt em UTC, com milissegundos');
confere(json_decode($linha['payload'], true) === $comMetadata, 'payload = o evento inteiro');
confere($linha['processado_em'] === null && $linha['ignorado'] === false && $linha['created_at'] === '2026-10-05 18:30:00', 'pendente, com as datas');
confere(EventosIfood::paraGravar(['id' => 'ev-5', 'code' => 'PLC'], '2026-10-05 18:30:00') === null, 'sem orderId/merchantId: não grava');
confere(EventosIfood::paraGravar(evento('ev-6', 'PLC', 'sem data'), '2026-10-05 18:30:00')['criado_no_ifood'] === null, 'data inválida grava nula');
$gravada = null;
try {
    $gravada = EventosIfood::paraGravar(['createdAt' => 1759688680624] + evento('ev-7', 'PLC', ''), '2026-10-05 18:30:00');
} catch (\TypeError $e) {
}
confere($gravada !== null && $gravada['criado_no_ifood'] === null, 'createdAt número: grava com data nula, sem TypeError');

echo '== instante(): só ISO 8601 com data e hora, ano de 2000 a 2037' . PHP_EOL;
confere(EventosIfood::instante('2026-10-05T18:24:40.624-03:00') === EventosIfood::instante('2026-10-05T21:24:40.624Z'), 'com fuso: vale o instante em UTC');
confere(EventosIfood::instante('2000-01-01T00:00:00Z') < INF && EventosIfood::instante('2037-12-31T23:59:59Z') < INF, 'ano 2000 e 2037 valem');
foreach (['1999-12-31T23:59:59Z', '2038-01-01T00:00:00Z', '9999-12-31T23:59:59Z', '0000-01-01T00:00:00Z'] as $texto) {
    confere(EventosIfood::instante($texto) === INF, "{$texto}: fora de 2000-2037, INF");
}
foreach (['tomorrow', 'now', '+1 day', 'next monday', '2026-10-05', '18:24:40', '@1759688680', '1759688680', 'sábado, 5 de outubro de 2026', '2026-10-05T', '  '] as $texto) {
    confere(EventosIfood::instante($texto) === INF, '"' . $texto . '": não é ISO com data e hora, INF');
}
confere(array_column(EventosIfood::ordenar([evento('ev-t', 'CFM', 'tomorrow'), evento('ev-d', 'CFM', '2038-06-01T00:00:00Z'), $plc]), 'id') === ['ev-1', 'ev-d', 'ev-t'], 'texto livre e ano fora da faixa vão para o fim (empate pelo id)');
confere(EventosIfood::paraGravar(evento('ev-8', 'PLC', '2099-01-01T00:00:00Z'), '2026-10-05 18:30:00')['criado_no_ifood'] === null && EventosIfood::paraGravar(evento('ev-8', 'PLC', 'tomorrow'), '2026-10-05 18:30:00')['criado_no_ifood'] === null, 'ano fora da faixa e texto livre: grava a data nula');

resumo();
