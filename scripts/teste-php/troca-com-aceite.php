<?php

// Oferta ao motoboy escolhido (troca ou atribuição pela central e pelo líder antes da coleta):
// Distribuidor::oferecerAoEscolhidoSemTrava e o ciclo depois dela, com e sem rodadas. O reset do pedido na
// TrocaDoMotoboy tem teste em lider.php. Spec: docs/superpowers/specs/2026-10-28-troca-com-aceite-e-buzina-design.md.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/troca-com-aceite.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Jobs\Entregas\AvancarOferta;
use App\Notifications\Entregas\OfertaDePedido;
use App\Support\Entregas\Distribuicao\Candidatos;
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

function ponto(array $p): object
{
    return new class($p) { public function __construct(private array $p) {} public function getLat() { return $this->p[0]; } public function getLng() { return $this->p[1]; } };
}

/** Motoboy livre a $km ao norte da coleta (0,009° de latitude ≈ 1 km); sem $km, sem posição. */
function motoboyA(string $id, string $nome, ?float $km): array
{
    $posicao = $km === null ? null : [-21.1700 + 0.009 * $km, -47.8100];
    $motoboy = new Driver(['uuid' => 'd-' . $id, 'public_id' => 'driver_' . $id, 'company_uuid' => 'empresa-1', 'name' => $nome, 'online' => true, 'location' => $posicao ? ponto($posicao) : null]);

    return ['motoboy' => $motoboy, 'posicao' => $posicao, 'distancia' => ($km ?? 0) * 1000];
}

/**
 * Pedido aberto (R = 6 km). $naBusca: os que a busca de candidatos acha (a busca falsa filtra pelo raio, como o
 * distanceSphere); $todos: os cadastrados (o escolhido pode estar fora da busca).
 */
