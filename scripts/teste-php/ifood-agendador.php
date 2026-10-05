<?php

// Integração iFood: os comandos entregas:ifood-tokens e entregas:ifood-agendados e o agendamento no Kernel.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-agendador.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Console\Commands\Entregas\AgendadosIfood;
use App\Console\Commands\Entregas\RenovarTokensIfood;
use App\Console\Kernel;
use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\CriadorDoPedidoIfood;
use App\Support\Entregas\Ifood\VinculosIfood;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Teste\Banco;
use Teste\Config;
use Teste\Http;
use Teste\Trava;

/** Linha de entregas_ifood_pedidos com um Order; devolve o Order. */
function pedidoIfood(string $id, ?string $despacharEm, array $extra = [], string $status = 'created', array $doPedido = []): Order
{
    $pedido = Order::create($doPedido + ['company_uuid' => 'empresa-1', 'status' => $status]);
    Banco::inserir('entregas_ifood_pedidos', $extra + [
        'company_uuid' => 'empresa-1', 'order_uuid' => $pedido->uuid, 'pedido_ifood_id' => $id, 'numero' => $id, 'merchant_id' => 'merchant-1',
        'teste' => false, 'agendado' => true, 'despachar_em' => $despacharEm, 'despachado_em' => null, 'cancelado_pelo_ifood_em' => null,
    ], false);

    return $pedido;
}

function linhaDe(string $id): object
{
    return (new Teste\Consulta('entregas_ifood_pedidos'))->where('pedido_ifood_id', $id)->first();
}

function rodarAgendados(): int
{
    return (new AgendadosIfood())->handle(new CriadorDoPedidoIfood());
}

/** Os logs com a mensagem que contém $trecho. */
function logsCom(string $trecho): array
{
    return array_values(array_filter(Log::$registros, fn ($registro) => str_contains($registro[1], $trecho)));
}

echo '== entregas:ifood-tokens' . PHP_EOL;
reiniciarIfood();
reiniciarFleetbase();
vinculoDaLojaA('2026-10-05 18:40:00');
Config::$valores['services.ifood.ativo'] = '';
(new RenovarTokensIfood())->handle(new VinculosIfood(new ClienteIfood()));
confere(Http::$chamadas === [], 'desligada: não renova');
Config::$valores['services.ifood.ativo'] = '1';
Http::responder(200, ['accessToken' => 'token-a2', 'type' => 'bearer', 'expiresIn' => 21600, 'refreshToken' => 'refresh-a2']);
confere((new RenovarTokensIfood())->handle(new VinculosIfood(new ClienteIfood())) === 0, 'sai com 0');
confere(Http::urls() === ['POST /authentication/v1.0/oauth/token'] && logou('[entregas] ifood: renovação dos tokens', 'info'), 'renova o que vence em 40 min e registra a contagem');

echo '== entregas:ifood-agendados' . PHP_EOL;
reiniciarIfood();
reiniciarFleetbase();
$vencido   = pedidoIfood('vencido', '2026-10-05 17:50:00');
$futuro    = pedidoIfood('futuro', '2026-10-05 19:20:00');
$agora     = pedidoIfood('agora', '2026-10-05 17:59:30');
$teste     = pedidoIfood('teste', null, ['teste' => true]);
$cancelado = pedidoIfood('cancelado', '2026-10-05 17:00:00', ['cancelado_pelo_ifood_em' => '2026-10-05 17:30:00']);
$feito     = pedidoIfood('feito', '2026-10-05 17:00:00', ['despachado_em' => '2026-10-05 17:00:05']);
$encerrado = pedidoIfood('encerrado', '2026-10-05 17:00:00', [], 'canceled');
Config::$valores['services.ifood.ativo'] = '';
rodarAgendados();
confere($vencido->chamadas === [], 'desligada: não despacha');
Config::$valores['services.ifood.ativo'] = '1';
rodarAgendados();
confere($vencido->chamadas === ['saveQuietly', 'firstDispatchWithActivity'] && linhaDe('vencido')->despachado_em === '2026-10-05 18:00:00', 'despacha o vencido e marca despachado_em');
confere($futuro->chamadas === [] && $agora->chamadas === [], 'o futuro espera; o recém-criado tem 1 min de folga (o job despacha)');
confere($teste->chamadas === [] && $cancelado->chamadas === [] && $feito->chamadas === [], 'teste, cancelado pelo iFood e já despachado ficam de fora');
confere($encerrado->chamadas === [] && linhaDe('encerrado')->despachar_em === null, 'encerrado pela central: sai da fila');
confere(count(logsCom('[entregas] ifood: pedido despachado pelo agendador')) === 1 && logou('[entregas] ifood: pedido despachado pelo agendador', 'info'), 'log do despacho (só o despachado)');
confere(!logou('despacho desistiu'), 'ninguém desistiu (o encerrado sai da fila sem o aviso)');
$vencido->chamadas = [];
rodarAgendados();
confere($vencido->chamadas === [], 'na rodada seguinte, não despacha de novo');

