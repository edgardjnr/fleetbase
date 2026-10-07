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

resumo();