function cenario(array $naBusca, array $todos, bool $rodadas = true): Distribuidor
{
    reiniciarFleetbase();
    reiniciarIfood();
    Relogio::$agora = '2026-10-28 10:00:00';
    Config::$valores['services.entregas.distribuicao']         = '1';
    Config::$valores['services.entregas.distribuicao_rodadas'] = $rodadas ? '1' : '';
    session(['company' => 'empresa-1']);
    $pedido          = new Order(['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'adhoc' => true, 'dispatched' => true, 'status' => 'dispatched']);
    $pedido->payload = (object) ['pickup' => (object) ['location' => ponto([-21.1700, -47.8100])], 'dropoff' => (object) ['location' => ponto([-21.1800, -47.8100])]];
    Order::$todos[]  = $pedido;
    Driver::$todos   = array_map(fn ($c) => $c['motoboy'], $todos);
    Candidatos::$buscarMotoboys = fn ($pedido, $gpsRecente, $raio = null) => array_values(array_filter($naBusca, fn ($c) => $c['distancia'] <= ($raio ?? 6000)));
    Candidatos::$buscarParadas  = fn () => [];
    $estimador = new class extends EstimadorDeTempo {
        public function matriz(array $pontos): array { $m = []; foreach ($pontos as $i => $a) { foreach ($pontos as $j => $b) { $m[$i][$j] = (float) Pontos::segundos($a, $b); } } return ['durations' => $m, 'aproximado' => true]; }
    };

    return new Distribuidor(new FilaDeCandidatos($estimador));
}

function pedidoDoCenario(): Order { return Order::$todos[0]; }
function ofertas(): array { return DB::table('entregas_ofertas')->orderBy('id')->get()->all(); }
function distribuicoes(): array { return DB::table('entregas_distribuicoes')->orderBy('id')->get()->all(); }
function avisos(): array { return array_map(fn ($a) => [$a[0], get_class($a[1])], Driver::$avisos); }

$ana   = motoboyA('ana', 'Ana', 2);
$bruno = motoboyA('bruno', 'Bruno', 4);
$zeca  = motoboyA('zeca', 'Zeca', 20); // além de 2R (12 km): nenhuma rodada o acharia

echo '== Em rodadas: a oferta vai primeiro ao escolhido, mesmo fora do raio' . PHP_EOL;
$dist   = cenario([$ana, $bruno], [$ana, $bruno, $zeca]);
$oferta = $dist->oferecerAoEscolhidoSemTrava(pedidoDoCenario(), $zeca['motoboy']);
$o      = ofertas()[0] ?? null;
confere($oferta !== null && $o?->id === $oferta->id && $o->motoboy_uuid === 'd-zeca' && $o->resposta === 'pendente', 'oferta pendente ao escolhido, devolvida ao chamador (' . json_encode($o) . ')');
confere($o->volta === 1 && $o->rodada === 1 && $o->raio_m === 6000 && $o->posicao === 1 && $o->vence_em === '2026-10-28 10:00:50', 'volta 1, rodada 1 (raio R), posição 1, 50 s');
confere($o->tempo_estimado_s > 0 && (bool) $o->aproximado === true, 'tempo até o cliente estimado pela posição dele (linha reta)');
$d = distribuicoes()[0];
confere(count(distribuicoes()) === 1 && $d->fase === 'ofertas' && $d->lista_aberta_em === null, 'distribuição nova em ofertas, com a lista fechada');
confere(array_column(json_decode($d->fila, true), 'public_id') === ['driver_zeca'], 'fila gravada para o painel: só o escolhido');
confere(avisos() === [['driver_zeca', OfertaDePedido::class]] && Driver::$avisos[0][1]->segundosDaOferta === 50, 'push "Oferta para você" de 50 s só ao escolhido');
confere((Fila::$jobs[0] ?? null) instanceof AvancarOferta && Fila::$jobs[0]->delay === 50, 'job AvancarOferta em 50 s');
confere(Distribuicoes::ofertaParaAceite('order-1', 'd-zeca')?->id === $oferta->id && Distribuicoes::ofertaParaAceite('order-1', 'd-ana') === null, 'só o escolhido pode aceitar (a lista está fechada)');
confere(logou('oferta ao escolhido enviada', 'info') && logsSem(['Zeca', 'Ana', '-21.1']), 'log da oferta ao escolhido, só com ids e números');

echo '== Recusou: a fila de sempre, e o anterior pode receber' . PHP_EOL;
Relogio::$agora = '2026-10-28 10:00:20';
// Ana faz o papel do motoboy de quem o pedido foi tirado: é candidata como qualquer um (decisão do Edgard)
confere($dist->recusar('order-1', $zeca['motoboy']) === true, 'o escolhido recusa');
confere(ofertas()[0]->resposta === 'recusada' && (ofertas()[1]->motoboy_uuid ?? null) === 'd-ana' && ofertas()[1]->volta === 1 && ofertas()[1]->rodada === 1, 'próxima oferta pela rodada 1: Ana, a mais perto');
confere(!in_array('d-zeca', array_column(array_slice(ofertas(), 1), 'motoboy_uuid'), true), 'o escolhido não volta na volta 1');

echo '== Venceu: a fila de sempre' . PHP_EOL;
$dist = cenario([$ana, $bruno], [$ana, $bruno]);
$dist->oferecerAoEscolhidoSemTrava(pedidoDoCenario(), $bruno['motoboy']);
Relogio::$agora = '2026-10-28 10:00:51';
(new AvancarOferta(1))->handle($dist);
confere(ofertas()[0]->resposta === 'vencida' && (ofertas()[1]->motoboy_uuid ?? null) === 'd-ana', 'o escolhido deixou vencer: Ana');

echo '== Distribuição em curso: redespachada' . PHP_EOL;
$dist = cenario([$ana, $bruno], [$ana, $bruno]);
$dist->iniciar(pedidoDoCenario());
confere(ofertas()[0]->motoboy_uuid === 'd-ana' && ofertas()[0]->resposta === 'pendente', 'antes: a distribuição oferecia a Ana');
$dist->oferecerAoEscolhidoSemTrava(pedidoDoCenario(), $bruno['motoboy']);
[$velha, $nova] = distribuicoes();
confere($velha->fase === 'encerrada' && $velha->motivo === 'redespachada' && ofertas()[0]->resposta === 'cancelada', 'a anterior encerra como redespachada e a oferta de Ana é cancelada');
confere($nova->fase === 'ofertas' && ofertas()[1]->distribuicao_id === $nova->id && ofertas()[1]->motoboy_uuid === 'd-bruno', 'a nova oferece a Bruno');

echo '== Escolhido sem posição' . PHP_EOL;
$semGps = motoboyA('sem', 'Sem GPS', null);
$dist   = cenario([$ana], [$ana, $semGps]);
$dist->oferecerAoEscolhidoSemTrava(pedidoDoCenario(), $semGps['motoboy']);
$o = ofertas()[0];
confere($o->motoboy_uuid === 'd-sem' && $o->tempo_estimado_s === 0 && (bool) $o->aproximado === true && avisos() === [['driver_sem', OfertaDePedido::class]], 'sem posição: oferta assim mesmo, tempo 0 aproximado');

echo '== Sem rodadas' . PHP_EOL;
$dist   = cenario([$ana, $bruno], [$ana, $bruno], false);
$oferta = $dist->oferecerAoEscolhidoSemTrava(pedidoDoCenario(), $bruno['motoboy']);
confere($oferta->vence_em === '2026-10-28 10:00:30' && $oferta->raio_m === null, 'oferta de 30 s, sem raio de rodada');
Relogio::$agora = '2026-10-28 10:00:10';
$dist->recusar('order-1', $bruno['motoboy']);
confere((ofertas()[1]->motoboy_uuid ?? null) === 'd-ana' && distribuicoes()[0]->fase === 'ofertas', 'recusou: a fila do ciclo sem rodadas (Ana)');

echo '== Distribuição desligada' . PHP_EOL;
$dist = cenario([$ana], [$ana, $bruno]);
Config::$valores['services.entregas.distribuicao'] = '';
confere($dist->oferecerAoEscolhidoSemTrava(pedidoDoCenario(), $bruno['motoboy']) === null && distribuicoes() === [] && avisos() === [], 'desligada: null, nada gravado, ninguém avisado');

echo '== Falha: abre a todos com o alarme geral' . PHP_EOL;
$dist = cenario([$ana, $bruno], [$ana, $bruno]);
Candidatos::$buscarParadas = fn () => throw new \RuntimeException('banco fora');
$oferta = $dist->oferecerAoEscolhidoSemTrava(pedidoDoCenario(), $bruno['motoboy']);
$d      = distribuicoes()[0];
confere($oferta === null && $d->fase === 'aberta' && $d->motivo === 'falha', 'a falha abre a distribuição (falha) e devolve null, sem lançar');
confere(array_column(avisos(), 1) === [OrderPing::class, OrderPing::class], 'alarme geral a todos no raio');
confere(logou('falha na oferta ao escolhido; aberta a todos', 'warning'), 'log da falha');

resumo(); // dos stubs: imprime "FALHAS: <n>"
