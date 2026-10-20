<?php

// Distribuição de pedidos abertos: matriz de tempos (EstimadorDeTempo) e a fila de candidatos (FilaDeCandidatos).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-fila.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\EstimadorDeTempo;
use App\Support\Entregas\Distribuicao\Pontos;
use Teste\Banco;
use Teste\Config;
use Teste\Http;


/** reiniciarIfood zera a config: o host do OSRM volta a cada cenário */
function reiniciar(): void
{
    reiniciarIfood();
    Config::$valores['fleetops.osrm.host']                  = 'http://osrm:5000';
    Config::$valores['services.entregas.distribuicao_osrm'] = '1';
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

reiniciar();
Config::$valores['services.entregas.distribuicao_osrm'] = '';
$matriz = (new EstimadorDeTempo())->matriz($pontos);
confere($matriz['aproximado'] === true && Http::urls() === [] && $matriz['durations'][0][1] === (float) Pontos::segundos($pontos[0], $pontos[1]) && !logou('OSRM', 'warning'), 'OSRM desligado (padrão): linha reta, sem chamar o Http e sem log');

reiniciar();
Http::responder(200, ['code' => 'TooBig']);
(new EstimadorDeTempo())->matriz($pontos);
confere(str_contains(json_encode(\Illuminate\Support\Facades\Log::$registros), 'TooBig'),'o code da resposta vai ao log');

echo '== FilaDeCandidatos' . PHP_EOL;
use App\Support\Entregas\Distribuicao\Candidatos;
use App\Support\Entregas\Distribuicao\FilaDeCandidatos;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;

/** Um Point como o do Fleet-Ops (getLat/getLng). */
function ponto(array $p): object
{
    return new class($p) { public function __construct(private array $p) {} public function getLat() { return $this->p[0]; } public function getLng() { return $this->p[1]; } };
}

function motoboy(string $id, string $nome, array $posicao): Driver
{
    return new Driver(['uuid' => 'd-' . $id, 'public_id' => 'driver_' . $id, 'company_uuid' => 'empresa-1', 'name' => $nome, 'online' => true, 'location' => ponto($posicao)]);
}

/** Pedido com coleta e entrega, como o Order real (payload->pickup/dropoff com location). */
function pedidoCom(array $coleta, array $entrega): Order
{
    $pedido          = new Order(['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'adhoc' => true, 'status' => 'dispatched']);
    $pedido->payload = (object) ['pickup' => (object) ['location' => ponto($coleta)], 'dropoff' => (object) ['location' => ponto($entrega)]];

    return $pedido;
}

reiniciarFleetbase();
reiniciarIfood();
$coleta  = [-21.1700, -47.8100];
$entrega = [-21.1800, -47.8100];
$pedido  = pedidoCom($coleta, $entrega);
// A livre a 1 km da loja; B livre a 200 m; C ocupado (uma entrega a 100 m da loja) a 300 m
Candidatos::$buscarMotoboys = fn (Order $p, bool $gpsRecente) => [
    ['motoboy' => motoboy('a', 'Ana', [-21.1610, -47.8100]), 'posicao' => [-21.1610, -47.8100], 'distancia' => 1000.0],
    ['motoboy' => motoboy('b', 'Bia', [-21.1682, -47.8100]), 'posicao' => [-21.1682, -47.8100], 'distancia' => 200.0],
    ['motoboy' => motoboy('c', 'Caio', [-21.1673, -47.8100]), 'posicao' => [-21.1673, -47.8100], 'distancia' => 300.0],
];
Candidatos::$buscarParadas = fn (string $empresa, array $uuids) => ['d-c' => [[-21.1709, -47.8100, 'entrega']]];

// a matriz sai da linha reta (o teste não depende do OSRM) e o estimador anota os pontos recebidos
$estimador = new class extends EstimadorDeTempo {
    public array $pontosRecebidos = [];
    public function matriz(array $pontos): array
    {
        $this->pontosRecebidos = $pontos;
        $m = [];
        foreach ($pontos as $i => $a) { foreach ($pontos as $j => $b) { $m[$i][$j] = (float) Pontos::segundos($a, $b); } }

        return ['durations' => $m, 'aproximado' => false];
    }
};
$fila = (new FilaDeCandidatos($estimador))->para($pedido, Candidatos::elegiveis($pedido, []));
confere(count($estimador->pontosRecebidos) === 2 + 3 + 1, 'uma matriz só: coleta, entrega, 3 posições e 1 parada');
confere(array_column($fila, 'public_id') === ['driver_b', 'driver_c', 'driver_a'], 'ordem pelo tempo até o cliente: B (perto, livre), C (ocupado mas perto, com encaixe), A (longe) (' . json_encode(array_column($fila, 'public_id')) . ')');
confere($fila[0]['livre'] === true && $fila[1]['livre'] === false && $fila[1]['encaixe'] === true, 'livre e encaixe marcados');
confere($fila[0]['tempo_s'] > 0 && $fila[0]['aproximado'] === false && $fila[0]['motoboy_uuid'] === 'd-b' && $fila[0]['nome'] === 'Bia' && $fila[0]['distancia_m'] === 200, 'campos da fila (' . json_encode($fila[0]) . ')');

$fila = (new FilaDeCandidatos($estimador))->para($pedido, Candidatos::elegiveis($pedido, ['d-b']));
confere(array_column($fila, 'public_id') === ['driver_c', 'driver_a'], 'excluído (já respondeu ou tem oferta pendente) não entra');

Candidatos::$buscarMotoboys = fn () => [];
confere((new FilaDeCandidatos($estimador))->para($pedido, Candidatos::elegiveis($pedido, [])) === [], 'sem candidato: fila vazia');

// empate: dois livres no mesmo ponto → public_id
Candidatos::$buscarMotoboys = fn () => [
    ['motoboy' => motoboy('z', 'Zé', $coleta), 'posicao' => $coleta, 'distancia' => 0.0],
    ['motoboy' => motoboy('m', 'Mia', $coleta), 'posicao' => $coleta, 'distancia' => 0.0],
];
Candidatos::$buscarParadas = fn () => [];
confere(array_column((new FilaDeCandidatos($estimador))->para($pedido, Candidatos::elegiveis($pedido, [])), 'public_id') === ['driver_m', 'driver_z'], 'empate: pelo public_id');

$pedidoSemEntrega = pedidoCom($coleta, [0.0, 0.0]);
confere((new FilaDeCandidatos($estimador))->para($pedidoSemEntrega, Candidatos::elegiveis($pedidoSemEntrega, [])) === [], 'pedido sem entrega válida: fila vazia (vai abrir a todos)');

echo '== paradasDosPedidos' . PHP_EOL;
function pedidoDaFila(string $motoboy, string $status, ?array $coleta, ?array $entrega): object
{
    return (object) ['driver_assigned_uuid' => $motoboy, 'status' => $status, 'payload' => (object) [
        'pickup'  => (object) ['location' => $coleta ? ponto($coleta) : null],
        'dropoff' => (object) ['location' => $entrega ? ponto($entrega) : null],
    ]];
}
$paradas = Candidatos::paradasDosPedidos([
    pedidoDaFila('d-a', 'enroute', [-21.1, -47.1], [-21.2, -47.2]),
    pedidoDaFila('d-a', 'started', [-21.3, -47.3], [-21.4, -47.4]),
    pedidoDaFila('d-b', 'dispatched', [-21.5, -47.5], [-21.6, -47.6]),
    pedidoDaFila('d-b', 'started', [0.0, 0.0], [-21.7, -47.7]),
]);
confere($paradas['d-a'] === [[-21.2, -47.2, 'entrega'], [-21.3, -47.3, 'coleta'], [-21.4, -47.4, 'entrega']], 'enroute só entrega; started coleta e entrega; agrupa na ordem (' . json_encode($paradas['d-a']) . ')');
confere($paradas['d-b'] === [[-21.5, -47.5, 'coleta'], [-21.6, -47.6, 'entrega'], [-21.7, -47.7, 'entrega']], 'dispatched coleta e entrega; ponto inválido descartado (' . json_encode($paradas['d-b']) . ')');
confere(Candidatos::paradasDosPedidos([]) === [], 'sem pedidos: vazio');

echo '== Rodadas: encaixe com até 5 min de atraso e o raio da rodada' . PHP_EOL;
Candidatos::$buscarMotoboys = fn (Order $p, bool $gpsRecente) => [
    ['motoboy' => motoboy('a', 'Ana', [-21.1610, -47.8100]), 'posicao' => [-21.1610, -47.8100], 'distancia' => 1000.0],
    ['motoboy' => motoboy('b', 'Bia', [-21.1682, -47.8100]), 'posicao' => [-21.1682, -47.8100], 'distancia' => 200.0],
    ['motoboy' => motoboy('c', 'Caio', [-21.1673, -47.8100]), 'posicao' => [-21.1673, -47.8100], 'distancia' => 300.0],
];
Candidatos::$buscarParadas = fn (string $empresa, array $uuids) => ['d-c' => [[-21.1709, -47.8100, 'entrega']]];
$fila = (new FilaDeCandidatos($estimador))->para($pedido, Candidatos::elegiveis($pedido, []), Distribuicao::ATRASO_MAXIMO_EM_RODADAS_S);
confere(array_column($fila, 'public_id') === ['driver_b', 'driver_c', 'driver_a'] && array_column($fila, 'tempo_s') === [425, 564, 575], 'rodadas: C (ocupado) passa na loja, que fica no caminho da entrega dele, e fica à frente de A (' . json_encode(array_column($fila, 'tempo_s')) . ')');
confere($fila[1]['encaixe'] === true && $fila[1]['livre'] === false && $fila[0]['encaixe'] === false, 'rodadas: C com encaixe ("no caminho") e marcado como ocupado');

// caso real (2026-10-07): Edgard a 2,07 km da loja com um pedido da MESMA loja aceito e ainda não coletado (cliente A a
// ~110 m da entrega nova); Edmar livre a 5,3 km. "Termina tudo" dava 1450 s (24,2 min) a Edgard e 1381 s (23,0 min) a Edmar.
Candidatos::$buscarMotoboys = fn (Order $p, bool $gpsRecente) => [
    ['motoboy' => motoboy('edmar', 'Edmar', [-21.1700 + 0.009 * 5.3, -47.8100]), 'posicao' => [-21.1700 + 0.009 * 5.3, -47.8100], 'distancia' => 5304.0],
    ['motoboy' => motoboy('edgard', 'Edgard', [-21.1700 + 0.009 * 2.07, -47.8100]), 'posicao' => [-21.1700 + 0.009 * 2.07, -47.8100], 'distancia' => 2072.0],
];
Candidatos::$buscarParadas = fn (string $empresa, array $uuids) => ['d-edgard' => [[-21.1700, -47.8100, 'coleta'], [-21.1790, -47.8100, 'entrega']]];
$fila = (new FilaDeCandidatos($estimador))->para($pedido, Candidatos::elegiveis($pedido, []), Distribuicao::ATRASO_MAXIMO_EM_RODADAS_S);
confere(array_column($fila, 'public_id') === ['driver_edgard', 'driver_edmar'] && array_column($fila, 'tempo_s') === [1076, 1381], 'caso real em rodadas: Edgard coleta junto na mesma loja (17,9 min) e fica à frente de Edmar livre (23,0 min) (' . json_encode(array_column($fila, 'tempo_s')) . ')');
confere($fila[0]['encaixe'] === true && $fila[0]['livre'] === false, 'caso real: Edgard com encaixe');
$fila = (new FilaDeCandidatos($estimador))->para($pedido, Candidatos::elegiveis($pedido, []));
confere(array_column($fila, 'tempo_s') === [956, 1381], 'caso real no ciclo antigo (10 min): entrega o cliente novo antes de A (A atrasa 342 s, acima do limite das rodadas) (' . json_encode(array_column($fila, 'tempo_s')) . ')');
$raioRecebido = 'nenhum';
Candidatos::$buscarMotoboys = function (Order $p, bool $gpsRecente, ?int $raio = null) use (&$raioRecebido) {
    $raioRecebido = $raio;

    return [];
};
Candidatos::elegiveis($pedido, [], 9000);
confere($raioRecebido === 9000, 'elegiveis repassa o raio da rodada à busca');
Candidatos::elegiveis($pedido, []);
confere($raioRecebido === null, 'sem raio: null (a busca usa o raio de pedido aberto)');
Candidatos::$buscarMotoboys = null;
Candidatos::$buscarParadas  = null;

echo '== Desempate por corridas do dia (ordenar)' . PHP_EOL;
/** Uma linha da fila como a do FilaDeCandidatos::para; $corridas null = sem a chave (fila de antes do desempate). */
function linhaDaFila(string $id, int $tempo, ?int $corridas, bool $livre = true): array
{
    $linha = ['motoboy_uuid' => 'd-' . $id, 'public_id' => 'driver_' . $id, 'nome' => $id, 'tempo_s' => $tempo, 'encaixe' => false, 'aproximado' => false, 'livre' => $livre, 'distancia_m' => 0];
    if ($corridas !== null) {
        $linha['corridas_hoje'] = $corridas;
    }

    return $linha;
}

confere(Distribuicao::DESEMPATE_CORRIDAS_S === 180, 'limite do desempate: 3 min');

// o exemplo da spec: Ana 10 min (4), Beto 12 min (1), Caio 13 min (2), Davi 14 min (0, fora: 4 min acima da Ana)
$fila = FilaDeCandidatos::ordenar([linhaDaFila('davi', 840, 0), linhaDaFila('ana', 600, 4), linhaDaFila('caio', 780, 2), linhaDaFila('beto', 720, 1)]);
confere(array_column($fila, 'public_id') === ['driver_beto', 'driver_caio', 'driver_ana', 'driver_davi'], 'até 3 min do mais rápido: menos corridas primeiro; o resto pelo tempo (' . json_encode(array_column($fila, 'public_id')) . ')');
confere(array_column($fila, 'desempate') === [true, true, false, false], 'desempate marcado em quem passou à frente de alguém mais rápido (' . json_encode(array_column($fila, 'desempate')) . ')');

// Beto recusou: o grupo é refeito a partir da Ana (até 13 min)
$fila = FilaDeCandidatos::ordenar([linhaDaFila('ana', 600, 4), linhaDaFila('caio', 780, 2), linhaDaFila('davi', 840, 0)]);
confere(array_column($fila, 'public_id') === ['driver_caio', 'driver_ana', 'driver_davi'], 'fila refeita sem o Beto: Caio, Ana, Davi (' . json_encode(array_column($fila, 'public_id')) . ')');

$fila = FilaDeCandidatos::ordenar([linhaDaFila('a', 600, 5), linhaDaFila('b', 780, 0), linhaDaFila('c', 781, 0)]);
confere(array_column($fila, 'public_id') === ['driver_b', 'driver_a', 'driver_c'], 'limite exato: 180 s entra no grupo, 181 s fica fora (' . json_encode(array_column($fila, 'public_id')) . ')');

$fila = FilaDeCandidatos::ordenar([linhaDaFila('a', 700, 2), linhaDaFila('b', 650, 2)]);
confere(array_column($fila, 'public_id') === ['driver_b', 'driver_a'] && array_column($fila, 'desempate') === [false, false], 'empate em corridas: o menor tempo, sem desempate');

$fila = FilaDeCandidatos::ordenar([linhaDaFila('c', 650, 0), linhaDaFila('a', 600, 0, false), linhaDaFila('b', 600, 0)]);
confere(array_column($fila, 'public_id') === ['driver_b', 'driver_a', 'driver_c'], 'todos com 0: a ordem de antes (tempo, o livre, o public_id) (' . json_encode(array_column($fila, 'public_id')) . ')');

$fila = FilaDeCandidatos::ordenar([linhaDaFila('a', 600, null), linhaDaFila('b', 700, 1)]);
confere(array_column($fila, 'public_id') === ['driver_a', 'driver_b'] && $fila[0]['corridas_hoje'] === 0, 'linha sem corridas_hoje vale 0');

confere(FilaDeCandidatos::ordenar([]) === [], 'fila vazia: vazia');

echo '== Corridas de hoje (Candidatos::corridasHoje)' . PHP_EOL;
use Illuminate\Support\Facades\DB;

reiniciarIfood(); // banco em memória vazio; o relógio dos stubs está em 2026-10-05 15:00 (Brasília)
$pedidoDoDia = fn (array $c) => DB::table('orders')->insert([$c + ['company_uuid' => 'empresa-1', 'deleted_at' => null, 'status' => 'completed', 'started_at' => null, 'dispatched_at' => null]]);
$pedidoDoDia(['driver_assigned_uuid' => 'd-a', 'started_at' => '2026-10-05 09:00:00']);                           // conta
$pedidoDoDia(['driver_assigned_uuid' => 'd-a', 'status' => 'dispatched', 'dispatched_at' => '2026-10-05 14:50:00']); // atribuído pela central: conta pelo despacho
$pedidoDoDia(['driver_assigned_uuid' => 'd-a', 'started_at' => '2026-10-04 23:59:59', 'dispatched_at' => '2026-10-04 23:50:00']); // ontem
$pedidoDoDia(['driver_assigned_uuid' => 'd-a', 'status' => 'canceled', 'started_at' => '2026-10-05 10:00:00']);
$pedidoDoDia(['driver_assigned_uuid' => 'd-a', 'status' => 'expired', 'started_at' => '2026-10-05 10:00:00']);
$pedidoDoDia(['driver_assigned_uuid' => 'd-a', 'started_at' => '2026-10-05 10:00:00', 'deleted_at' => '2026-10-05 11:00:00']);
$pedidoDoDia(['driver_assigned_uuid' => 'd-b', 'status' => 'started', 'started_at' => '2026-10-05 00:00:00']);    // meia-noite em ponto: conta
$pedidoDoDia(['driver_assigned_uuid' => 'd-b', 'company_uuid' => 'outra', 'started_at' => '2026-10-05 10:00:00']);
$pedidoDoDia(['driver_assigned_uuid' => 'd-c', 'started_at' => '2026-10-05 10:00:00']);                           // não foi pedido
$pedidoDoDia(['driver_assigned_uuid' => 'd-b']);                                                                    // sem started_at nem dispatched_at
$pedidoDoDia(['driver_assigned_uuid' => 'd-r', 'started_at' => '2026-10-04 22:00:00', 'dispatched_at' => '2026-10-05 08:00:00']); // aceito ontem, redespachado hoje: o COALESCE usa o started_at, não conta
$corridas = Candidatos::corridasHoje('empresa-1', ['d-a', 'd-b', 'd-r']);
ksort($corridas);
confere($corridas === ['d-a' => 2, 'd-b' => 1], 'hoje em Brasília, sem cancelados, expirados, apagados, outra empresa, outro motoboy e redespachado de ontem (' . json_encode($corridas) . ')');

// 22:30 em Brasília já é 01:30 UTC do dia seguinte: o "hoje" continua o de Brasília
\Teste\Relogio::$agora = '2026-10-05 22:30:00';
$corridas = Candidatos::corridasHoje('empresa-1', ['d-a', 'd-b']);
ksort($corridas);
confere($corridas === ['d-a' => 2, 'd-b' => 1], 'às 22:30 de Brasília (01:30 UTC do dia 6) o dia ainda é o 5 (' . json_encode($corridas) . ')');
\Teste\Relogio::$agora = '2026-10-05 15:00:00';
confere(Candidatos::corridasHoje('empresa-1', []) === [], 'sem motoboys: vazio, sem consultar');

Banco::$falharAoConsultar['orders'] = new \RuntimeException('banco fora');
confere(Candidatos::corridasHoje('empresa-1', ['d-a']) === [], 'falha na consulta: vazio (todos com 0)');
confere(logou('não deu para contar as corridas de hoje', 'warning'), 'falha fica no log');

reiniciarIfood();
Candidatos::$buscarCorridasHoje = fn () => throw new \RuntimeException('falhou');
confere(Candidatos::corridasHoje('empresa-1', ['d-a']) === [] && logou('não deu para contar as corridas de hoje', 'warning'), 'falha da closure dos testes: vazio, com o log');

// elegiveis põe corridas_hoje em cada candidato, e para() leva o número para a fila
Candidatos::$buscarMotoboys = fn () => [
    ['motoboy' => motoboy('a', 'Ana', $coleta), 'posicao' => $coleta, 'distancia' => 0.0],
    ['motoboy' => motoboy('b', 'Bia', $coleta), 'posicao' => $coleta, 'distancia' => 0.0],
];
Candidatos::$buscarParadas      = fn () => [];
Candidatos::$buscarCorridasHoje = fn (string $empresa, array $uuids) => ['d-a' => 3];
$elegiveis = Candidatos::elegiveis($pedido, []);
confere(array_column($elegiveis, 'corridas_hoje') === [3, 0], 'elegiveis: corridas_hoje de cada candidato, 0 para quem não aparece');
$fila = (new FilaDeCandidatos($estimador))->para($pedido, $elegiveis);
confere(array_column($fila, 'public_id') === ['driver_b', 'driver_a'] && array_column($fila, 'corridas_hoje') === [0, 3], 'para: mesmo tempo, Bia (0 hoje) antes de Ana (3)');
Candidatos::$buscarMotoboys     = null;
Candidatos::$buscarParadas      = null;
Candidatos::$buscarCorridasHoje = null;

resumo();
