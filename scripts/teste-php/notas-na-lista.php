<?php

// Integração iFood (etapa 4): o IncluirNotasNaListaDePedidos põe o `notes` nas listas de pedidos do console (o recurso
// enxuto do Fleet-Ops não traz), para o selo "iFood #N" (notas "iFood #N" + internal_id).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/notas-na-lista.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Http\Middleware\IncluirNotasNaListaDePedidos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Teste\Banco;

// o JsonResponse do Laravel: o corpo fica em texto JSON (getData decodifica, setData codifica de novo) e os cabeçalhos e o
// status ficam como estão
eval('namespace Illuminate\Http; class JsonResponse { public $headers = []; private string $json; public function __construct($dados = null, private int $status = 200, array $headers = []) { $this->json = json_encode($dados); $this->headers = $headers; } public function getStatusCode(): int { return $this->status; } public function getData($assoc = false) { return json_decode($this->json, $assoc); } public function setData($dados = []) { $this->json = json_encode($dados); return $this; } public function getContent() { return $this->json; } }');

const CONSOLE = 'Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController@';
const AO_VIVO = 'Fleetbase\FleetOps\Http\Controllers\Internal\v1\LiveController@orders';

/** Três pedidos da empresa-1 (notas, uma delas do iFood) e um da empresa-2. */
function cenario(): void
{
    reiniciarFleetbase();
    reiniciarIfood();
    session(['company' => 'empresa-1']);
    Banco::inserir('orders', ['uuid' => 'order-1', 'company_uuid' => 'empresa-1', 'internal_id' => '4821', 'notes' => 'iFood #4821'], false);
    Banco::inserir('orders', ['uuid' => 'order-2', 'company_uuid' => 'empresa-1', 'internal_id' => null, 'notes' => 'portão azul'], false);
    Banco::inserir('orders', ['uuid' => 'order-3', 'company_uuid' => 'empresa-1', 'internal_id' => null, 'notes' => null], false);
    Banco::inserir('orders', ['uuid' => 'order-4', 'company_uuid' => 'empresa-2', 'internal_id' => '77', 'notes' => 'iFood #77'], false);
    Banco::$consultadas = [];
}

/** Um item como o do recurso enxuto: tem uuid e internal_id, não tem notes. */
function item(string $uuid, ?string $interno = null): array
{
    return ['uuid' => $uuid, 'internal_id' => $interno, 'status' => 'created', 'meta' => ['_index_resource' => true]];
}

/** Roda o middleware; devolve a resposta (a original, ou a mesma com o corpo reescrito). */
function rodar(string $acao, JsonResponse $resposta, string $metodo = 'GET', array $dados = []): JsonResponse
{
    $request         = new Request($dados);
    $request->metodo = $metodo;
    $request->rota   = new Route($acao);

    return (new IncluirNotasNaListaDePedidos())->handle($request, fn () => $resposta);
}

/** Quantas vezes a tabela orders foi consultada desde o cenário. */
function consultas(): int
{
    return count(array_filter(Banco::$consultadas, fn ($tabela) => $tabela === 'orders'));
}

echo '== Lista do console (GET int/v1/orders)' . PHP_EOL;
cenario();
$corpo = ['orders' => [item('order-1', '4821'), item('order-2'), item('order-3'), item('order-4', '77'), item('order-5')], 'meta' => ['total' => 5], 'links' => ['next' => null]];
$resposta = rodar(CONSOLE . 'queryRecord', new JsonResponse($corpo, 200, ['X-Teste' => 'sim']));
$lista = $resposta->getData(true)['orders'];
confere($lista[0]['notes'] === 'iFood #4821' && $lista[0]['internal_id'] === '4821', 'pedido do iFood: recebe as notas "iFood #4821"');
confere($lista[1]['notes'] === 'portão azul', 'outro pedido: recebe as notas dele');
confere(!array_key_exists('notes', $lista[2]), 'pedido com notas nulas: sem a chave (nada a dizer)');
confere(!array_key_exists('notes', $lista[3]), 'pedido de OUTRA empresa: não recebe nota (filtro da empresa)');
confere(!array_key_exists('notes', $lista[4]), 'pedido que não existe no banco: sem nota');
confere($resposta->getStatusCode() === 200 && $resposta->headers === ['X-Teste' => 'sim'], 'status e cabeçalhos preservados');
confere($resposta->getData(true)['meta'] === ['total' => 5] && $resposta->getData(true)['links'] === ['next' => null], 'meta e links da paginação preservados');
confere($lista[0]['meta'] === ['_index_resource' => true] && $lista[0]['status'] === 'created', 'o resto do item fica como estava');

echo '== Objeto vazio do corpo continua objeto' . PHP_EOL;
cenario();
$resposta = rodar(CONSOLE . 'queryRecord', new JsonResponse(['orders' => [item('order-1', '4821') + ['extra' => new stdClass()]]]));
confere(str_contains($resposta->getContent(), '"extra":{}') && str_contains($resposta->getContent(), 'iFood #4821'), '{} não vira [] ao reescrever');

