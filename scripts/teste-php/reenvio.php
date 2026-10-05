<?php

// Reenvio do aviso de pedido aberto (ReenviarPedidosAbertos), com os arquivos reais do Fleet-Ops (fleetops-api 0.6.65).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/reenvio.php

require __DIR__ . '/stubs.php';
require __DIR__ . '/stubs-reenvio.php';
require '/repo/packages/fleetops/server/src/Notifications/OrderPing.php';
require '/repo/packages/fleetops/server/src/Console/Commands/DispatchAdhocOrders.php';

use App\Console\Commands\Entregas\ReenviarPedidosAbertos;
use App\Events\Entregas\PedidoSemMotoboy;
use App\Notifications\Entregas\LembretePedidoAberto;
use Carbon\CarbonImmutable;
use Fleetbase\FleetOps\Console\Commands\DispatchAdhocOrders;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Teste\ConsultaMotoboys;
use Teste\ConsultaPedidos;
use Teste\Registro;
use Teste\Socket;

function utc(string $hora): DateTimeImmutable
{
    return new DateTimeImmutable('2026-10-03 ' . $hora, new DateTimeZone('UTC'));
}

function pedido(string $id, string $despachadoEm): Order
{
    $p                = new Order();
    $p->uuid          = 'uuid-' . $id;
    $p->public_id     = $id;
    $p->created_at    = utc('11:50:00');
    $p->dispatched_at = utc($despachadoEm);

    return $p;
}

function motoboy(string $nome, int $distancia, string $status = 'available', int $online = 1): Driver
{
    $m            = new Driver();
    $m->name      = $nome;
    $m->public_id = 'driver_' . $nome;
    $m->distance  = $distancia;
    $m->status    = $status;
    $m->online    = $online;

    return $m;
}

function reiniciar(array $pedidos, array $motoboys): void
{
    ConsultaPedidos::$pedidos   = $pedidos;
    ConsultaMotoboys::$motoboys = $motoboys;
    Cache::$dados               = [];
    Registro::$avisos           = [];
    Socket::$transmitidos       = [];
    Socket::$falhar             = false;
    Log::$linhas                = [];
}

// roda o comando a cada minuto, de 2 a 6 s depois do minuto cheio, como o agendador
function rodarMinutos(string $de, int $minutos, ?callable $antes = null): void
{
    for ($i = 0; $i < $minutos; $i++) {
        $agora                       = utc($de)->modify("+{$i} minutes")->modify('+' . (2 + ($i * 7) % 5) . ' seconds');
        CarbonImmutable::$agoraTeste = $agora->format('Y-m-d H:i:s');
        if ($antes) {
            $antes($agora);
        }
        (new ReenviarPedidosAbertos())->handle();
    }
}

function horarios(string $pedido): array
{
    $avisos = array_filter(Registro::$avisos, fn ($a) => $a['aviso']->order->public_id === $pedido);

    return array_values(array_map(fn ($a) => $a['quando']->format('H:i:s'), $avisos));
}

function intervalos(array $horarios): array
{
    $saida = [];
    for ($i = 1; $i < count($horarios); $i++) {
        $saida[] = utc($horarios[$i])->getTimestamp() - utc($horarios[$i - 1])->getTimestamp();
    }

    return $saida;
}

confereVersaoDoFleetOps();

echo '== Original do Fleet-Ops (fleetops-api 0.6.65)' . PHP_EOL;
reiniciar([pedido('PED-A', '12:05:00')], [motoboy('Motoca', 1200)]);
CarbonImmutable::$agoraTeste = '2026-10-03 12:10:00';
$encontrados                 = (new DispatchAdhocOrders())->getDispatchableOrders(2);
[$de, $ate]                  = ConsultaPedidos::$limitesUsados;
confere($de === $ate, "os dois limites do whereBetween viram o mesmo instante ({$de} e {$ate})");
confere($encontrados->isEmpty(), 'o pedido aberto há 5 min não é encontrado');

