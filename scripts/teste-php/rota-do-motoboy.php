<?php

// Mapa do pedido no app do motoboy: o traçado loja → cliente (RotaDoPedido) e a rota que o app consulta
// (MotoboyController::rota), com a situação do motoboy que vira a cor do capacete.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/rota-do-motoboy.php

require __DIR__ . '/stubs-ganhos.php';

use App\Http\Controllers\Entregas\MotoboyController;
use App\Support\Entregas\CalculoEntregas;
use App\Support\Entregas\RotaDoPedido;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\OSRM;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Teste\Ponto;

// a polyline do exemplo da documentação do Google: (38.5, -120.2), (40.7, -120.95), (43.252, -126.453)
const POLYLINE = '_p~iF~ps|U_ulLnnqC_mqNvxq`@';

function reiniciar(): void
{
    Cache::$dados      = [];
    Cache::$validades  = [];
    Log::$registros    = [];
    OSRM::$chamadas    = 0;
    OSRM::$pedidos     = [];
    OSRM::$metros      = 3210.4;
    OSRM::$resposta    = ['code' => 'Ok', 'routes' => [['geometry' => POLYLINE, 'distance' => 3210.4, 'duration' => 545.6]]];
    Utils::$linhaReta  = 2000;
    Driver::$todos     = [];
}

function lugar(string $id, ?float $lat, ?float $lng): Place
{
    return new Place(['uuid' => 'uuid-' . $id, 'public_id' => $id, 'name' => $id, 'location' => $lat === null ? null : new Ponto($lat, $lng)]);
}

function pedido(string $id, array $atributos = []): Order
{
    return new Order($atributos + [
        'uuid'                 => 'uuid-' . $id,
        'public_id'            => $id,
        'status'               => 'started',
        'driver_assigned_uuid' => 'uuid-driver_motoca',
        'updated_at'           => now()->subMinutes(10)->format('Y-m-d H:i:s'),
        'payload'              => new Payload(lugar('place_loja', -21.17, -47.81), lugar('place_cliente', -21.2, -47.8)),
    ]);
}

echo '== Polyline do OSRM' . PHP_EOL;
confere(RotaDoPedido::decodificar(POLYLINE) === [[38.5, -120.2], [40.7, -120.95], [43.252, -126.453]], 'decodifica a polyline de precisão 5 em [lat, lng]');
confere(RotaDoPedido::decodificar('') === [], 'texto vazio: nenhum ponto');
confere(RotaDoPedido::decodificar('_p~iF~ps|U_ulL') === [[38.5, -120.2]], 'texto cortado: só os pontos completos');

echo '== Traçado do pedido (RotaDoPedido)' . PHP_EOL;
reiniciar();
$rotas = new RotaDoPedido(new CalculoEntregas());
$rota  = $rotas->doPedido(pedido('order_a'));
confere($rota === ['linha' => [[38.5, -120.2], [40.7, -120.95], [43.252, -126.453]], 'metros' => 3210, 'segundos' => 546, 'aproximado' => false], 'rota do OSRM: linha, metros e segundos');
confere(OSRM::$pedidos === [['-47.81,-21.17;-47.8,-21.2', ['overview' => 'simplified']]], 'OSRM recebe loja → cliente em lng,lat, com a geometria simplificada');
$chave = array_key_first(Cache::$dados);
confere(str_starts_with((string) $chave, 'entregas:rota:uuid-order_a:') && Cache::$validades[$chave] === RotaDoPedido::VALIDADE_OSRM, 'guardada no cache por pedido e coordenadas, por 24 h');
$rotas->doPedido(pedido('order_a'));
confere(OSRM::$chamadas === 1, 'a segunda consulta vem do cache, sem chamar o OSRM');
$rotas->doPedido(pedido('order_a', ['payload' => new Payload(lugar('place_loja', -21.17, -47.81), lugar('place_outro', -21.3, -47.9))]));
confere(OSRM::$chamadas === 2, 'destino trocado: rota nova');

reiniciar();
OSRM::$metros = null;
$rota         = $rotas->doPedido(pedido('order_b'));
confere($rota === ['linha' => [[-21.17, -47.81], [-21.2, -47.8]], 'metros' => 2600, 'segundos' => null, 'aproximado' => true], 'OSRM fora do ar: linha reta loja → cliente, km × 1,3, sem tempo');
confere(Cache::$validades[array_key_first(Cache::$dados)] === RotaDoPedido::VALIDADE_ESTIMATIVA, 'a linha reta fica só 5 min no cache');
confere(($registro = Log::$registros[0] ?? null) && $registro[1] === '[entregas] OSRM sem rota para o mapa do app; vai a linha reta' && $registro[2]['erro'] === 'OSRM fora do ar', 'falha registrada no log com o erro');

