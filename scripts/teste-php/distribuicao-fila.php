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

echo '== Rodadas: "termina tudo e depois vai" e o raio da rodada' . PHP_EOL;
Candidatos::$buscarMotoboys = fn (Order $p, bool $gpsRecente) => [
    ['motoboy' => motoboy('a', 'Ana', [-21.1610, -47.8100]), 'posicao' => [-21.1610, -47.8100], 'distancia' => 1000.0],
    ['motoboy' => motoboy('b', 'Bia', [-21.1682, -47.8100]), 'posicao' => [-21.1682, -47.8100], 'distancia' => 200.0],
    ['motoboy' => motoboy('c', 'Caio', [-21.1673, -47.8100]), 'posicao' => [-21.1673, -47.8100], 'distancia' => 300.0],
];
Candidatos::$buscarParadas = fn (string $empresa, array $uuids) => ['d-c' => [[-21.1709, -47.8100, 'entrega']]];
$fila = (new FilaDeCandidatos($estimador))->para($pedido, Candidatos::elegiveis($pedido, []), true);
confere(array_column($fila, 'public_id') === ['driver_b', 'driver_a', 'driver_c'] && array_column($fila, 'tempo_s') === [425, 575, 602], 'no fim: C (ocupado) termina a entrega dele e só depois vai à loja; fica atrás de A (' . json_encode(array_column($fila, 'tempo_s')) . ')');
confere(array_filter(array_column($fila, 'encaixe')) === [] && $fila[2]['livre'] === false, 'no fim: encaixe sempre falso; C continua marcado como ocupado');
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

resumo();
