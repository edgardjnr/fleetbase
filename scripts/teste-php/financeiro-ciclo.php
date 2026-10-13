<?php

// Financeiro: o ciclo de cobrança das lojas (CicloDeCobranca, funções puras): o período que fecha num dia, o período em
// andamento, o vencimento e a validação da configuração.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/financeiro-ciclo.php

require __DIR__ . '/stubs-financeiro.php';

use App\Support\Entregas\Financeiro\CicloDeCobranca;

$semanal = fn (int $dia, int $prazo = 3) => ['ciclo' => 'semanal', 'dia_fechamento' => $dia, 'prazo_dias' => $prazo];
$mensal  = fn (int $dia, int $prazo = 5) => ['ciclo' => 'mensal', 'dia_fechamento' => $dia, 'prazo_dias' => $prazo];
$quinzenal = ['ciclo' => 'quinzenal', 'dia_fechamento' => 0, 'prazo_dias' => 2];

echo '== Semanal' . PHP_EOL;
// 2026-10-11 é domingo
confere(CicloDeCobranca::periodoQueFechaEm($semanal(0), '2026-10-11') === ['inicio' => '2026-10-05', 'fim' => '2026-10-11'], 'fecha no domingo: segunda a domingo');
confere(CicloDeCobranca::periodoQueFechaEm($semanal(0), '2026-10-12') === null && CicloDeCobranca::periodoQueFechaEm($semanal(0), '2026-10-10') === null, 'não fecha na segunda nem no sábado');
confere(CicloDeCobranca::periodoQueFechaEm($semanal(5), '2026-10-09') === ['inicio' => '2026-10-03', 'fim' => '2026-10-09'], 'fecha na sexta: sábado a sexta');
confere(CicloDeCobranca::periodoQueFechaEm($semanal(0), '2026-11-01') === ['inicio' => '2026-10-26', 'fim' => '2026-11-01'], 'virada de mês');
confere(CicloDeCobranca::periodoQueFechaEm($semanal(0), '2027-01-03') === ['inicio' => '2026-12-28', 'fim' => '2027-01-03'], 'virada de ano');
confere(CicloDeCobranca::periodoQueContem($semanal(0), '2026-10-12') === ['inicio' => '2026-10-12', 'fim' => '2026-10-18'], 'em andamento: da segunda ao próximo domingo');
confere(CicloDeCobranca::periodoQueContem($semanal(0), '2026-10-11') === ['inicio' => '2026-10-05', 'fim' => '2026-10-11'], 'em andamento no dia do fechamento: o que fecha hoje');
confere(CicloDeCobranca::periodoQueContem($semanal(3), '2026-10-12') === ['inicio' => '2026-10-08', 'fim' => '2026-10-14'], 'em andamento, fecha na quarta');

echo '== Quinzenal' . PHP_EOL;
confere(CicloDeCobranca::periodoQueFechaEm($quinzenal, '2026-10-15') === ['inicio' => '2026-10-01', 'fim' => '2026-10-15'], 'fecha no dia 15: 1 a 15');
confere(CicloDeCobranca::periodoQueFechaEm($quinzenal, '2026-10-31') === ['inicio' => '2026-10-16', 'fim' => '2026-10-31'], 'fecha no último dia: 16 ao fim');
confere(CicloDeCobranca::periodoQueFechaEm($quinzenal, '2027-02-28') === ['inicio' => '2027-02-16', 'fim' => '2027-02-28'], 'fevereiro: fecha no 28');
confere(CicloDeCobranca::periodoQueFechaEm($quinzenal, '2028-02-28') === null && CicloDeCobranca::periodoQueFechaEm($quinzenal, '2028-02-29') === ['inicio' => '2028-02-16', 'fim' => '2028-02-29'], 'fevereiro bissexto: fecha no 29, não no 28');
confere(CicloDeCobranca::periodoQueFechaEm($quinzenal, '2026-10-16') === null && CicloDeCobranca::periodoQueFechaEm($quinzenal, '2026-10-30') === null, 'não fecha nos outros dias');
confere(CicloDeCobranca::periodoQueContem($quinzenal, '2026-10-12') === ['inicio' => '2026-10-01', 'fim' => '2026-10-15'], 'em andamento na primeira quinzena');
confere(CicloDeCobranca::periodoQueContem($quinzenal, '2026-11-20') === ['inicio' => '2026-11-16', 'fim' => '2026-11-30'], 'em andamento na segunda quinzena (mês de 30 dias)');
confere(CicloDeCobranca::periodoQueFechaEm(['ciclo' => 'quinzenal', 'dia_fechamento' => 9, 'prazo_dias' => 2], '2026-10-15') !== null, 'quinzenal ignora o dia de fechamento');

