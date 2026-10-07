<?php

// Distribuição de pedidos abertos: a lista "Novos pedidos" do app (GET v1/orders?adhoc=1&unassigned=1) só com a oferta
// do motoboy e os pedidos abertos a todos (FiltrarPedidosAbertosDoMotoboy).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/filtrar-pedidos-abertos.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Http\Middleware\FiltrarPedidosAbertosDoMotoboy;
use App\Support\Entregas\Distribuicao\Distribuicoes;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Teste\Banco;
use Teste\Config;

eval('namespace Illuminate\Http; class JsonResponse { public $headers = []; private string $json; public function __construct($dados = null, private int $status = 200, array $headers = []) { $this->json = json_encode($dados); $this->headers = $headers; } public function getStatusCode(): int { return $this->status; } public function getData($assoc = false) { return json_decode($this->json, $assoc); } public function setData($dados = []) { $this->json = json_encode($dados); return $this; } public function getContent() { return $this->json; } }');

const LISTA = 'Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController@query';

/** Motoboys A e B; pedidos 1 (em ofertas, oferecido a A), 2 (em ofertas, oferecido a B), 3 (aberto a todos), 4 (sem distribuição). */
function cenario(): void
{
    reiniciarFleetbase();
    reiniciarIfood();
    Config::$valores['services.entregas.distribuicao'] = '1';
    \Teste\Relogio::$agora = '2026-10-07 10:00:00';
    session(['company' => 'empresa-1', 'user' => 'u-a']);
    Driver::$todos[] = new Driver(['uuid' => 'd-a', 'public_id' => 'driver_a', 'company_uuid' => 'empresa-1', 'user_uuid' => 'u-a']);
    Driver::$todos[] = new Driver(['uuid' => 'd-b', 'public_id' => 'driver_b', 'company_uuid' => 'empresa-1', 'user_uuid' => 'u-b']);
    foreach ([1, 2, 3, 4] as $n) {
        Banco::inserir('orders', ['uuid' => "order-$n", 'public_id' => "order_$n", 'company_uuid' => 'empresa-1'], false);
    }
    $pedido = fn ($n) => new Order(['uuid' => "order-$n", 'public_id' => "order_$n", 'company_uuid' => 'empresa-1', 'adhoc' => true]);
    $d1 = Distribuicoes::criar($pedido(1));
    Distribuicoes::criarOferta($d1, ['motoboy_uuid' => 'd-a', 'tempo_s' => 400, 'encaixe' => false, 'aproximado' => false], 1);
    $d2 = Distribuicoes::criar($pedido(2));
    Distribuicoes::criarOferta($d2, ['motoboy_uuid' => 'd-b', 'tempo_s' => 500, 'encaixe' => false, 'aproximado' => false], 1);
    $d3 = Distribuicoes::criar($pedido(3));
    Distribuicoes::mudarFase($d3->id, 'aberta', 'fila_esgotada');
}

function listar(string $token, array $query = ['adhoc' => 1, 'unassigned' => 1, 'nearby' => 'driver_a'], $resposta = null, string $acao = LISTA)
{
    $request         = new Request($query);
    $request->token  = $token;
    $request->rota   = new Route($acao, []);
    $request->metodo = 'GET';
    $resposta      ??= new JsonResponse(array_map(fn ($n) => ['id' => "order_$n", 'status' => 'dispatched', 'adhoc' => true], [1, 2, 3, 4]));

    return (new FiltrarPedidosAbertosDoMotoboy())->handle($request, fn () => $resposta);
}

// a produção devolve array simples (JsonResource::withoutWrapping()); o `{data: [...]}` é o caso secundário
function itens($resposta): array { $c = $resposta->getData(true); return isset($c['data']) ? $c['data'] : $c; }
function ids($resposta): array { return array_column(itens($resposta), 'id'); }