echo '== Painel do mapa (GET int/v1/fleet-ops/live/orders)' . PHP_EOL;
cenario();
$resposta = rodar(AO_VIVO, new JsonResponse(['data' => [item('order-1', '4821'), item('order-2')]]));
$lista = $resposta->getData(true)['data'];
confere($lista[0]['notes'] === 'iFood #4821' && $lista[1]['notes'] === 'portão azul', 'formato {"data": [...]}: recebe as notas');
$resposta = rodar(AO_VIVO, new JsonResponse(['orders' => [item('order-1', '4821')]]));
confere($resposta->getData(true)['orders'][0]['notes'] === 'iFood #4821', 'a rota ao vivo com o envelope "orders": recebe as notas');
$resposta = rodar(AO_VIVO, new JsonResponse([item('order-1', '4821'), item('order-3')]));
$lista = $resposta->getData(true);
confere($lista[0]['notes'] === 'iFood #4821' && !array_key_exists('notes', $lista[1]), 'array na raiz: recebe as notas');

echo '== Não mexe no que já tem notas' . PHP_EOL;
cenario();
$resposta = rodar(CONSOLE . 'queryRecord', new JsonResponse(['orders' => [item('order-1', '4821') + ['notes' => 'já veio']]]));
confere($resposta->getData(true)['orders'][0]['notes'] === 'já veio', 'item que já tem notes: fica como veio');

echo '== Rotas e métodos fora do escopo' . PHP_EOL;
cenario();
$original = new JsonResponse(['orders' => [item('order-1', '4821')]]);
$antes    = $original->getContent();
confere(rodar(CONSOLE . 'findRecord', $original)->getContent() === $antes, 'detalhe (int/v1/orders/{id}): intocado');
confere(rodar(CONSOLE . 'queryRecord', $original, 'GET', ['single' => 'true'])->getContent() === $antes, 'lista com ?single: intocada');
confere(rodar(CONSOLE . 'queryRecord', $original, 'POST')->getContent() === $antes, 'POST: intocado');
confere(rodar(CONSOLE . 'queryRecord', $original, 'PUT')->getContent() === $antes, 'PUT: intocado');
confere(rodar('App\Outro\Controller@lista', $original)->getContent() === $antes, 'outra rota: intocada');
$semRota = new Request();
confere((new IncluirNotasNaListaDePedidos())->handle($semRota, fn () => $original)->getContent() === $antes, 'requisição sem rota: intocada');
confere($original->getContent() === $antes, 'o corpo original nunca foi reescrito');

echo '== Sem consulta quando não há o que consultar' . PHP_EOL;
cenario();
rodar(CONSOLE . 'queryRecord', new JsonResponse(['orders' => []]));
confere(consultas() === 0, 'lista vazia: não consulta');
rodar(CONSOLE . 'queryRecord', new JsonResponse(['orders' => [['id' => 'x']]]));
confere(consultas() === 0, 'itens sem uuid: não consulta');
rodar(CONSOLE . 'queryRecord', new JsonResponse(['orders' => [item('order-1', '4821') + ['notes' => 'x']]]));
confere(consultas() === 0, 'todos os itens já com notes: não consulta');
session(['company' => null]);
$resposta = rodar(CONSOLE . 'queryRecord', new JsonResponse(['orders' => [item('order-1', '4821')]]));
confere(consultas() === 0 && !array_key_exists('notes', $resposta->getData(true)['orders'][0]), 'sem empresa na sessão: não consulta');
session(['company' => 'empresa-1']);
rodar(CONSOLE . 'queryRecord', new JsonResponse(['orders' => [item('order-1', '4821'), item('order-2'), item('order-3')]]));
confere(consultas() === 1, 'lista com pedidos: UMA consulta só');
rodar(CONSOLE . 'queryRecord', new JsonResponse('texto', 200));
confere(consultas() === 1, 'corpo que não é uma lista: não consulta');
rodar(CONSOLE . 'queryRecord', new JsonResponse(['orders' => [item('order-1', '4821')]], 404));
confere(consultas() === 1, 'resposta que não é 200: não consulta');

echo '== Falha na consulta: devolve a original' . PHP_EOL;
cenario();
Banco::$falharAoConsultar['orders'] = new \RuntimeException('banco caiu: SELECT * FROM orders WHERE segredo');
$original = new JsonResponse(['orders' => [item('order-1', '4821')]]);
$antes    = $original->getContent();
$resposta = rodar(CONSOLE . 'queryRecord', $original);
confere($resposta === $original && $resposta->getContent() === $antes, 'exceção: a resposta original, sem reescrever');
$avisos = array_values(array_filter(Log::$registros, fn ($r) => $r[0] === 'warning'));
confere(count($avisos) === 1 && $avisos[0][1] === '[entregas] notas na lista de pedidos: RuntimeException', 'warning curto, só com a classe da exceção');
confere(!str_contains(json_encode(Log::$registros), 'SELECT') && !str_contains(json_encode(Log::$registros), 'segredo'), 'o log não leva a mensagem da exceção');

resumo();
