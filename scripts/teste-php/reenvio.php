<?php

// Reenvio do aviso de pedido aberto (ReenviarPedidosAbertos), com os arquivos reais do Fleet-Ops (fleetops-api 0.6.65).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/reenvio.php

require __DIR__ . '/stubs.php';
require '/repo/packages/fleetops/server/src/Notifications/OrderPing.php';
require '/repo/packages/fleetops/server/src/Console/Commands/DispatchAdhocOrders.php';

use App\Console\Commands\Entregas\ReenviarPedidosAbertos;
use App\Notifications\Entregas\LembretePedidoAberto;
use Carbon\CarbonImmutable;
use Fleetbase\FleetOps\Console\Commands\DispatchAdhocOrders;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\Cache;
use Teste\ConsultaMotoboys;
use Teste\ConsultaPedidos;
use Teste\Registro;

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
reiniciar([pedido('PED-B', '12:00:30')], [motoboy('Motoca', 1200), motoboy('Ocupado', 800, 'busy'), motoboy('Longe', 9000), motoboy('Offline', 500, 'available', 0)]);
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
$motoca = motoboy('Motoca', 9000);
reiniciar([pedido('PED-C', '12:00:30')], [$motoca]);
rodarMinutos('12:01:00', 30, function ($agora) use ($motoca) {
    $motoca->distance = $agora >= utc('12:07:00') ? 1200 : 9000;
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

resumo();
