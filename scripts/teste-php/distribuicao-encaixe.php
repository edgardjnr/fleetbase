<?php

// Distribuição de pedidos abertos: pontos (Pontos) e o encaixe da coleta e entrega novas na sequência do motoboy (Encaixe).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-encaixe.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Pontos;

echo '== Pontos' . PHP_EOL;
$ponto = new class { public function getLat() { return -21.1775; } public function getLng() { return -47.8103; } };
confere(Pontos::de($ponto) === [-21.1775, -47.8103], 'Point do Fleet-Ops vira [lat, lng]');
confere(Pontos::de(null) === null, 'nulo: null');
confere(Pontos::de(new class { public function getLat() { return 0.0; } public function getLng() { return 0.0; } }) === null, '0,0 não vale');
confere(Pontos::de(new class { public function getLat() { return 95; } public function getLng() { return 10; } }) === null, 'fora da faixa não vale');
$metros = Pontos::metros([-21.1775, -47.8103], [-21.1775, -47.8003]);
confere($metros > 1000 && $metros < 1100, 'linha reta: ~1,04 km por 0,01° de longitude a 21° S (' . round($metros) . ' m)');
confere(Pontos::segundos([-21.1775, -47.8103], [-21.1775, -47.8003]) === (int) round($metros * Distribuicao::FATOR_LINHA_RETA / Distribuicao::METROS_POR_SEGUNDO), 'estimativa: × 1,3 a 25 km/h');

echo '== Encaixe' . PHP_EOL;
use App\Support\Entregas\Distribuicao\Encaixe;

// matriz fixa: índices 0 = motoboy, 1 = coleta A, 2 = entrega A, 3 = coleta nova (P), 4 = entrega nova (D)
$m = [
    0 => [0 => 0, 1 => 300, 2 => 900, 3 => 300, 4 => 1200],
    1 => [0 => 300, 1 => 0, 2 => 600, 3 => 0, 4 => 900],
    2 => [0 => 900, 1 => 600, 2 => 0, 3 => 600, 4 => 300],
    3 => [0 => 300, 1 => 0, 2 => 600, 3 => 0, 4 => 900],
    4 => [0 => 1200, 1 => 900, 2 => 300, 3 => 900, 4 => 0],
];
$dur = fn (int $i, int $j) => (float) $m[$i][$j];
$loja = Distribuicao::PARADA_LOJA_S; $cliente = Distribuicao::PARADA_CLIENTE_S;

$livre = Encaixe::calcular(0, [], 3, 4, $dur);
confere($livre === ['tempo_s' => 300 + $loja + 900, 'encaixe' => false, 'atraso_s' => 0], 'livre: posição → loja → cliente, com a parada na loja (' . json_encode($livre) . ')');

// ocupado ainda na coleta de A, e a loja nova é a mesma (dur 1→3 = 0): pega os dois juntos e entrega A antes (mais perto)
$base = [['indice' => 1, 'tipo' => 'coleta'], ['indice' => 2, 'tipo' => 'entrega']];
$r    = Encaixe::calcular(0, $base, 3, 4, $dur);
// melhor: 0→1 (300) +loja, 1→3 (0) +loja, 3→2 (600) +cliente, 2→4 (300) = 300+180+0+180+600+120+300 = 1680; A chega em 1260 em vez de 300+180+600 = 1080: atraso 180 ≤ 600
confere($r['tempo_s'] === 1680 && $r['encaixe'] === true && $r['atraso_s'] === 180, 'mesma loja: coleta junto, entrega A primeiro, D depois (' . json_encode($r) . ')');

// atraso acima do limite: a entrega nova fica a 2 h de tudo; encaixar D antes de A atrasaria A demais → "termina e vai"
$longe = $m;
foreach ([0, 1, 2, 3] as $i) { $longe[$i][4] = 7200; $longe[4][$i] = 7200; }
$durLonge = fn (int $i, int $j) => (float) $longe[$i][$j];
$r = Encaixe::calcular(0, $base, 3, 4, $durLonge);
confere($r['atraso_s'] <= Distribuicao::ATRASO_MAXIMO_S, 'nunca atrasa uma entrega já aceita mais que o limite');
confere($r['encaixe'] === true && $r['tempo_s'] === 300 + $loja + 0 + $loja + 600 + $cliente + 7200, 'coleta no caminho (mesma loja) e D por último: encaixe só na coleta (' . json_encode($r) . ')');

