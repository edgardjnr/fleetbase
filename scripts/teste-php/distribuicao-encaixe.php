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

resumo();
