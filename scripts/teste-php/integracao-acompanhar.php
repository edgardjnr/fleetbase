<?php

// Integração: o entregas:integracao-acompanhar (AcompanharIntegracao): refaz o despacho que falhou e enfileira os avisos
// que faltam.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-acompanhar.php

require __DIR__ . '/stubs-integracao.php';

use App\Console\Commands\Entregas\AcompanharIntegracao;
use App\Support\Entregas\Integracao\ChavesDeIntegracao;
use App\Support\Entregas\Integracao\CriadorDoPedidoDaIntegracao;
use Teste\Fila;

function acompanhar(): int
{
    return (new AcompanharIntegracao())->handle(app(CriadorDoPedidoDaIntegracao::class));
}

echo '== Aviso que falta: enfileira' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao();
confere(acompanhar() === 0 && count(Fila::$jobs) === 1 && Fila::$jobs[0]->orderUuid === 'order-1', 'aceito sem aviso: um job');
Fila::$jobs = [];
confere(acompanhar() === 0 && Fila::$jobs === [], 'job ainda pendente (marca no cache): não enfileira outro');

echo '== Nada a avisar' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao([], ['ultimo_aviso' => 'aceito', 'motoboy_avisado' => 'driver-1']);
acompanhar();
confere(Fila::$jobs === [], 'já avisado: nada');
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao(['status' => 'completed'], ['ultimo_aviso' => 'concluido']);
acompanhar();
confere(Fila::$jobs === [], 'concluído e avisado: fora da consulta');
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao([], ['created_at' => '2026-10-04 14:00:00']);
acompanhar();
confere(Fila::$jobs === [], 'criado há mais de 24 h: fora');

echo '== Loja que não recebe' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA(false);
pedidoDaIntegracao();
acompanhar();
confere(Fila::$jobs === [], 'loja não conectada: não enfileira');
reiniciarIntegracao();
ChavesDeIntegracao::registrarFalhaDeAviso(lojaIntegradaA(), 'o sistema de origem respondeu 500');
pedidoDaIntegracao();
acompanhar();
confere(Fila::$jobs === [], 'falha de aviso há menos de 5 min: espera');
\Teste\Relogio::$agora = '2026-10-05 15:06:00';
acompanhar();
confere(count(Fila::$jobs) === 1, 'depois de 5 min: tenta de novo');

echo '== Despacho que falhou' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
$pedido = pedidoDaIntegracao(['status' => 'created', 'started' => false, 'dispatched' => false, 'driver_assigned_uuid' => null], ['despachado_em' => null, 'created_at' => '2026-10-05 14:58:00']);
acompanhar();
confere($pedido->chamadas === ['saveQuietly', 'firstDispatchWithActivity'] && $pedido->adhoc === true && linhaDaIntegracao()->despachado_em === '2026-10-05 15:00:00', 'despacha agora, como pedido aberto');
reiniciarIntegracao();
lojaIntegradaA();
$pedido = pedidoDaIntegracao(['status' => 'created', 'started' => false, 'dispatched' => false, 'driver_assigned_uuid' => null], ['despachado_em' => null, 'created_at' => '2026-10-05 14:59:30']);
acompanhar();
confere($pedido->chamadas === [], 'criado há menos de 1 min: espera o despacho do próprio pedido');
reiniciarIntegracao();
lojaIntegradaA();
$pedido = pedidoDaIntegracao(['status' => 'created', 'started' => false, 'dispatched' => false, 'driver_assigned_uuid' => null], ['despachado_em' => null, 'sem_localizacao' => true, 'created_at' => '2026-10-05 14:50:00']);
acompanhar();
confere($pedido->chamadas === [], 'sem localização: a central atribui, não despacha');
reiniciarIntegracao();
lojaIntegradaA();
$pedido = pedidoDaIntegracao(['status' => 'canceled', 'started' => false, 'dispatched' => false, 'driver_assigned_uuid' => null], ['despachado_em' => null, 'created_at' => '2026-10-05 14:50:00']);
acompanhar();
confere($pedido->chamadas === [], 'cancelado: não despacha');

echo '== Linhas presas não tiram a vez' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
for ($i = 0; $i < 300; $i++) {
    pedidoDaIntegracao(['uuid' => "velho-{$i}", 'public_id' => "order_v{$i}", 'status' => 'created', 'started' => false, 'dispatched' => false, 'driver_assigned_uuid' => null, 'deleted_at' => '2026-10-05 14:00:00'], ['order_uuid' => "velho-{$i}", 'pedido_externo_id' => "v{$i}", 'despachado_em' => null, 'created_at' => '2026-10-05 14:50:00']);
}
$novo = pedidoDaIntegracao(['status' => 'created', 'started' => false, 'dispatched' => false, 'driver_assigned_uuid' => null], ['despachado_em' => null, 'created_at' => '2026-10-05 14:58:00']);
acompanhar();
confere($novo->chamadas === ['saveQuietly', 'firstDispatchWithActivity'], '300 linhas com Order apagado: o pedido novo é despachado');

echo '== Despacho fora da janela de 30 min' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
$pedido = pedidoDaIntegracao(['status' => 'created', 'started' => false, 'dispatched' => false, 'driver_assigned_uuid' => null], ['despachado_em' => null, 'created_at' => '2026-10-05 14:20:00']);
acompanhar();
confere($pedido->chamadas === [], 'criado há mais de 30 min: não redespacha');

echo '== Loja sem conexão fora dos avisos' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao(['uuid' => 'order-b', 'public_id' => 'order_b', 'customer_uuid' => 'vendor-b'], ['order_uuid' => 'order-b', 'vendor_uuid' => 'vendor-b', 'pedido_externo_id' => 'ord-b']);
pedidoDaIntegracao();
acompanhar();
confere(count(Fila::$jobs) === 1 && Fila::$jobs[0]->orderUuid === 'order-1', 'só o pedido da loja conectada recebe o job');

echo '== Desligada' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao();
\Teste\Config::$valores['services.entregas.integracao'] = '';
confere(acompanhar() === 0 && Fila::$jobs === [], 'nada');

resumo();
