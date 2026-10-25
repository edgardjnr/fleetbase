<?php

// Ao vivo do painel inicial (App\Support\Entregas\Painel\AoVivo): oferta da distribuição e origem iFood.
// Uso: PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/painel-inicial-ao-vivo.php

require __DIR__ . '/stubs-financeiro.php';

use App\Support\Entregas\Painel\AoVivo;
use Teste\Banco;
use Teste\Relogio;

function mapaFalso(): array
{
    return [
        'pedidos' => [
            ['id' => 'order_1', 'numero' => '4821', 'motoboy_id' => 'driver_a', 'status' => 'enroute'],
            ['id' => 'order_2', 'numero' => '4836', 'motoboy_id' => null, 'status' => 'dispatched'],
            ['id' => 'order_3', 'numero' => 'RP12', 'motoboy_id' => null, 'status' => 'created'],
        ],
        'motoboys' => [['id' => 'driver_a', 'nome' => 'Carlos', 'latitude' => -21.1, 'longitude' => -47.8, 'situacao' => 'entrega', 'online' => true]],
    ];
}

function cenarioAoVivo(string $fase = 'ofertas'): void
{
    reiniciarFinanceiro();
    Relogio::$agora = '2026-10-24 21:14:00';
    // DB::table('orders') lê a tabela em memória (o Order::$todos só entra nos joins)
    Banco::inserir('orders', ['uuid' => 'uuid-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1'], false);
    Banco::inserir('orders', ['uuid' => 'uuid-2', 'public_id' => 'order_2', 'company_uuid' => 'empresa-1'], false);
    Banco::inserir('orders', ['uuid' => 'uuid-3', 'public_id' => 'order_3', 'company_uuid' => 'empresa-1'], false);
    Banco::inserir('orders', ['uuid' => 'uuid-9', 'public_id' => 'order_1', 'company_uuid' => 'empresa-2'], false); // mesmo id, outra central
    Banco::inserir('entregas_ifood_pedidos', ['company_uuid' => 'empresa-1', 'order_uuid' => 'uuid-1', 'pedido_ifood_id' => 'if-1', 'numero' => '4821', 'merchant_id' => 'm-1'], false);
    Banco::inserir('entregas_distribuicoes', ['id' => 1, 'pedido_uuid' => 'uuid-2', 'company_uuid' => 'empresa-1', 'fase' => $fase, 'rodada' => 2, 'volta' => 1], false);
    Banco::inserir('entregas_ofertas', ['distribuicao_id' => 1, 'pedido_uuid' => 'uuid-2', 'motoboy_uuid' => 'd-x', 'resposta' => 'recusada', 'vence_em' => '2026-10-24 21:13:00', 'rodada' => 1], false);
    Banco::inserir('entregas_ofertas', ['distribuicao_id' => 1, 'pedido_uuid' => 'uuid-2', 'motoboy_uuid' => 'd-y', 'resposta' => 'pendente', 'vence_em' => '2026-10-24 21:14:40', 'rodada' => 2], false);
    Banco::inserir('drivers', ['uuid' => 'd-a', 'public_id' => 'driver_a'], false);
    $corrida = fn ($pedido, $numero, $motoboy, $quando, $empresa = 'empresa-1') => Banco::inserir('entregas_corridas_finalizadas', ['order_uuid' => "u-{$pedido}", 'company_uuid' => $empresa, 'pedido' => $pedido, 'numero' => $numero, 'loja_nome' => 'Terraço', 'motoboy_uuid' => $motoboy, 'motoboy_nome' => 'Carlos', 'finalizada_em' => $quando, 'origem' => 'conclusao'], false);
    $corrida('order_5', '4818', 'd-a', '2026-10-24 20:40:00');
    $corrida('order_6', '4819', 'd-a', '2026-10-24 21:02:00');
    $corrida('order_7', null, 'd-b', '2026-10-24 12:10:00');
    $corrida('order_8', '4700', 'd-a', '2026-10-23 22:00:00');                 // ontem
    $corrida('order_9', '4701', 'd-a', '2026-10-24 19:00:00', 'empresa-2');    // outra central
}

cenarioAoVivo();
$r = AoVivo::montar('empresa-1', mapaFalso());
[$p1, $p2, $p3] = $r['pedidos'];

confere($p1['ifood'] === true && $p2['ifood'] === false, 'origem iFood pela tabela entregas_ifood_pedidos');
confere($p1['oferta'] === null, 'pedido com motoboy: sem oferta');
confere($p2['oferta']['fase'] === 'ofertas' && $p2['oferta']['rodada'] === 2, 'oferta em andamento com a rodada');
confere($p2['oferta']['vence_em'] === '2026-10-24T21:14:40-03:00', 'vencimento da oferta pendente em ISO de Brasília');
confere($p3['oferta'] === null, 'sem distribuição (aguardando a central): oferta nula');
confere($r['motoboys'][0]['nome'] === 'Carlos', 'motoboys do mapa passam como vieram');
confere($r['motoboys'][0]['corridas_hoje'] === 2, 'corridas de hoje do motoboy (só as de hoje e da central)');
confere($r['concluidos']['total'] === 3, 'concluídos hoje: total da central');
confere($r['concluidos']['ultimos'][0]['numero'] === '4819' && $r['concluidos']['ultimos'][0]['finalizada_em'] === '2026-10-24T21:02:00-03:00', 'o concluído mais recente primeiro, com a hora em ISO');
confere($r['concluidos']['ultimos'][2]['numero'] === 'order_7', 'sem número: o public_id');

// distribuição encerrada não conta
cenarioAoVivo('encerrada');
confere(AoVivo::montar('empresa-1', mapaFalso())['pedidos'][1]['oferta'] === null, 'distribuição encerrada: sem oferta');

// cache de 5 s e o esquecer
cenarioAoVivo();
AoVivo::montar('empresa-1', mapaFalso());
$outro = ['pedidos' => [], 'motoboys' => []];
confere(count(AoVivo::montar('empresa-1', $outro)['pedidos']) === 3, 'ao vivo em cache');
AoVivo::esquecer('empresa-1');
confere(count(AoVivo::montar('empresa-1', $outro)['pedidos']) === 0, 'esquecer limpa o cache da central');

resumo();