echo '== A lista do motoboy A' . PHP_EOL;
cenario();
$resposta = listar('12|token-do-motoboy-a');
confere(ids($resposta) === ['order_1', 'order_3', 'order_4'], 'A vê a oferta dele, o aberto a todos e o sem distribuição; não vê a oferta de B (' . json_encode(ids($resposta)) . ')');
$item = itens($resposta)[0];
confere($item['entregas_oferta']['vence_em'] === Distribuicoes::data('2026-10-07 10:00:30')->toIso8601String() && $item['entregas_oferta']['tempo_estimado_s'] === 400, 'a oferta dele traz entregas_oferta (vence_em ISO e tempo) (' . json_encode($item['entregas_oferta'] ?? null) . ')');
confere(($item['entregas_oferta']['segundos_restantes'] ?? null) === 30, 'segundos_restantes = 30 faltando 30 s (' . json_encode($item['entregas_oferta']['segundos_restantes'] ?? null) . ')');
\Teste\Relogio::$agora = '2026-10-07 10:00:20';
confere((itens(listar('12|token-do-motoboy-a'))[0]['entregas_oferta']['segundos_restantes'] ?? null) === 10, 'com o relógio 10 s antes do vencimento: segundos_restantes = 10');
\Teste\Relogio::$agora = '2026-10-07 10:01:00';
confere((itens(listar('12|token-do-motoboy-a'))[0]['entregas_oferta']['segundos_restantes'] ?? null) === 0, 'oferta já vencida: segundos_restantes = 0');
\Teste\Relogio::$agora = '2026-10-07 10:00:00';
confere(!isset(itens($resposta)[1]['entregas_oferta']), 'os outros não trazem entregas_oferta');

cenario();
session(['user' => 'u-b']);
confere(ids(listar('13|token-do-motoboy-b', ['adhoc' => 1, 'unassigned' => 1, 'nearby' => 'driver_b'])) === ['order_2', 'order_3', 'order_4'], 'B vê a dele');

echo '== Quando não filtra' . PHP_EOL;
cenario();
confere(ids(listar('flb_live_chave-do-apk')) === ['order_1', 'order_2', 'order_3', 'order_4'], 'sem motoboy na sessão (chave de API): lista inteira');
confere(ids(listar('12|token-do-motoboy-a', ['nearby' => 'driver_a'])) === ['order_1', 'order_2', 'order_3', 'order_4'], 'sem adhoc=1&unassigned=1: lista inteira');
confere(ids(listar('12|token-do-motoboy-a', ['adhoc' => 1, 'unassigned' => 1], null, 'Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController@find')) === ['order_1', 'order_2', 'order_3', 'order_4'], 'outra ação: lista inteira');
Config::$valores['services.entregas.distribuicao'] = '';
confere(ids(listar('12|token-do-motoboy-a')) === ['order_1', 'order_2', 'order_3', 'order_4'], 'desligada: lista inteira');
Config::$valores['services.entregas.distribuicao'] = '1';

cenario();
$lista = new JsonResponse(['data' => [['id' => 'order_2'], ['id' => 'order_3']]]);
confere(ids(listar('12|token-do-motoboy-a', ['adhoc' => 1, 'unassigned' => 1], $lista)) === ['order_3'] && isset($lista->getData(true)['data']), 'corpo {data: [...]} também é filtrado e continua com o envelope');

cenario();
Banco::$consultadas = [];
listar('12|token-do-motoboy-a', ['adhoc' => 1, 'unassigned' => 1], new JsonResponse([]));
listar('12|token-do-motoboy-a', ['adhoc' => 1, 'unassigned' => 1], new JsonResponse([['status' => 'x']]));
confere(Banco::$consultadas === [] && !isset(Banco::$consultadas['drivers']), 'lista vazia ou sem id: sai antes de buscar o motoboy e o banco');
$texto = 'não é json';
confere(listar('12|token-do-motoboy-a', ['adhoc' => 1, 'unassigned' => 1], $texto) === $texto, 'resposta que não é JsonResponse: devolvida como veio');

cenario();
Banco::$falharAoConsultar['entregas_distribuicoes'] = new \RuntimeException('banco fora');
$resposta = listar('12|token-do-motoboy-a');
confere(ids($resposta) === ['order_1', 'order_2', 'order_3', 'order_4'] && logou('filtro da lista', 'warning'), 'erro no banco: a resposta original e o log');
Banco::$falharAoConsultar = [];

echo '== Rodadas: lista fechada × aberta, dispensados e entregas_distribuicao' . PHP_EOL;

// o recurso do Fleet-Ops (Http\Resources\v1\Order) só com o que o teste confere
eval('namespace Fleetbase\FleetOps\Http\Resources\v1; class Order { public static array $resolvidos = []; public static ?string $falharPara = null; public function __construct(public $resource) {} public function resolve($request = null) { if (self::$falharPara === $this->resource->public_id) { throw new \LogicException("recurso"); } self::$resolvidos[] = $this->resource->public_id; return ["id" => $this->resource->public_id, "status" => $this->resource->status, "adhoc" => $this->resource->adhoc]; } }');