echo '== Nosso comando, mesmo pedido' . PHP_EOL;
reiniciar([pedido('PED-A', '12:05:00')], [motoboy('Motoca', 1200)]);
CarbonImmutable::$agoraTeste = '2026-10-03 12:10:00';
(new ReenviarPedidosAbertos())->handle();
[$de, $ate] = ConsultaPedidos::$limitesUsados;
confere($de === '2026-10-03 11:54:00' && $ate === '2026-10-03 12:06:30', "janela de despacho de {$de} a {$ate}");
confere(count(Registro::$avisos) === 1, 'o pedido aberto há 5 min recebe o aviso');

echo '== Linha do tempo: despachado 12:00:30 e ninguém aceita' . PHP_EOL;
reiniciar([pedido('PED-B', '12:00:30')], [motoboy('Motoca', 1200), motoboy('Ocupado', 800, 'busy'), motoboy('Longe', 13000), motoboy('Offline', 500, 'available', 0)]);
rodarMinutos('12:01:00', 30);
$h = horarios('PED-B');
confere(count($h) === 3, 'três avisos extras e nada depois, em 30 min (' . implode(', ', $h) . ')');
confere(($h[0] ?? '') >= '12:04:00' && ($h[0] ?? '') < '12:05:00', 'o primeiro sai uns 4 min depois do despacho');
$gaps = intervalos($h);
confere($gaps && min($gaps) >= 230 && max($gaps) <= 250, 'cerca de 4 min entre um aviso e outro (' . implode('s, ', $gaps) . 's)');
$nomes = array_values(array_unique(array_map(fn ($a) => $a['motoboy'], Registro::$avisos)));
confere($nomes === ['Motoca'], 'só o motoboy online, livre e no raio recebe (' . implode(', ', $nomes) . ')');
$aviso = Registro::$avisos[0]['aviso'] ?? null;
confere($aviso instanceof LembretePedidoAberto, 'o aviso é o LembretePedidoAberto');
confere($aviso?->title === 'Pedido ainda sem motoboy' && $aviso?->message === 'Coleta a 1,2 km de você. Toque para ver o pedido.', "texto em pt-BR: {$aviso?->title} / {$aviso?->message}");
confere($aviso?->data === ['id' => 'PED-B', 'type' => 'order_ping'], 'dados do push iguais aos do primeiro aviso (order_ping)');

echo '== Sem motoboy no raio até 12:07' . PHP_EOL;
$motoca = motoboy('Motoca', 13000);
reiniciar([pedido('PED-C', '12:00:30')], [$motoca]);
rodarMinutos('12:01:00', 30, function ($agora) use ($motoca) {
    $motoca->distance = $agora >= utc('12:07:00') ? 1200 : 13000;
});
$h = horarios('PED-C');
confere(count($h) === 3 && $h[0] >= '12:07:00' && $h[0] < '12:08:00', 'não gasta reenvio sem motoboy e avisa assim que um entra no raio (' . implode(', ', $h) . ')');

echo '== Aceito às 12:06' . PHP_EOL;
$p = pedido('PED-D', '12:00:30');
reiniciar([$p], [motoboy('Motoca', 1200)]);
rodarMinutos('12:01:00', 20, function ($agora) use ($p) {
    if ($agora >= utc('12:06:00')) {
        $p->driver_assigned_uuid = 'uuid-motoca';
    }
});
confere(count(horarios('PED-D')) === 1, 'para de avisar depois do aceite (' . implode(', ', horarios('PED-D')) . ')');

echo '== Cancelado pela loja às 12:06' . PHP_EOL;
$p = pedido('PED-E', '12:00:30');
reiniciar([$p], [motoboy('Motoca', 1200)]);
rodarMinutos('12:01:00', 20, function ($agora) use ($p) {
    if ($agora >= utc('12:06:00')) {
        $p->status = 'canceled';
    }
});
confere(count(horarios('PED-E')) === 1, 'para de avisar depois do cancelamento (' . implode(', ', horarios('PED-E')) . ')');

