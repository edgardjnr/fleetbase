<?php

// Distribuição de pedidos abertos: a recusa pelo app (POST v1/entregas/motoboy/pedidos/{id}/recusar) e o painel do
// console (GET int/v1/entregas/pedidos/{id}/distribuicao, POST .../distribuicao/abrir).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-rotas.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Http\Controllers\Entregas\DistribuicaoController;
use App\Http\Controllers\Entregas\MotoboyController;
use App\Support\Entregas\Distribuicao\Candidatos;
use App\Support\Entregas\Distribuicao\Distribuicoes;
use App\Support\Entregas\Distribuicao\Distribuidor;
use App\Support\Entregas\Distribuicao\EstimadorDeTempo;
use App\Support\Entregas\Distribuicao\FilaDeCandidatos;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Http\Request;
use Teste\Config;

function cenario(): Distribuidor
{
    reiniciarFleetbase();
    reiniciarIfood();
    Config::$valores['services.entregas.distribuicao'] = '1';
    \Teste\Relogio::$agora = '2026-10-07 10:00:00';
    session(['company' => 'empresa-1', 'user' => 'u-a']);
    Driver::$todos[] = new Driver(['uuid' => 'd-a', 'public_id' => 'driver_a', 'company_uuid' => 'empresa-1', 'user_uuid' => 'u-a', 'name' => 'Ana']);
    Driver::$todos[] = new Driver(['uuid' => 'd-b', 'public_id' => 'driver_b', 'company_uuid' => 'empresa-1', 'user_uuid' => 'u-b', 'name' => 'Bia']);
    Order::$todos[]  = new Order(['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'adhoc' => true, 'status' => 'dispatched', 'payload' => null]);
    Candidatos::$buscarMotoboys = fn () => [];
    $dist = new Distribuidor(new FilaDeCandidatos(new EstimadorDeTempo()));
    \Teste\Container::$instancias[Distribuidor::class] = $dist;
    $d = Distribuicoes::criar(Order::$todos[0]);
    Distribuicoes::criarOferta($d, ['motoboy_uuid' => 'd-a', 'tempo_s' => 400, 'encaixe' => true, 'aproximado' => false], 1);

    return $dist;
}

function requisicao(string $token, array $dados = []): Request
{
    $request        = new Request($dados);
    $request->token = $token;

    return $request;
}

$admin = new class {
    public function isNotAdmin() { return false; }
};
$naoAdmin = new class {
    public function isNotAdmin() { return true; }
};

echo '== Recusar' . PHP_EOL;
$dist = cenario();
$resposta = (new MotoboyController())->recusar(requisicao('12|token-do-motoboy-a'), 'order_1', $dist);
confere($resposta->status === 200 && $resposta->dados === ['resultado' => 'recusada'], 'A recusa a oferta dele: 200 (' . json_encode($resposta->dados) . ')');
confere(Distribuicoes::oferta(1)->resposta === 'recusada', 'a oferta fica recusada');
confere(Distribuicoes::doPedido('order-1')->fase === 'aberta', 'sem outro candidato: aberta a todos');

$dist = cenario();
session(['user' => 'u-b']);
$resposta = (new MotoboyController())->recusar(requisicao('13|token-do-motoboy-b'), 'order_1', $dist);
confere($resposta->status === 409 && $resposta->dados === ['errors' => ['Esta oferta não está mais com você.']], 'B não tem oferta: 409');
confere((new MotoboyController())->recusar(requisicao('flb_live_chave'), 'order_1', $dist)->status === 403, 'chave de API: 403');
confere((new MotoboyController())->recusar(requisicao('13|token-do-motoboy-b'), 'order_9', $dist)->status === 404, 'pedido inexistente: 404');

echo '== Painel' . PHP_EOL;
$dist = cenario();
\Fleetbase\Support\Auth::$usuario = $admin;
$resposta = (new DistribuicaoController())->painel(requisicao('sessao'), 'order_1');
confere($resposta->status === 200 && $resposta->dados['fase'] === 'ofertas' && $resposta->dados['oferta']['motoboy'] === 'Ana' && $resposta->dados['oferta']['vence_em'] === Distribuicoes::data('2026-10-07 10:00:30')->toIso8601String(), 'painel: fase, oferta atual com nome e vence_em (' . json_encode($resposta->dados) . ')');
confere(count($resposta->dados['historico']) === 1 && $resposta->dados['historico'][0]['resposta'] === 'pendente' && $resposta->dados['historico'][0]['encaixe'] === true, 'histórico das ofertas');
confere($resposta->dados['fila'] === [], 'fila (JSON gravado; vazia aqui)');
confere($resposta->dados['ligada'] === true, 'painel: ligada = true com a chave ligada');
Config::$valores['services.entregas.distribuicao'] = '';
confere((new DistribuicaoController())->painel(requisicao('sessao'), 'order_1')->dados['ligada'] === false, 'painel: ligada = false com a chave desligada (o console não relê nem oferece o Abrir)');
Config::$valores['services.entregas.distribuicao'] = '1';

$resposta = (new DistribuicaoController())->abrir(requisicao('sessao'), 'order_1', $dist);
confere($resposta->status === 200 && $resposta->dados['fase'] === 'aberta' && $resposta->dados['motivo'] === 'aberta_pela_central', 'abrir: fase aberta pela central (' . json_encode($resposta->dados) . ')');
confere((new DistribuicaoController())->abrir(requisicao('sessao'), 'order_1', $dist)->status === 409, 'já aberta: 409');
confere((new DistribuicaoController())->painel(requisicao('sessao'), 'order_9')->status === 404, 'pedido inexistente: 404');
Order::$todos[] = new Order(['uuid' => 'order-2', 'public_id' => 'order_2', 'company_uuid' => 'empresa-1']);
confere((new DistribuicaoController())->painel(requisicao('sessao'), 'order_2')->dados === ['distribuicao' => false], 'pedido sem distribuição: {distribuicao: false}');

\Fleetbase\Support\Auth::$usuario = $naoAdmin;
confere((new DistribuicaoController())->painel(requisicao('sessao'), 'order_1')->status === 403, 'não admin: 403');
confere((new DistribuicaoController())->abrir(requisicao('sessao'), 'order_1', $dist)->status === 403, 'não admin não abre: 403');

echo '== Depois do aceite, outra empresa e trava ocupada' . PHP_EOL;
$dist = cenario();
\Fleetbase\Support\Auth::$usuario = $admin;
$dist->registrarAceite(Distribuicoes::oferta(1));
$resposta = (new DistribuicaoController())->painel(requisicao('sessao'), 'order_1');
confere($resposta->status === 200 && $resposta->dados['fase'] === 'encerrada' && $resposta->dados['motivo'] === 'aceita' && $resposta->dados['oferta'] === null && $resposta->dados['historico'][0]['resposta'] === 'aceita', 'painel depois do aceite: encerrada, aceita, sem oferta atual, histórico (' . json_encode($resposta->dados) . ')');

Order::$todos[] = new Order(['uuid' => 'order-x', 'public_id' => 'order_x', 'company_uuid' => 'empresa-2']);
confere((new DistribuicaoController())->painel(requisicao('sessao'), 'order_x')->status === 404, 'painel: pedido de outra empresa: 404');
confere((new MotoboyController())->recusar(requisicao('12|token-do-motoboy-a'), 'order_x', $dist)->status === 404, 'recusar: pedido de outra empresa: 404');

$dist = cenario();
\Fleetbase\Support\Auth::$usuario = $admin;
\Teste\Trava::$ocupadas['entregas:pedido:order-1'] = true;
confere((new MotoboyController())->recusar(requisicao('12|token-do-motoboy-a'), 'order_1', $dist)->status === 503, 'recusar com a trava ocupada: 503');
confere((new DistribuicaoController())->abrir(requisicao('sessao'), 'order_1', $dist)->status === 503, 'abrir com a trava ocupada: 503');
unset(\Teste\Trava::$ocupadas['entregas:pedido:order-1']);

resumo();
