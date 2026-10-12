<?php

// Integração: avisos de volta por webhook assinado (AvisosDaIntegracao), o job (EnviarAvisoDaIntegracao) e o observador
// do Order (ObservadorDosPedidosDaIntegracao).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-avisos.php

require __DIR__ . '/stubs-integracao.php';

use App\Jobs\Entregas\EnviarAvisoDaIntegracao;
use App\Listeners\Entregas\ObservadorDosPedidosDaIntegracao;
use App\Support\Entregas\Integracao\AvisosDaIntegracao;
use App\Support\Entregas\Integracao\ChavesDeIntegracao;
use App\Support\Entregas\Integracao\FalhaNoAviso;
use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Support\Facades\Cache;
use Teste\Fila;
use Teste\Http;
use Teste\Trava;

function sincronizar(?\Closure $resolver = null): array
{
    return (new AvisosDaIntegracao($resolver ?? resolverPublico()))->sincronizar('order-1');
}

function corpoEnviado(int $indice = 0): array
{
    return json_decode(Http::$chamadas[$indice]['corpo'], true);
}

function lojaA(): ?object
{
    return ChavesDeIntegracao::ativa('empresa-1', 'vendor-a');
}

echo '== Aceito: envia assinado' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao();
Http::responder(200, ['ok' => true]);
confere(sincronizar() === ['aceito'], 'envia o aceito');
$chamada = Http::$chamadas[0];
confere($chamada['metodo'] === 'POST' && $chamada['url'] === 'https://api.restaurantepro.com.br/functions/v1/entregas-webhook?t=abc', 'POST na URL da loja');
confere($chamada['timeout'] === 5 && $chamada['options'] === ['allow_redirects' => false, 'force_ip_resolve' => 'v4', 'curl' => [10203 => ['api.restaurantepro.com.br:443:200.150.10.20']]], 'timeout de 5 s, sem redirecionamento e preso ao IP conferido');
confere($chamada['headers']['X-Entregas-Evento'] === 'pedido.aceito' && $chamada['headers']['X-Entregas-Id'] === AvisosDaIntegracao::idDoAviso('order-1', 'aceito', 'driver-1', 0), 'cabeçalhos do evento e do id');
confere($chamada['headers']['X-Entregas-Assinatura'] === 'sha256=' . hash_hmac('sha256', $chamada['corpo'], 'segredo-a'), 'assinatura HMAC-SHA256 do corpo cru');
$corpo = corpoEnviado();
confere($corpo['evento'] === 'pedido.aceito' && $corpo['pedido_externo_id'] === 'ord-1' && $corpo['id'] === 'order_1' && $corpo['status'] === 'started', 'corpo: evento e ids');
confere($corpo['motoboy'] === ['nome' => 'Motoboy Ficticio', 'foto_url' => 'https://exemplo.com/foto.png'] && $corpo['id_entrega'] === $chamada['headers']['X-Entregas-Id'], 'corpo: motoboy e id do aviso');
confere($corpo['codigo_rastreio'] === 'RP0001' && $corpo['link_rastreio'] === 'https://entregas.restaurantepro.com.br/~/track-order?order=RP0001' && $corpo['cancelamento'] === null, 'corpo: rastreio');
confere($corpo['ocorrido_em'] === '2026-10-05T15:00:00-03:00', 'corpo: ocorrido_em em -03:00');
confere(linhaDaIntegracao()->ultimo_aviso === 'aceito' && linhaDaIntegracao()->motoboy_avisado === 'driver-1', 'grava o último aviso e o motoboy');
confere(logou('[entregas] integração: aviso enviado', 'info') && logsSem(['segredo-a', 'Motoboy Ficticio']), 'log sem segredo nem nome');
confere(sincronizar() === [] && count(Http::$chamadas) === 1, 'de novo: nada a enviar');

echo '== A caminho e concluído' . PHP_EOL;
\Fleetbase\FleetOps\Models\Order::$todos[0]->status = 'enroute';
Http::responder(204);
confere(sincronizar() === ['a_caminho'] && corpoEnviado(1)['evento'] === 'pedido.a_caminho', 'a caminho (204 também vale)');
\Fleetbase\FleetOps\Models\Order::$todos[0]->status = 'completed';
Http::responder(200);
confere(sincronizar() === ['concluido'] && linhaDaIntegracao()->ultimo_aviso === 'concluido', 'concluído');

echo '== Concluído sem nada avisado: aceito e concluído' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao(['status' => 'completed']);
Http::responder(200);
Http::responder(200);
confere(sincronizar() === ['aceito', 'concluido'] && corpoEnviado(0)['evento'] === 'pedido.aceito' && corpoEnviado(1)['evento'] === 'pedido.concluido', 'os dois, em ordem');