/** O Payload do Fleet-Ops só com a coleta (getPickupLocation, como o Payload::getPickupLocation). */
function payloadCom(object $local): object
{
    return new class($local) { public object $pickup; public function __construct(object $local) { $this->pickup = (object) ['location' => $local]; } public function getPickupLocation() { return $this->pickup->location; } };
}

function ponto(array $p): object
{
    return new class($p) { public function __construct(private array $p) {} public function getLat() { return $this->p[0]; } public function getLng() { return $this->p[1]; } };
}

/** O cenário de sempre com as rodadas ligadas e A parado em (-21.17, -47.81). */
function cenarioRodadas(): void
{
    cenario();
    Config::$valores['services.entregas.distribuicao_rodadas'] = '1';
    Driver::$todos[0]->location = ponto([-21.1700, -47.8100]);
}

function item(array $itens, string $id): ?array
{
    foreach ($itens as $item) {
        if (($item['id'] ?? null) === $id) {
            return $item;
        }
    }

    return null;
}

cenarioRodadas();
$itens = itens(listar('12|token-do-motoboy-a'));
confere(array_column($itens, 'id') === ['order_1', 'order_3', 'order_4'], 'lista fechada (pedido 2, oferta de B): A não vê, como hoje (' . json_encode(array_column($itens, 'id')) . ')');
confere((item($itens, 'order_1')['entregas_distribuicao'] ?? null) === true && isset(item($itens, 'order_1')['entregas_oferta']), 'a oferta dele: entregas_oferta e entregas_distribuicao');
confere(!isset(item($itens, 'order_3')['entregas_distribuicao']) && !isset(item($itens, 'order_4')['entregas_distribuicao']), 'fora de ofertas (aberta) e sem distribuição: sem entregas_distribuicao');

Distribuicoes::abrirLista(2);
$itens = itens(listar('12|token-do-motoboy-a'));
confere(array_column($itens, 'id') === ['order_1', 'order_2', 'order_3', 'order_4'] && (item($itens, 'order_2')['entregas_distribuicao'] ?? null) === true && !isset(item($itens, 'order_2')['entregas_oferta']), 'lista aberta: A vê o pedido 2 (oferta de B), sem entregas_oferta');
Distribuicoes::registrarResposta(Distribuicoes::porId(2), 'd-a', 'dispensada');
confere(ids(listar('12|token-do-motoboy-a')) === ['order_1', 'order_3', 'order_4'], 'A dispensou o pedido 2 nesta volta: some da lista dele');
session(['user' => 'u-b']);
confere(ids(listar('13|token-do-motoboy-b', ['adhoc' => 1, 'unassigned' => 1, 'nearby' => 'driver_b'])) === ['order_2', 'order_3', 'order_4'], 'B (com a oferta do 2) continua vendo; o pedido 1 (lista fechada, oferta de A) não');
session(['user' => 'u-a']);
Distribuicoes::novaVolta(2, 2);
confere(ids(listar('12|token-do-motoboy-a')) === ['order_1', 'order_2', 'order_3', 'order_4'], 'volta nova: A volta a ver o pedido 2');

echo '== Rodadas: pedidos com a lista aberta além de R (até 2R)' . PHP_EOL;
/** Pedido aberto da empresa com a coleta a $km ao norte de A, em distribuição; com a lista aberta se pedido. */
function pedidoLonge(int $n, float $km, bool $listaAberta, array $extra = []): void
{
    $pedido          = new Order($extra + ['uuid' => "order-$n", 'public_id' => "order_$n", 'company_uuid' => 'empresa-1', 'adhoc' => true, 'status' => 'dispatched', 'driver_assigned_uuid' => null]);
    $pedido->payload = payloadCom(ponto([-21.1700 + 0.009 * $km, -47.8100]));
    Order::$todos[]  = $pedido;
    $d               = Distribuicoes::criar($pedido);
    if ($listaAberta) {
        Distribuicoes::abrirLista($d->id);
    }
}

