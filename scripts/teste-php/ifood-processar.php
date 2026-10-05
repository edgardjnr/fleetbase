<?php

// Integração iFood: processamento dos eventos de um pedido (ProcessarPedidoIfood), com um criador falso.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-processar.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';
require __DIR__ . '/fixtures-ifood.php';

use App\Jobs\Entregas\ProcessarPedidoIfood;
use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\CriadorDoPedidoIfood;
use App\Support\Entregas\Ifood\ErroIfood;
use App\Support\Entregas\Ifood\EventosIfood;
use App\Support\Entregas\Ifood\VinculosIfood;
use Teste\Banco;
use Teste\Http;
use Teste\Trava;

// grava a linha do pedido como o de verdade, sem os models do Fleetbase
class CriadorFalso extends CriadorDoPedidoIfood
{
    public array $criados = [];

    public function criar(object $vinculo, array $pedidoIfood): ?object
    {
        $this->criados[] = [$vinculo->merchant_id, $pedidoIfood['id']];
        Banco::inserir('entregas_ifood_pedidos', [
            'company_uuid' => $vinculo->company_uuid, 'order_uuid' => 'order-' . count($this->criados), 'pedido_ifood_id' => $pedidoIfood['id'],
            'numero' => $pedidoIfood['displayId'], 'merchant_id' => $vinculo->merchant_id, 'exige_codigo' => false, 'cancelado_pelo_ifood_em' => null,
        ], false);

        return (new Teste\Consulta('entregas_ifood_pedidos'))->where('pedido_ifood_id', $pedidoIfood['id'])->first();
    }
}

function gravarEvento(string $id, string $codigo, string $criado, string $pedido = 'pedido-real-1', string $merchant = 'merchant-1'): void
{
    Banco::inserir('entregas_ifood_eventos', EventosIfood::paraGravar(['id' => $id, 'code' => $codigo, 'orderId' => $pedido, 'merchantId' => $merchant, 'createdAt' => $criado], '2026-10-05 18:00:00'), false);
}

function rodar(CriadorFalso $criador, string $pedido = 'pedido-real-1'): ProcessarPedidoIfood
{
    $job = new ProcessarPedidoIfood($pedido);
    $job->handle(new VinculosIfood(new ClienteIfood()), new ClienteIfood(), $criador);

    return $job;
}

function evento(string $id): object
{
    foreach (Banco::linhas('entregas_ifood_eventos') as $linha) {
        if ($linha->evento_id === $id) {
            return $linha;
        }
    }

    throw new LogicException("evento {$id} não gravado");
}

function linhaDoPedido(string $pedido = 'pedido-real-1'): ?object
{
    return (new Teste\Consulta('entregas_ifood_pedidos'))->where('pedido_ifood_id', $pedido)->first();
}

echo '== PLC cria o pedido' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00.100Z');
Http::responder(200, pedidoEmDinheiroComTroco());
$criador = new CriadorFalso();
rodar($criador);
confere(Http::urls() === ['GET /logistics/v1.0/orders/pedido-real-1'] && Http::$chamadas[0]['token'] === 'token-a', 'busca o pedido no Logistics com o token da loja');
confere($criador->criados === [['merchant-1', 'pedido-real-1']], 'cria uma vez, com o vínculo da loja');
confere(evento('ev-1')->processado_em === '2026-10-05 18:00:00' && evento('ev-1')->ignorado === false, 'evento processado');

echo '== Eventos seguintes não criam de novo' . PHP_EOL;
gravarEvento('ev-2', 'CFM', '2026-10-05T18:01:00Z');
gravarEvento('ev-3', 'DDCR', '2026-10-05T18:01:00.400Z');
rodar($criador);
confere(count($criador->criados) === 1 && count(Http::$chamadas) === 1, 'pedido já existe: não busca nem cria');
confere(linhaDoPedido()->exige_codigo === true, 'DDCR marca exige_codigo');
confere(evento('ev-2')->processado_em !== null && evento('ev-3')->processado_em !== null, 'CFM e DDCR processados');
rodar($criador);
confere(count($criador->criados) === 1, 'sem eventos pendentes: nada a fazer');

echo '== PLC, CFM e DDCR fora de ordem na mesma rodada' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-3', 'DDCR', '2026-10-05T18:01:00.400Z');
gravarEvento('ev-2', 'CFM', '2026-10-05T18:01:00Z');
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00.100Z');
Http::responder(200, pedidoEmDinheiroComTroco());
$criador = new CriadorFalso();
rodar($criador);
confere(count($criador->criados) === 1 && linhaDoPedido()->exige_codigo === true, 'cria uma vez e marca exige_codigo');

echo '== PLC perdido: o primeiro evento conhecido cria' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-2', 'CFM', '2026-10-05T18:01:00Z');
Http::responder(200, pedidoEmDinheiroComTroco());
$criador = new CriadorFalso();
rodar($criador);
confere(count($criador->criados) === 1, 'CFM sem PLC também cria');

echo '== CAN depois de criado: só registra' . PHP_EOL;
gravarEvento('ev-4', 'CAR', '2026-10-05T18:05:00Z');
gravarEvento('ev-5', 'CAN', '2026-10-05T18:05:00.500Z');
rodar($criador);
confere(linhaDoPedido()->cancelado_pelo_ifood_em === '2026-10-05 18:05:00', 'cancelado_pelo_ifood_em = createdAt do CAN');
confere(logou('pedido cancelado pelo iFood', 'warning') && evento('ev-5')->processado_em !== null && evento('ev-4')->ignorado === false, 'log; CAR e CAN processados');

