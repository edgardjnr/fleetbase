<?php

// Filtro da posição do motoboy no POST v1/drivers/{id}/track (App\Support\Entregas\FiltroDePosicaoDoMotoboy): posição
// anterior à última aceita, ou grosseira enquanto há uma boa recente, não entra. Antes, o track() aceitava tudo na
// ordem de chegada, e o capacete ia e voltava no mapa (batimento com posição em cache, fila do plugin, rede de celular).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/posicao-do-motoboy.php

use App\Support\Entregas\FiltroDePosicaoDoMotoboy as Filtro;

$GLOBALS['falhas'] = 0;

function confere(bool $ok, string $descricao): void
{
    if (!$ok) {
        $GLOBALS['falhas']++;
    }
    echo ($ok ? 'PASSA ' : 'FALHA ') . $descricao . PHP_EOL;
}

require '/repo/api/app/Support/Entregas/FiltroDePosicaoDoMotoboy.php';

date_default_timezone_set('America/Sao_Paulo');

$agora = 1_791_372_421_000; // 2026-10-07 08:27:01 em Brasília (11:27:01 UTC)
$min   = 60_000;

echo '== Leitura do timestamp e da precisão' . PHP_EOL;
confere(Filtro::instante('2026-10-07T11:27:01.000Z') === $agora, 'ISO com Z (o <%= timestamp %> do plugin) em ms');
confere(Filtro::instante('2026-10-07T11:27:01.250Z') === $agora + 250, 'milissegundos contam');
confere(Filtro::instante('2026-10-07T08:27:01-03:00') === $agora, 'ISO com fuso');
confere(Filtro::instante('2026-10-07 08:27:01') === $agora, 'sem fuso = horário de Brasília (fuso do app)');
foreach ([null, '', '   ', 'ontem à tarde', 1791372421000, '1791372421000', [], true] as $ruim) {
    confere(Filtro::instante($ruim) === null, 'timestamp ilegível = null: ' . json_encode($ruim));
}
confere(Filtro::precisao(12.5) === 12.5 && Filtro::precisao('1500') === 1500.0, 'precisão em número ou texto');
foreach ([null, '', 0, -3, 'x', [], INF] as $ruim) {
    confere(Filtro::precisao($ruim) === null, 'precisão ilegível, zero ou negativa = null: ' . json_encode($ruim));
}

echo '== Primeira posição' . PHP_EOL;
$r = Filtro::avaliar(null, $agora - 2_000, 8.0, $agora);
confere($r['aceita'] && $r['motivo'] === null, 'sem posição anterior: aceita');
confere($r['ultima'] === ['instante' => $agora - 2_000, 'precisao' => 8.0, 'em' => $agora], 'guarda instante, precisão e hora do servidor');
$r = Filtro::avaliar(null, null, 1800.0, $agora);
confere($r['aceita'], 'sem posição anterior: aceita até a grosseira');

echo '== Posição anterior à última aceita' . PHP_EOL;
$ultima = ['instante' => $agora - 10_000, 'precisao' => 8.0, 'em' => $agora - 9_000];
$r      = Filtro::avaliar($ultima, $agora - 4 * $min, 8.0, $agora);
confere(!$r['aceita'] && $r['motivo'] === 'antiga' && $r['ultima'] === null, 'cache do batimento de 4 min atrás: descartada');
$r = Filtro::avaliar($ultima, $agora - 10_001, 8.0, $agora);
confere(!$r['aceita'] && $r['motivo'] === 'antiga', '1 ms antes da última: descartada');
$r = Filtro::avaliar($ultima, $agora - 10_000, 8.0, $agora);
confere($r['aceita'] && $r['ultima']['instante'] === $agora - 10_000 && $r['ultima']['em'] === $agora, 'mesmo instante (batimento repetindo a última): aceita e renova a hora');
$r = Filtro::avaliar($ultima, $agora - 1_000, 8.0, $agora);
confere($r['aceita'] && $r['ultima']['instante'] === $agora - 1_000, 'mais nova: aceita');

echo '== Sem timestamp (SDK do app, APK antigo)' . PHP_EOL;
$r = Filtro::avaliar($ultima, null, 8.0, $agora);
confere($r['aceita'] && $r['ultima']['instante'] === $agora - 10_000, 'aceita e mantém o instante da última');
$r = Filtro::avaliar(['instante' => null, 'precisao' => null, 'em' => $agora - $min], $agora - 4 * $min, 8.0, $agora);
confere($r['aceita'] && $r['ultima']['instante'] === $agora - 4 * $min, 'última sem instante: a nova passa e passa a valer');

echo '== Relógio do celular adiantado' . PHP_EOL;
$r = Filtro::avaliar(null, $agora + 60 * $min, 8.0, $agora);
confere($r['aceita'] && $r['ultima']['instante'] === $agora, 'instante no futuro é guardado como a hora do servidor');
$r2 = Filtro::avaliar($r['ultima'], $agora + 5_000, 8.0, $agora + 5_000);
confere($r2['aceita'], 'relógio corrigido: a posição seguinte passa');

echo '== Posição grosseira (rede de celular)' . PHP_EOL;
$boa = ['instante' => $agora - 30_000, 'precisao' => 15.0, 'em' => $agora - 30_000];
$r   = Filtro::avaliar($boa, $agora, 1800.0, $agora);
confere(!$r['aceita'] && $r['motivo'] === 'imprecisa', 'boa há 30 s e chega uma de 1,8 km de precisão: descartada');
$r = Filtro::avaliar($boa, $agora, 500.0, $agora);
confere($r['aceita'], 'exatamente 500 m: aceita');
$r = Filtro::avaliar($boa, $agora, 500.1, $agora);
confere(!$r['aceita'], '500,1 m: descartada');
$r = Filtro::avaliar(['instante' => $agora - 6 * $min, 'precisao' => 15.0, 'em' => $agora - 5 * $min], $agora, 1800.0, $agora);
confere($r['aceita'] && $r['ultima']['precisao'] === 1800.0, 'boa aceita há 5 min ou mais: a grosseira passa (melhor que nada)');
$r = Filtro::avaliar(['instante' => $agora - 30_000, 'precisao' => 1200.0, 'em' => $agora - 30_000], $agora, 1800.0, $agora);
confere($r['aceita'], 'a última também era grosseira: passa');
$r = Filtro::avaliar(['instante' => $agora - 30_000, 'precisao' => null, 'em' => $agora - 30_000], $agora, 1800.0, $agora);
confere($r['aceita'], 'a última sem precisão (APK antigo): passa');
$r = Filtro::avaliar($boa, $agora, null, $agora);
confere($r['aceita'] && $r['ultima']['precisao'] === null, 'a nova sem precisão: passa');
$r = Filtro::avaliar($boa, $agora - 60_000, 1800.0, $agora);
confere($r['motivo'] === 'antiga', 'antiga e grosseira: o motivo é antiga');

echo '== Cache estragado' . PHP_EOL;
foreach ([[], ['instante' => 'x', 'precisao' => 'y', 'em' => 'z'], ['instante' => 1.5]] as $estragado) {
    $r = Filtro::avaliar($estragado, $agora, 1800.0, $agora);
    confere($r['aceita'], 'última ilegível não descarta nada: ' . json_encode($estragado));
}

echo PHP_EOL . 'FALHAS: ' . $GLOBALS['falhas'] . PHP_EOL;
