<?php

// Cartão do alarme de pedido no app (App\Support\Entregas\CartaoDoAlarme): loja, destino, km e valor do motoboy que vão
// nos dados do push de alarme, já como texto em pt-BR. Só as funções puras: a busca da loja e o cálculo do valor usam o
// banco e o CalculoEntregas.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/cartao-do-alarme.php

require __DIR__ . '/../../api/app/Support/Entregas/CartaoDoAlarme.php';

use App\Support\Entregas\CartaoDoAlarme as C;

$falhas = 0;

function confere(bool $ok, string $caso): void
{
    global $falhas;
    if (!$ok) {
        $falhas++;
    }
    echo ($ok ? 'PASSA ' : 'FALHA ') . $caso . PHP_EOL;
}

echo '== km' . PHP_EOL;
confere(C::km(3.21) === '3,2 km', 'uma casa decimal, vírgula');
confere(C::km(0.4) === '400 m', 'menos de 1 km em metros');
confere(C::km(12.0) === '12,0 km', 'inteiro mantém a casa decimal');
confere(C::km(3.21, true) === '≈ 3,2 km', 'estimativa (linha reta) com ≈');
confere(C::km(null) === null && C::km(-1) === null, 'sem km: nada');
confere(C::km(0) === 'menos de 100 m' && C::km(0.05) === 'menos de 100 m', 'coleta e entrega quase no mesmo lugar');

echo '== tempo' . PHP_EOL;
confere(C::tempo(540) === '9 min', 'minutos');
confere(C::tempo(20) === '1 min', 'menos de 1 min vale 1 min');
confere(C::tempo(3900) === '1 h 05' && C::tempo(7200) === '2 h', 'horas');
confere(C::tempo(null) === null && C::tempo(0) === null, 'sem tempo: nada');

echo '== valor' . PHP_EOL;
confere(C::valor(8) === 'R$ 8,00', 'reais com centavos');
confere(C::valor(1234.5) === 'R$ 1.234,50', 'milhar com ponto');
confere(C::valor(null) === null, 'sem valor (sem faixas): nada');
confere(C::valor(0) === 'R$ 0,00', 'zero é valor');

echo '== destino' . PHP_EOL;
confere(C::destino('Centro', 'Ribeirão Preto', 'Rua A, 10') === 'Centro, Ribeirão Preto', 'bairro e cidade');
confere(C::destino(null, 'Ribeirão Preto', 'Rua A, 10') === 'Rua A, 10, Ribeirão Preto', 'sem bairro: rua e cidade');
confere(C::destino(' ', null, null) === null, 'sem nada: nulo');

echo '== dados' . PHP_EOL;
confere(C::dados('Terraço Pizza', 'Centro, Ribeirão Preto', 3.21, 8.0) === [
    'entregas_loja'    => 'Terraço Pizza',
    'entregas_destino' => 'Centro, Ribeirão Preto',
    'entregas_km'      => '3,2 km',
    'entregas_valor'   => 'R$ 8,00',
], 'as quatro chaves em texto');
confere(C::dados(null, null, null, null) === [], 'sem nada: nenhuma chave (o app mostra só título e texto)');
confere(array_keys(C::dados('Loja', null, 2.0, null)) === ['entregas_loja', 'entregas_km'], 'só o que existe');

echo '== mapa' . PHP_EOL;
// exemplo da documentação do Google: (38.5, -120.2), (40.7, -120.95), (43.252, -126.453)
confere(C::polyline([[38.5, -120.2], [40.7, -120.95], [43.252, -126.453]]) === '_p~iF~ps|U_ulLnnqC_mqNvxq`@', 'polyline no formato do Google (precisão 5)');
confere(C::polyline([]) === '', 'sem pontos: vazio');
$longa = [];
for ($i = 0; $i < 500; $i++) {
    $longa[] = [-21.0 - $i / 10000, -47.0 + $i / 20000];
}
$reduzida = C::reduzir($longa, 80);
confere(count($reduzida) <= 80 && $reduzida[0] === $longa[0] && end($reduzida) === end($longa), 'rota longa reduzida a 80 pontos, com o início e o fim (' . count($reduzida) . ')');
confere(C::reduzir([[1, 2], [3, 4]], 80) === [[1, 2], [3, 4]], 'rota curta fica como está');
confere(strlen(C::polyline($reduzida)) < 1000, 'a rota reduzida cabe no push (' . strlen(C::polyline($reduzida)) . ' caracteres)');
confere(C::coordenada(-21.1775, -47.8103) === '-21.177500,-47.810300', 'coordenada "lat,lng" com 6 casas');
confere(C::coordenada(null, -47.8) === null && C::coordenada(0.0, 0.0) === null, 'sem coordenada (ou 0,0): nada');
confere(C::mapa('-21.1,-47.8', '-21.2,-47.9', '_p~iF') === ['entregas_coleta' => '-21.1,-47.8', 'entregas_entrega' => '-21.2,-47.9', 'entregas_rota' => '_p~iF'], 'chaves do mapa');
confere(C::mapa('-21.1,-47.8', null, '') === ['entregas_coleta' => '-21.1,-47.8'], 'só o que existe');
confere(C::mapa('-21.1,-47.8', '-21.2,-47.9', '_p~iF', 'dispatched', true) === ['entregas_coleta' => '-21.1,-47.8', 'entregas_entrega' => '-21.2,-47.9', 'entregas_rota' => '_p~iF', 'entregas_status' => 'dispatched', 'entregas_rota_aproximada' => '1'], 'status e linha aproximada');
confere(!isset(C::mapa('-21.1,-47.8', '-21.2,-47.9', '', 'created', true)['entregas_rota_aproximada']), 'sem linha, sem o aproximada');

echo PHP_EOL . "FALHAS: {$falhas}" . PHP_EOL;
