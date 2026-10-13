<?php

// Financeiro: quitação por antiguidade (App\Support\Entregas\Financeiro\Quitacao), funções puras, sem banco. Créditos do
// motoboy (ou da loja sem fatura): pago, pago em parte, a receber ou anulado; faturas emitidas da loja: paga, parcial,
// aberta e vencida. Ver docs/superpowers/specs/2026-10-12-financeiro-design.md, "Quitação por antiguidade".
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/financeiro-quitacao.php

require __DIR__ . '/stubs-ifood.php';

use App\Support\Entregas\Financeiro\Quitacao;

/** Um lançamento como o banco devolve (decimal em texto). */
function l(int $id, string $tipo, float $valor, string $data, ?string $pedido = null, ?int $estornoDe = null): object
{
    return (object) ['id' => $id, 'tipo' => $tipo, 'valor' => number_format($valor, 2, '.', ''), 'data_referencia' => $data, 'order_uuid' => $pedido, 'estorno_de' => $estornoDe];
}

function situacao(array $resultado, int $id): ?string
{
    return $resultado[$id]['situacao'] ?? null;
}

echo '== Créditos do motoboy' . PHP_EOL;

$r = Quitacao::creditos([l(1, 'corrida', 8, '2026-10-01', 'o1'), l(2, 'corrida', 10, '2026-10-02', 'o2'), l(3, 'pagamento', -18, '2026-10-03')]);
confere(situacao($r, 1) === 'pago' && situacao($r, 2) === 'pago', 'pagamento do total: tudo pago');
confere($r[1]['pago'] === 8.0 && $r[1]['restante'] === 0.0 && $r[2]['pago'] === 10.0, 'pago e restante em reais');
confere(!isset($r[3]), 'só os créditos aparecem (o pagamento não)');

$r = Quitacao::creditos([l(1, 'corrida', 10, '2026-10-01', 'o1'), l(2, 'corrida', 10, '2026-10-02', 'o2'), l(3, 'corrida', 10, '2026-10-03', 'o3'), l(4, 'pagamento', -15, '2026-10-03')]);
confere(situacao($r, 1) === 'pago' && situacao($r, 2) === 'parcial' && situacao($r, 3) === 'aberto', 'pagamento de 1,5 corrida: pago, parcial e aberto');
confere($r[2]['pago'] === 5.0 && $r[2]['restante'] === 5.0 && $r[3]['pago'] === 0.0 && $r[3]['restante'] === 10.0, 'a parcial tem 5 pagos e 5 a receber');

$r = Quitacao::creditos([l(1, 'corrida', 10, '2026-10-01', 'o1'), l(2, 'pagamento', -30, '2026-10-01'), l(3, 'diaria', 20, '2026-10-02')]);
confere(situacao($r, 1) === 'pago' && situacao($r, 3) === 'pago', 'adiantamento: o pagamento maior quita também a diária lançada depois');
$r = Quitacao::creditos([l(1, 'corrida', 10, '2026-10-01', 'o1'), l(2, 'pagamento', -30, '2026-10-01')]);
confere(situacao($r, 1) === 'pago' && count($r) === 1, 'caixa maior que os créditos: tudo pago (o resto fica de adiantamento)');

$r = Quitacao::creditos([l(1, 'corrida', 10, '2026-10-01', 'o1'), l(2, 'estorno', -10, '2026-10-02', 'o1', 1), l(3, 'corrida', 10, '2026-10-02', 'o3')]);
confere(situacao($r, 1) === 'anulado' && $r[1]['restante'] === 0.0 && $r[1]['pago'] === 0.0, 'corrida estornada: anulada');
confere(situacao($r, 3) === 'aberto' && !isset($r[2]), 'o estorno não vai ao caixa e não é crédito');

$r = Quitacao::creditos([l(1, 'corrida', 10, '2026-10-01', 'oA'), l(2, 'corrida', 8, '2026-10-02', 'oB'), l(3, 'ajuste', -2, '2026-10-03', 'oB'), l(4, 'pagamento', -10, '2026-10-03')]);
confere(situacao($r, 1) === 'pago' && situacao($r, 2) === 'aberto' && $r[2]['restante'] === 6.0, 'ajuste −2 com pedido abate a corrida do pedido, não a mais antiga');
$r = Quitacao::creditos([l(1, 'corrida', 10, '2026-10-01', 'oA'), l(2, 'corrida', 8, '2026-10-02', 'oB'), l(3, 'ajuste', -8, '2026-10-03', 'oB')]);
confere(situacao($r, 2) === 'anulado' && situacao($r, 1) === 'aberto', '"Não pagar" (ajuste do valor inteiro): a corrida fica anulada');
$r = Quitacao::creditos([l(1, 'corrida', 10, '2026-10-01', 'oA'), l(2, 'corrida', 8, '2026-10-02', 'oB'), l(3, 'ajuste', -12, '2026-10-03', 'oB')]);
confere(situacao($r, 2) === 'anulado' && situacao($r, 1) === 'parcial' && $r[1]['pago'] === 4.0, 'o que passa da corrida do pedido vai ao caixa');
$r = Quitacao::creditos([l(1, 'corrida', 8, '2026-10-02', 'oB'), l(2, 'ajuste', -8, '2026-10-03', 'oB'), l(3, 'estorno', 8, '2026-10-04', 'oB', 2)]);
confere(situacao($r, 1) === 'aberto' && $r[1]['restante'] === 8.0, 'estorno do ajuste: a corrida volta a receber');

