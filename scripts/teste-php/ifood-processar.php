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
use Fleetbase\FleetOps\Models\Order;
use Teste\Banco;
use Teste\Http;
use Teste\Trava;

// grava a linha do pedido como o de verdade (e um Order falso, para o public_id), sem os outros models do Fleetbase
class CriadorFalso extends CriadorDoPedidoIfood
{
    public array $criados = [];
    /** Devolve null, como o de verdade quando a loja não tem Local de coleta. */
    public bool $semLocal = false;
    /** Lança esta exceção antes de gravar ('antes') ou depois de gravar a linha ('depois'). */
    public ?\Throwable $erro = null;
    public string $quando = 'antes';
    /** despachar_em e despachado_em gravados na linha (o agendado ou o despacho que falhou ficam na fila do agendador). */
    public ?string $despacharEm  = null;
    public ?string $despachadoEm = null;

    public function criar(object $vinculo, array $pedidoIfood): ?object
    {
        $this->criados[] = [$vinculo->merchant_id, $pedidoIfood['id']];
        if ($this->semLocal) {
            return null;
        }
        if ($this->erro && $this->quando === 'antes') {
            throw $this->erro;
        }
        $numero = count($this->criados);
        Order::create(['uuid' => 'order-' . $numero, 'public_id' => 'order_pub' . $numero, 'company_uuid' => $vinculo->company_uuid]);
        Banco::inserir('entregas_ifood_pedidos', [
            'company_uuid' => $vinculo->company_uuid, 'order_uuid' => 'order-' . $numero, 'pedido_ifood_id' => $pedidoIfood['id'],
            'numero' => $pedidoIfood['displayId'], 'merchant_id' => $vinculo->merchant_id, 'exige_codigo' => false, 'cancelado_pelo_ifood_em' => null,
            'despachar_em' => $this->despacharEm, 'despachado_em' => $this->despachadoEm,
        ], false);
        if ($this->erro) {
            throw $this->erro;
        }

        return (new Teste\Consulta('entregas_ifood_pedidos'))->where('pedido_ifood_id', $pedidoIfood['id'])->first();
    }
}

