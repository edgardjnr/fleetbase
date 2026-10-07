<?php

// Distribuição de pedidos abertos: as tabelas (Distribuicoes) e o ciclo (Distribuidor): iniciar, avançar, recusar,
// vencer, prazo, abrir a todos, encerrar, aceite, desligada.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-ciclo.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Distribuicoes;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\DB;
use Teste\Relogio;

function pedidoAberto(array $extra = []): Order
{
    return new Order($extra + ['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'adhoc' => true, 'dispatched' => true, 'status' => 'dispatched']);
}

echo '== Distribuicoes' . PHP_EOL;
reiniciarFleetbase();
reiniciarIfood();
Relogio::$agora = '2026-10-07 10:00:00';
$d = Distribuicoes::criar(pedidoAberto());
confere($d->id === 1 && $d->fase === 'ofertas' && $d->despachada_em === '2026-10-07 10:00:00' && $d->company_uuid === 'empresa-1', 'criar: fase ofertas, despachada agora (' . json_encode($d) . ')');
confere(Distribuicoes::emOfertas('order-1') === true && Distribuicoes::emOfertas('order-2') === false, 'emOfertas');
confere(Distribuicoes::doPedido('order-1')?->id === 1, 'doPedido: a que não está encerrada');

$o = Distribuicoes::criarOferta($d, ['motoboy_uuid' => 'd-a', 'tempo_s' => 500, 'encaixe' => false, 'aproximado' => false], 1);
confere($o->resposta === 'pendente' && $o->vence_em === '2026-10-07 10:00:30' && $o->posicao === 1 && $o->tempo_estimado_s === 500, 'criarOferta: pendente, vence em 30 s (' . json_encode($o) . ')');
confere(Distribuicoes::ofertaPendente(1)?->id === $o->id, 'ofertaPendente');
confere(Distribuicoes::motoboysComOfertaPendente() === ['d-a'], 'motoboysComOfertaPendente (qualquer pedido)');

Relogio::$agora = '2026-10-07 10:00:10';
Distribuicoes::responder($o->id, 'recusada');
$o = DB::table('entregas_ofertas')->where('id', $o->id)->first();
confere($o->resposta === 'recusada' && $o->respondida_em === '2026-10-07 10:00:10', 'responder grava a resposta e a hora');
confere(Distribuicoes::motoboysQueResponderam(1) === ['d-a'] && Distribuicoes::motoboysComOfertaPendente() === [], 'quem respondeu sai dos pendentes');

$o2 = Distribuicoes::criarOferta($d, ['motoboy_uuid' => 'd-b', 'tempo_s' => 700, 'encaixe' => true, 'aproximado' => true], 2);
confere(Distribuicoes::ofertaParaAceite('order-1', 'd-b')?->id === $o2->id, 'ofertaParaAceite: a pendente dele');
confere(Distribuicoes::ofertaParaAceite('order-1', 'd-a') === null, 'quem recusou não tem oferta para aceitar');
Distribuicoes::responder($o2->id, 'vencida');
confere(Distribuicoes::ofertaParaAceite('order-1', 'd-b')?->id === $o2->id, 'vencida, mas ninguém foi oferecido depois: ainda vale');
$o3 = Distribuicoes::criarOferta($d, ['motoboy_uuid' => 'd-c', 'tempo_s' => 900, 'encaixe' => false, 'aproximado' => false], 3);
confere(Distribuicoes::ofertaParaAceite('order-1', 'd-b') === null, 'depois que outro foi oferecido, a vencida não vale mais');

Distribuicoes::gravarFila(1, [['motoboy_uuid' => 'd-c', 'tempo_s' => 900]]);
confere(json_decode(Distribuicoes::doPedido('order-1')->fila, true)[0]['tempo_s'] === 900, 'gravarFila (JSON)');

Relogio::$agora = '2026-10-07 10:01:40';
Distribuicoes::mudarFase(1, 'aberta', 'fila_esgotada');
$d = DB::table('entregas_distribuicoes')->where('id', 1)->first();
confere($d->fase === 'aberta' && $d->motivo === 'fila_esgotada' && $d->aberta_em === '2026-10-07 10:01:40' && $d->encerrada_em === null, 'mudarFase aberta grava aberta_em');
confere(Distribuicoes::cancelarPendentes(1) === 1 && DB::table('entregas_ofertas')->where('id', $o3->id)->value('resposta') === 'cancelada', 'cancelarPendentes');
confere(Distribuicoes::emOfertas('order-1') === false && Distribuicoes::doPedido('order-1')?->id === 1, 'aberta: não está em ofertas, mas é a do pedido');
Distribuicoes::mudarFase(1, 'encerrada', 'atribuida');
$d = DB::table('entregas_distribuicoes')->where('id', 1)->first();
confere($d->fase === 'encerrada' && $d->encerrada_em === '2026-10-07 10:01:40' && Distribuicoes::doPedido('order-1') === null, 'encerrada: grava encerrada_em e some do doPedido');

Relogio::$agora = '2026-10-07 10:05:00';
$d2 = Distribuicoes::criar(pedidoAberto());
Distribuicoes::criarOferta($d2, ['motoboy_uuid' => 'd-a', 'tempo_s' => 1, 'encaixe' => false, 'aproximado' => false], 1);
Relogio::$agora = '2026-10-07 10:06:00';
confere(array_map(fn ($o) => $o->id, Distribuicoes::pendentesVencidasHa(20)) === [4], 'pendentesVencidasHa: a que venceu há mais de 20 s');
confere(Distribuicoes::pendentesVencidasHa(60) === [], 'folga maior: nenhuma');
Relogio::$agora = '2026-10-07 10:08:30';
confere(array_map(fn ($d) => $d->id, Distribuicoes::emOfertasHaMais(3)) === [2], 'emOfertasHaMais: despachada há mais de 3 min');
confere(array_map(fn ($d) => $d->id, Distribuicoes::naoEncerradas()) === [2], 'naoEncerradas');
confere(Distribuicoes::data('2026-10-07 10:06:00')->toIso8601String() === \Illuminate\Support\Carbon::parse('2026-10-07 10:06:00', date_default_timezone_get())->toIso8601String() && Distribuicoes::data(null) === null, 'data: texto do banco no fuso do app');

confere(Distribuicoes::ofertaParaAceite('order-1', 'd-a')?->id === 4, 'ofertaParaAceite: em ofertas, a pendente vale');
Distribuicoes::mudarFase(2, 'aberta', 'prazo');
confere(Distribuicoes::ofertaParaAceite('order-1', 'd-a') === null, 'ofertaParaAceite: null com a distribuição aberta');
$x = Distribuicoes::data('2026-10-07 10:06:00');
$x->addMinutes(3);
confere(Distribuicoes::data('2026-10-07 10:06:00')->format('H:i:s') === '10:06:00', 'data: objeto novo a cada chamada');
echo '== Distribuidor' . PHP_EOL;
use App\Jobs\Entregas\AvancarOferta;
use App\Notifications\Entregas\OfertaDePedido;
use App\Support\Entregas\Distribuicao\Candidatos;
use App\Support\Entregas\Distribuicao\Distribuidor;
use App\Support\Entregas\Distribuicao\EstimadorDeTempo;
use App\Support\Entregas\Distribuicao\FilaDeCandidatos;
use App\Support\Entregas\Distribuicao\Pontos;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Teste\Config;
use Teste\DespachoPendente;
use Teste\Fila;
use Teste\Trava;

function ponto(array $p): object
{
    return new class($p) { public function __construct(private array $p) {} public function getLat() { return $this->p[0]; } public function getLng() { return $this->p[1]; } };
}

/** Pedido aberto com coleta e entrega e dois motoboys livres (A a 200 m, B a 1 km). Devolve o Distribuidor. */
function cenario(): Distribuidor
{
    reiniciarFleetbase();
    reiniciarIfood(); // zera o Config::$valores: a chave da distribuição vem depois
    Relogio::$agora = '2026-10-07 10:00:00';
    Config::$valores['services.entregas.distribuicao'] = '1';
    session(['company' => 'empresa-1']);
    $pedido          = pedidoAberto();
    $pedido->payload = (object) ['pickup' => (object) ['location' => ponto([-21.1700, -47.8100])], 'dropoff' => (object) ['location' => ponto([-21.1800, -47.8100])]];
    Order::$todos[]  = $pedido;
    $a = new Driver(['uuid' => 'd-a', 'public_id' => 'driver_a', 'company_uuid' => 'empresa-1', 'name' => 'Ana', 'online' => true, 'location' => ponto([-21.1682, -47.8100])]);
    $b = new Driver(['uuid' => 'd-b', 'public_id' => 'driver_b', 'company_uuid' => 'empresa-1', 'name' => 'Bia', 'online' => true, 'location' => ponto([-21.1610, -47.8100])]);
    Driver::$todos  = [$a, $b];
    Candidatos::$buscarMotoboys = fn () => [
        ['motoboy' => $a, 'posicao' => [-21.1682, -47.8100], 'distancia' => 200.0],
        ['motoboy' => $b, 'posicao' => [-21.1610, -47.8100], 'distancia' => 1000.0],
    ];
    Candidatos::$buscarParadas = fn () => [];
    $estimador = new class extends EstimadorDeTempo {
        public function matriz(array $pontos): array { $m = []; foreach ($pontos as $i => $a) { foreach ($pontos as $j => $b) { $m[$i][$j] = (float) Pontos::segundos($a, $b); } } return ['durations' => $m, 'aproximado' => false]; }
    };

    return new Distribuidor(new FilaDeCandidatos($estimador));
}

function pedidoDoCenario(): Order { return Order::$todos[0]; }
function ofertas(): array { return DB::table('entregas_ofertas')->orderBy('id')->get()->all(); }
function distribuicao(): ?object { return DB::table('entregas_distribuicoes')->where('id', 1)->first(); }
function avisos(): array { return array_map(fn ($a) => [$a[0], get_class($a[1])], Driver::$avisos); }

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
confere(distribuicao()->fase === 'ofertas' && count(ofertas()) === 1 && ofertas()[0]->motoboy_uuid === 'd-a' && ofertas()[0]->resposta === 'pendente', 'iniciar: distribuição em ofertas e a 1ª oferta ao mais rápido (A)');
confere(avisos() === [['driver_a', OfertaDePedido::class]] && Driver::$avisos[0][1]->venceEm->format('H:i:s') === '10:00:30', 'push da oferta a A, vencendo em 30 s');
confere(count(Fila::$jobs) === 1 && Fila::$jobs[0] instanceof AvancarOferta && Fila::$jobs[0]->ofertaId === 1 && DespachoPendente::$atrasos[0] === 30 && Fila::$jobs[0]->delay === 30, 'job AvancarOferta atrasado 30 s');
confere(json_decode(distribuicao()->fila, true)[0]['public_id'] === 'driver_a' && count(json_decode(distribuicao()->fila, true)) === 2, 'a fila calculada fica gravada');
confere(!isset(Trava::$ocupadas['entregas:pedido:order-1']), 'a trava do pedido é solta');
confere(logou('oferta enviada', 'info'), 'log: oferta enviada');
$motoboysNosLogs = fn () => array_values(array_filter(array_map(fn ($r) => $r[2]['motoboy'] ?? null, \Illuminate\Support\Facades\Log::$registros)));
confere(logsSem(['Ana', 'Bia', '-21.1']), 'logs só com ids e números (sem nome nem coordenada)');

// recusa de A: passa a B na hora
Relogio::$agora = '2026-10-07 10:00:10';
confere($dist->recusar('order-1', Driver::$todos[0]) === true, 'recusar: a oferta pendente dele');
confere(ofertas()[0]->resposta === 'recusada' && ofertas()[1]->motoboy_uuid === 'd-b' && ofertas()[1]->resposta === 'pendente' && ofertas()[1]->posicao === 2, 'recusou: a 2ª oferta vai a B');
confere(avisos()[1] === ['driver_b', OfertaDePedido::class], 'push a B');
confere($dist->recusar('order-1', Driver::$todos[0]) === false, 'recusar de novo (sem oferta pendente dele): false');
confere(!isset(Trava::$ocupadas['entregas:pedido:order-1']), 'recusar solta a trava');

// o job vence a oferta de B: fila esgotada → aberta a todos (OrderPing comum a todos no raio, inclusive a quem recusou)
Relogio::$agora = '2026-10-07 10:00:45';
(new AvancarOferta(2))->handle($dist);
confere(ofertas()[1]->resposta === 'vencida', 'job: a oferta pendente vence');
confere(distribuicao()->fase === 'aberta' && distribuicao()->motivo === 'fila_esgotada' && distribuicao()->aberta_em === '2026-10-07 10:00:45', 'fila esgotada: aberta a todos');
confere(array_slice(avisos(), 2) === [['driver_a', OrderPing::class], ['driver_b', OrderPing::class]], 'OrderPing comum a todos no raio');
confere(logou('aberta a todos', 'info'), 'log: aberta a todos');
confere($motoboysNosLogs() !== [] && array_filter($motoboysNosLogs(), fn ($m) => !str_starts_with($m, 'driver_')) === [] && logou('oferta vencida', 'info'), 'logs com o public_id do motoboy (inclusive oferta vencida)');
confere(array_column(json_decode(distribuicao()->fila, true), 'public_id') === ['driver_b'], 'fila vazia não apaga a última calculada (o painel a mostra)');

// job velho (oferta já respondida): nada
$antes = count(Driver::$avisos);
(new AvancarOferta(1))->handle($dist);
confere(count(Driver::$avisos) === $antes && count(ofertas()) === 2, 'job de oferta já respondida: não faz nada');

// encerrar (central atribuiu) e registrar aceite
$dist->encerrar('order-1', 'atribuida');
confere(distribuicao()->fase === 'encerrada' && distribuicao()->motivo === 'atribuida', 'encerrar: fase encerrada com o motivo');
$dist->encerrar('order-1', 'cancelada');
confere(distribuicao()->motivo === 'atribuida', 'encerrar de novo não muda o motivo');

echo '== Prazo de 3 min e o aceite' . PHP_EOL;
$dist = cenario();
$dist->iniciar(pedidoDoCenario());
Relogio::$agora = '2026-10-07 10:03:05';
$dist->avancar('order-1');
confere(distribuicao()->fase === 'aberta' && distribuicao()->motivo === 'prazo' && ofertas()[0]->resposta === 'cancelada', 'passados 3 min do despacho, abre a todos (prazo) e cancela a pendente');

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
Relogio::$agora = '2026-10-07 10:00:20';
$dist->avancar('order-1');
confere(count(ofertas()) === 1 && ofertas()[0]->resposta === 'pendente' && distribuicao()->fase === 'ofertas', 'antes do prazo, com uma oferta pendente: avancar espera');

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
$oferta = Distribuicoes::ofertaParaAceite('order-1', 'd-a');
$dist->registrarAceite($oferta);
confere(ofertas()[0]->resposta === 'aceita' && distribuicao()->fase === 'encerrada' && distribuicao()->motivo === 'aceita', 'registrarAceite: oferta aceita e distribuição encerrada (aceita)');
Relogio::$agora = '2026-10-07 10:00:30';
(new AvancarOferta(1))->handle($dist);
confere(count(ofertas()) === 1 && count(Driver::$avisos) === 1, 'o job da oferta aceita não faz nada');

echo '== Uma oferta por vez por motoboy' . PHP_EOL;
$dist = cenario();
$dist->iniciar(pedidoDoCenario());
$outro          = pedidoAberto(['uuid' => 'order-2', 'public_id' => 'order_2']);
$outro->payload = pedidoDoCenario()->payload;
Order::$todos[] = $outro;
$dist->iniciar($outro);
$doOutro = array_values(array_filter(ofertas(), fn ($o) => $o->pedido_uuid === 'order-2'));
confere(count($doOutro) === 1 && $doOutro[0]->motoboy_uuid === 'd-b', 'A tem oferta pendente do 1º pedido: o 2º vai a B');

echo '== Sem candidato, abrir pela central, redespacho, desligada' . PHP_EOL;
$dist = cenario();
Candidatos::$buscarMotoboys = fn () => [];
$dist->iniciar(pedidoDoCenario());
confere(distribuicao()->fase === 'aberta' && distribuicao()->motivo === 'sem_candidato' && ofertas() === [], 'sem candidato: abre na hora');

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
confere($dist->abrirATodos('order-1', 'aberta_pela_central') === true && distribuicao()->motivo === 'aberta_pela_central' && ofertas()[0]->resposta === 'cancelada', 'abrir pela central');
confere($dist->abrirATodos('order-1', 'aberta_pela_central') === false, 'já aberta: false');

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
Relogio::$agora = '2026-10-07 10:00:20';
$dist->iniciar(pedidoDoCenario());
confere(distribuicao()->fase === 'encerrada' && distribuicao()->motivo === 'redespachada' && DB::table('entregas_distribuicoes')->where('id', 2)->value('fase') === 'ofertas', 'redespacho: encerra a anterior e cria outra');
confere(ofertas()[0]->resposta === 'cancelada' && ofertas()[1]->distribuicao_id === 2 && ofertas()[1]->resposta === 'pendente', 'redespacho: a pendente da anterior é cancelada e a nova oferece de novo');

$dist = cenario();
Config::$valores['services.entregas.distribuicao'] = '';
confere(Distribuicao::ligada() === false, 'desligada');
$dist->iniciar(pedidoDoCenario());
confere(distribuicao() === null && Driver::$avisos === [], 'desligada: iniciar não faz nada (o listener chama o Fleet-Ops)');

echo '== Corrida com outro pedido e motoboy que sumiu' . PHP_EOL;
$dist   = cenario();
$buscar = Candidatos::$buscarMotoboys;
Candidatos::$buscarMotoboys = function ($pedido, $gpsRecente) use ($buscar) {
    if ($gpsRecente) {
        // outro pedido (outra trava) oferece a A enquanto esta fila é calculada
        Distribuicoes::criarOferta((object) ['id' => 99, 'pedido_uuid' => 'order-9'], ['motoboy_uuid' => 'd-a', 'tempo_s' => 1, 'encaixe' => false, 'aproximado' => false], 1);
    }

    return $buscar($pedido, $gpsRecente);
};
$dist->iniciar(pedidoDoCenario());
$doPedido = array_values(array_filter(ofertas(), fn ($o) => $o->pedido_uuid === 'order-1'));
confere(count($doPedido) === 1 && $doPedido[0]->motoboy_uuid === 'd-b' && avisos() === [['driver_b', OfertaDePedido::class]], 'A ganhou a oferta de outro pedido no meio-tempo: a oferta vai a B');

$dist = cenario();
Driver::$todos = [Driver::$todos[1]]; // A sumiu (apagado) depois da consulta
$dist->iniciar(pedidoDoCenario());
confere(count(ofertas()) === 1 && ofertas()[0]->motoboy_uuid === 'd-b' && distribuicao()->fase === 'ofertas', 'motoboy da fila que sumiu: tenta o próximo');

$dist = cenario();
Driver::$todos = [];
$dist->iniciar(pedidoDoCenario());
confere(ofertas() === [] && distribuicao()->fase === 'aberta' && distribuicao()->motivo === 'fila_esgotada', 'nenhum da fila existe: abre a todos (fila_esgotada)');

echo '== Pedido que já tem motoboy ou foi encerrado' . PHP_EOL;
$dist = cenario();
$dist->iniciar(pedidoDoCenario());
pedidoDoCenario()->driver_assigned_uuid = 'd-b'; // a central atribuiu sem o Order::updated (saveQuietly)
Relogio::$agora = '2026-10-07 10:00:10';
$dist->recusar('order-1', Driver::$todos[0]);
confere(distribuicao()->fase === 'encerrada' && distribuicao()->motivo === 'atribuida' && count(ofertas()) === 1 && count(Driver::$avisos) === 1, 'pedido com motoboy: encerra (atribuida) sem oferecer a mais ninguém');

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
pedidoDoCenario()->status = 'canceled';
confere($dist->abrirATodos('order-1', 'aberta_pela_central') === false && distribuicao()->motivo === 'cancelada' && count(Driver::$avisos) === 1, 'pedido cancelado: abrir a todos encerra (cancelada) sem alarme');

echo '== Falhas' . PHP_EOL;
$dist = cenario();
$quebrado = new Distribuidor(new FilaDeCandidatos(new class extends EstimadorDeTempo {
    public function matriz(array $pontos): array { throw new \RuntimeException('bug no estimador'); }
}));
$erro = excecao(fn () => $quebrado->iniciar(pedidoDoCenario()));
confere($erro instanceof \RuntimeException && $erro->getMessage() === 'bug no estimador', 'falha no ciclo do iniciar: relança a exceção original (o listener manda o alarme geral)');
confere(distribuicao()->fase === 'aberta' && distribuicao()->motivo === 'falha' && Distribuicoes::ofertaPendente(1) === null && Driver::$avisos === [], 'falha no ciclo: aberta (falha), sem oferta pendente e sem alarme (fica com o listener)');
confere(logou('falha no ciclo; aberta a todos', 'warning') && logsSem(['bug no estimador']), 'log da falha: warning só com ids e a classe');
confere(!isset(Trava::$ocupadas['entregas:pedido:order-1']), 'falha no ciclo: a trava é solta');

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
$quebrado = new Distribuidor(new FilaDeCandidatos(new class extends EstimadorDeTempo {
    public function matriz(array $pontos): array { throw new \RuntimeException('bug no estimador'); }
}));
Relogio::$agora = '2026-10-07 10:00:10';
confere($quebrado->recusar('order-1', Driver::$todos[0]) === true, 'falha depois da recusa: devolve true (a recusa foi gravada)');
confere(ofertas()[0]->resposta === 'recusada' && distribuicao()->fase === 'aberta' && distribuicao()->motivo === 'falha' && Distribuicoes::ofertaPendente(1) === null, 'falha depois da recusa: aberta (falha), sem pendente');
confere(array_slice(avisos(), 1) === [['driver_a', OrderPing::class], ['driver_b', OrderPing::class]] && logou('falha no ciclo; aberta a todos', 'warning'), 'falha depois da recusa: alarme geral e warning');
confere(!isset(Trava::$ocupadas['entregas:pedido:order-1']), 'falha depois da recusa: a trava é solta');

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
Relogio::$agora = '2026-10-07 10:00:31';
$job = new AvancarOferta(1);
confere(excecao(fn () => $job->handle($quebrado)) === null && $job->liberadoPor === null, 'falha depois do vencimento: o job não lança nem volta à fila');
confere(ofertas()[0]->resposta === 'vencida' && distribuicao()->fase === 'aberta' && distribuicao()->motivo === 'falha' && count(Driver::$avisos) === 3, 'falha depois do vencimento: aberta (falha) com o alarme geral');

$dist = cenario();
Fila::$falhar = new \RuntimeException('redis fora');
$dist->iniciar(pedidoDoCenario());
confere(ofertas()[0]->resposta === 'pendente' && count(Driver::$avisos) === 1 && logou('job da oferta não entrou na fila', 'warning'), 'fila fora: a oferta sai mesmo assim (a varredura vence)');
Fila::$falhar = null;

$dist = cenario();
Trava::$ocupadas['entregas:pedido:order-1'] = true;
confere(excecao(fn () => $dist->iniciar(pedidoDoCenario())) instanceof \Illuminate\Contracts\Cache\LockTimeoutException, 'iniciar com a trava ocupada lança (o listener trata)');
unset(Trava::$ocupadas['entregas:pedido:order-1']);

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
Trava::$ocupadas['entregas:pedido:order-1'] = true;
$job = new AvancarOferta(1);
$job->handle($dist);
confere($job->liberadoPor === 2 && ofertas()[0]->resposta === 'pendente' && logou('trava ocupada ao vencer a oferta', 'info'), 'job com a trava ocupada: volta à fila em 2 s');
unset(Trava::$ocupadas['entregas:pedido:order-1']);
Config::$valores['services.entregas.distribuicao'] = '';
(new AvancarOferta(1))->handle($dist);
confere(ofertas()[0]->resposta === 'pendente', 'job com a distribuição desligada: não faz nada');

echo '== ObservadorDaDistribuicao (Order::updated)' . PHP_EOL;
use App\Listeners\Entregas\DistribuirPedidoAberto;
use App\Listeners\Entregas\ObservadorDaDistribuicao;
use Fleetbase\FleetOps\Events\OrderDispatched;
use Fleetbase\FleetOps\Listeners\HandleOrderDispatched;

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
\Teste\Container::$instancias[Distribuidor::class] = $dist;
$pedido                       = pedidoDoCenario();
$pedido->driver_assigned_uuid = 'd-b';
$pedido->alterados            = ['driver_assigned_uuid'];
ObservadorDaDistribuicao::aoAtualizar($pedido);
confere(distribuicao()->fase === 'encerrada' && distribuicao()->motivo === 'atribuida' && ofertas()[0]->resposta === 'cancelada', 'ganhou motoboy: encerra (atribuida) e cancela a oferta pendente');
confere(!isset(Trava::$ocupadas['entregas:pedido:order-1']) && count(Driver::$avisos) === 1, 'encerrar não mexe na trava (roda dentro do aceite e da troca, que já a seguram) nem avisa ninguém');

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
Trava::$ocupadas['entregas:pedido:order-1'] = true; // o aceite (middleware) segura a trava enquanto o startOrder grava
\Teste\Container::$instancias[Distribuidor::class] = $dist;
$pedido                       = pedidoDoCenario();
$pedido->driver_assigned_uuid = 'd-a';
$pedido->alterados            = ['driver_assigned_uuid', 'started', 'status'];
ObservadorDaDistribuicao::aoAtualizar($pedido);
confere(distribuicao()->motivo === 'atribuida' && !logou('falha ao encerrar', 'warning'), 'com a trava do pedido tomada (aceite): encerra sem tentar tomá-la');
unset(Trava::$ocupadas['entregas:pedido:order-1']);

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
\Teste\Container::$instancias[Distribuidor::class] = $dist;
$pedido            = pedidoDoCenario();
$pedido->status    = 'canceled';
$pedido->alterados = ['status'];
ObservadorDaDistribuicao::aoAtualizar($pedido);
confere(distribuicao()->motivo === 'cancelada', 'cancelado: encerra (cancelada)');

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
\Teste\Container::$instancias[Distribuidor::class] = $dist;
$pedido            = pedidoDoCenario();
$pedido->status    = 'enroute';
$pedido->alterados = ['status'];
ObservadorDaDistribuicao::aoAtualizar($pedido);
confere(distribuicao()->fase === 'ofertas', 'status que não encerra: nada');
$pedido->driver_assigned_uuid = null;
$pedido->alterados            = ['driver_assigned_uuid'];
ObservadorDaDistribuicao::aoAtualizar($pedido);
confere(distribuicao()->fase === 'ofertas', 'motoboy tirado (null): nada');
$pedido->alterados = ['updated_at'];
ObservadorDaDistribuicao::aoAtualizar($pedido);
confere(distribuicao()->fase === 'ofertas', 'outra mudança: nada');
Config::$valores['services.entregas.distribuicao'] = '';
$pedido->status    = 'completed';
$pedido->alterados = ['status'];
ObservadorDaDistribuicao::aoAtualizar($pedido);
confere(distribuicao()->fase === 'ofertas', 'desligada: nada');
Config::$valores['services.entregas.distribuicao'] = '1';
\Teste\Container::$instancias[Distribuidor::class] = new class(null) extends Distribuidor {
    public function __construct($f) {}
    public function encerrar(string $p, string $m): void { throw new \RuntimeException('banco fora'); }
};
confere(excecao(fn () => ObservadorDaDistribuicao::aoAtualizar($pedido)) === null && logou('falha ao encerrar a distribuição', 'warning') && logsSem(['banco fora']), 'erro no observador: só o log, sem a mensagem (nunca lança)');
unset(\Teste\Container::$instancias[Distribuidor::class]);

echo '== DistribuirPedidoAberto' . PHP_EOL;
$dist = cenario();
\Teste\Container::$instancias[Distribuidor::class] = $dist;
$listener = new class extends DistribuirPedidoAberto {
    public array $geral = [];
    protected function alarmeGeral($pedido): void { $this->geral[] = $pedido->public_id; }
};
$listener->distribuir(pedidoDoCenario());
confere(distribuicao()->fase === 'ofertas' && $listener->geral === [], 'ligada: inicia a distribuição e não manda o alarme geral');

$dist = cenario();
Trava::$ocupadas['entregas:pedido:order-1'] = true;
\Teste\Container::$instancias[Distribuidor::class] = $dist;
$listener->distribuir(pedidoDoCenario());
confere($listener->geral === ['order_1'] && logou('falha ao iniciar a distribuição', 'error'), 'distribuição falhou (trava ocupada): alarme geral do Fleet-Ops e log');
unset(Trava::$ocupadas['entregas:pedido:order-1'], \Teste\Container::$instancias[Distribuidor::class]);

// o alarme geral de verdade (sem o override): OrderPing a todos do nearbyAvailableDrivers, mesmo com um que falha
$dist = cenario();
\Teste\Container::$instancias[Distribuidor::class] = new Distribuidor(new FilaDeCandidatos(new class extends EstimadorDeTempo {
    public function matriz(array $pontos): array { throw new \RuntimeException('bug no estimador'); }
}));
$quebra = new class(['uuid' => 'd-x', 'public_id' => 'driver_x']) extends Driver { public function notify($n): void { throw new \RuntimeException('fcm fora'); } };
HandleOrderDispatched::$proximos = [$quebra, ...array_map(function ($m) { $m->distance = 200; return $m; }, Driver::$todos)];
(new DistribuirPedidoAberto())->distribuir(pedidoDoCenario());
confere(avisos() === [['driver_a', OrderPing::class], ['driver_b', OrderPing::class]] && Driver::$avisos[0][1]->distance === 200, 'falha no ciclo: OrderPing a todos do raio (o que falha não impede os outros)');
confere(distribuicao()->fase === 'aberta' && distribuicao()->motivo === 'falha', 'falha no ciclo: a distribuição fica aberta (falha), o aceite é livre');
\Teste\Container::$instancias[Distribuidor::class] = new class(null) extends Distribuidor {
    public function __construct($f) {}
    public function iniciar(Order $p): void { throw new \Illuminate\Contracts\Cache\LockTimeoutException(); }
};
$semColeta          = pedidoAberto(['uuid' => 'order-3', 'public_id' => 'order_3']);
$semColeta->payload = (object) ['pickup' => (object) ['location' => null]];
Driver::$avisos     = [];
(new DistribuirPedidoAberto())->distribuir($semColeta);
confere(Driver::$avisos === [], 'alarme geral sem ponto de coleta: ninguém (como o original)');
unset(\Teste\Container::$instancias[Distribuidor::class]);

echo '== DistribuirPedidoAberto::handle' . PHP_EOL;
$dist = cenario();
\Teste\Container::$instancias[Distribuidor::class] = $dist;
session(['company' => 'outra-empresa']);
HandleOrderDispatched::$semAtividade = true;
HandleOrderDispatched::$atividade    = (object) ['code' => 'dispatched'];
Relogio::$agora                      = '2026-10-07 10:00:05';
$pedido                              = pedidoDoCenario();
$pedido->dispatched                  = false;
(new DistribuirPedidoAberto())->handle(new OrderDispatched($pedido));
confere(HandleOrderDispatched::$originais === [] && distribuicao()?->fase === 'ofertas' && avisos() === [['driver_a', OfertaDePedido::class]], 'pedido aberto sem motoboy: distribuição (oferta a A), sem o original');
confere($pedido->dispatched === true && $pedido->dispatched_at->format('Y-m-d H:i:s') === '2026-10-07 10:00:05' && $pedido->status === 'dispatched', 'despacho como o original: dispatched, dispatched_at (fuso do app) e o status da atividade');
confere($pedido->chamadas === ['setStatus', 'createActivity', 'save', 'load'] && session('company') === 'empresa-1', 'atividade de despacho, save, load e a empresa na sessão');

$dist = cenario();
\Teste\Container::$instancias[Distribuidor::class] = $dist;
pedidoDoCenario()->driver_assigned_uuid = 'd-b';
(new DistribuirPedidoAberto())->handle(new OrderDispatched(pedidoDoCenario()));
confere(count(HandleOrderDispatched::$originais) === 1 && distribuicao() === null && Driver::$avisos === [] && pedidoDoCenario()->chamadas === [], 'aberto que já tem motoboy no despacho: o original (avisa todos do raio, como antes), sem distribuição');

$dist = cenario();
\Teste\Container::$instancias[Distribuidor::class] = $dist;
pedidoDoCenario()->adhoc = false;
(new DistribuirPedidoAberto())->handle(new OrderDispatched(pedidoDoCenario()));
confere(count(HandleOrderDispatched::$originais) === 1 && distribuicao() === null, 'pedido que não é aberto: o original');

$dist = cenario();
\Teste\Container::$instancias[Distribuidor::class] = $dist;
Config::$valores['services.entregas.distribuicao'] = '';
(new DistribuirPedidoAberto())->handle(new OrderDispatched(pedidoDoCenario()));
confere(count(HandleOrderDispatched::$originais) === 1 && distribuicao() === null, 'distribuição desligada: o original');

$dist = cenario();
confere(excecao(fn () => (new DistribuirPedidoAberto())->handle(new OrderDispatched(null))) === null && HandleOrderDispatched::$originais === [] && logou('pedido do despacho não encontrado', 'info'), 'pedido que sumiu antes do worker: só o log');
unset(\Teste\Container::$instancias[Distribuidor::class]);

echo '== Troca do listener (AppServiceProvider) e o original do Fleet-Ops' . PHP_EOL;
use App\Support\Entregas\Distribuicao\TrocaDoListenerDoDespacho;

// o Dispatcher do Laravel 10 no que a troca usa: listen guarda o listener cru, forget apaga o evento
function despachante(array $listeners): object
{
    return new class($listeners) {
        public function __construct(public array $listeners) {}
        public function getRawListeners(): array { return $this->listeners; }
        public function forget($evento): void { unset($this->listeners[$evento]); }
        public function listen($evento, $listener): void { $this->listeners[$evento][] = $listener; }
    };
}

$original   = 'Fleetbase\FleetOps\Listeners\HandleOrderDispatched';
$webhook    = 'Fleetbase\Listeners\SendResourceLifecycleWebhook';
$notificar  = 'Fleetbase\FleetOps\Listeners\NotifyOrderEvent';
$storefront = 'Fleetbase\Storefront\Listeners\HandleOrderDispatched';
$nosso      = DistribuirPedidoAberto::class;
$outro      = 'Fleetbase\FleetOps\Events\OrderCompleted';

reiniciarIfood();
$eventos = despachante([OrderDispatched::class => [$original, $webhook, $notificar, $storefront], $outro => [$storefront]]);
TrocaDoListenerDoDespacho::aplicar($eventos);
confere($eventos->listeners[OrderDispatched::class] === [$nosso, $webhook, $notificar, $storefront], 'troca: o nosso no lugar do original e os outros (inclusive o do Storefront) na ordem em que estavam');
confere($eventos->listeners[$outro] === [$storefront] && !logou('listener do Fleet-Ops não encontrado'), 'outros eventos ficam como estavam; sem aviso');

$fechamento = fn () => null;
$eventos    = despachante([OrderDispatched::class => ['\\' . $original . '@handle', $fechamento, $storefront]]);
TrocaDoListenerDoDespacho::aplicar($eventos);
confere($eventos->listeners[OrderDispatched::class] === [$nosso, $fechamento, $storefront], 'o original com barra inicial e @handle também sai; closure fica');
$eventos = despachante([OrderDispatched::class => [[$original, 'handle'], $webhook]]);
TrocaDoListenerDoDespacho::aplicar($eventos);
confere($eventos->listeners[OrderDispatched::class] === [$nosso, $webhook], 'o original como [classe, método] também sai');
confere(TrocaDoListenerDoDespacho::ehOOriginal($storefront) === false && TrocaDoListenerDoDespacho::ehOOriginal($original . 'X') === false, 'o HandleOrderDispatched do Storefront (e um nome parecido) não é o original');

reiniciarIfood();
$eventos = despachante([OrderDispatched::class => [$webhook, $storefront]]);
TrocaDoListenerDoDespacho::aplicar($eventos);
confere($eventos->listeners[OrderDispatched::class] === [$nosso, $webhook, $storefront] && logou('listener do Fleet-Ops não encontrado no OrderDispatched', 'warning'), 'sem o original: aviso e o nosso registrado mesmo assim');
reiniciarIfood();
$eventos = despachante([]);
TrocaDoListenerDoDespacho::aplicar($eventos);
confere($eventos->listeners[OrderDispatched::class] === [$nosso] && logou('listener do Fleet-Ops não encontrado', 'warning'), 'evento sem listeners: aviso e só o nosso');

$semCrus = new class {
    public array $listeners = [OrderDispatched::class => ['x']];
    public function forget($evento): void { unset($this->listeners[$evento]); }
    public function listen($evento, $listener): void { $this->listeners[$evento][] = $listener; }
};
TrocaDoListenerDoDespacho::aplicar($semCrus);
confere($semCrus->listeners[OrderDispatched::class] === [$nosso, $webhook, $notificar], 'dispatcher sem getRawListeners: a lista fixa do Fleet-Ops');

$eventosFleetOps = file_get_contents('/repo/packages/fleetops/server/src/Providers/EventServiceProvider.php');
preg_match('/\\\\Fleetbase\\\\FleetOps\\\\Events\\\\OrderDispatched::class\s*=>\s*\[([^\]]*)\]/', $eventosFleetOps, $lista);
$listeners = array_map(fn ($l) => trim(str_replace('::class', '', $l), " \\"), explode(',', $lista[1] ?? ''));
confere($listeners === [$original, $webhook, $notificar] && TrocaDoListenerDoDespacho::LISTA_FIXA === [$webhook, $notificar], 'o Fleet-Ops registra o original e os dois da lista fixa (' . implode(', ', $listeners) . ')');
confere(str_contains(file_get_contents('/repo/packages/storefront/server/src/Providers/EventServiceProvider.php'), '\Fleetbase\FleetOps\Events\OrderDispatched::class     => [\Fleetbase\Storefront\Listeners\HandleOrderDispatched::class]'), 'o Storefront também escuta o OrderDispatched (preservado pela troca)');

$provedor = str_replace("\r\n", "\n", file_get_contents('/repo/api/app/Providers/AppServiceProvider.php'));
preg_match('/protected function distribuirPedidosAbertos\(\): void\s*\{(.*?)\n    \}/s', $provedor, $troca);
confere(str_contains($troca[1] ?? '', 'Order::updated(fn ($pedido) => ObservadorDaDistribuicao::aoAtualizar($pedido));') && str_contains($troca[1] ?? '', '$this->app->booted(fn () => TrocaDoListenerDoDespacho::aplicar(Event::getFacadeRoot()));'), 'distribuirPedidosAbertos: o observador e, no booted, a troca no dispatcher do Laravel');
foreach (['use App\Support\Entregas\Distribuicao\TrocaDoListenerDoDespacho;', 'use App\Listeners\Entregas\ObservadorDaDistribuicao;'] as $uso) {
    confere(str_contains($provedor, $uso), "import: {$uso}");
}
preg_match('/public function boot\(\)\s*\{(.*?)\n    \}/s', $provedor, $boot);
confere(str_contains($boot[1] ?? '', '$this->distribuirPedidosAbertos();'), 'chamado no boot');
confere(is_subclass_of(DistribuirPedidoAberto::class, HandleOrderDispatched::class), 'o nosso estende o original (herda o ShouldQueue: continua na fila)');

$original = str_replace("\r\n", "\n", file_get_contents('/repo/packages/fleetops/server/src/Listeners/HandleOrderDispatched.php'));
foreach (['doesntHaveDispatchActivity($order): bool', 'getDispatchActivity($order): mixed', 'nearbyAvailableDrivers($pickup, int|float $distance)', 'notifyAdhocDriver(Driver $driver, $order): void'] as $metodo) {
    confere(str_contains($original, 'protected function ' . $metodo), "o original ainda tem: protected function {$metodo}");
}
foreach ([
    'class HandleOrderDispatched implements ShouldQueue',
    'public function handle(OrderDispatched $event)',
    "session([\n            'company' => \$order->company_uuid,\n        ]);",
    '$order->setStatus($activity->code);',
    '$order->createActivity($activity, $location);',
    '$order->dispatched    = true;',
    '$order->dispatched_at = Carbon::now();',
    '$order->save();',
    '$order->flushAttributesCache();',
    '$drivers = $this->nearbyAvailableDrivers($pickup, $distance);',
    '$this->notifyAdhocDriver($driver, $order);',
] as $trecho) {
    confere(str_contains($original, $trecho), 'o handle() original ainda faz o que o nosso repete: ' . strtok($trecho, "\n"));
}

echo '== entregas:distribuicao-varrer' . PHP_EOL;
use App\Console\Commands\Entregas\VarrerDistribuicoes;

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
Relogio::$agora = '2026-10-07 10:00:55';          // a oferta venceu às 10:00:30 e o job não veio (25 s > 20 s de folga)
(new VarrerDistribuicoes())->handle($dist);
confere(ofertas()[0]->resposta === 'vencida' && ofertas()[1]->motoboy_uuid === 'd-b', 'oferta pendente vencida há mais de 20 s: vence e passa ao próximo');

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
Relogio::$agora = '2026-10-07 10:00:40';          // venceu há 10 s: o job ainda pode vir
(new VarrerDistribuicoes())->handle($dist);
confere(ofertas()[0]->resposta === 'pendente', 'vencida há menos de 20 s: espera o job');

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
Distribuicoes::responder(1, 'recusada');          // sem pendente e sem job: a distribuição ficaria presa
Relogio::$agora = '2026-10-07 10:03:10';
(new VarrerDistribuicoes())->handle($dist);
confere(distribuicao()->fase === 'aberta' && distribuicao()->motivo === 'prazo', 'em ofertas há mais de 3 min: abre a todos (prazo)');

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
pedidoDoCenario()->driver_assigned_uuid = 'd-b';  // a central atribuiu e o observador não rodou
(new VarrerDistribuicoes())->handle($dist);
confere(distribuicao()->fase === 'encerrada' && distribuicao()->motivo === 'atribuida', 'pedido já com motoboy: encerra');

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
pedidoDoCenario()->status = 'canceled';
(new VarrerDistribuicoes())->handle($dist);
confere(distribuicao()->motivo === 'cancelada', 'pedido encerrado: encerra (cancelada)');

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
Config::$valores['services.entregas.distribuicao'] = '';
Relogio::$agora = '2026-10-07 10:05:00';
confere((new VarrerDistribuicoes())->handle($dist) === 0 && ofertas()[0]->resposta === 'pendente' && distribuicao()->fase === 'ofertas', 'desligada: sai sem agir');

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
DB::table('entregas_ofertas')->where('id', 1)->update(['vence_em' => '2026-10-07 10:03:30']); // pendente e ainda dentro da folga de 20 s
Relogio::$agora = '2026-10-07 10:03:10';          // o prazo de 3 min passou
(new VarrerDistribuicoes())->handle($dist);
confere(distribuicao()->fase === 'aberta' && distribuicao()->motivo === 'prazo' && ofertas()[0]->resposta === 'cancelada', 'prazo de 3 min com oferta pendente: abre a todos e cancela a oferta');

foreach ([['2026-10-09 10:00:00', 'aberta', 'despachada há 2 dias: a varredura não mexe'], ['2026-10-07 11:00:00', 'encerrada', 'despachada há 1 h: encerra (cancelada)']] as [$hora, $fase, $nome]) {
    $dist = cenario();
    $dist->iniciar(pedidoDoCenario());
    Distribuicoes::mudarFase(1, 'aberta', 'prazo');
    pedidoDoCenario()->status = 'canceled';
    Relogio::$agora = $hora;
    (new VarrerDistribuicoes())->handle($dist);
    confere(distribuicao()->fase === $fase, 'distribuição aberta ' . $nome);
}

resumo();