$r = Quitacao::creditos([l(1, 'corrida', 10, '2026-10-01', 'o1'), l(2, 'ajuste', -4, '2026-10-02')]);
confere(situacao($r, 1) === 'parcial' && $r[1]['pago'] === 4.0 && $r[1]['restante'] === 6.0, 'ajuste sem pedido vai ao caixa');
$r = Quitacao::creditos([l(1, 'corrida', 10, '2026-10-02', 'o1'), l(2, 'saldo_inicial', -3, '2026-10-01')]);
confere(situacao($r, 1) === 'parcial' && $r[1]['pago'] === 3.0, 'saldo inicial negativo vai ao caixa');

$r = Quitacao::creditos([l(1, 'corrida', 10, '2026-10-01', 'o1'), l(2, 'pagamento', -10, '2026-10-02'), l(3, 'estorno', 10, '2026-10-03', null, 2)]);
confere(situacao($r, 1) === 'aberto' && !isset($r[3]), 'estorno do pagamento devolve o caixa (e não é crédito)');
$r = Quitacao::creditos([l(1, 'corrida', 10, '2026-10-01', 'o1'), l(3, 'estorno', 10, '2026-10-03', null, 99)]);
confere(situacao($r, 1) === 'aberto', 'caixa nunca fica negativo');

$r = Quitacao::creditos([l(5, 'corrida', 10, '2026-10-01', 'o5'), l(2, 'corrida', 10, '2026-10-02', 'o2'), l(6, 'pagamento', -15, '2026-10-03'), l(3, 'corrida', 10, '2026-10-01', 'o3')]);
confere(situacao($r, 3) === 'pago' && situacao($r, 5) === 'parcial' && situacao($r, 2) === 'aberto', 'ordem por data e depois id, em qualquer ordem de entrada');

$r = Quitacao::creditos([l(1, 'corrida', 0.1, '2026-10-01', 'o1'), l(2, 'corrida', 0.2, '2026-10-01', 'o2'), l(3, 'pagamento', -0.3, '2026-10-02')]);
confere(situacao($r, 1) === 'pago' && situacao($r, 2) === 'pago' && $r[2]['restante'] === 0.0, 'arredondamento: 0,1 + 0,2 pagos com 0,3');

$r = Quitacao::creditos([l(1, 'bonus', 5, '2026-10-01'), l(2, 'saldo_inicial', 20, '2026-09-30'), l(3, 'ajuste', 3, '2026-10-02'), l(4, 'pagamento', -20, '2026-10-02')]);
confere(situacao($r, 2) === 'pago' && situacao($r, 1) === 'aberto' && situacao($r, 3) === 'aberto', 'bônus, saldo inicial e ajuste positivo são créditos');
$r = Quitacao::creditos([['id' => 1, 'tipo' => 'corrida', 'valor' => 8, 'data_referencia' => '2026-10-01', 'order_uuid' => 'o1', 'estorno_de' => null], ['id' => 2, 'tipo' => 'pagamento', 'valor' => '-8.00', 'data_referencia' => '2026-10-01', 'order_uuid' => null, 'estorno_de' => null]]);
confere(situacao($r, 1) === 'pago', 'aceita arrays e números');
confere(Quitacao::creditos([]) === [], 'conta vazia: nada');

echo '== Faturas da loja' . PHP_EOL;

$faturas = [
    ['uuid' => 'f2', 'total' => '50.00', 'vencimento' => '2026-10-08', 'numero' => 2],
    ['uuid' => 'f1', 'total' => '100.00', 'vencimento' => '2026-10-01', 'numero' => 1],
];
$s = Quitacao::faturas($faturas, 120.0, '2026-10-12');
confere($s['f1'] === ['pagamento' => 'paga', 'recebido' => 100.0, 'em_aberto' => 0.0, 'vencida' => false], 'a de vencimento mais antigo é paga primeiro');
confere($s['f2'] === ['pagamento' => 'parcial', 'recebido' => 20.0, 'em_aberto' => 30.0, 'vencida' => true], 'a seguinte fica parcial e vencida');
$s = Quitacao::faturas($faturas, 150.0, '2026-10-12');
confere($s['f1']['pagamento'] === 'paga' && $s['f2']['pagamento'] === 'paga' && !$s['f2']['vencida'], 'tudo recebido: pagas, nenhuma vencida');
$s = Quitacao::faturas([['uuid' => 'a', 'total' => 10, 'vencimento' => '2026-10-12'], ['uuid' => 'b', 'total' => 10, 'vencimento' => '2026-10-11']], 0.0, '2026-10-12');
confere($s['a'] === ['pagamento' => 'aberta', 'recebido' => 0.0, 'em_aberto' => 10.0, 'vencida' => false], 'vence hoje: aberta, ainda não vencida');
confere($s['b']['vencida'] === true && $s['b']['pagamento'] === 'aberta', 'venceu ontem: vencida');
$s = Quitacao::faturas([(object) ['uuid' => 'x2', 'total' => 10, 'vencimento' => '2026-10-10', 'numero' => 8], (object) ['uuid' => 'x1', 'total' => 10, 'vencimento' => '2026-10-10', 'numero' => 7]], 10.0, '2026-10-12');
confere($s['x1']['pagamento'] === 'paga' && $s['x2']['pagamento'] === 'aberta', 'mesmo vencimento: pela ordem do número (e aceita objetos)');
$s = Quitacao::faturas([['uuid' => 'z', 'total' => 0.3, 'vencimento' => '2026-10-01']], 0.1 + 0.2, '2026-10-12');
confere($s['z']['pagamento'] === 'paga' && $s['z']['em_aberto'] === 0.0 && !$s['z']['vencida'], 'arredondamento nas faturas');
confere(Quitacao::faturas([], 50.0, '2026-10-12') === [], 'sem faturas: nada');

resumo();