echo '== Despachado de novo às 12:20' . PHP_EOL;
$p = pedido('PED-F', '12:00:30');
reiniciar([$p], [motoboy('Motoca', 1200)]);
rodarMinutos('12:01:00', 40, function ($agora) use ($p) {
    if ($agora->format('H:i') === '12:20') {
        $p->dispatched_at = utc('12:20:00');
    }
});
$h = horarios('PED-F');
confere(count($h) === 6 && ($h[3] ?? '') >= '12:23:30' && ($h[3] ?? '') < '12:25:00', 'um novo despacho recomeça a contagem (' . implode(', ', $h) . ')');

echo '== Dois pedidos ao mesmo tempo' . PHP_EOL;
reiniciar([pedido('PED-G1', '12:00:30'), pedido('PED-G2', '12:02:10')], [motoboy('Motoca', 1200)]);
rodarMinutos('12:01:00', 30);
confere(count(horarios('PED-G1')) === 3 && count(horarios('PED-G2')) === 3, 'cada pedido tem a própria contagem (' . implode(', ', horarios('PED-G1')) . ' | ' . implode(', ', horarios('PED-G2')) . ')');

echo '== Pedidos que não são oferecidos aos motoboys' . PHP_EOL;
$fora = [
    'não ad hoc'       => fn ($p) => $p->adhoc = 0,
    'já iniciado'      => fn ($p) => $p->started = 1,
    'apagado'          => fn ($p) => $p->deleted_at = utc('11:55:00'),
    'sem carga'        => fn ($p) => $p->payload = false,
    'criado há 3 dias' => fn ($p) => $p->created_at = utc('11:50:00')->modify('-3 days'),
];
foreach ($fora as $nome => $ajustar) {
    $p = pedido('PED-X', '12:00:30');
    $ajustar($p);
    reiniciar([$p], [motoboy('Motoca', 1200)]);
    rodarMinutos('12:01:00', 10);
    confere(horarios('PED-X') === [], "{$nome}: nenhum aviso");
}

echo '== Texto da distância' . PHP_EOL;
$p = pedido('PED-H', '12:00:00');
confere((new LembretePedidoAberto($p, 800))->message === 'Coleta a 800 m de você. Toque para ver o pedido.', '800 m');
confere((new LembretePedidoAberto($p, 15000))->message === 'Coleta a 15,0 km de você. Toque para ver o pedido.', '15,0 km');
confere((new LembretePedidoAberto($p, null))->message === 'Toque para ver o pedido.', 'sem distância');

echo '== Lista única de status encerrados' . PHP_EOL;
confere(!defined(ReenviarPedidosAbertos::class . '::ENCERRADOS'), 'o reenvio usa StatusDoPedido::ENCERRADOS, sem lista própria');
foreach (App\Support\Entregas\StatusDoPedido::ENCERRADOS as $status) {
    $p         = pedido('PED-S', '12:00:30');
    $p->status = $status;
    reiniciar([$p], [motoboy('Motoca', 1200)]);
    rodarMinutos('12:01:00', 6);
    confere(horarios('PED-S') === [], "pedido {$status} não é reenviado");
}

echo '== Raio crescente (R = 6000 m)' . PHP_EOL;
confere(ReenviarPedidosAbertos::raioDoReenvio(6000, 1) === 9000, '1º reenvio: 1,5R');
confere(ReenviarPedidosAbertos::raioDoReenvio(6000, 2) === 12000, '2º reenvio: 2R');
confere(ReenviarPedidosAbertos::raioDoReenvio(6000, 3) === 12000, '3º reenvio: 2R');
confere(ReenviarPedidosAbertos::raioDoReenvio(6000, 9) === 12000, 'além do 3º: continua 2R');
confere(ReenviarPedidosAbertos::raioDoReenvio(5000, 1) === 7500, 'arredonda para metros inteiros');

