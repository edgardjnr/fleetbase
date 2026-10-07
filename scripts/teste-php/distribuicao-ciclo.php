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
resumo();