// ocupado já em entrega (só a entrega A falta) e nada no caminho: termina A e vai
$soEntrega = [['indice' => 2, 'tipo' => 'entrega']];
$r = Encaixe::calcular(0, $soEntrega, 3, 4, $dur);
$terminaEVai = 900 + $cliente + 600 + $loja + 900;          // 0→2, 2→3, 3→4
$pegaAntes   = 300 + $loja + 600 + $cliente + 300;          // 0→3, 3→2, 2→4 = 1500: A atrasa 300+180+600-900 = 180 → cabe e é mais rápido
confere($r['tempo_s'] === min($terminaEVai, $pegaAntes) && $r['encaixe'] === true, 'passa na loja antes de entregar A quando compensa (' . json_encode($r) . ')');

// base vazia e dur nula: zero mais as paradas
confere(Encaixe::calcular(0, [], 3, 4, fn () => 0.0)['tempo_s'] === $loja, 'tudo no mesmo ponto: só a parada na loja');

// atraso exatamente no limite (600 s) cabe; 601 não cabe
$limite = fn (int $rota32) => fn (int $i, int $j) => (float) (($i === 3 && $j === 2) ? $rota32 : $m[$i][$j]);
$r = Encaixe::calcular(0, $soEntrega, 3, 4, $limite(1020));
confere($r['atraso_s'] === Distribuicao::ATRASO_MAXIMO_S && $r['encaixe'] === true && $r['tempo_s'] === 300 + $loja + 1020 + $cliente + 300, 'atraso de exatamente 600 s cabe (' . json_encode($r) . ')');
$r = Encaixe::calcular(0, $soEntrega, 3, 4, $limite(1021));
confere($r['encaixe'] === false && $r['atraso_s'] === 0 && $r['tempo_s'] === 900 + $cliente + 600 + $loja + 900, 'atraso de 601 s não cabe: termina e vai (' . json_encode($r) . ')');

echo '== ligada()' . PHP_EOL;
foreach (['1' => true, 'true' => true, 'on' => true, 'yes' => true, '0' => false, '' => false, 'no' => false, 'nao' => false, 'off' => false] as $v => $esperado) {
    \Teste\Config::$valores['services.entregas.distribuicao'] = (string) $v;
    confere(Distribuicao::ligada() === $esperado, "ligada() com '$v' = " . json_encode($esperado));
}
\Teste\Config::$valores['services.entregas.distribuicao'] = null;
confere(Distribuicao::ligada() === false, 'ligada() com null = false');

echo '== emRodadas(), segundosDaOferta() e raioDaRodada()' . PHP_EOL;
\Teste\Config::$valores['services.entregas.distribuicao']         = '1';
\Teste\Config::$valores['services.entregas.distribuicao_rodadas'] = '1';
confere(Distribuicao::emRodadas() === true && Distribuicao::segundosDaOferta() === 20, 'rodadas ligadas: oferta de 20 s');
\Teste\Config::$valores['services.entregas.distribuicao'] = '';
confere(Distribuicao::emRodadas() === false && Distribuicao::segundosDaOferta() === 30, 'rodadas só valem com a distribuição ligada');
\Teste\Config::$valores['services.entregas.distribuicao']         = '1';
\Teste\Config::$valores['services.entregas.distribuicao_rodadas'] = '';
confere(Distribuicao::emRodadas() === false && Distribuicao::segundosDaOferta() === 30, 'rodadas desligadas: 30 s, como hoje');
confere([Distribuicao::raioDaRodada(6000, 1), Distribuicao::raioDaRodada(6000, 2), Distribuicao::raioDaRodada(6000, 3)] === [6000, 9000, 12000], 'raios das rodadas: R, 1,5R e 2R');
confere(Distribuicao::raioDaRodada(6000, 0) === 6000 && Distribuicao::raioDaRodada(6000, 9) === 12000, 'rodada fora da faixa fica entre 1 e 3');
\Teste\Config::$valores['services.entregas.distribuicao']         = null;
\Teste\Config::$valores['services.entregas.distribuicao_rodadas'] = null;

echo '== Pontos (texto e não finito)' . PHP_EOL;
confere(Pontos::de(new class { public function getLat() { return '-21.17'; } public function getLng() { return '-47.81'; } }) === [-21.17, -47.81], 'texto numérico vale');
confere(Pontos::de(new class { public function getLat() { return 'abc'; } public function getLng() { return '10'; } }) === null, 'texto não numérico: null');
confere(Pontos::de(new class { public function getLat() { return NAN; } public function getLng() { return 10; } }) === null, 'NAN: null');

resumo();
