<?php

// Aceite do motoboy (POST v1/orders/{id}/start) e cancelamento da API v1 com a trava do pedido (BarrarAceiteDePedidoEncerrado):
// pedido encerrado barrado, pedido que a central/o líder passou para outro motoboy barrado, e o que continua passando.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/barrar-aceite.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Http\Middleware\BarrarAceiteDePedidoEncerrado;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Teste\Trava;
use App\Support\Entregas\Distribuicao\Distribuicoes;
use Illuminate\Support\Facades\DB;
use Teste\Config;

const API = 'Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController@';

/** Dois motoboys (A e B) e o pedido order_1 com o estado pedido. */
function cenario(array $pedido = []): void
{
    reiniciarFleetbase();
    reiniciarIfood();
    session(['company' => 'empresa-1', 'user' => 'u-a']);
    Driver::$todos[] = new Driver(['uuid' => 'd-a', 'public_id' => 'driver_a', 'company_uuid' => 'empresa-1', 'user_uuid' => 'u-a', 'name' => 'A']);
    Driver::$todos[] = new Driver(['uuid' => 'd-b', 'public_id' => 'driver_b', 'company_uuid' => 'empresa-1', 'user_uuid' => 'u-b', 'name' => 'B']);
    Order::$todos[]  = new Order($pedido + ['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'status' => 'dispatched', 'adhoc' => false, 'driver_assigned_uuid' => 'd-b']);
}

/** Roda o middleware: $token = o bearer ("12|x" é de motoboy; sem "|" é chave de API). Devolve a resposta ou 'passou'. */
function aceitar(string $token, array $dados = [], string $acao = API . 'startOrder', $proximo = null)
{
    $request        = new Request($dados);
    $request->token = $token;
    $request->rota  = new Route($acao, ['id' => 'order_1']);

    return (new BarrarAceiteDePedidoEncerrado())->handle($request, fn () => is_callable($proximo) ? $proximo() : ($proximo ?? 'passou'));
}

const TOKEN_A = '12|token-do-motoboy-a';

echo '== Pedido encerrado' . PHP_EOL;
cenario(['status' => 'canceled']);
$resposta = aceitar(TOKEN_A);
confere($resposta->status === 400 && $resposta->dados === ['error' => 'Este pedido foi cancelado.', 'errors' => ['Este pedido foi cancelado.']], 'cancelado: barrado');
cenario(['status' => 'completed']);
confere((aceitar(TOKEN_A)->dados['error'] ?? null) === 'Este pedido já foi encerrado.', 'concluído: barrado');

echo '== Pedido passado para outro motoboy (líder ou central)' . PHP_EOL;
cenario();
Log::$registros = [];
$resposta       = aceitar(TOKEN_A);
confere($resposta->status === 409 && $resposta->dados === ['error' => 'Este pedido passou para outro motoboy.', 'errors' => ['Este pedido passou para outro motoboy.']], 'o motoboy A tenta aceitar o pedido atribuído a B: 409');
confere(count(array_filter(Log::$registros, fn ($linha) => str_contains(json_encode($linha), 'passou para outro'))) === 1, 'fica no log');
confere(!isset(Trava::$ocupadas['entregas:pedido:order-1']), 'a trava é solta');


cenario();
session(['user' => 'u-b']);
confere(aceitar('13|token-do-motoboy-b') === 'passou', 'o próprio motoboy atribuído (B) aceita: passa');
confere(aceitar('13|token-do-motoboy-b', ['assign' => 'driver_b']) === 'passou', 'B com assign = ele mesmo: passa');

cenario(['adhoc' => true, 'driver_assigned_uuid' => null]);
confere(aceitar(TOKEN_A, ['assign' => 'driver_a']) === 'passou', 'pedido aberto (adhoc): passa, o startOrder atribui o assign');
cenario(['adhoc' => true, 'driver_assigned_uuid' => 'd-b']);
confere(aceitar(TOKEN_A, ['assign' => 'driver_a']) === 'passou', 'pedido aberto, mesmo com motoboy gravado: passa (o startOrder troca pelo assign)');

cenario(['driver_assigned_uuid' => null]);
confere(aceitar(TOKEN_A) === 'passou', 'sem motoboy atribuído e não aberto: passa (o startOrder responde "No driver assigned")');

cenario();
confere(aceitar('flb_live_chave-do-apk') === 'passou', 'sem motoboy da sessão (chave de API) e sem assign: passa');
$resposta = aceitar('flb_live_chave-do-apk', ['assign' => 'driver_a']);
confere($resposta->status === 409 && $resposta->dados === ['error' => 'Este pedido passou para outro motoboy.', 'errors' => ['Este pedido passou para outro motoboy.']], 'assign de outro motoboy num pedido não aberto atribuído a B: 409');
confere(aceitar('flb_live_chave-do-apk', ['assign' => 'driver_b']) === 'passou', 'assign igual ao atribuído: passa');
confere(aceitar('flb_live_chave-do-apk', ['assign' => '']) === 'passou', 'assign vazio: passa');

cenario();
session(['user' => 'u-sem-cadastro']);
confere(aceitar(TOKEN_A) === 'passou', 'usuário da sessão sem cadastro de motoboy: passa (nada a comparar)');

echo '== Trava e cancelamento' . PHP_EOL;
cenario();
Trava::$ocupadas['entregas:pedido:order-1'] = true;
$resposta = aceitar(TOKEN_A);
confere($resposta->status === 409 && str_contains($resposta->dados['error'], 'sendo atualizado'), 'trava ocupada: 409 "Tente aceitar de novo"');
cenario();
confere(aceitar(TOKEN_A, [], API . 'cancelOrder') === 'passou', 'cancelamento pela API v1 não passa pela conferência do motoboy');
confere(aceitar(TOKEN_A, [], API . 'outraAcao') === 'passou', 'outra ação: passa direto');

echo '== Distribuição: só quem tem a oferta aceita' . PHP_EOL;

function comDistribuicao(array $ofertas): void
{
    cenario(['adhoc' => true, 'driver_assigned_uuid' => null]);
    Config::$valores['services.entregas.distribuicao'] = '1';
    \Teste\Relogio::$agora = '2026-10-07 10:00:00';
    $d = Distribuicoes::criar(Order::$todos[0]);
    foreach ($ofertas as $i => [$motoboy, $resposta]) {
        $o = Distribuicoes::criarOferta($d, ['motoboy_uuid' => $motoboy, 'tempo_s' => 100, 'encaixe' => false, 'aproximado' => false], $i + 1);
        if ($resposta !== 'pendente') {
            Distribuicoes::responder($o->id, $resposta);
        }
    }
}

comDistribuicao([['d-a', 'pendente']]);
confere(aceitar(TOKEN_A, ['assign' => 'driver_a']) === 'passou', 'A tem a oferta pendente: passa');
confere(DB::table('entregas_ofertas')->where('id', 1)->value('resposta') === 'aceita' && DB::table('entregas_distribuicoes')->where('id', 1)->value('fase') === 'encerrada', 'aceite registrado: oferta aceita, distribuição encerrada');

comDistribuicao([['d-a', 'pendente']]);
session(['user' => 'u-b']);
$resposta = aceitar('13|token-do-motoboy-b', ['assign' => 'driver_b']);
confere($resposta->status === 409 && $resposta->dados === ['error' => 'Este pedido está sendo oferecido a outro motoboy.', 'errors' => ['Este pedido está sendo oferecido a outro motoboy.']], 'B tenta aceitar a oferta de A: 409');
confere(DB::table('entregas_ofertas')->where('id', 1)->value('resposta') === 'pendente', 'a oferta de A continua pendente');

comDistribuicao([['d-a', 'vencida']]);
confere(aceitar(TOKEN_A, ['assign' => 'driver_a']) === 'passou', 'a oferta de A venceu, mas ninguém foi oferecido depois: passa');

comDistribuicao([['d-a', 'vencida'], ['d-b', 'pendente']]);
confere(aceitar(TOKEN_A, ['assign' => 'driver_a'])->status === 409, 'a oferta de A venceu e B já foi oferecido: 409');

comDistribuicao([['d-a', 'recusada'], ['d-b', 'pendente']]);
confere(aceitar(TOKEN_A, ['assign' => 'driver_a'])->status === 409, 'A recusou: 409');

comDistribuicao([['d-a', 'pendente']]);
session(['user' => 'u-sem-cadastro']);
confere(aceitar('flb_live_chave-do-apk', ['assign' => 'driver_a']) === 'passou', 'chave de API com assign = quem tem a oferta: passa');
comDistribuicao([['d-a', 'pendente']]);
session(['user' => 'u-sem-cadastro']);
confere(aceitar('flb_live_chave-do-apk', ['assign' => 'driver_b'])->status === 409, 'chave de API com assign de outro: 409');

comDistribuicao([['d-a', 'pendente']]);
Distribuicoes::mudarFase(1, 'aberta', 'fila_esgotada');
session(['user' => 'u-b']);
confere(aceitar('13|token-do-motoboy-b', ['assign' => 'driver_b']) === 'passou', 'fase aberta: qualquer um aceita, como hoje');

comDistribuicao([['d-a', 'pendente']]);
Config::$valores['services.entregas.distribuicao'] = '';
confere(aceitar('13|token-do-motoboy-b', ['assign' => 'driver_b']) === 'passou', 'desligada: a regra não vale');
Config::$valores['services.entregas.distribuicao'] = '1';

comDistribuicao([['d-a', 'pendente']]);
$falha = response()->json(['error' => 'Order has already started.'], 400);
confere(aceitar(TOKEN_A, ['assign' => 'driver_a'], API . 'startOrder', $falha) === $falha && DB::table('entregas_ofertas')->where('id', 1)->value('resposta') === 'pendente', 'o Fleet-Ops recusou o aceite: a oferta continua pendente');

comDistribuicao([['d-a', 'pendente']]);
$resposta = aceitar(TOKEN_A, ['assign' => 'driver_b']);
confere($resposta->status === 409 && DB::table('entregas_ofertas')->where('id', 1)->value('resposta') === 'pendente', 'sessão A com assign de B: 409 e a oferta continua pendente');

comDistribuicao([['d-a', 'pendente']]);
$real = aceitar(TOKEN_A, ['assign' => 'driver_a'], API . 'startOrder', function () {
    app(\App\Support\Entregas\Distribuicao\Distribuidor::class)->encerrar('order-1', 'atribuida'); // o observador do startOrder

    return response()->json(['id' => 'order_1'], 200);
});
confere($real->status === 200
    && DB::table('entregas_ofertas')->where('id', 1)->value('resposta') === 'aceita'
    && DB::table('entregas_distribuicoes')->where('id', 1)->value('fase') === 'encerrada'
    && DB::table('entregas_distribuicoes')->where('id', 1)->value('motivo') === 'aceita', 'fluxo real: o observador encerra como atribuida e o aceite grava aceita por cima');

comDistribuicao([['d-a', 'pendente']]);
\Teste\Container::$instancias[\App\Support\Entregas\Distribuicao\Distribuidor::class] = new class extends \App\Support\Entregas\Distribuicao\Distribuidor {
    public function __construct() {}

    public function registrarAceite(object $oferta): void
    {
        throw new \RuntimeException('falhou');
    }
};
Log::$registros = [];
$ok = response()->json(['id' => 'order_1'], 200);
confere(aceitar(TOKEN_A, ['assign' => 'driver_a'], API . 'startOrder', $ok) === $ok
    && count(array_filter(Log::$registros, fn ($l) => str_contains(json_encode($l), 'falha ao registrar o aceite'))) === 1, 'registrarAceite lança: a resposta 2xx volta intacta e o warning é registrado');
unset(\Teste\Container::$instancias[\App\Support\Entregas\Distribuicao\Distribuidor::class]);

echo '== Distribuição em rodadas: lista aberta e quem chega depois' . PHP_EOL;

function comRodadas(array $ofertas, bool $listaAberta): void
{
    comDistribuicao($ofertas);
    Config::$valores['services.entregas.distribuicao_rodadas'] = '1';
    if ($listaAberta) {
        Distribuicoes::abrirLista(1);
    }
}

comRodadas([['d-a', 'pendente']], false);
session(['user' => 'u-b']);
confere(aceitar('13|token-do-motoboy-b', ['assign' => 'driver_b'])->status === 409, 'lista fechada (rodada 1 da volta 1): só quem tem a oferta, como hoje');

comRodadas([['d-a', 'pendente']], true);
session(['user' => 'u-b']);
confere(aceitar('13|token-do-motoboy-b', ['assign' => 'driver_b']) === 'passou', 'lista aberta: B aceita com a oferta de A pendente');
$linhas = DB::table('entregas_ofertas')->orderBy('id')->get()->all();
confere(count($linhas) === 2 && $linhas[0]->resposta === 'cancelada' && $linhas[1]->resposta === 'aceita_pela_lista' && $linhas[1]->motoboy_uuid === 'd-b', 'a oferta de A vira cancelada; B ganha a linha aceita_pela_lista');
confere(DB::table('entregas_distribuicoes')->where('id', 1)->value('fase') === 'encerrada' && DB::table('entregas_distribuicoes')->where('id', 1)->value('motivo') === 'aceita', 'distribuição encerrada (aceita)');

comRodadas([['d-a', 'vencida']], true);
DB::table('entregas_distribuicoes')->where('id', 1)->update(['despachada_em' => '2026-10-07 07:00:00']); // passou do limite de 1 h
session(['user' => 'u-b']);
confere(aceitar('13|token-do-motoboy-b', ['assign' => 'driver_b']) === 'passou' && DB::table('entregas_distribuicoes')->where('id', 1)->value('motivo') === 'aceita', 'depois do limite de 1 h (só na lista): B aceita pela lista');

comRodadas([['d-a', 'pendente']], true);
confere(aceitar(TOKEN_A, ['assign' => 'driver_a']) === 'passou' && DB::table('entregas_ofertas')->where('id', 1)->value('resposta') === 'aceita' && DB::table('entregas_ofertas')->count() === 1, 'lista aberta, quem tem a oferta aceita: aceita na própria oferta, sem linha nova');

comRodadas([['d-a', 'pendente']], true);
session(['user' => 'u-b']);
$falha = response()->json(['error' => 'Order has already started.'], 400);
confere(aceitar('13|token-do-motoboy-b', ['assign' => 'driver_b'], API . 'startOrder', $falha) === $falha && DB::table('entregas_ofertas')->where('id', 1)->value('resposta') === 'pendente' && DB::table('entregas_ofertas')->count() === 1, 'o Fleet-Ops recusou o aceite: nada gravado');

comRodadas([['d-a', 'pendente']], true);
Config::$valores['services.entregas.distribuicao_rodadas'] = '';
session(['user' => 'u-b']);
confere(aceitar('13|token-do-motoboy-b', ['assign' => 'driver_b'])->status === 409, 'rodadas desligadas: o lista_aberta_em não vale (só quem tem a oferta)');

comRodadas([['d-a', 'pendente']], true);
$resposta = aceitar(TOKEN_A, ['assign' => 'driver_b']);
confere($resposta->status === 409 && $resposta->dados === ['error' => 'Este pedido está sendo oferecido a outro motoboy.', 'errors' => ['Este pedido está sendo oferecido a outro motoboy.']]
    && DB::table('entregas_ofertas')->where('id', 1)->value('resposta') === 'pendente' && DB::table('entregas_ofertas')->count() === 1
    && DB::table('entregas_distribuicoes')->where('id', 1)->value('fase') === 'ofertas', 'lista aberta, sessão A com assign de B: 409, nada gravado e a oferta continua pendente');

cenario(['adhoc' => true, 'driver_assigned_uuid' => 'd-b', 'started' => true]);
Config::$valores['services.entregas.distribuicao']         = '1';
Config::$valores['services.entregas.distribuicao_rodadas'] = '1';
$resposta = aceitar(TOKEN_A, ['assign' => 'driver_a']);
confere($resposta->status === 409 && $resposta->dados === ['error' => 'Este pedido passou para outro motoboy.', 'errors' => ['Este pedido passou para outro motoboy.']], 'em rodadas: pedido aberto já aceito por B, A chega depois: 409');
session(['user' => 'u-b']);
confere(aceitar('13|token-do-motoboy-b', ['assign' => 'driver_b']) === 'passou', 'o próprio B: passa (o Fleet-Ops responde)');
Config::$valores['services.entregas.distribuicao_rodadas'] = '';
session(['user' => 'u-a']);
confere(aceitar(TOKEN_A, ['assign' => 'driver_a']) === 'passou', 'rodadas desligadas: como hoje (o Fleet-Ops responde "already started")');

resumo();
