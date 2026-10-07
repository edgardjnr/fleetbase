<?php

// Distribuição em rodadas (ENTREGAS_DISTRIBUICAO_RODADAS): colunas novas, tabelas (Distribuicoes), o ciclo em rodadas e
// voltas (Distribuidor), recusar × dispensar, "Mostrar a todos agora", o aceite pela lista, o job AvancarDistribuicao e a
// varredura. Com a chave desligada valem os testes de distribuicao-ciclo.php, sem mudança.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-rodadas.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Console\Commands\Entregas\VarrerDistribuicoes;
use App\Jobs\Entregas\AvancarDistribuicao;
use App\Jobs\Entregas\AvancarOferta;
use App\Notifications\Entregas\OfertaDePedido;
use App\Support\Entregas\Distribuicao\Candidatos;
use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Distribuicoes;
use App\Support\Entregas\Distribuicao\Distribuidor;
use App\Support\Entregas\Distribuicao\EstimadorDeTempo;
use App\Support\Entregas\Distribuicao\FilaDeCandidatos;
use App\Support\Entregas\Distribuicao\Pontos;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Illuminate\Support\Facades\DB;
use Teste\Config;
use Teste\Fila;
use Teste\Relogio;
use Teste\Trava;

echo '== Colunas novas (migration add_rodadas)' . PHP_EOL;
reiniciarFleetbase();
reiniciarIfood();
$id = DB::table('entregas_distribuicoes')->insertGetId(['pedido_uuid' => 'order-1', 'company_uuid' => 'empresa-1', 'despachada_em' => '2026-10-07 10:00:00', 'fase' => 'ofertas']);
$d  = DB::table('entregas_distribuicoes')->where('id', $id)->first();
confere(($d->volta ?? null) === 1 && ($d->rodada ?? null) === 1 && property_exists($d, 'volta_iniciada_em') && $d->volta_iniciada_em === null && property_exists($d, 'lista_aberta_em') && $d->lista_aberta_em === null, 'distribuição de antes: volta 1, rodada 1, sem início de volta nem lista aberta (' . json_encode($d) . ')');
$idOferta = DB::table('entregas_ofertas')->insertGetId(['distribuicao_id' => $id, 'pedido_uuid' => 'order-1', 'motoboy_uuid' => 'd-a', 'posicao' => 1, 'oferecida_em' => '2026-10-07 10:00:00', 'vence_em' => '2026-10-07 10:00:30', 'resposta' => 'pendente']);
$o        = DB::table('entregas_ofertas')->where('id', $idOferta)->first();
confere(($o->volta ?? null) === 1 && ($o->rodada ?? null) === 1 && property_exists($o, 'raio_m') && $o->raio_m === null, 'oferta de antes: volta 1, rodada 1, sem raio (' . json_encode($o) . ')');
DB::table('entregas_ofertas')->where('id', $idOferta)->update(['resposta' => 'aceita_pela_lista']);
confere(DB::table('entregas_ofertas')->where('distribuicao_id', $id)->where('volta', 1)->value('resposta') === 'aceita_pela_lista', 'a volta filtra e a resposta nova é gravada');
// o banco em memória não aplica o tamanho: confere o tamanho declarado nas migrations (depois do ->change())
$tamanhoResposta = \Teste\Banco::tamanhoDe('entregas_ofertas', 'resposta');
$maiorResposta   = max(array_map('strlen', [Distribuicao::PENDENTE, Distribuicao::ACEITA, Distribuicao::RECUSADA, Distribuicao::VENCIDA, Distribuicao::CANCELADA, Distribuicao::DISPENSADA, Distribuicao::ACEITA_PELA_LISTA]));
confere($tamanhoResposta !== null && $tamanhoResposta >= $maiorResposta, "toda resposta cabe em entregas_ofertas.resposta (string($tamanhoResposta); a maior tem $maiorResposta caracteres)");

