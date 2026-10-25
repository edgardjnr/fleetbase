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