echo '== agendados: motoboy atribuído pela central' . PHP_EOL;
reiniciarIfood();
reiniciarFleetbase();
$atribuido = pedidoIfood('atribuido', '2026-10-05 17:50:00', [], 'created', ['driver_assigned_uuid' => 'motoboy-1']);
rodarAgendados();
// a política do motoboy atribuído é do CriadorDoPedidoIfood::despachar (testada no ifood-criador.php) e mudou durante a
// etapa: antes, nada a despachar (false, linha fora da fila); agora, despacho só ao atribuído, sem adhoc (true). Aqui
// vale o contrato do agendador nos dois casos: o log "despachado pelo agendador" só com true, e a linha nunca fica na fila
if (linhaDe('atribuido')->despachado_em !== null) {
    echo '(despachar: atribuído vai só ao motoboy dele)' . PHP_EOL;
    confere($atribuido->chamadas === ['saveQuietly', 'firstDispatchWithActivity'] && $atribuido->adhoc === false, 'atribuído: despachado uma vez, sem pedido aberto (só ao atribuído)');
    confere(count(logsCom('pedido despachado pelo agendador')) === 1 && !logou('despacho desistiu'), 'atribuído: log de despachado, sem desistência');
} else {
    echo '(despachar: atribuído não é despachado)' . PHP_EOL;
    confere($atribuido->chamadas === [] && linhaDe('atribuido')->despachar_em === null, 'atribuído: não avisa os motoboys e sai da fila');
    confere(!logou('pedido despachado pelo agendador') && !logou('despacho desistiu'), 'atribuído: sem log de despachado nem de desistência');
}
$atribuido->chamadas = [];
rodarAgendados();
confere($atribuido->chamadas === [], 'atribuído: na rodada seguinte, nada');

echo '== agendados: trava do pedido ocupada' . PHP_EOL;
reiniciarIfood();
reiniciarFleetbase();
$travado = pedidoIfood('travado', '2026-10-05 17:50:00');
Trava::$ocupadas['entregas:pedido:' . $travado->uuid] = true;
rodarAgendados();
confere($travado->chamadas === [] && linhaDe('travado')->despachar_em === '2026-10-05 17:50:00' && linhaDe('travado')->despachado_em === null, 'trava ocupada: a linha continua na fila');
confere(!logou('pedido despachado pelo agendador') && !logou('despacho desistiu'), 'trava ocupada: sem log de despachado nem de desistência');
unset(Trava::$ocupadas['entregas:pedido:' . $travado->uuid]);
rodarAgendados();
confere($travado->chamadas === ['saveQuietly', 'firstDispatchWithActivity'] && linhaDe('travado')->despachado_em === '2026-10-05 18:00:00', 'trava solta: despacha na rodada seguinte');

echo '== agendados: despacho que falha sempre' . PHP_EOL;
reiniciarIfood();
reiniciarFleetbase();
$recente = pedidoIfood('recente', '2026-10-05 17:45:00');
$velho   = pedidoIfood('velho', '2026-10-05 17:20:00');
Order::$falharDespacho = true;
rodarAgendados();
confere(linhaDe('recente')->despachar_em === '2026-10-05 17:45:00', 'falhou há 15 min: continua na fila');
confere(linhaDe('velho')->despachar_em === null && linhaDe('velho')->despachado_em === null && $velho->chamadas === ['saveQuietly', 'firstDispatchWithActivity'], 'vencido há mais de 30 min: tenta uma vez e, falhando, sai da fila');
$desistencias = logsCom('[entregas] ifood: despacho desistiu');
confere(count($desistencias) === 1 && $desistencias[0][0] === 'warning', 'um warning de desistência');
confere(($desistencias[0][2] ?? null) === ['pedido' => $velho->public_id, 'pedido_ifood' => 'velho'], 'o warning só com ids (pedido e pedido_ifood)');
$velho->chamadas = [];
Log::$registros  = [];
rodarAgendados();
confere($velho->chamadas === [] && !logou('despacho desistiu'), 'na rodada seguinte, não tenta nem avisa de novo');
Order::$falharDespacho = false;
$antigoBom = pedidoIfood('antigo-bom', '2026-10-05 17:00:00');
rodarAgendados();
confere(linhaDe('antigo-bom')->despachado_em === '2026-10-05 18:00:00' && !logou('despacho desistiu'), 'vencido há 1 h mas o despacho sai (agendador parado): despacha, sem desistir');

echo '== Kernel' . PHP_EOL;
$schedule = new Schedule();
$metodo   = new ReflectionMethod(Kernel::class, 'schedule');
$metodo->setAccessible(true);
$metodo->invoke((new ReflectionClass(Kernel::class))->newInstanceWithoutConstructor(), $schedule);
$porComando = [];
foreach ($schedule->eventos as $evento) {
    $porComando[$evento->comando] = $evento->chamadas;
}
confere(array_keys($porComando) === ['entregas:ifood-polling', 'entregas:ifood-agendados', 'entregas:ifood-tokens'], 'os três comandos agendados');
confere(isset($porComando['entregas:ifood-polling']['everyThirtySeconds']) && isset($porComando['entregas:ifood-agendados']['everyMinute']) && isset($porComando['entregas:ifood-tokens']['everyThirtyMinutes']), 'a cada 30 s, a cada minuto e a cada 30 min');
foreach ($porComando as $comando => $chamadas) {
    confere(($chamadas['withoutOverlapping'][0] ?? 0) > 0 && ($chamadas['withoutOverlapping'][0] ?? 99) <= 10, "{$comando}: sem sobrepor, com trava de validade curta");
    confere(($chamadas['appendOutputTo'] ?? null) === ['/proc/1/fd/1'], "{$comando}: saída no stdout do container");
    confere(!isset($chamadas['storeOutputInDb']), "{$comando}: sem storeOutputInDb");
}
confere(isset($porComando['entregas:ifood-polling']['runInBackground']), 'polling em segundo plano (não espera os comandos do Fleet-Ops)');

resumo();