// erro do banco como o de verdade: a mensagem traz o SQL com os valores (dados do cliente)
function erroDoBancoComDados(): Teste\ErroDeBanco
{
    return new Teste\ErroDeBanco("SQLSTATE[23000]: Integrity constraint violation: 1452 (SQL: insert into `places` (`name`, `street1`) values (CLIENTE FICTICIO, RUA FICTICIA 123))", '23000', 1452);
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
reiniciarFleetbase();
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
$contextoDoCan = null;
foreach (\Illuminate\Support\Facades\Log::$registros as [$nivel, $mensagem, $contexto]) {
    if (str_contains($mensagem, 'pedido cancelado pelo iFood')) {
        $contextoDoCan = $contexto;
    }
}
confere(($contextoDoCan['pedido'] ?? null) === 'order_pub1' && ($contextoDoCan['order_uuid'] ?? null) === 'order-1', 'log do CAN com o public_id do Order e o order_uuid');

echo '== CAN tira da fila do agendador o pedido ainda não despachado' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00Z');
Http::responder(200, pedidoEmDinheiroComTroco());
$criador              = new CriadorFalso();
$criador->despacharEm = '2026-10-05 18:40:00';
rodar($criador);
confere(linhaDoPedido()->despachar_em === '2026-10-05 18:40:00', 'agendado na fila');
gravarEvento('ev-2', 'CAN', '2026-10-05T18:02:00Z');
rodar($criador);
confere(linhaDoPedido()->cancelado_pelo_ifood_em === '2026-10-05 18:02:00' && linhaDoPedido()->despachar_em === null, 'CAN: cancelado_pelo_ifood_em gravado e despachar_em nulo (sai da fila)');
confere(linhaDoPedido()->despachado_em === null, 'continua sem despachado_em');

echo '== CAN depois do despacho não mexe no despachar_em' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00Z');
Http::responder(200, pedidoEmDinheiroComTroco());
$criador               = new CriadorFalso();
$criador->despacharEm  = '2026-10-05 17:59:30';
$criador->despachadoEm = '2026-10-05 17:59:30';
rodar($criador);
gravarEvento('ev-2', 'CAN', '2026-10-05T18:02:00Z');
rodar($criador);
confere(linhaDoPedido()->cancelado_pelo_ifood_em === '2026-10-05 18:02:00' && linhaDoPedido()->despachar_em === '2026-10-05 17:59:30', 'já despachado: só registra o cancelamento');

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
confere(evento('ev-1')->ignorado === true && evento('ev-3')->ignorado === true, 'eventos não aplicados ficam ignorados');

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
confere(evento('ev-1')->ignorado === true && evento('ev-2')->ignorado === true, 'sem pedido, nada se aplica: ignorados');
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

echo '== Fila: prazo de 30 min (release não gasta tentativa), 6 exceções, timeout abaixo da trava' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00Z');
Http::responder(200, pedidoEmDinheiroComTroco());
$job = rodar(new CriadorFalso());
confere(!property_exists($job, 'tries') && ($job->maxExceptions ?? null) === 6, 'sem $tries; $maxExceptions = 6');
confere(method_exists($job, 'retryUntil') && $job->retryUntil()->format('Y-m-d H:i:s') === '2026-10-05 18:30:00', 'retryUntil = agora + 30 min');
$timeout  = $job->timeout ?? 0;
$validade = Trava::$validades['entregas:ifood-pedido:pedido-real-1'] ?? 0;
confere($timeout > 0 && $timeout < 90 && $validade > $timeout, "timeout ({$timeout} s) abaixo do retry_after do Redis (90 s) e da validade da trava ({$validade} s)");

echo '== Pedido que já passou da coleta não é criado' . PHP_EOL;
foreach (['DSP', 'CON', 'CLT', 'DDD', 'AAD', 'DDCS', 'GTO', 'ADR', 'AAO'] as $codigo) {
    reiniciarIfood();
    vinculoDaLojaA();
    gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00Z');
    gravarEvento('ev-2', $codigo, '2026-10-05T18:20:00Z');
    Http::responder(200, pedidoEmDinheiroComTroco());
    $criador = new CriadorFalso();
    rodar($criador);
    confere($criador->criados === [] && Http::$chamadas === [] && linhaDoPedido() === null, "PLC com {$codigo} pendente: não busca nem cria");
}
$contexto = null;
foreach (\Illuminate\Support\Facades\Log::$registros as [$nivel, $mensagem, $ctx]) {
    if ($mensagem === '[entregas] ifood: pedido já passou da coleta; não foi criado' && $nivel === 'warning') {
        $contexto = $ctx;
    }
}
confere($contexto !== null && array_keys($contexto) === ['pedido_ifood', 'codigos'] && $contexto['pedido_ifood'] === 'pedido-real-1' && $contexto['codigos'] === ['AAO'], 'log warning só com o pedido e os códigos');
confere(evento('ev-1')->processado_em !== null && evento('ev-1')->ignorado === true && evento('ev-2')->ignorado === true, 'eventos processados e ignorados');

echo '== Pedido que já passou da coleta: o evento de coleta já processado também vale' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-5', 'DSP', '2026-10-05T18:20:00Z');
$criador = new CriadorFalso();
rodar($criador);
confere(evento('ev-5')->processado_em !== null && linhaDoPedido() === null, 'DSP sozinho: evento sem pedido, processado');
gravarEvento('ev-2', 'CFM', '2026-10-05T18:01:00Z');
Http::responder(200, pedidoEmDinheiroComTroco());
rodar($criador);
confere($criador->criados === [] && Http::$chamadas === [] && linhaDoPedido() === null, 'CFM atrasado depois do DSP: não busca nem cria');
confere(evento('ev-2')->processado_em !== null && evento('ev-2')->ignorado === true && logou('pedido já passou da coleta; não foi criado', 'warning'), 'CFM processado e ignorado, com log');

echo '== Erro ao criar: a exceção relançada e o log não trazem dados do cliente' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00Z');
Http::responder(200, pedidoEmDinheiroComTroco());
$criador       = new CriadorFalso();
$criador->erro = erroDoBancoComDados();
$erro          = excecao(fn () => rodar($criador));
confere($erro !== null && get_class($erro) === RuntimeException::class && $erro->getPrevious() === null, 'RuntimeException nova, sem a original encadeada');
confere($erro !== null && !str_contains($erro->getMessage(), 'FICTICIO') && !str_contains($erro->getMessage(), 'insert into') && str_contains($erro->getMessage(), 'ErroDeBanco') && str_contains($erro->getMessage(), '1452'), 'mensagem só com a classe e o código do driver');
confere(logsSem(['FICTICIO', 'FICTICIA', 'insert into']) && evento('ev-1')->processado_em === null, 'log sem dados; evento pendente');
confere(Trava::$ocupadas === [], 'a trava é solta');
$criador->erro = new Error('Cliente Ficticio na Rua Ficticia');
Http::responder(200, pedidoEmDinheiroComTroco());
$erro = excecao(fn () => rodar($criador));
confere($erro !== null && get_class($erro) === RuntimeException::class && !str_contains($erro->getMessage(), 'Ficticia') && str_contains($erro->getMessage(), 'Error'), 'Error qualquer: só a classe');
confere(logsSem(['Ficticia']), 'log sem dados');

echo '== Erro depois de criar: a nova tentativa não duplica' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00Z');
gravarEvento('ev-2', 'DDCR', '2026-10-05T18:01:00Z');
Http::responder(200, pedidoEmDinheiroComTroco());
$criador         = new CriadorFalso();
$criador->erro   = erroDoBancoComDados();
$criador->quando = 'depois';
$erro            = excecao(fn () => rodar($criador));
confere($erro instanceof RuntimeException && linhaDoPedido() !== null && evento('ev-1')->processado_em === null, 'gravou o pedido, falhou, eventos pendentes');
$criador->erro = null;
rodar($criador);
confere(count($criador->criados) === 1 && count(Http::$chamadas) === 1, 'na nova tentativa o pedido já existe: não busca nem cria de novo');
confere(evento('ev-1')->processado_em !== null && linhaDoPedido()->exige_codigo === true, 'eventos aplicados ao pedido existente');

echo '== Loja sem Local: não cria; um evento de criação posterior tenta de novo' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00Z');
Http::responder(200, pedidoEmDinheiroComTroco());
$criador           = new CriadorFalso();
$criador->semLocal = true;
rodar($criador);
confere(count($criador->criados) === 1 && linhaDoPedido() === null && evento('ev-1')->processado_em !== null && evento('ev-1')->ignorado === true, 'nada criado; PLC processado e ignorado');
$criador->semLocal = false;
gravarEvento('ev-2', 'CFM', '2026-10-05T18:01:00Z');
Http::responder(200, pedidoEmDinheiroComTroco());
rodar($criador);
confere(count($criador->criados) === 2 && linhaDoPedido() !== null && evento('ev-2')->ignorado === false, 'o CFM seguinte busca e cria');

echo '== Vínculo perdido durante a busca' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00Z');
Http::responder(401, ['error' => ['code' => 'Unauthorized']]);
Http::responder(400, ['error' => 'invalid_grant']);
$criador = new CriadorFalso();
$erro    = excecao(fn () => rodar($criador));
confere($erro === null && $criador->criados === [] && linhaDoPedido() === null, 'não sobe erro nem cria');
confere(evento('ev-1')->processado_em !== null && evento('ev-1')->ignorado === true, 'evento processado e ignorado');
$contexto = null;
foreach (\Illuminate\Support\Facades\Log::$registros as [$nivel, $mensagem, $ctx]) {
    if ($mensagem === '[entregas] ifood: vínculo perdido; pedido não criado' && $nivel === 'warning') {
        $contexto = $ctx;
    }
}
confere($contexto === ['merchant' => 'merchant-1', 'pedido_ifood' => 'pedido-real-1'], 'log warning com o merchant e o pedido');

echo '== Erro permanente do iFood (403): desiste sem nova tentativa' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00Z');
Http::responder(403, ['message' => 'Cliente Ficticio sem acesso']);
$criador = new CriadorFalso();
$job     = new ProcessarPedidoIfood('pedido-real-1');
$erro    = excecao(fn () => $job->handle(new VinculosIfood(new ClienteIfood()), new ClienteIfood(), $criador));
confere($erro === null && $job->falhouCom instanceof ErroIfood && $job->falhouCom->status === 403, 'fail() com o ErroIfood 403');
confere($job->falhouCom !== null && $job->falhouCom->corpo === '' && !str_contains((string) $job->falhouCom, 'Ficticio'), 'sem o corpo da resposta');
confere($criador->criados === [] && evento('ev-1')->processado_em === null && Trava::$ocupadas === [], 'nada criado, evento pendente, trava solta');

echo '== Erro na renovação do token (403, 409, 200 sem accessToken): sobe para nova tentativa, sem fail' . PHP_EOL;
foreach ([[403, ['message' => 'Cliente Ficticio sem acesso']], [409, ['message' => 'conflito']], [200, ['type' => 'bearer']]] as [$status, $corpo]) {
    reiniciarIfood();
    vinculoDaLojaA();
    gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00Z');
    Http::responder(401, ['error' => ['code' => 'Unauthorized']]);
    Http::responder($status, $corpo);
    $criador = new CriadorFalso();
    $job     = new ProcessarPedidoIfood('pedido-real-1');
    $erro    = excecao(fn () => $job->handle(new VinculosIfood(new ClienteIfood()), new ClienteIfood(), $criador));
    confere($erro instanceof ErroIfood && $erro->operacao === 'refresh' && $erro->status === $status && $job->falhouCom === null && $job->liberadoPor === null, "renovação com {$status}: o ErroIfood sobe (backoff), sem fail nem release");
    confere($criador->criados === [] && evento('ev-1')->processado_em === null && Trava::$ocupadas === [], "renovação com {$status}: nada criado, evento pendente, trava solta");
}

echo '== 429: volta para a fila pelo Retry-After' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00Z');
Http::responder(429, ['message' => 'Too many requests'], ['Retry-After' => '42']);
$criador = new CriadorFalso();
$job     = new ProcessarPedidoIfood('pedido-real-1');
$erro    = excecao(fn () => $job->handle(new VinculosIfood(new ClienteIfood()), new ClienteIfood(), $criador));
confere($erro === null && $job->liberadoPor === 42 && $job->falhouCom === null, 'release(42), sem erro nem fail');
confere($criador->criados === [] && evento('ev-1')->processado_em === null && Trava::$ocupadas === [], 'evento pendente, trava solta');

echo '== 5xx: o erro sobe (a fila tenta de novo pelo $backoff)' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00Z');
Http::responder(503, ['message' => 'indisponível']);
$job  = new ProcessarPedidoIfood('pedido-real-1');
$erro = excecao(fn () => $job->handle(new VinculosIfood(new ClienteIfood()), new ClienteIfood(), new CriadorFalso()));
confere($erro instanceof ErroIfood && $erro->status === 503 && $job->falhouCom === null && $job->liberadoPor === null, 'ErroIfood 503 sobe, sem fail nem release');

echo '== Falha definitiva' . PHP_EOL;
reiniciarIfood();
(new ProcessarPedidoIfood('pedido-real-1'))->failed(new ErroIfood('pedido', 503, 'Cliente Ficticio, Rua Ficticia'));
confere(logou('[entregas] ifood: pedido não processado', 'error') && logsSem(['Cliente Ficticio', 'Rua Ficticia']), 'log só com a classe e o status');

resumo();
