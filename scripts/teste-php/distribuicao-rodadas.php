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
confere(DB::table('entregas_ofertas')->where('distribuicao_id', $id)->where('volta', 1)->value('resposta') === 'aceita_pela_lista', 'a resposta nova cabe na coluna (texto) e a volta filtra');

resumo();
