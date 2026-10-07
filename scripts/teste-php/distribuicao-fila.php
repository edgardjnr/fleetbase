<?php

// Distribuição de pedidos abertos: matriz de tempos (EstimadorDeTempo) e a fila de candidatos (FilaDeCandidatos).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-fila.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\EstimadorDeTempo;
use App\Support\Entregas\Distribuicao\Pontos;
use Teste\Config;
use Teste\Http;


/** reiniciarIfood zera a config: o host do OSRM volta a cada cenário */
function reiniciar(): void
{
    reiniciarIfood();
    Config::$valores['fleetops.osrm.host'] = 'http://osrm:5000';
}

echo '== EstimadorDeTempo' . PHP_EOL;
$pontos = [[-21.17, -47.81], [-21.18, -47.80], [-21.19, -47.79]];
reiniciar();
Http::responder(200, ['code' => 'Ok', 'durations' => [[0, 100, 200], [100, 0, 150], [200, 150, 0]]]);
$matriz = (new EstimadorDeTempo())->matriz($pontos);
confere($matriz['aproximado'] === false && $matriz['durations'][0][2] === 200.0, 'OSRM: devolve a matriz (' . json_encode($matriz['durations'][0]) . ')');
confere(str_contains(Http::urls()[0], 'http://osrm:5000/table/v1/driving/-47.81,-21.17;-47.8,-21.18;-47.79,-21.19'), 'URL do table com lng,lat (' . Http::urls()[0] . ')');

reiniciar();
Http::falharConexao();
$matriz = (new EstimadorDeTempo())->matriz($pontos);
confere($matriz['aproximado'] === true && $matriz['durations'][0][1] === (float) Pontos::segundos($pontos[0], $pontos[1]), 'OSRM fora: linha reta marcada como aproximada');
confere(logou('OSRM indisponível', 'warning'), 'fica no log');

reiniciar();
Http::responder(200, ['code' => 'NoTable']);
confere((new EstimadorDeTempo())->matriz($pontos)['aproximado'] === true, 'resposta sem durations: aproximada');

reiniciar();
Http::responder(200, ['code' => 'Ok', 'durations' => [[0, null, 200], [100, 0, 150], [200, 150, 0]]]);
$matriz = (new EstimadorDeTempo())->matriz($pontos);
confere($matriz['durations'][0][1] === (float) Pontos::segundos($pontos[0], $pontos[1]) && $matriz['aproximado'] === false, 'null numa célula (sem rota): só aquela célula em linha reta');

reiniciar();
$muitos = array_map(fn ($i) => [-21.17 + $i / 1000, -47.81], range(0, Distribuicao::MAX_PONTOS_DA_MATRIZ));
confere((new EstimadorDeTempo())->matriz($muitos)['aproximado'] === true && Http::urls() === [], 'acima do máximo de pontos: nem chama o OSRM');

resumo();