echo '== Cancelado antes de entrar: não cria' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00Z');
gravarEvento('ev-2', 'CAR', '2026-10-05T17:59:30Z');
gravarEvento('ev-3', 'CAN', '2026-10-05T17:59:31Z');
$criador = new CriadorFalso();
rodar($criador);
confere($criador->criados === [] && Http::$chamadas === [] && linhaDoPedido() === null, 'não busca nem cria');
confere(evento('ev-1')->processado_em !== null && evento('ev-3')->processado_em !== null && logou('cancelado antes de entrar'), 'eventos processados, com log');

echo '== Cancelado antes de entrar: evento atrasado numa rodada seguinte também não cria' . PHP_EOL;
\Illuminate\Support\Facades\Log::$registros = [];
gravarEvento('ev-4', 'CFM', '2026-10-05T17:59:10Z');
rodar($criador);
confere($criador->criados === [] && Http::$chamadas === [] && linhaDoPedido() === null, 'o CAN já processado ainda vale: não busca nem cria');
confere(evento('ev-4')->processado_em !== null && logou('cancelado antes de entrar'), 'evento atrasado processado, com log');

echo '== Evento sem pedido que não cria' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-1', 'ADR', '2026-10-05T18:10:00Z');
gravarEvento('ev-2', 'CON', '2026-10-05T18:30:00Z');
$criador = new CriadorFalso();
rodar($criador);
confere($criador->criados === [] && Http::$chamadas === [] && linhaDoPedido() === null, 'ADR e CON não criam: não busca nem cria');
confere(evento('ev-1')->processado_em !== null && evento('ev-2')->processado_em !== null, 'eventos processados');
confere(logou('[entregas] ifood: evento sem pedido; não cria', 'info') && logsSem(['Pizzaria', 'token-a']), 'log só com ids e códigos');

echo '== Código desconhecido' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00Z');
gravarEvento('ev-2', 'HSD', '2026-10-05T18:00:00Z');
gravarEvento('ev-3', 'XYZ', '2026-10-05T18:00:01Z');
Http::responder(200, pedidoEmDinheiroComTroco());
rodar(new CriadorFalso());
confere(evento('ev-2')->ignorado === true && evento('ev-2')->processado_em !== null && evento('ev-1')->ignorado === false, 'gravado como ignorado');
confere(evento('ev-3')->ignorado === true && evento('ev-3')->processado_em !== null, 'código fora da lista também ignorado');
$niveis = [];
foreach (\Illuminate\Support\Facades\Log::$registros as [$nivel, $mensagem, $contexto]) {
    if ($mensagem === '[entregas] ifood: evento ignorado') {
        $niveis[$contexto['codigo']] = $nivel;
    }
}
confere($niveis === ['HSD' => 'warning', 'XYZ' => 'info'], 'log do ignorado: warning no HSD (exige ação da loja), info no desconhecido');

echo '== Loja não vinculada' . PHP_EOL;
reiniciarIfood();
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00Z', 'pedido-x', 'merchant-9');
$criador = new CriadorFalso();
rodar($criador, 'pedido-x');
confere($criador->criados === [] && Http::$chamadas === [] && evento('ev-1')->ignorado === true, 'não busca, não cria, evento ignorado');
confere(logou('pedido de loja não vinculada', 'warning'), 'aviso no log');

echo '== Trava ocupada: volta para a fila' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00Z');
Trava::$ocupadas['entregas:ifood-pedido:pedido-real-1'] = true;
$criador = new CriadorFalso();
$job     = rodar($criador);
confere($job->liberadoPor === ProcessarPedidoIfood::ESPERA_DA_TRAVA && $criador->criados === [] && evento('ev-1')->processado_em === null, 'release(15) sem processar');

echo '== Pedido ainda indisponível (404): tenta de novo depois' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00Z');
Http::responder(404, ['message' => 'Order not found']);
$criador = new CriadorFalso();
$erro    = excecao(fn () => rodar($criador));
confere($erro instanceof ErroIfood && $erro->status === 404 && evento('ev-1')->processado_em === null, 'o erro sobe e o evento fica pendente');
confere(Trava::$ocupadas === [], 'a trava é solta mesmo com erro');
Http::responder(200, pedidoEmDinheiroComTroco());
rodar($criador);
confere(count($criador->criados) === 1, 'na tentativa seguinte, cria');

echo '== 401 no Logistics: renova e repete' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00Z');
Http::responder(401, ['error' => ['code' => 'Unauthorized']]);
Http::responder(200, ['accessToken' => 'token-a2', 'type' => 'bearer', 'expiresIn' => 21600, 'refreshToken' => 'refresh-a2']);
Http::responder(200, pedidoEmDinheiroComTroco());
$criador = new CriadorFalso();
rodar($criador);
confere(Http::urls() === ['GET /logistics/v1.0/orders/pedido-real-1', 'POST /authentication/v1.0/oauth/token', 'GET /logistics/v1.0/orders/pedido-real-1'] && Http::$chamadas[2]['token'] === 'token-a2', 'token novo e a mesma busca de novo');
confere(count($criador->criados) === 1, 'cria');

echo '== Falha definitiva' . PHP_EOL;
reiniciarIfood();
(new ProcessarPedidoIfood('pedido-real-1'))->failed(new ErroIfood('pedido', 503, 'Cliente Ficticio, Rua Ficticia'));
confere(logou('[entregas] ifood: pedido não processado', 'error') && logsSem(['Cliente Ficticio', 'Rua Ficticia']), 'log só com a classe e o status');

resumo();
