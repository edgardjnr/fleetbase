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
$empate = [evento('ev-z', 'CFM', '2026-10-05T18:00:00.000Z'), evento('ev-y', 'DDCR', '2026-10-05T18:00:00Z')];
confere(array_column(EventosIfood::ordenar($empate), 'id') === ['ev-y', 'ev-z'], 'empate: pelo id');
$semData = evento('ev-0', 'CFM', 'não é data');
confere(array_column(EventosIfood::ordenar([$semData, $plc]), 'id') === ['ev-1', 'ev-0'], 'data inválida vai para o fim');
confere(EventosIfood::instante('2026-10-05 18:24:40.624') === EventosIfood::instante('2026-10-05T18:24:40.624Z'), 'o formato gravado no banco (UTC, sem fuso) dá o mesmo instante');

echo '== Ação por código' . PHP_EOL;
confere(EventosIfood::acao('PLC') === EventosIfood::CRIA, 'PLC cria');
confere(EventosIfood::acao('DDCR') === EventosIfood::EXIGE_CODIGO, 'DDCR exige código');
confere(EventosIfood::acao('CAN') === EventosIfood::CANCELA, 'CAN cancela (só registra na etapa 2)');
foreach (['CFM', 'RTP', 'DSP', 'CON', 'CAR', 'CARF', 'ADR', 'GTO', 'AAO', 'DDD', 'CLT', 'AAD', 'DDCS', 'DPCR', 'OPA'] as $codigo) {
    confere(EventosIfood::acao($codigo) === EventosIfood::REGISTRA, "{$codigo} só registra");
}
confere(EventosIfood::acao('HSD') === EventosIfood::IGNORA && EventosIfood::acao('') === EventosIfood::IGNORA, 'desconhecido é ignorado');

echo '== Cria o pedido' . PHP_EOL;
confere(EventosIfood::criaPedido('PLC') && EventosIfood::criaPedido('CFM') && EventosIfood::criaPedido('DDCR'), 'PLC e, se o PLC se perdeu, outro conhecido');
confere(!EventosIfood::criaPedido('CAN') && !EventosIfood::criaPedido('HSD'), 'CAN e desconhecido não criam');
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

resumo();