reiniciar([pedido('PED-R', '12:00:30')], [motoboy('Perto', 5000), motoboy('Medio', 8000), motoboy('Longe', 11000), motoboy('MuitoLonge', 13000)]);
rodarMinutos('12:01:00', 30);
$porMinuto = [];
foreach (Registro::$avisos as $a) {
    $porMinuto[$a['quando']->format('H:i')][] = $a['motoboy'];
}
$rodadas = array_values($porMinuto);
confere(count($rodadas) === 3, 'três reenvios (' . implode(', ', array_keys($porMinuto)) . ')');
confere(($rodadas[0] ?? []) === ['Perto', 'Medio'], '1º reenvio até 9 km: ' . implode(', ', $rodadas[0] ?? []));
confere(($rodadas[1] ?? []) === ['Perto', 'Medio', 'Longe'], '2º reenvio até 12 km: ' . implode(', ', $rodadas[1] ?? []));
confere(($rodadas[2] ?? []) === ['Perto', 'Medio', 'Longe'], '3º reenvio até 12 km: ' . implode(', ', $rodadas[2] ?? []));

echo '== Etapa do raio pelo tempo' . PHP_EOL;
confere(ReenviarPedidosAbertos::etapaPeloTempo(100) === 1, 'antes de 4 min: etapa 1');
confere(ReenviarPedidosAbertos::etapaPeloTempo(212) === 1, '~4 min: etapa 1');
confere(ReenviarPedidosAbertos::etapaPeloTempo(452) === 2, '~8 min: etapa 2');
confere(ReenviarPedidosAbertos::etapaPeloTempo(692) === 3, '~12 min: etapa 3');
confere(ReenviarPedidosAbertos::etapaPeloTempo(209) === 1, 'virada: 209 s ainda etapa 1');
confere(ReenviarPedidosAbertos::etapaPeloTempo(449) === 1, 'virada: 449 s ainda etapa 1');
confere(ReenviarPedidosAbertos::etapaPeloTempo(450) === 2, 'virada: 450 s já etapa 2');
confere(ReenviarPedidosAbertos::etapaPeloTempo(690) === 3, 'virada: 690 s já etapa 3');

echo '== Só há motoboy a 10 km (entre 1,5R e 2R)' . PHP_EOL;
reiniciar([pedido('PED-T', '12:00:30')], [motoboy('Dez', 10000)]);
rodarMinutos('12:01:00', 30);
$h = horarios('PED-T');
confere(($h[0] ?? '') >= '12:08:00' && ($h[0] ?? '') < '12:09:00', 'recebe quando o raio chega a 2R, mesmo sem reenvio antes (' . implode(', ', $h) . ')');

echo '== R do próprio pedido (4000 m)' . PHP_EOL;
$p                 = pedido('PED-U', '12:00:30');
$p->adhoc_distance = 4000;
reiniciar([$p], [motoboy('A', 5500), motoboy('B', 6500)]);
rodarMinutos('12:01:00', 30);
$porMinuto = [];
foreach (Registro::$avisos as $a) {
    $porMinuto[$a['quando']->format('H:i')][] = $a['motoboy'];
}
$rodadas = array_values($porMinuto);
confere(($rodadas[0] ?? []) === ['A'], '1º reenvio até 6 km (1,5 × 4000): ' . implode(', ', $rodadas[0] ?? []));
confere(($rodadas[1] ?? []) === ['A', 'B'], '2º reenvio até 8 km: ' . implode(', ', $rodadas[1] ?? []));

function avisosACentral(): array
{
    return array_map(fn ($t) => $t['quando']->format('H:i:s'), Socket::$transmitidos);
}