echo '== Distribuicoes em rodadas' . PHP_EOL;
reiniciarFleetbase();
reiniciarIfood();
Relogio::$agora = '2026-10-07 10:00:00';
Config::$valores['services.entregas.distribuicao']         = '1';
Config::$valores['services.entregas.distribuicao_rodadas'] = '1';
$d = Distribuicoes::criar(new Order(['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'adhoc' => true]));
confere($d->volta === 1 && $d->rodada === 1 && $d->volta_iniciada_em === '2026-10-07 10:00:00' && $d->lista_aberta_em === null, 'criar: volta 1, rodada 1, iniciada agora, lista fechada');
$o = Distribuicoes::criarOferta($d, ['motoboy_uuid' => 'd-a', 'tempo_s' => 300, 'encaixe' => false, 'aproximado' => true], 1, 1, 1, 6000);
confere($o->vence_em === '2026-10-07 10:00:20' && $o->volta === 1 && $o->rodada === 1 && $o->raio_m === 6000, 'criarOferta em rodadas: vence em 20 s, com volta, rodada e raio (' . json_encode($o) . ')');
Relogio::$agora = '2026-10-07 10:00:25';
Distribuicoes::irParaRodada($d->id, 2);
confere(Distribuicoes::abrirLista($d->id) === true && Distribuicoes::abrirLista($d->id) === false, 'abrirLista: abre uma vez só');
$d = Distribuicoes::porId($d->id);
confere($d->rodada === 2 && $d->lista_aberta_em === '2026-10-07 10:00:25' && $d->updated_at === '2026-10-07 10:00:25', 'irParaRodada e abrirLista gravam a rodada, a hora e o updated_at');
$dispensa = Distribuicoes::registrarResposta($d, 'd-b', 'dispensada', 9000);
confere($dispensa->resposta === 'dispensada' && $dispensa->oferecida_em === '2026-10-07 10:00:25' && $dispensa->vence_em === '2026-10-07 10:00:25' && $dispensa->respondida_em === '2026-10-07 10:00:25' && $dispensa->volta === 1 && $dispensa->rodada === 2 && $dispensa->raio_m === 9000 && $dispensa->posicao === 2 && $dispensa->tempo_estimado_s === null, 'registrarResposta: linha sem oferta, oferecida = vence = respondida = agora (' . json_encode($dispensa) . ')');
confere(Distribuicoes::ofertaParaAceite('order-1', 'd-a')?->id === $o->id, 'ofertaParaAceite ignora a linha dispensada: a pendente de A continua valendo');
Distribuicoes::responder($o->id, 'vencida');
confere(Distribuicoes::motoboysDaVolta($d->id, 1) === ['d-a', 'd-b'] && Distribuicoes::motoboysQueDispensaramNaVolta($d->id, 1) === ['d-b'], 'quem tem linha na volta (qualquer resposta) e quem recusou ou dispensou (a vencida não some da lista)');
Relogio::$agora = '2026-10-07 10:01:00';
Distribuicoes::novaVolta($d->id, 2);
$d = Distribuicoes::porId($d->id);
confere($d->volta === 2 && $d->rodada === 1 && $d->volta_iniciada_em === '2026-10-07 10:01:00' && Distribuicoes::motoboysDaVolta($d->id, 2) === [], 'novaVolta: volta 2, rodada 1, iniciada agora, ninguém perguntado ainda');
Relogio::$agora = '2026-10-07 10:02:01';
confere(array_map(fn ($x) => $x->id, Distribuicoes::emOfertasParadasHa(60)) === [1], 'emOfertasParadasHa: em ofertas, sem pendente, parada há mais de 60 s');
Distribuicoes::tocar(1);
confere(Distribuicoes::emOfertasParadasHa(60) === [] && Distribuicoes::porId(1)->updated_at === '2026-10-07 10:02:01', 'tocar: updated_at agora');
Relogio::$agora = '2026-10-07 10:03:05';
Distribuicoes::criarOferta($d, ['motoboy_uuid' => 'd-a', 'tempo_s' => 300, 'encaixe' => false, 'aproximado' => true], 3, 2, 1, 6000);
confere(Distribuicoes::emOfertasParadasHa(60) === [], 'com oferta pendente: não está parada');
Relogio::$agora = '2026-10-09 10:00:00';
DB::table('entregas_ofertas')->update(['resposta' => 'vencida']);
confere(Distribuicoes::emOfertasParadasHa(60) === [], 'despachada há mais de 24 h: a varredura não mexe');
Distribuicoes::criar(new Order(['uuid' => 'order-2', 'public_id' => 'order_2', 'company_uuid' => 'empresa-1']));
confere(array_map(fn ($x) => $x->pedido_uuid, Distribuicoes::comListaAberta('empresa-1')) === ['order-1'] && Distribuicoes::comListaAberta('empresa-2') === [], 'comListaAberta: só as em ofertas com a lista aberta, da empresa');

echo '== Rodadas: R, 1,5R, 2R e a volta nova' . PHP_EOL;

function ponto(array $p): object
{
    return new class($p) { public function __construct(private array $p) {} public function getLat() { return $this->p[0]; } public function getLng() { return $this->p[1]; } };
}

/** Motoboy livre a $km ao norte da coleta (0,009° de latitude ≈ 1 km), no formato da busca de Candidatos. */
function motoboyA(string $id, string $nome, float $km): array
{
    $posicao = [-21.1700 + 0.009 * $km, -47.8100];
    $motoboy = new Driver(['uuid' => 'd-' . $id, 'public_id' => 'driver_' . $id, 'company_uuid' => 'empresa-1', 'name' => $nome, 'online' => true, 'location' => ponto($posicao)]);

    return ['motoboy' => $motoboy, 'posicao' => $posicao, 'distancia' => $km * 1000];
}

/** Os motoboys disponíveis; a busca falsa filtra pelo raio pedido, como o distanceSphere (null = R = 6000). */
function definirMotoboys(array $motoboys): void
{
    Driver::$todos              = array_map(fn ($c) => $c['motoboy'], $motoboys);
    Candidatos::$buscarMotoboys = fn ($pedido, $gpsRecente, $raio = null) => array_values(array_filter($motoboys, fn ($c) => $c['distancia'] <= ($raio ?? 6000)));
}

/** Pedido aberto (R = 6 km) com as rodadas ligadas; tempos em linha reta. Devolve o Distribuidor. */
function cenarioRodadas(array $motoboys): Distribuidor
{
    reiniciarFleetbase();
    reiniciarIfood();
    Relogio::$agora = '2026-10-07 10:00:00';
    Config::$valores['services.entregas.distribuicao']         = '1';
    Config::$valores['services.entregas.distribuicao_rodadas'] = '1';
    session(['company' => 'empresa-1']);
    $pedido          = new Order(['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'adhoc' => true, 'dispatched' => true, 'status' => 'dispatched']);
    $pedido->payload = (object) ['pickup' => (object) ['location' => ponto([-21.1700, -47.8100])], 'dropoff' => (object) ['location' => ponto([-21.1800, -47.8100])]];
    Order::$todos[]  = $pedido;
    definirMotoboys($motoboys);
    Candidatos::$buscarParadas = fn () => [];
    $estimador = new class extends EstimadorDeTempo {
        public function matriz(array $pontos): array { $m = []; foreach ($pontos as $i => $a) { foreach ($pontos as $j => $b) { $m[$i][$j] = (float) Pontos::segundos($a, $b); } } return ['durations' => $m, 'aproximado' => true]; }
    };

    return new Distribuidor(new FilaDeCandidatos($estimador));
}

function pedidoDoCenario(): Order { return Order::$todos[0]; }
function ofertas(): array { return DB::table('entregas_ofertas')->orderBy('id')->get()->all(); }
function distribuicao(): ?object { return DB::table('entregas_distribuicoes')->where('id', 1)->first(); }
function avisos(): array { return array_map(fn ($a) => [$a[0], get_class($a[1])], Driver::$avisos); }
function alarmesGerais(): array { return array_values(array_filter(avisos(), fn ($a) => $a[1] === OrderPing::class)); }

$ana   = motoboyA('ana', 'Ana', 2);
$bruno = motoboyA('bruno', 'Bruno', 4);
$caio  = motoboyA('caio', 'Caio', 7);
$davi  = motoboyA('davi', 'Davi', 11);

$dist = cenarioRodadas([$ana, $bruno, $caio, $davi]);
$dist->iniciar(pedidoDoCenario());
$o = ofertas()[0];
confere($o->motoboy_uuid === 'd-ana' && $o->volta === 1 && $o->rodada === 1 && $o->raio_m === 6000 && $o->vence_em === '2026-10-07 10:00:20' && $o->encaixe === false, 'volta 1 · rodada 1 (6 km): Ana, a mais perto, por 20 s (' . json_encode($o) . ')');
confere(avisos() === [['driver_ana', OfertaDePedido::class]] && Driver::$avisos[0][1]->segundosDaOferta === 20, 'push da oferta de 20 s só para Ana');
confere(Fila::$jobs[0] instanceof AvancarOferta && Fila::$jobs[0]->delay === 20, 'job AvancarOferta em 20 s');
confere(distribuicao()->lista_aberta_em === null && distribuicao()->fase === 'ofertas', 'rodada 1 da volta 1: lista fechada');

Relogio::$agora = '2026-10-07 10:00:20';
(new AvancarOferta(1))->handle($dist);
confere(ofertas()[0]->resposta === 'vencida' && ofertas()[1]->motoboy_uuid === 'd-bruno' && ofertas()[1]->rodada === 1, 'Ana deixou vencer: Bruno, ainda na rodada 1');

Relogio::$agora = '2026-10-07 10:00:40';
confere($dist->recusar('order-1', $bruno['motoboy']) === true, 'Bruno recusa');
confere(ofertas()[2]->motoboy_uuid === 'd-caio' && ofertas()[2]->rodada === 2 && ofertas()[2]->raio_m === 9000 && distribuicao()->rodada === 2, 'rodada 1 sem ninguém novo: rodada 2 (9 km), Caio');
confere(distribuicao()->lista_aberta_em === '2026-10-07 10:00:40' && logou('lista aberta', 'info') && logou('rodada 2 (raio 9000 m)', 'info'), 'ao sair da rodada 1 da volta 1: lista aberta (e os logs)');

Relogio::$agora = '2026-10-07 10:01:00';
(new AvancarOferta(3))->handle($dist);
confere(ofertas()[3]->motoboy_uuid === 'd-davi' && ofertas()[3]->rodada === 3 && ofertas()[3]->raio_m === 12000, 'rodada 3 (12 km): Davi');

Relogio::$agora = '2026-10-07 10:01:20';
(new AvancarOferta(4))->handle($dist);
confere(ofertas()[4]->motoboy_uuid === 'd-ana' && ofertas()[4]->volta === 2 && ofertas()[4]->rodada === 1 && distribuicao()->volta === 2 && distribuicao()->volta_iniciada_em === '2026-10-07 10:01:20', 'depois da rodada 3, já com 1 min da volta 1: volta 2, Ana de novo');
confere(logou('volta 2', 'info'), 'log: volta 2');

Relogio::$agora = '2026-10-07 10:01:40';
(new AvancarOferta(5))->handle($dist);
confere(ofertas()[5]->motoboy_uuid === 'd-bruno' && ofertas()[5]->volta === 2, 'quem recusou na volta 1 recebe de novo na volta 2');
confere(distribuicao()->fase === 'ofertas' && alarmesGerais() === [] && count(avisos()) === 6, 'nunca abre a todos: só ofertas, uma por vez');
confere(logsSem(['Ana', 'Bruno', '-21.1']), 'logs só com ids e números');

echo '== Rodadas: um motoboy só (1 min entre voltas)' . PHP_EOL;
$dist = cenarioRodadas([$ana]);
$dist->iniciar(pedidoDoCenario());
Relogio::$agora = '2026-10-07 10:00:20';
(new AvancarOferta(1))->handle($dist);
$job = end(Fila::$jobs);
confere(count(ofertas()) === 1 && distribuicao()->rodada === 3 && distribuicao()->fase === 'ofertas' && distribuicao()->lista_aberta_em === '2026-10-07 10:00:20', 'ninguém mais até 2R: rodada 3, lista aberta, sem oferta nova');
confere($job instanceof AvancarDistribuicao && $job->pedidoUuid === 'order-1' && $job->delay === 40 && alarmesGerais() === [], 'próximo passo agendado para quando completar 1 min da volta (40 s); sem alarme a todos');
Relogio::$agora = '2026-10-07 10:01:00';
$job->handle($dist);
confere(count(ofertas()) === 2 && ofertas()[1]->motoboy_uuid === 'd-ana' && ofertas()[1]->volta === 2 && distribuicao()->volta === 2, 'com 1 min: volta 2, Ana de novo');
$job->handle($dist);
confere(count(ofertas()) === 2, 'job repetido com a oferta pendente: espera');
$preso = new AvancarDistribuicao('order-1');
Trava::$ocupadas['entregas:pedido:order-1'] = true;
$preso->handle($dist);
confere($preso->liberadoPor === 2, 'job com a trava ocupada: volta à fila em 2 s');
unset(Trava::$ocupadas['entregas:pedido:order-1']);
Config::$valores['services.entregas.distribuicao_rodadas'] = '';
DB::table('entregas_ofertas')->update(['resposta' => 'vencida']);
(new AvancarDistribuicao('order-1'))->handle($dist);
confere(count(ofertas()) === 2, 'job com as rodadas desligadas: não faz nada');

echo '== Rodadas: ninguém em 2R' . PHP_EOL;
$dist = cenarioRodadas([]);
$dist->iniciar(pedidoDoCenario());
$job = end(Fila::$jobs);
confere(ofertas() === [] && distribuicao()->fase === 'ofertas' && distribuicao()->rodada === 3 && distribuicao()->lista_aberta_em === '2026-10-07 10:00:00' && Driver::$avisos === [], 'sem ninguém no despacho: fica em ofertas, lista aberta, sem alarme');
confere($job instanceof AvancarDistribuicao && $job->delay === 60, 'tenta de novo quando completar 1 min');
Relogio::$agora = '2026-10-07 10:01:00';
$job->handle($dist);
confere(distribuicao()->volta === 2 && distribuicao()->rodada === 3 && distribuicao()->updated_at === '2026-10-07 10:01:00' && logou('aguardando motoboy', 'info') && count(Fila::$jobs) === 1, 'volta 2 inteira sem ninguém: para e espera, sem outro job (a varredura tenta a cada minuto); no máximo uma volta por passo');

echo '== Rodadas: recálculo a cada oferta e oferta pendente de outro pedido' . PHP_EOL;
$dist = cenarioRodadas([$ana, $bruno]);
$dist->iniciar(pedidoDoCenario());
definirMotoboys([$ana, $bruno, motoboyA('eva', 'Eva', 1)]); // Eva ficou disponível durante a oferta de Ana
Relogio::$agora = '2026-10-07 10:00:20';
(new AvancarOferta(1))->handle($dist);
confere(ofertas()[1]->motoboy_uuid === 'd-eva' && ofertas()[1]->rodada === 1, 'quem ficou disponível entra na hora: Eva (1 km) antes de Bruno');

$dist = cenarioRodadas([$ana, $bruno]);
Distribuicoes::criarOferta((object) ['id' => 99, 'pedido_uuid' => 'order-9'], ['motoboy_uuid' => 'd-ana', 'tempo_s' => 1, 'encaixe' => false, 'aproximado' => false], 1);
$dist->iniciar(pedidoDoCenario());
$doPedido = fn () => array_values(array_filter(ofertas(), fn ($o) => $o->pedido_uuid === 'order-1'));
confere(count($doPedido()) === 1 && $doPedido()[0]->motoboy_uuid === 'd-bruno', 'Ana tem oferta pendente de outro pedido: Bruno primeiro');
DB::table('entregas_ofertas')->where('pedido_uuid', 'order-9')->update(['resposta' => 'recusada']);
Relogio::$agora = '2026-10-07 10:00:20';
$dist->vencer((int) $doPedido()[0]->id);
confere($doPedido()[1]->motoboy_uuid === 'd-ana' && $doPedido()[1]->rodada === 1 && $doPedido()[1]->volta === 1, 'livre de novo e ainda não perguntada nesta volta: Ana entra na mesma rodada');

echo '== Rodadas: pedido com motoboy e falha no ciclo' . PHP_EOL;
$dist = cenarioRodadas([$ana]);
$dist->iniciar(pedidoDoCenario());
pedidoDoCenario()->driver_assigned_uuid = 'd-x';
Relogio::$agora = '2026-10-07 10:00:20';
$dist->vencer(1);
confere(distribuicao()->fase === 'encerrada' && distribuicao()->motivo === 'atribuida' && count(ofertas()) === 1, 'pedido já com motoboy: encerra (atribuida) sem oferecer a mais ninguém');

$dist = cenarioRodadas([$ana, $bruno]);
$dist->iniciar(pedidoDoCenario());
$quebrado = new Distribuidor(new FilaDeCandidatos(new class extends EstimadorDeTempo {
    public function matriz(array $pontos): array { throw new \RuntimeException('bug no estimador'); }
}));
Relogio::$agora = '2026-10-07 10:00:20';
$quebrado->vencer(1);
confere(distribuicao()->fase === 'aberta' && distribuicao()->motivo === 'falha' && count(alarmesGerais()) === 2, 'falha depois do vencimento: aberta (falha) com o alarme geral, a rede de segurança de hoje');

echo '== Rodadas: quem fica livre durante a rodada 2' . PHP_EOL;
$dist = cenarioRodadas([$ana, $caio, $davi]);
$dist->iniciar(pedidoDoCenario());
Relogio::$agora = '2026-10-07 10:00:20';
(new AvancarOferta(1))->handle($dist);
confere(ofertas()[1]->motoboy_uuid === 'd-caio' && ofertas()[1]->rodada === 2, 'rodada 1 sem ninguém novo: rodada 2, Caio');
definirMotoboys([$ana, $caio, $davi, motoboyA('eva', 'Eva', 1)]); // Eva ficou livre perto da loja durante a oferta de Caio
Relogio::$agora = '2026-10-07 10:00:40';
(new AvancarOferta(2))->handle($dist);
confere(ofertas()[2]->motoboy_uuid === 'd-eva' && ofertas()[2]->volta === 1 && ofertas()[2]->rodada === 2 && ofertas()[2]->raio_m === 9000 && distribuicao()->rodada === 2, 'Eva (1 km) entra no passo seguinte, ainda na rodada 2 (antes de Davi, na rodada 3)');

echo '== Rodadas: volta_iniciada_em nulo vale o despachada_em' . PHP_EOL;
$dist = cenarioRodadas([$ana]);
$dist->iniciar(pedidoDoCenario());
DB::table('entregas_distribuicoes')->where('id', 1)->update(['volta_iniciada_em' => null, 'despachada_em' => '2026-10-07 09:59:30']);
Relogio::$agora = '2026-10-07 10:00:20';
(new AvancarOferta(1))->handle($dist);
$job = end(Fila::$jobs);
confere($job instanceof AvancarDistribuicao && $job->delay === 10 && distribuicao()->volta === 1, 'sem volta_iniciada_em: o 1 min conta do despacho (09:59:30), faltam 10 s');
Relogio::$agora = '2026-10-07 10:00:30';
$job->handle($dist);
confere(count(ofertas()) === 2 && ofertas()[1]->motoboy_uuid === 'd-ana' && ofertas()[1]->volta === 2 && distribuicao()->volta_iniciada_em === '2026-10-07 10:00:30', 'com 1 min do despacho: volta 2, iniciada agora');

echo '== Rodadas: pedido sem coordenada abre a todos, como hoje' . PHP_EOL;
$dist = cenarioRodadas([$ana, $bruno]);
pedidoDoCenario()->payload->dropoff = (object) ['location' => ponto([0.0, 0.0])];
$dist->iniciar(pedidoDoCenario());
confere(distribuicao()->fase === 'aberta' && distribuicao()->motivo === 'sem_candidato' && ofertas() === [], 'entrega em (0,0): aberta (sem_candidato), sem oferta');
confere(alarmesGerais() === [['driver_ana', OrderPing::class], ['driver_bruno', OrderPing::class]], 'alarme geral para os dois no raio (' . json_encode(avisos()) . ')');
confere(logou('pedido sem coordenada; aberta a todos', 'warning') && logsSem(['-21.1', 'Ana']), 'log de aviso só com ids');

echo '== Recusar × dispensar (Distribuidor)' . PHP_EOL;
$dist = cenarioRodadas([$ana, $bruno, $caio, $davi]);
$dist->iniciar(pedidoDoCenario());                                                // Ana
confere($dist->recusarOuDispensar('order-1', $bruno['motoboy']) === null && count(ofertas()) === 1, 'lista fechada e sem oferta dele: null (a rota responde 409)');
Relogio::$agora = '2026-10-07 10:00:10';
confere($dist->recusarOuDispensar('order-1', $ana['motoboy']) === 'recusada' && ofertas()[0]->resposta === 'recusada' && ofertas()[1]->motoboy_uuid === 'd-bruno', 'com a oferta dele: recusada, e passa ao próximo na hora');
Relogio::$agora = '2026-10-07 10:00:20';
$dist->recusarOuDispensar('order-1', $bruno['motoboy']);                         // rodada 2: Caio; lista aberta
Relogio::$agora = '2026-10-07 10:00:25';
confere($dist->recusarOuDispensar('order-1', $davi['motoboy']) === 'dispensada', 'lista aberta, sem oferta dele: dispensada');
$linha = ofertas()[3];
confere($linha->motoboy_uuid === 'd-davi' && $linha->resposta === 'dispensada' && $linha->volta === 1 && $linha->rodada === 2 && $linha->raio_m === 9000 && ofertas()[2]->resposta === 'pendente', 'linha dispensada na volta e na rodada atuais; a oferta de Caio continua');
confere($dist->recusarOuDispensar('order-1', $davi['motoboy']) === 'dispensada' && count(ofertas()) === 4, 'dispensar de novo na mesma volta: sem linha nova');
confere(logou('oferta dispensada', 'info'), 'log: oferta dispensada');
Relogio::$agora = '2026-10-07 10:00:40';
(new AvancarOferta(3))->handle($dist);                                            // Caio vence; Davi dispensou
$job = end(Fila::$jobs);
confere(count(ofertas()) === 4 && distribuicao()->rodada === 3 && $job instanceof AvancarDistribuicao && $job->delay === 20, 'quem dispensou não recebe oferta nesta volta: rodada 3 vazia, próximo passo quando completar 1 min');
Relogio::$agora = '2026-10-07 10:01:00';
$job->handle($dist);
confere(ofertas()[4]->motoboy_uuid === 'd-ana' && ofertas()[4]->volta === 2, 'volta 2: todos de novo, inclusive quem recusou');
Config::$valores['services.entregas.distribuicao_rodadas'] = '';
confere($dist->recusarOuDispensar('order-1', $davi['motoboy']) === null, 'rodadas desligadas: sem dispensa');

$dist = cenarioRodadas([$ana, $bruno]);
$dist->iniciar(pedidoDoCenario());
Distribuicoes::abrirLista(1);
pedidoDoCenario()->driver_assigned_uuid = 'd-ana';
confere($dist->recusarOuDispensar('order-1', $bruno['motoboy']) === null, 'pedido já com motoboy: null (409)');

echo '== Mostrar a todos agora e aceite pela lista (Distribuidor)' . PHP_EOL;
$dist = cenarioRodadas([$ana, $bruno]);
$dist->iniciar(pedidoDoCenario());
confere($dist->mostrarATodos('order-1') === 'aberta' && distribuicao()->lista_aberta_em === '2026-10-07 10:00:00' && distribuicao()->fase === 'ofertas' && ofertas()[0]->resposta === 'pendente' && count(avisos()) === 1, 'abre a lista na hora; a oferta de Ana continua, sem alarme geral');
confere($dist->mostrarATodos('order-1') === 'ja_aberta', 'já aberta: ja_aberta');
$dist->registrarAceitePelaLista(distribuicao(), 'd-bruno');
confere(ofertas()[0]->resposta === 'cancelada' && ofertas()[1]->resposta === 'aceita_pela_lista' && ofertas()[1]->motoboy_uuid === 'd-bruno' && distribuicao()->fase === 'encerrada' && distribuicao()->motivo === 'aceita' && logou('aceita pela lista', 'info'), 'aceite pela lista: a pendente de Ana vira cancelada, Bruno ganha a linha aceita_pela_lista, encerrada (aceita)');
confere($dist->mostrarATodos('order-1') === 'fora_de_ofertas', 'encerrada: fora_de_ofertas');
$dist = cenarioRodadas([$ana]);
$dist->iniciar(pedidoDoCenario());
pedidoDoCenario()->driver_assigned_uuid = 'd-x';
confere($dist->mostrarATodos('order-1') === 'fora_de_ofertas' && distribuicao()->motivo === 'atribuida', 'pedido já com motoboy: encerra (atribuida)');
confere(!isset(Trava::$ocupadas['entregas:pedido:order-1']), 'a trava é solta');

resumo();