cenarioRodadas();
pedidoLonge(5, 10, true);                                   // 10 km: dentro de 2R (12 km)
pedidoLonge(6, 13, true);                                   // 13 km: fora de 2R
pedidoLonge(7, 3, false);                                   // lista fechada
pedidoLonge(8, 5, true, ['driver_assigned_uuid' => 'd-b']); // já tem motoboy
pedidoLonge(9, 8, true, ['status' => 'canceled']);          // encerrado
\Fleetbase\FleetOps\Http\Resources\v1\Order::$resolvidos = [];
$itens = itens(listar('12|token-do-motoboy-a'));
$resolvidos = \Fleetbase\FleetOps\Http\Resources\v1\Order::$resolvidos;
confere(Order::$carregados === ['order-5'] && $resolvidos === ['order_5'], 'só o pedido que entra carrega as relações do recurso e é serializado; o fora de 2R, o com motoboy e o encerrado não (' . json_encode([Order::$carregados, $resolvidos]) . ')');
confere(array_column($itens, 'id') === ['order_1', 'order_3', 'order_4', 'order_5'], 'acrescenta só o pedido 5 (lista aberta, coleta a 10 km ≤ 2R, sem motoboy) (' . json_encode(array_column($itens, 'id')) . ')');
confere(item($itens, 'order_5') === ['id' => 'order_5', 'status' => 'dispatched', 'adhoc' => true, 'entregas_distribuicao' => true], 'no formato do recurso do Fleet-Ops, com entregas_distribuicao (' . json_encode(item($itens, 'order_5')) . ')');
confere(ids(listar('12|token-do-motoboy-a', ['adhoc' => 1, 'unassigned' => 1], new JsonResponse([]))) === ['order_5'], 'lista do Fleet-Ops vazia: ainda acrescenta');
Distribuicoes::registrarResposta(Distribuicoes::doPedido('order-5'), 'd-a', 'dispensada');
confere(!in_array('order_5', ids(listar('12|token-do-motoboy-a')), true), 'dispensado nesta volta: não acrescenta');

cenarioRodadas();
pedidoLonge(5, 10, true);
Distribuicoes::criarOferta(Distribuicoes::doPedido('order-5'), ['motoboy_uuid' => 'd-a', 'tempo_s' => 900, 'encaixe' => false, 'aproximado' => true], 1, 1, 2, 9000);
$item5 = item(itens(listar('12|token-do-motoboy-a')), 'order_5');
confere(($item5['entregas_oferta']['segundos_restantes'] ?? null) === 20 && ($item5['entregas_distribuicao'] ?? null) === true, 'a oferta dele além de R (rodada 2) também vem, com entregas_oferta de 20 s');

cenarioRodadas();
Driver::$todos[0]->location = null;
pedidoLonge(5, 10, true);
confere(ids(listar('12|token-do-motoboy-a')) === ['order_1', 'order_3', 'order_4'], 'motoboy sem posição: nada a acrescentar');

cenario(); // rodadas desligadas
Driver::$todos[0]->location = ponto([-21.1700, -47.8100]);
pedidoLonge(5, 10, true);
$itens = itens(listar('12|token-do-motoboy-a'));
confere(array_column($itens, 'id') === ['order_1', 'order_3', 'order_4'] && !isset(item($itens, 'order_1')['entregas_distribuicao']), 'rodadas desligadas: nada muda (sem acréscimo nem entregas_distribuicao)');

echo '== Rodadas: falha no acréscimo não derruba o filtro principal' . PHP_EOL;
cenarioRodadas();
pedidoLonge(5, 10, true);
Order::$falharLoadMissing = new \RuntimeException('relação');
$itens = itens(listar('12|token-do-motoboy-a'));
confere(array_column($itens, 'id') === ['order_1', 'order_3', 'order_4'] && (item($itens, 'order_1')['entregas_distribuicao'] ?? null) === true
    && logou('filtro da lista: acréscimo: RuntimeException', 'warning') && !logou('filtro da lista: RuntimeException'),
    'loadMissing lança: sem o acréscimo, a lista principal filtrada (oferta de B escondida, entregas_distribuicao) e o log do acréscimo (' . json_encode(array_column($itens, 'id')) . ')');
Order::$falharLoadMissing = null;

cenarioRodadas();
pedidoLonge(5, 10, true);
pedidoLonge(10, 4, true);
\Fleetbase\FleetOps\Http\Resources\v1\Order::$falharPara = 'order_5';
$itens = itens(listar('12|token-do-motoboy-a'));
confere(array_column($itens, 'id') === ['order_1', 'order_3', 'order_4', 'order_10'] && logou('filtro da lista: acréscimo: LogicException', 'warning'),
    'o recurso lança num pedido: só ele fica de fora (' . json_encode(array_column($itens, 'id')) . ')');