reiniciar();
OSRM::$resposta = ['code' => 'NoRoute', 'routes' => []];
confere($rotas->doPedido(pedido('order_c'))['aproximado'] === true, 'OSRM sem rota (NoRoute): linha reta');
reiniciar();
OSRM::$resposta = ['code' => 'Ok', 'routes' => [['geometry' => POLYLINE, 'distance' => 0, 'duration' => 0]]];
confere($rotas->doPedido(pedido('order_d'))['aproximado'] === true, 'OSRM com 0 m: linha reta');
reiniciar();
OSRM::$resposta = ['code' => 'Ok', 'routes' => [['geometry' => '_p~iF~ps|U', 'distance' => 500, 'duration' => 60]]];
confere($rotas->doPedido(pedido('order_e'))['aproximado'] === true, 'OSRM com um ponto só: linha reta');

reiniciar();
confere($rotas->doPedido(pedido('order_f', ['payload' => new Payload(lugar('place_loja', -21.17, -47.81), lugar('place_sem', null, null))])) === null, 'destino sem posição: sem rota');
confere($rotas->doPedido(pedido('order_g', ['payload' => null])) === null && OSRM::$chamadas === 0, 'pedido sem payload: sem rota e sem OSRM');

reiniciar();
// rota longa: 2500 pontos viram no máximo MAX_PONTOS + 1, com o último (o cliente) mantido
$pontos = [];
for ($i = 0; $i < 2500; $i++) {
    $pontos[] = [-21.0 - $i / 10000, -47.0];
}
class RotaLonga extends RotaDoPedido
{
    public static array $linha = [];
    public static function decodificar(string $polyline): array { return static::$linha; }
}
RotaLonga::$linha = $pontos;
$longa            = (new RotaLonga(new CalculoEntregas()))->doPedido(pedido('order_longa'));
confere(count($longa['linha']) <= RotaDoPedido::MAX_PONTOS + 1 && $longa['linha'][0] === $pontos[0] && end($longa['linha']) === end($pontos), 'rota longa reduzida (' . count($longa['linha']) . ' pontos), com o início e o fim');

echo '== Rota do app (MotoboyController::rota)' . PHP_EOL;
reiniciar();
Driver::$todos = [new Driver(['uuid' => 'uuid-driver_motoca', 'public_id' => 'driver_motoca', 'user_uuid' => 'usuario-motoca', 'online' => true])];
Order::$todos  = [
    pedido('order_meu'),
    pedido('order_de-outro', ['driver_assigned_uuid' => 'uuid-driver_outro']),
    pedido('order_aberto', ['status' => 'dispatched', 'adhoc' => true, 'driver_assigned_uuid' => null]),
    pedido('order_outra-empresa', ['company_uuid' => 'outra']),
];
$controller = new MotoboyController();
$resposta   = $controller->rota(new Request([], '12|abc'), 'order_meu', $rotas);
confere($resposta->status === 200 && $resposta->dados['pedido'] === 'order_meu' && $resposta->dados['rota']['metros'] === 3210, 'pedido dele: a rota');
confere($resposta->dados['situacao'] === 'coleta', 'situação com o pedido dele aceito (started): coleta (amarelo)');
confere($controller->rota(new Request([], '12|abc'), 'uuid-order_aberto', $rotas)->status === 200, 'pedido aberto, achado também pelo uuid');
confere($controller->rota(new Request([], '12|abc'), 'order_de-outro', $rotas)->status === 404, 'pedido de outro motoboy: 404');
confere($controller->rota(new Request([], '12|abc'), 'uuid-order_outra-empresa', $rotas)->status === 404, 'pedido de outra empresa: 404');
confere($controller->rota(new Request([], 'flb_live_abc'), 'order_meu', $rotas)->status === 403, 'chave de API: 403');

Order::$todos[0]->status = 'enroute';
confere($controller->rota(new Request([], '12|abc'), 'order_meu', $rotas)->dados['situacao'] === 'entrega', 'a caminho do cliente (enroute): entrega (vermelho)');
Order::$todos[0]->status = 'completed';
confere($controller->rota(new Request([], '12|abc'), 'order_meu', $rotas)->dados['situacao'] === 'livre', 'pedido concluído e online: livre (verde)');
Order::$todos[0]->status     = 'started';
Order::$todos[0]->updated_at = now()->subHours(13)->format('Y-m-d H:i:s');
confere($controller->rota(new Request([], '12|abc'), 'order_meu', $rotas)->dados['situacao'] === 'livre', 'pedido parado há mais de 12 h não ocupa o motoboy');
Driver::$todos[0]->online = false;
confere($controller->rota(new Request([], '12|abc'), 'order_meu', $rotas)->dados['situacao'] === 'offline', 'offline e sem pedido em andamento: offline (cinza)');

Order::$todos[0]->payload = new Payload(lugar('place_loja', -21.17, -47.81), null);
$resposta                 = $controller->rota(new Request([], '12|abc'), 'order_meu', $rotas);
confere($resposta->status === 200 && $resposta->dados['rota'] === null, 'pedido sem destino: 200 com rota nula');

resumo();
