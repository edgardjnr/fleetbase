<?php

// Entrega junto (segunda tabela de faixas) e multiplicador por período: valor final (ValorDaEntrega), congelamento das
// duas tabelas e da atribuição (CalculoEntregas + ValoresCongelados), prévia do card, reserva por horários, o observador,
// o que o app recebe (GanhosDoMotoboy) e a migration. Os períodos (Multiplicadores, rotas) ficam no
// financeiro-multiplicadores.php.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/entrega-junto.php

require __DIR__ . '/stubs-ganhos.php';

use App\Support\Entregas\ValorDaEntrega;
use Illuminate\Support\Facades\Schema;

echo '== Valor final (ValorDaEntrega)' . PHP_EOL;

// linha congelada como o MySQL devolve: decimal em texto, booleano em 0/1
$base = [
    'de_km' => '2.00', 'ate_km' => '4.00', 'acima' => 0, 'valor_motoboy' => '8.00', 'valor_loja' => '11.37',
    'junto_de_km' => '3.00', 'junto_ate_km' => '6.00', 'junto_acima' => 0, 'junto_motoboy' => '6.00', 'junto_loja' => '9.00',
];
$chuva = ['multiplicador_uuid' => 'mult-chuva', 'multiplicador_nome' => 'Chuva', 'mult_motoboy' => '1.20', 'mult_loja' => '1.10'];

$v = ValorDaEntrega::final($base);
confere($v['motoboy'] === 8.0 && $v['loja'] === 11.37 && $v['tabela'] === 'normal' && $v['posicao'] === null && $v['multiplicador'] === null, 'sem atribuição: tabela normal × 1');
confere($v['faixa'] === ['de_km' => 2.0, 'ate_km' => 4.0, 'motoboy' => 8.0, 'loja' => 11.37], 'a faixa da tabela normal, em números');

$v = ValorDaEntrega::final(['posicao' => 1, 'mult_motoboy' => '1.50', 'mult_loja' => '1.00', 'multiplicador_nome' => 'Chuva'] + $base);
confere($v['motoboy'] === 12.0 && $v['loja'] === 11.37 && $v['tabela'] === 'normal', '1ª entrega com ×1,5 ao motoboy e ×1 à loja: 12,00 / 11,37');
confere($v['multiplicador'] === ['nome' => 'Chuva', 'motoboy' => 1.5, 'loja' => 1.0], 'o multiplicador aplicado, com os dois fatores');

$v = ValorDaEntrega::final(['posicao' => 2] + $chuva + $base);
confere($v['motoboy'] === 7.2 && $v['loja'] === 9.9 && $v['tabela'] === 'junto' && $v['posicao'] === 2, '2ª entrega com chuva: segunda tabela × fatores (6 × 1,2 = 7,20; 9 × 1,1 = 9,90)');
confere($v['faixa'] === ['de_km' => 3.0, 'ate_km' => 6.0, 'motoboy' => 6.0, 'loja' => 9.0], 'a faixa é a da segunda tabela');

$v = ValorDaEntrega::final(['posicao' => 3, 'junto_motoboy' => null, 'junto_loja' => null] + $base);
confere($v['motoboy'] === 8.0 && $v['tabela'] === 'normal' && $v['posicao'] === 3, 'segunda tabela vazia no cálculo: tabela normal (a posição fica guardada)');

$v = ValorDaEntrega::final(['posicao' => 4, 'junto_acima' => 1] + $base);
confere(($v['faixa']['acima'] ?? null) === true && $v['tabela'] === 'junto', 'acima da última faixa da segunda tabela');

confere(ValorDaEntrega::final(['posicao' => 1, 'mult_motoboy' => '1.10', 'mult_loja' => '1.10', 'multiplicador_nome' => 'Chuva'] + $base)['loja'] === 12.51, 'arredonda a 2 casas (11,37 × 1,1 = 12,507 → 12,51)');

echo '== Posição pelos horários' . PHP_EOL;
$outros = [
    ['aceite' => '2026-10-21 18:40:00', 'fim' => null],                  // ainda levando
    ['aceite' => '2026-10-21 18:30:00', 'fim' => '2026-10-21 18:55:00'], // terminou antes
    ['aceite' => '2026-10-21 19:05:00', 'fim' => null],                  // aceito depois
    ['aceite' => '2026-10-21 18:50:00', 'fim' => '2026-10-21 19:10:00'], // levando no instante
    ['aceite' => null, 'fim' => null],                                   // sem horário
];
confere(ValorDaEntrega::posicaoPorHorarios('2026-10-21 19:00:00', $outros) === 3, 'dois pedidos em mãos às 19:00: 3ª entrega');
confere(ValorDaEntrega::posicaoPorHorarios('2026-10-21 19:00:00', []) === 1, 'sem outros: 1ª');

echo '== Fator visível a cada conta' . PHP_EOL;
$m = ['nome' => 'Chuva', 'motoboy' => 1.2, 'loja' => 1.0];
confere(ValorDaEntrega::doMotoboy($m) === ['nome' => 'Chuva', 'fator' => 1.2] && ValorDaEntrega::daLoja($m) === null, 'motoboy vê ×1,2; a loja (×1) não vê selo');
confere(ValorDaEntrega::doMotoboy(null) === null && ValorDaEntrega::daLoja(['nome' => 'Chuva', 'motoboy' => 1.0, 'loja' => 1.1]) === ['nome' => 'Chuva', 'fator' => 1.1], 'sem multiplicador: nada; loja ×1,1: selo da loja');

echo '== Migration das colunas novas' . PHP_EOL;
$migration = require '/repo/api/database/migrations/2026_10_21_100100_add_junto_e_multiplicador_to_entregas_valores_pedido_table.php';
$migration->up();
$colunas = [];
foreach ((Schema::$alteradas['entregas_valores_pedido'][0] ?? null)?->colunas ?? [] as $coluna) {
    $colunas[$coluna->argumentos[0] ?? $coluna->tipo] = $coluna;
}
$esperadas = ['junto_de_km', 'junto_ate_km', 'junto_acima', 'junto_motoboy', 'junto_loja', 'motoboy_uuid', 'atribuido_em', 'posicao', 'multiplicador_uuid', 'multiplicador_nome', 'mult_motoboy', 'mult_loja'];
confere(array_keys($colunas) === $esperadas, 'as doze colunas, na ordem');
confere(!array_filter($colunas, fn ($c) => !array_key_exists('nullable', $c->modificadores)), 'todas nulas: as linhas antigas valem como antes');
confere($colunas['junto_motoboy']->argumentos === ['junto_motoboy', 10, 2] && $colunas['mult_motoboy']->argumentos === ['mult_motoboy', 4, 2] && $colunas['posicao']->tipo === 'unsignedTinyInteger', 'valores em decimal(10,2), fatores em decimal(4,2), posição tinyint');
confere(array_diff(ValorDaEntrega::COLUNAS_DA_ATRIBUICAO, $esperadas) === [], 'toda coluna da atribuição existe na migration');
$migration->down();
$drop = (Schema::$alteradas['entregas_valores_pedido'][1] ?? null)?->colunas[0] ?? null;
confere($drop && $drop->tipo === 'dropColumn' && $drop->argumentos[0] === $esperadas, 'o down apaga as doze');

resumo();
