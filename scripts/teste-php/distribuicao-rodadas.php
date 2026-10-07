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

resumo();