echo '== Aviso à central: ninguém aceita' . PHP_EOL;
$p              = pedido('PED-S1', '12:00:30');
$p->internal_id = '4821';
reiniciar([$p], [motoboy('Motoca', 1200)]);
rodarMinutos('12:01:00', 30);
$h = avisosACentral();
confere(count($h) === 1, 'um aviso só em 30 min (' . implode(', ', $h) . ')');
confere(($h[0] ?? '') >= '12:12:00' && ($h[0] ?? '') < '12:13:00', 'sai uns 12 min depois do despacho');
$evento = Socket::$transmitidos[0]['evento'] ?? null;
confere($evento instanceof PedidoSemMotoboy, 'o evento é o PedidoSemMotoboy');
confere($evento?->broadcastOn()[0]->name === 'company.empresa', 'no canal company.<uuid da empresa>');
confere($evento?->broadcastAs() === 'entregas.pedido_sem_motoboy', 'nome entregas.pedido_sem_motoboy (o console espera este)');
$dados = $evento?->broadcastWith() ?? [];
confere(($dados['event'] ?? null) === 'entregas.pedido_sem_motoboy', 'event no corpo da mensagem');
confere(($dados['data'] ?? null) === ['id' => 'PED-S1', 'uuid' => 'uuid-PED-S1', 'numero' => '4821', 'minutos' => 12], 'dados: public_id, uuid, número e minutos (' . json_encode($dados['data'] ?? null) . ')');

echo '== Aviso à central: sem número interno' . PHP_EOL;
reiniciar([pedido('PED-S2', '12:00:30')], [motoboy('Motoca', 1200)]);
rodarMinutos('12:01:00', 15);
confere((Socket::$transmitidos[0]['evento'] ?? null)?->broadcastWith()['data']['numero'] === 'PED-S2', 'número = public_id');

echo '== Aviso à central: nenhum motoboy no raio o tempo todo' . PHP_EOL;
reiniciar([pedido('PED-S3', '12:00:30')], [motoboy('Motoca', 20000)]);
rodarMinutos('12:01:00', 30);
confere(horarios('PED-S3') === [] && count(avisosACentral()) === 1, 'sem reenvio, mas a central é avisada (' . implode(', ', avisosACentral()) . ')');

echo '== Aviso à central: aceito às 12:06' . PHP_EOL;
$p = pedido('PED-S4', '12:00:30');
reiniciar([$p], [motoboy('Motoca', 1200)]);
rodarMinutos('12:01:00', 30, function ($agora) use ($p) {
    if ($agora >= utc('12:06:00')) {
        $p->driver_assigned_uuid = 'uuid-motoca';
    }
});
confere(avisosACentral() === [], 'pedido aceito não avisa a central');

echo '== Aviso à central: despachado de novo às 12:20' . PHP_EOL;
$p = pedido('PED-S5', '12:00:30');
reiniciar([$p], [motoboy('Motoca', 1200)]);
rodarMinutos('12:01:00', 40, function ($agora) use ($p) {
    if ($agora->format('H:i') === '12:20') {
        $p->dispatched_at = utc('12:20:00');
    }
});
$h = avisosACentral();
confere(count($h) === 2 && ($h[1] ?? '') >= '12:31:30' && ($h[1] ?? '') < '12:33:00', 'um aviso por despacho (' . implode(', ', $h) . ')');

echo '== Aviso à central: socket fora do ar até 12:13:30' . PHP_EOL;
reiniciar([pedido('PED-S6', '12:00:30')], [motoboy('Motoca', 1200)]);
rodarMinutos('12:01:00', 30, function ($agora) {
    Socket::$falhar = $agora < utc('12:13:30');
});
$h = avisosACentral();
confere(count($h) === 1 && ($h[0] ?? '') >= '12:14:00' && ($h[0] ?? '') < '12:15:00', 'tenta de novo no minuto seguinte e avisa uma vez (' . implode(', ', $h) . ')');
$falhasNoLog = array_filter(Log::$linhas, fn ($l) => $l[1] === '[entregas] aviso de pedido sem motoboy não chegou ao socket');
confere(count($falhasNoLog) === 2, 'cada falha fica no log (' . count($falhasNoLog) . ')');
confere(count(horarios('PED-S6')) === 3, 'os reenvios aos motoboys não param por causa do socket');

resumo();
