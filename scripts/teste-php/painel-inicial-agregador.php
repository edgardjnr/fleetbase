<?php

// Contas puras do painel inicial do console (App\Support\Entregas\Painel\AgregadorDoPainel).
// Uso: PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/painel-inicial-agregador.php

require __DIR__ . '/stubs-financeiro.php';

use App\Support\Entregas\Painel\AgregadorDoPainel as A;

// período anterior: um dia → mesmo dia da semana passada; N dias → os N dias antes
confere(A::periodoAnterior('2026-10-24', '2026-10-24') === ['2026-10-17', '2026-10-17'], 'hoje compara com o mesmo dia da semana passada');
confere(A::periodoAnterior('2026-10-18', '2026-10-24') === ['2026-10-11', '2026-10-17'], '7 dias comparam com os 7 anteriores');
confere(A::periodoAnterior('2026-09-25', '2026-10-24') === ['2026-08-26', '2026-09-24'], '30 dias comparam com os 30 anteriores');

// variação
confere(A::variacao(142, 127) === 12, 'variação arredondada');
confere(A::variacao(3, 0) === null, 'sem base, sem variação');
confere(A::variacao(0, 4) === -100, 'queda total');

// por hora
$horas = A::porHora(['2026-10-24 19:10:00', '2026-10-24 19:59:59', '2026-10-24 00:05:00']);
confere(count($horas) === 24 && $horas[19] === 2 && $horas[0] === 1 && $horas[20] === 0, 'conta por hora do dia');

// por dia (com os dias vazios)
$dias = A::porDia(['2026-10-22 10:00:00', '2026-10-24 21:00:00', '2026-10-24 22:00:00'], '2026-10-22', '2026-10-24');
confere($dias === [['dia' => '2026-10-22', 'total' => 1], ['dia' => '2026-10-23', 'total' => 0], ['dia' => '2026-10-24', 'total' => 2]], 'conta por dia, com dia vazio');

// média por hora de N semanas
$media = A::mediaPorHora(['2026-10-17 19:00:00', '2026-10-10 19:30:00', '2026-10-03 20:00:00'], 4);
confere($media[19] === 0.5 && $media[20] === 0.3 && $media[10] === 0.0, 'média por hora com uma casa');

// ranking por loja (nome da linha; sem uuid fica fora; empate pelo nome)
$ranking = A::ranking([
    (object) ['loja_uuid' => 'v-b', 'loja_nome' => 'Spunto'],
    (object) ['loja_uuid' => 'v-a', 'loja_nome' => 'Terraço'],
    (object) ['loja_uuid' => 'v-a', 'loja_nome' => 'Terraço'],
    (object) ['loja_uuid' => null, 'loja_nome' => 'Sem loja'],
    (object) ['loja_uuid' => 'v-c', 'loja_nome' => 'Brasa'],
], 'loja_uuid', 'loja_nome');
confere(array_column($ranking, 'uuid') === ['v-a', 'v-c', 'v-b'] && $ranking[0]['corridas'] === 2, 'ranking: mais corridas, depois o nome');

// prazos: só os pedidos com prazo entram no %; tempo médio do aceite à conclusão
$prazos = A::prazos([
    ['concluido_em' => '2026-10-24 19:10:00', 'aceito_em' => '2026-10-24 18:40:00', 'prazo_em' => '2026-10-24T19:30:00-03:00'],
    ['concluido_em' => '2026-10-24 19:40:00', 'aceito_em' => '2026-10-24 19:00:00', 'prazo_em' => '2026-10-24T19:30:00-03:00'],
    ['concluido_em' => '2026-10-24 20:05:00', 'aceito_em' => '2026-10-24 19:35:00', 'prazo_em' => null],
    ['concluido_em' => '2026-10-24 20:30:00', 'aceito_em' => null, 'prazo_em' => 'lixo'],
], 'America/Sao_Paulo');
confere($prazos === ['no_prazo' => 50, 'tempo_medio_min' => 33], 'no prazo 50% e tempo médio 33 min');
confere(A::prazos([], 'America/Sao_Paulo') === ['no_prazo' => null, 'tempo_medio_min' => null], 'sem pedidos: nulos');

resumo();
