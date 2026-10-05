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
confere(C::km(null) === null && C::km(0) === null, 'sem km: nada');

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

echo PHP_EOL . "FALHAS: {$falhas}" . PHP_EOL;