\Fleetbase\FleetOps\Http\Resources\v1\Order::$falharPara = null;

echo '== Rodadas: a oferta dele fora de 2R, mesmo com a lista fechada' . PHP_EOL;
cenarioRodadas();
pedidoLonge(11, 20, false); // 20 km, lista fechada
Distribuicoes::criarOferta(Distribuicoes::doPedido('order-11'), ['motoboy_uuid' => 'd-a', 'tempo_s' => 1800, 'encaixe' => false, 'aproximado' => true], 1, 1, 3, 12000);
$item11 = item(itens(listar('12|token-do-motoboy-a')), 'order_11');
confere(isset($item11['entregas_oferta']) && ($item11['entregas_distribuicao'] ?? null) === true, 'A tem a oferta do pedido 11 (20 km, lista fechada): vem, com entregas_oferta (' . json_encode($item11) . ')');
session(['user' => 'u-b']);
confere(!in_array('order_11', ids(listar('13|token-do-motoboy-b', ['adhoc' => 1, 'unassigned' => 1, 'nearby' => 'driver_b'])), true), 'B não vê a oferta de A');
session(['user' => 'u-a']);

cenarioRodadas();
pedidoLonge(11, 20, false, ['driver_assigned_uuid' => 'd-b']);
Distribuicoes::criarOferta(Distribuicoes::doPedido('order-11'), ['motoboy_uuid' => 'd-a', 'tempo_s' => 1800, 'encaixe' => false, 'aproximado' => true], 1, 1, 3, 12000);
confere(!in_array('order_11', ids(listar('12|token-do-motoboy-a')), true), 'oferta dele em pedido que já tem motoboy: não entra');

echo '== Rodadas: sem duplicata e sem outra empresa' . PHP_EOL;
cenarioRodadas();
foreach ([1, 2] as $n) { // os pedidos 1 (oferta de A) e 2 (lista aberta) vieram na lista do Fleet-Ops e estão também nos models
    $pedido          = new Order(['uuid' => "order-$n", 'public_id' => "order_$n", 'company_uuid' => 'empresa-1', 'adhoc' => true, 'status' => 'dispatched', 'driver_assigned_uuid' => null]);
    $pedido->payload = payloadCom(ponto([-21.1710, -47.8100]));
    Order::$todos[]  = $pedido;
}
Distribuicoes::abrirLista(2);
confere(ids(listar('12|token-do-motoboy-a')) === ['order_1', 'order_2', 'order_3', 'order_4'], 'o que veio na lista do Fleet-Ops não é acrescentado de novo');

cenarioRodadas();
pedidoLonge(12, 3, true, ['company_uuid' => 'empresa-2']);
Distribuicoes::criarOferta(Distribuicoes::doPedido('order-12'), ['motoboy_uuid' => 'd-a', 'tempo_s' => 300, 'encaixe' => false, 'aproximado' => true], 1, 1, 1, 6000);
confere(!in_array('order_12', ids(listar('12|token-do-motoboy-a')), true), 'pedido de outra empresa (lista aberta e até oferta para A): não entra');

echo '== Fleet-Ops: as relações carregadas existem no Order (cópia em packages/)' . PHP_EOL;
$fonteDoOrder = file_get_contents('/repo/packages/fleetops/server/src/Models/Order.php');
preg_match_all('/^    use (\w+);/m', $fonteDoOrder, $traits);
foreach ($traits[1] as $trait) { // os traits que o Order usa (do Fleet-Ops ou do core)
    foreach (["/repo/packages/fleetops/server/src/Traits/$trait.php", "/repo/packages/core-api/src/Traits/$trait.php"] as $arquivo) {
        if (is_file($arquivo)) {
            $fonteDoOrder .= file_get_contents($arquivo);
        }
    }
}
foreach (array_merge(FiltrarPedidosAbertosDoMotoboy::RELACOES_DO_FILTRO, FiltrarPedidosAbertosDoMotoboy::RELACOES_DO_RECURSO) as $relacao) {
    confere(preg_match('/public function ' . preg_quote($relacao, '/') . '\s*\(/', $fonteDoOrder) === 1, "Order::{$relacao}() existe");
}

resumo();
