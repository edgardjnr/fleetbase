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

resumo();