echo '== Troca de motoboy' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
$pedido = pedidoDaIntegracao(['status' => 'enroute'], ['ultimo_aviso' => 'a_caminho', 'motoboy_avisado' => 'driver-1']);
Driver::$todos[]              = new Driver(['uuid' => 'driver-2', 'public_id' => 'driver_2', 'name' => 'Outro Ficticio']);
$pedido->driver_assigned_uuid = 'driver-2';
$pedido->driverAssigned       = Driver::$todos[1];
Http::responder(200);
confere(sincronizar() === ['aceito'] && corpoEnviado()['motoboy']['nome'] === 'Outro Ficticio', 'o aceito de novo, com o motoboy novo');
confere(linhaDaIntegracao()->ultimo_aviso === 'a_caminho' && linhaDaIntegracao()->motoboy_avisado === 'driver-2', 'o ponto não volta; motoboy avisado trocado');

echo '== Troca A → B → A: um id por aviso' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
$pedido = pedidoDaIntegracao();
$motoboyA = $pedido->driverAssigned;
$motoboyB = new Driver(['uuid' => 'driver-2', 'public_id' => 'driver_2', 'name' => 'Outro Ficticio']);
Driver::$todos[] = $motoboyB;
Http::responder(200);
confere(sincronizar() === ['aceito'], 'aceito com o driver-1');
$pedido->driver_assigned_uuid = 'driver-2';
$pedido->driverAssigned       = $motoboyB;
Http::responder(200);
confere(sincronizar() === ['aceito'], 'aceito com o driver-2');
$pedido->driver_assigned_uuid = 'driver-1';
$pedido->driverAssigned       = $motoboyA;
Http::responder(200);
confere(sincronizar() === ['aceito'] && corpoEnviado(2)['motoboy']['nome'] === 'Motoboy Ficticio', 'aceito de novo com o driver-1');
$ids = array_map(fn ($c) => $c['headers']['X-Entregas-Id'], Http::$chamadas);
confere(count($ids) === 3 && count(array_unique($ids)) === 3, 'três X-Entregas-Id diferentes');
confere($ids[2] === AvisosDaIntegracao::idDoAviso('order-1', 'aceito', 'driver-1', 2) && corpoEnviado(2)['id_entrega'] === $ids[2], 'o terceiro usa o contador 2');
confere((int) linhaDaIntegracao()->avisos_enviados === 3 && linhaDaIntegracao()->motoboy_avisado === 'driver-1', 'avisos_enviados = 3');

echo '== Id estável entre as tentativas' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao();
Http::responder(500);
excecao(fn () => sincronizar());
Http::responder(200);
confere(sincronizar() === ['aceito'] && Http::$chamadas[0]['headers']['X-Entregas-Id'] === Http::$chamadas[1]['headers']['X-Entregas-Id'], 'a falha não muda o id');
confere((int) linhaDaIntegracao()->avisos_enviados === 1, 'só o aceito conta');

echo '== Cancelado' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao(['status' => 'canceled'], ['ultimo_aviso' => 'a_caminho', 'motoboy_avisado' => 'driver-1', 'cancelado_em' => '2026-10-05 14:58:00', 'cancelado_por' => 'loja', 'pago_mesmo_cancelado' => true]);
Http::responder(200);
confere(sincronizar() === ['cancelado'] && corpoEnviado()['cancelamento'] === ['por' => 'loja', 'pago' => true], 'cancelado, com quem e se paga');
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao(['status' => 'canceled', 'started' => false, 'driver_assigned_uuid' => null]);
Http::responder(200);
confere(sincronizar() === ['cancelado'] && corpoEnviado()['cancelamento'] === ['por' => 'central', 'pago' => false] && corpoEnviado()['motoboy'] === null, 'sem quem cancelou gravado: central');

echo '== Falhas' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao();
Http::responder(500, 'erro interno com dados');
$erro = excecao(fn () => sincronizar());
confere($erro instanceof FalhaNoAviso && $erro->status === 500 && linhaDaIntegracao()->ultimo_aviso === null, '500: FalhaNoAviso, nada gravado');
confere(!str_contains($erro->getMessage(), 'dados'), 'a exceção não leva o corpo da resposta');
Http::falharConexao();
$erro = excecao(fn () => sincronizar());
confere($erro instanceof FalhaNoAviso && $erro->status === 0 && logou('aviso sem resposta', 'warning'), 'rede: FalhaNoAviso com status 0');
Http::responder(410);
confere(sincronizar() === [] && lojaA()->ultima_falha_aviso !== null && logou('o destino recusou os avisos (410)', 'warning'), '410: para sem exceção e registra a falha');
$antes = count(Http::$chamadas);
confere(sincronizar(fn ($host) => ['10.0.0.7']) === [] && count(Http::$chamadas) === $antes, 'URL que resolve para rede privada: não envia');
confere(str_contains(lojaA()->ultima_falha_aviso, 'URL do webhook recusada') && logou('webhook recusado', 'warning'), 'registra e avisa no log');
Http::responder(200);
sincronizar();
confere(lojaA()->ultima_falha_aviso === null, 'o envio aceito limpa a falha');

echo '== Sem destino' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA(false);
pedidoDaIntegracao();
confere(sincronizar() === [] && Http::$chamadas === [], 'loja sem conexão: nada');
reiniciarIntegracao();
pedidoDaIntegracao();
confere(sincronizar() === [] && Http::$chamadas === [], 'sem chave ativa: nada');