echo '== Mensal' . PHP_EOL;
confere(CicloDeCobranca::periodoQueFechaEm($mensal(5), '2026-10-05') === ['inicio' => '2026-09-06', 'fim' => '2026-10-05'], 'fecha no dia 5: do dia 6 do mês anterior ao 5');
confere(CicloDeCobranca::periodoQueFechaEm($mensal(5), '2026-10-06') === null && CicloDeCobranca::periodoQueFechaEm($mensal(5), '2026-10-04') === null, 'não fecha nos outros dias');
confere(CicloDeCobranca::periodoQueFechaEm($mensal(5), '2027-01-05') === ['inicio' => '2026-12-06', 'fim' => '2027-01-05'], 'virada de ano');
confere(CicloDeCobranca::periodoQueFechaEm($mensal(28), '2027-03-28') === ['inicio' => '2027-03-01', 'fim' => '2027-03-28'], 'dia 28 em março: começa em 1º (fevereiro de 28 dias)');
confere(CicloDeCobranca::periodoQueFechaEm($mensal(0), '2026-10-31') === ['inicio' => '2026-10-01', 'fim' => '2026-10-31'], 'dia 0 = último dia: o mês inteiro');
confere(CicloDeCobranca::periodoQueFechaEm($mensal(0), '2026-11-30') === ['inicio' => '2026-11-01', 'fim' => '2026-11-30'] && CicloDeCobranca::periodoQueFechaEm($mensal(0), '2026-10-30') === null, 'último dia em mês de 30 (e não no dia 30 de mês de 31)');
confere(CicloDeCobranca::periodoQueFechaEm($mensal(0), '2027-02-28') === ['inicio' => '2027-02-01', 'fim' => '2027-02-28'], 'último dia em fevereiro');
confere(CicloDeCobranca::periodoQueContem($mensal(5), '2026-10-12') === ['inicio' => '2026-10-06', 'fim' => '2026-11-05'], 'em andamento depois do fechamento: vai até o dia 5 do mês seguinte');
confere(CicloDeCobranca::periodoQueContem($mensal(5), '2026-10-03') === ['inicio' => '2026-09-06', 'fim' => '2026-10-05'], 'em andamento antes do fechamento: até o dia 5 deste mês');
confere(CicloDeCobranca::periodoQueContem($mensal(5), '2026-12-20') === ['inicio' => '2026-12-06', 'fim' => '2027-01-05'], 'em andamento na virada do ano');
confere(CicloDeCobranca::periodoQueContem($mensal(0), '2026-02-10') === ['inicio' => '2026-02-01', 'fim' => '2026-02-28'], 'em andamento, último dia: o mês');

echo '== Vencimento' . PHP_EOL;
confere(CicloDeCobranca::vencimento($semanal(0, 3), '2026-10-11') === '2026-10-14', 'fim + prazo');
confere(CicloDeCobranca::vencimento($semanal(0, 0), '2026-10-11') === '2026-10-11', 'prazo zero: vence no fim');
confere(CicloDeCobranca::vencimento($mensal(0, 10), '2026-12-31') === '2027-01-10', 'atravessa o ano');

echo '== Validar' . PHP_EOL;
confere(CicloDeCobranca::validar($semanal(0)) === null && CicloDeCobranca::validar($semanal(6, 60)) === null && CicloDeCobranca::validar($mensal(28)) === null && CicloDeCobranca::validar($mensal(0)) === null && CicloDeCobranca::validar($quinzenal) === null, 'válidos');
confere(CicloDeCobranca::validar(['ciclo' => 'semanal', 'dia_fechamento' => '2', 'prazo_dias' => '7']) === null, 'números em texto valem');
foreach ([
    'ciclo desconhecido'      => ['ciclo' => 'anual', 'dia_fechamento' => 0, 'prazo_dias' => 3],
    'sem ciclo'               => ['dia_fechamento' => 0, 'prazo_dias' => 3],
    'semanal com dia 7'       => $semanal(7),
    'semanal com dia -1'      => ['ciclo' => 'semanal', 'dia_fechamento' => -1, 'prazo_dias' => 3],
    'mensal com dia 29'       => $mensal(29),
    'prazo 61'                => $semanal(0, 61),
    'prazo negativo'          => ['ciclo' => 'semanal', 'dia_fechamento' => 0, 'prazo_dias' => -1],
    'prazo quebrado'          => ['ciclo' => 'semanal', 'dia_fechamento' => 0, 'prazo_dias' => '2.5'],
    'sem prazo'               => ['ciclo' => 'semanal', 'dia_fechamento' => 0],
    'dia em texto'            => ['ciclo' => 'semanal', 'dia_fechamento' => 'domingo', 'prazo_dias' => 3],
] as $caso => $config) {
    $erro = CicloDeCobranca::validar($config);
    confere(is_string($erro) && $erro !== '' && !preg_match('/[a-z]+\.[a-z]+/', $erro), "{$caso}: recusa em pt-BR ({$erro})");
}
confere(CicloDeCobranca::normalizar(['ciclo' => 'quinzenal', 'dia_fechamento' => '9', 'prazo_dias' => '4']) === ['ciclo' => 'quinzenal', 'dia_fechamento' => 0, 'prazo_dias' => 4], 'normalizar: números e quinzenal com dia 0');

resumo();