echo '== Trava' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao();
Trava::$ocupadas['entregas:integracao-aviso:order-1'] = true;
confere(excecao(fn () => sincronizar()) instanceof \Illuminate\Contracts\Cache\LockTimeoutException, 'trava ocupada: LockTimeoutException');

echo '== Job' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao();
confere(EnviarAvisoDaIntegracao::enfileirar('order-1') === true && EnviarAvisoDaIntegracao::enfileirar('order-1') === false && count(Fila::$jobs) === 1, 'um job por pedido de cada vez');
$job = Fila::$jobs[0];
confere($job->afterCommit === true && $job->queue === null, 'afterCommit, fila default');
Http::responder(503);
$erro = excecao(fn () => $job->handle(new AvisosDaIntegracao(resolverPublico())));
confere($erro instanceof FalhaNoAviso && Cache::get(EnviarAvisoDaIntegracao::PENDENTE . 'order-1') === true, 'falha sobe (nova tentativa) e a marca fica');
$job->failed($erro);
confere(Cache::get(EnviarAvisoDaIntegracao::PENDENTE . 'order-1') === null && logou('[entregas] integração: aviso não entregue', 'error') && str_contains((string) lojaA()->ultima_falha_aviso, '503'), 'esgotadas: tira a marca, loga e registra a falha na loja');
Trava::$ocupadas['entregas:integracao-aviso:order-1'] = true;
$job->handle(new AvisosDaIntegracao(resolverPublico()));
confere($job->liberadoPor === EnviarAvisoDaIntegracao::ESPERA_DA_TRAVA, 'trava ocupada: volta para a fila');
unset(Trava::$ocupadas['entregas:integracao-aviso:order-1']);
\Teste\Config::$valores['services.entregas.integracao'] = '';
$antes = count(Http::$chamadas);
$job->handle(new AvisosDaIntegracao(resolverPublico()));
confere(count(Http::$chamadas) === $antes, 'integração desligada: o job não envia');

echo '== Observador' . PHP_EOL;
reiniciarIntegracao();
$pedido            = pedidoDaIntegracao();
$pedido->alterados = ['status'];
ObservadorDosPedidosDaIntegracao::aoAtualizar($pedido);
confere(count(Fila::$jobs) === 1 && Fila::$jobs[0]->orderUuid === 'order-1', 'status mudou: enfileira');
reiniciarIntegracao();
$pedido            = pedidoDaIntegracao();
$pedido->alterados = ['notes'];
ObservadorDosPedidosDaIntegracao::aoAtualizar($pedido);
confere(Fila::$jobs === [], 'outro campo: nada');
reiniciarIntegracao();
$outro = new \Fleetbase\FleetOps\Models\Order(['uuid' => 'order-9', 'alterados' => ['status']]);
ObservadorDosPedidosDaIntegracao::aoAtualizar($outro);
confere(Fila::$jobs === [], 'pedido que não é da integração: nada');
reiniciarIntegracao();
$pedido            = pedidoDaIntegracao();
$pedido->alterados = ['status'];
\Teste\Config::$valores['services.entregas.integracao'] = '';
ObservadorDosPedidosDaIntegracao::aoAtualizar($pedido);
confere(Fila::$jobs === [], 'desligada: nada');

echo '== Observador: quem cancelou' . PHP_EOL;
foreach ([['canceled', null, 'central'], ['canceled', 'customer', 'loja'], ['canceled', 'user', 'central'], ['expired', null, 'sistema'], ['CANCELED', 'customer', 'loja'], ['Expired', null, 'sistema']] as [$status, $tipo, $esperado]) {
    reiniciarIntegracao();
    $pedido            = pedidoDaIntegracao(['status' => $status]);
    $pedido->alterados = ['status'];
    if ($tipo) {
        \Fleetbase\Models\User::$todos[] = new \Fleetbase\Models\User(['uuid' => 'user-1', 'type' => $tipo]);
        \Teste\Sessao::$dados['user']    = 'user-1';
    }
    ObservadorDosPedidosDaIntegracao::aoAtualizar($pedido);
    confere(linhaDaIntegracao()->cancelado_por === $esperado && linhaDaIntegracao()->cancelado_em === '2026-10-05 15:00:00' && count(Fila::$jobs) === 1, "{$status}" . ($tipo ? " por usuário {$tipo}" : '') . ": {$esperado}");
}
reiniciarIntegracao();
$pedido            = pedidoDaIntegracao(['status' => 'canceled'], ['cancelado_em' => '2026-10-05 14:50:00', 'cancelado_por' => 'loja']);
$pedido->alterados = ['status'];
ObservadorDosPedidosDaIntegracao::aoAtualizar($pedido);
confere(linhaDaIntegracao()->cancelado_por === 'loja' && linhaDaIntegracao()->cancelado_em === '2026-10-05 14:50:00', 'já registrado pela origem: não sobrescreve');
reiniciarIntegracao();
pedidoDaIntegracao();
ObservadorDosPedidosDaIntegracao::aoAtribuirMotoboy((object) ['modelUuid' => 'order-1']);
confere(count(Fila::$jobs) === 1, 'OrderDriverAssigned: enfileira');

resumo();
