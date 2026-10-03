<?php

// Avisos push do motoboy (AvisosDoMotoboy e CanalFcmEntregas): texto em pt-BR, canal e formato.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/avisos-push.php

require __DIR__ . '/stubs.php';
require __DIR__ . '/stubs-avisos.php';
require '/repo/packages/fleetops/server/src/Notifications/OrderPing.php';
// as demais notificações também são as reais: Fleet-Ops e core-api (cópias em packages/, nas versões da produção)
require '/repo/packages/fleetops/server/src/Notifications/OrderAssigned.php';
require '/repo/packages/fleetops/server/src/Notifications/OrderDispatched.php';
require '/repo/packages/fleetops/server/src/Notifications/OrderCanceled.php';
require '/repo/packages/fleetops/server/src/Notifications/OrderFailed.php';
require '/repo/packages/fleetops/server/src/Notifications/OrderCompleted.php';
require '/repo/packages/fleetops/server/src/Notifications/WaypointCompleted.php';
require '/repo/packages/core-api/src/Notifications/ChatMessageReceived.php';
require '/repo/packages/core-api/src/Notifications/TestPushNotification.php';

use App\Notifications\Entregas\AvisosDoMotoboy;
use App\Notifications\Entregas\LembretePedidoAberto;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\OrderAssigned;
use Fleetbase\FleetOps\Notifications\OrderCanceled;
use Fleetbase\FleetOps\Notifications\OrderCompleted;
use Fleetbase\FleetOps\Notifications\OrderDispatched;
use Fleetbase\FleetOps\Notifications\OrderFailed;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Fleetbase\FleetOps\Notifications\WaypointCompleted;
use Fleetbase\Notifications\ChatMessageReceived;
use Fleetbase\Notifications\TestPushNotification;

function pedidoDoTeste(): Order
{
    $pedido            = new Order();
    $pedido->uuid      = 'uuid-1';
    $pedido->public_id = 'order_abc';

    return $pedido;
}

// aviso real do Fleet-Ops ou do core com o título e o texto originais (em inglês) e os dados que ele manda; a instância
// nasce sem o construtor real, que pede os modelos (pedido, parada, mensagem) e monta o texto a partir deles
function aviso(string $classe, string $titulo, string $texto, array $dados)
{
    $notificacao          = (new ReflectionClass($classe))->newInstanceWithoutConstructor();
    $notificacao->title   = $titulo;
    $notificacao->message = $texto;
    $notificacao->data    = $dados;

    return $notificacao;
}

function avisosDoFleetOps(): array
{
    return [
        'atribuído' => aviso(OrderAssigned::class, 'New order RP-1 assigned!', 'You have a new order assigned, tap for details.', ['id' => 'order_abc', 'type' => 'order_assigned']),
        'liberado'  => aviso(OrderDispatched::class, 'Order RP-1 has been dispatched!', 'An order has just been dispatched to you and is ready to be started.', ['id' => 'order_abc', 'type' => 'order_dispatched']),
        'cancelado' => aviso(OrderCanceled::class, 'Order RP-1 was canceled', 'Order RP-1 has been canceled.', ['id' => 'order_abc', 'type' => 'order_canceled']),
        'falhou'    => aviso(OrderFailed::class, 'Order RP-1 delivery has has failed', 'Order RP-1 delivery has failed.', ['id' => 'order_abc', 'type' => 'order_canceled']),
        'concluído' => aviso(OrderCompleted::class, 'Order RP-1 has been completed.', 'Order RP-1 has been completed by agent.', ['id' => 'order_abc', 'type' => 'order_completed']),
        'parada'    => aviso(WaypointCompleted::class, 'Order RP-1 driver has arrived', 'Driver has arrived', ['id' => 'waypoint_1', 'type' => 'waypoint_completed']),
        'chat'      => aviso(ChatMessageReceived::class, 'Message from Edgard Junior', 'preciso de motoca', ['id' => 'chat_message_1', 'type' => 'chat_message_received', 'channel' => 'chat_channel_1']),
        'teste'     => aviso(TestPushNotification::class, 'Teste', 'Push de teste do admin', ['id' => 'abc123', 'message' => 'Test Push Notification', 'type' => 'test']),
    ];
}

echo '== Textos em pt-BR' . PHP_EOL;
$avisos    = avisosDoFleetOps();
$esperados = [
    'atribuído' => ['Novo pedido para você', 'Pedido RP-1. Toque para ver os detalhes.'],
    'liberado'  => ['Pedido RP-1 liberado para você', 'Toque para ver e iniciar a entrega.'],
    'cancelado' => ['Pedido RP-1 cancelado', 'O pedido RP-1 foi cancelado.'],
    'falhou'    => ['Entrega do pedido RP-1 não concluída', 'A entrega do pedido RP-1 falhou.'],
    'concluído' => ['Pedido RP-1 concluído', 'O pedido RP-1 foi concluído.'],
    'parada'    => ['Pedido RP-1: parada concluída', 'Uma parada do pedido RP-1 foi concluída.'],
    'chat'      => ['Mensagem de Edgard Junior', 'preciso de motoca'],
    'teste'     => ['Teste', 'Push de teste do admin'],
];
foreach ($esperados as $nome => $esperado) {
    $obtido = AvisosDoMotoboy::texto($avisos[$nome]);
    confere($obtido === $esperado, $nome . ': ' . json_encode($obtido, JSON_UNESCAPED_UNICODE));
}

$casos = [
    'pedido novo'               => [new OrderPing(pedidoDoTeste(), 1234), ['Novo pedido disponível', 'Coleta a 1,2 km de você. Toque para ver o pedido.']],
    'pedido novo sem distância' => [new OrderPing(pedidoDoTeste()), ['Novo pedido disponível', 'Toque para ver o pedido.']],
    'reenvio (já em pt-BR)'     => [new LembretePedidoAberto(pedidoDoTeste(), 800), ['Pedido ainda sem motoboy', 'Coleta a 800 m de você. Toque para ver o pedido.']],
    'cancelado sem código'      => [aviso(OrderCanceled::class, 'Order  was canceled', 'Order  has been canceled.', ['type' => 'order_canceled']), ['Pedido cancelado', 'O pedido foi cancelado.']],
    'parada sem código'         => [aviso(WaypointCompleted::class, 'Order  driver has arrived', 'x', ['type' => 'waypoint_completed']), ['Parada concluída', 'Uma parada do pedido foi concluída.']],
    'atribuído sem código'      => [aviso(OrderAssigned::class, 'New order  assigned!', 'You have a new order assigned, tap for details.', ['type' => 'order_assigned']), ['Novo pedido para você', 'Toque para ver os detalhes.']],
    'liberado sem código'       => [aviso(OrderDispatched::class, 'Order  has been dispatched!', 'An order has just been dispatched to you and is ready to be started.', ['type' => 'order_dispatched']), ['Pedido liberado para você', 'Toque para ver e iniciar a entrega.']],
    'falhou sem código'         => [aviso(OrderFailed::class, 'Order  delivery has has failed', 'Order  delivery has failed.', ['type' => 'order_canceled']), ['Entrega não concluída', 'A entrega do pedido falhou.']],
    'concluído sem código'      => [aviso(OrderCompleted::class, 'Order  has been completed.', 'Order  has been completed by agent.', ['type' => 'order_completed']), ['Pedido concluído', 'O pedido foi concluído.']],
    'chat com outro título'     => [aviso(ChatMessageReceived::class, 'Something', 'oi', ['type' => 'chat_message_received']), ['Nova mensagem', 'oi']],
];
foreach ($casos as $nome => [$notificacao, $esperado]) {
    $obtido = AvisosDoMotoboy::texto($notificacao);
    confere($obtido === $esperado, $nome . ': ' . json_encode($obtido, JSON_UNESCAPED_UNICODE));
}

// distância da coleta: os metros são arredondados antes de escolher a unidade
confere(AvisosDoMotoboy::textoDaColeta(999.6) === 'Coleta a 1,0 km de você. Toque para ver o pedido.', 'coleta a 999,6 m arredonda para 1,0 km (e não "1000 m")');
confere(AvisosDoMotoboy::textoDaColeta(0.4) === 'Toque para ver o pedido.', 'coleta a 0,4 m (arredonda para 0) fica só com o convite');
confere(AvisosDoMotoboy::textoDaColeta('0.0') === 'Toque para ver o pedido.', 'coleta a "0.0" fica só com o convite');

$agendado        = aviso(OrderAssigned::class, 'New order RP-2 assigned!', 'You have a new order scheduled for 2026-10-03 18:00:00', ['type' => 'order_assigned']);
$agendado->order = (object) ['scheduled_at' => new DateTimeImmutable('2026-10-03 18:00:00', new DateTimeZone('UTC')), 'company' => (object) ['timezone' => 'America/Sao_Paulo']];
$obtido          = AvisosDoMotoboy::texto($agendado);
confere($obtido === ['Novo pedido para você', 'Pedido RP-2 agendado para 03/10 às 15:00.'], 'atribuído agendado, no fuso da organização: ' . json_encode($obtido, JSON_UNESCAPED_UNICODE));
$agendado->order = (object) ['scheduled_at' => new DateTimeImmutable('2026-10-03 18:00:00', new DateTimeZone('UTC')), 'company' => null];
confere((AvisosDoMotoboy::texto($agendado)[1] ?? null) === 'Pedido RP-2 agendado para 03/10 às 15:00.', 'agendado sem fuso usa America/Sao_Paulo');
$agendado->order = null;
confere((AvisosDoMotoboy::texto($agendado)[1] ?? null) === 'Pedido RP-2. Toque para ver os detalhes.', 'agendado sem data cai no texto comum');

$agendadoSemCodigo        = aviso(OrderAssigned::class, 'New order  assigned!', 'You have a new order scheduled for 2026-10-03 18:00:00', ['type' => 'order_assigned']);
$agendadoSemCodigo->order = (object) ['scheduled_at' => new DateTimeImmutable('2026-10-03 18:00:00', new DateTimeZone('UTC')), 'company' => (object) ['timezone' => 'America/Sao_Paulo']];
confere((AvisosDoMotoboy::texto($agendadoSemCodigo)[1] ?? null) === 'Pedido agendado para 03/10 às 15:00.', 'agendado sem código de rastreamento: frase sem o código');

confere(AvisosDoMotoboy::texto(new Illuminate\Notifications\Notification()) === null, 'classe sem tradução devolve null');

function enviado($notificacao): array
{
    return AvisosDoMotoboy::adaptar($notificacao, $notificacao->toFcm(null))->toArray();
}

echo '== Canal e formato (chave desligada, o padrão)' . PHP_EOL;
putenv('ENTREGAS_ALARME_POR_DADOS');
$avisos = avisosDoFleetOps();
$ping   = enviado(new OrderPing(pedidoDoTeste(), 1234));
confere(($ping['notification'] ?? null) === ['title' => 'Novo pedido disponível', 'body' => 'Coleta a 1,2 km de você. Toque para ver o pedido.'], 'pedido novo: push comum com o texto em pt-BR');
confere(($ping['android']['notification']['channel_id'] ?? null) === 'alarme_pedido', 'pedido novo no canal "alarme_pedido"');
confere(!isset($ping['android']['priority']), 'sem prioridade de push de dados com a chave desligada');
confere(($ping['android']['ttl'] ?? null) === '900s', 'pedido novo com validade de 15 min também como push comum');
$canais =['atribuído' => 'alarme_pedido', 'liberado' => 'alarme_pedido', 'chat' => 'mensagens', 'cancelado' => 'avisos', 'falhou' => 'avisos', 'concluído' => 'avisos', 'parada' => 'avisos', 'teste' => 'avisos'];
foreach ($canais as $nome => $canal) {
    $mensagem = enviado($avisos[$nome]);
    confere(($mensagem['android']['notification']['channel_id'] ?? null) === $canal, "{$nome}: canal \"{$canal}\"");
}
// o Fleet-Ops manda o OrderFailed com o tipo order_canceled; se corrigir para order_failed, o canal continua o mesmo
$falhouCorrigido = aviso(OrderFailed::class, 'Order RP-1 delivery has has failed', 'Order RP-1 delivery has failed.', ['id' => 'order_abc', 'type' => 'order_failed']);
confere((enviado($falhouCorrigido)['android']['notification']['channel_id'] ?? null) === 'avisos', 'falhou com o tipo corrigido (order_failed): canal "avisos"');
foreach (['atribuído', 'liberado'] as $nome) {
    confere((enviado($avisos[$nome])['android']['ttl'] ?? null) === '900s', "{$nome}: validade de 15 min também como push comum");
}
foreach (['chat', 'cancelado'] as $nome) {
    confere(!isset(enviado($avisos[$nome])['android']['ttl']), "{$nome}: sem validade curta (não é alarme)");
}
$chat = enviado($avisos['chat']);
confere(($chat['notification']['title'] ?? null) === 'Mensagem de Edgard Junior' && ($chat['data']['channel'] ?? null) === 'chat_channel_1', 'chat: texto em pt-BR e os dados da conversa mantidos');
confere(($chat['android']['notification']['color'] ?? null) === '#4391EA', 'o resto do push do Fleet-Ops (cor, som) fica');
confere(($chat['android']['notification']['sound'] ?? null) === 'default', 'o som do push do Fleet-Ops também fica');
confere(isset($chat['android']['fcm_options'], $chat['apns']), 'fcm_options e apns do push do Fleet-Ops ficam no push comum');

echo '== Alarme como push de dados (chave ligada)' . PHP_EOL;
foreach (['1', 'true', 'on', 'ON'] as $valor) {
    putenv("ENTREGAS_ALARME_POR_DADOS={$valor}");
    confere(AvisosDoMotoboy::alarmePorDados(), "chave '{$valor}' liga");
}
putenv('ENTREGAS_ALARME_POR_DADOS= on ');
confere(AvisosDoMotoboy::alarmePorDados(), "chave ' on ' (com espaços em volta) liga");
foreach (['0', 'false', 'off', ''] as $valor) {
    putenv("ENTREGAS_ALARME_POR_DADOS={$valor}");
    confere(!AvisosDoMotoboy::alarmePorDados(), "chave '{$valor}' desliga");
}
putenv('ENTREGAS_ALARME_POR_DADOS=1');
$ping = enviado(new OrderPing(pedidoDoTeste(), 1234));
confere(!isset($ping['notification']), 'pedido novo vai sem bloco de notificação');
confere(($ping['data'] ?? null) === ['id' => 'order_abc', 'type' => 'order_ping', 'title' => 'Novo pedido disponível', 'body' => 'Coleta a 1,2 km de você. Toque para ver o pedido.', 'android_channel_id' => 'pedidos'], 'dados com id, tipo, título, texto e o canal do APK antigo: ' . json_encode($ping['data'] ?? null, JSON_UNESCAPED_UNICODE));
confere(($ping['android']['priority'] ?? null) === 'high' && ($ping['android']['ttl'] ?? null) === '900s', 'prioridade alta e validade de 15 min');
confere(!isset($ping['android']['notification']), 'sem android.notification (senão o Android mostra como push comum)');
confere(isset($ping['android']['fcm_options'], $ping['apns']), 'o resto do push (fcm_options, apns) fica');
foreach (['atribuído', 'liberado'] as $nome) {
    $mensagem = enviado($avisos[$nome]);
    confere(!isset($mensagem['notification']) && ($mensagem['android']['priority'] ?? null) === 'high', "{$nome} também vai como push de dados");
}
$cancelado = enviado($avisos['cancelado']);
confere(($cancelado['notification']['title'] ?? null) === 'Pedido RP-1 cancelado' && !isset($cancelado['android']['priority']), 'cancelado continua push comum com a chave ligada');
confere(($cancelado['android']['notification']['channel_id'] ?? null) === 'avisos', 'cancelado segue no canal "avisos" com a chave ligada');
$chat = enviado($avisos['chat']);
confere(isset($chat['notification']) && ($chat['android']['notification']['channel_id'] ?? null) === 'mensagens', 'chat segue push comum no canal "mensagens" com a chave ligada');
$comTipos = aviso(OrderAssigned::class, 'New order RP-1 assigned!', 'You have a new order assigned, tap for details.', ['id' => 'order_abc', 'type' => 'order_assigned', 'tentativa' => 2, 'urgente' => true, 'vazio' => null]);
$dados    = enviado($comTipos)['data'];
confere($dados['tentativa'] === '2' && $dados['urgente'] === '1' && !array_key_exists('vazio', $dados), 'dados do push de dados todos como texto (nulos saem)');
$comEstruturas = aviso(OrderAssigned::class, 'New order RP-1 assigned!', 'You have a new order assigned, tap for details.', [
    'id'       => 'order_abc',
    'type'     => 'order_assigned',
    'lista'    => ['a', 'b'],
    'acentos'  => ['ação'],
    'quebrado' => ["a\xB1"],
    'objeto'   => new class {
        public function __toString(): string
        {
            return 'em texto';
        }
    },
]);
$dados = enviado($comEstruturas)['data'];
confere($dados['lista'] === '["a","b"]', 'lista nos dados vira JSON em texto');
confere($dados['acentos'] === '["ação"]', 'JSON dos dados sem escapar os acentos');
confere($dados['objeto'] === 'em texto', 'objeto com __toString vira o texto dele (e não "{}")');
confere($dados['quebrado'] === "[\"a\u{FFFD}\"]", 'UTF-8 inválido dentro de uma lista é substituído (json_encode não deixa false nos dados)');
putenv('ENTREGAS_ALARME_POR_DADOS');

echo '== Aviso sem tradução' . PHP_EOL;

class AvisoNovoDoFleetOps extends Illuminate\Notifications\Notification
{
    public string $title   = 'Something new';
    public string $message = 'Body';
    public array $data     = ['type' => 'algo_novo'];

    public function toFcm($notifiable)
    {
        return Fleetbase\Support\PushNotification::createFcmMessage($this->title, $this->message, $this->data);
    }
}

Illuminate\Support\Facades\Log::$registros = [];
$mensagem                                  = enviado(new AvisoNovoDoFleetOps());
confere(($mensagem['notification']['title'] ?? null) === 'Something new' && !isset($mensagem['android']['notification']['channel_id']), 'segue como veio (texto e canal)');
confere((Illuminate\Support\Facades\Log::$registros[0][1] ?? null) === '[entregas] aviso push sem tradução', 'e fica no log');

echo '== CanalFcmEntregas' . PHP_EOL;

class MessagingFalso implements Kreait\Firebase\Contract\Messaging
{
    public array $enviados = [];

    /** Uma resposta por chamada, na ordem (função dos tokens que devolve o relatório). Fila vazia: um token entregue e outro recusado. */
    public array $respostas = [];

    public function sendMulticast($message, $registrationTokens, bool $validateOnly = false)
    {
        $this->enviados[] = [$message, $registrationTokens];

        $resposta = array_shift($this->respostas);

        return $resposta ? $resposta($registrationTokens) : new Kreait\Firebase\Messaging\MulticastSendReport([new Kreait\Firebase\Messaging\SendReport(false), new Kreait\Firebase\Messaging\SendReport(true)]);
    }
}

// o que o FCM responde por token: 'ok', 'invalida' (400: mensagem recusada), 'token-invalido' (400: token malformado)
// ou 'desconhecido' (404: o token não existe mais)
function fcmResponde(array $situacoes): Closure
{
    return fn (array $tokens) => new Kreait\Firebase\Messaging\MulticastSendReport(array_map(function ($token) use ($situacoes) {
        $situacao = $situacoes[$token] ?? 'ok';

        return new Kreait\Firebase\Messaging\SendReport(
            $situacao !== 'ok',
            in_array($situacao, ['invalida', 'token-invalido'], true), // o 400 do FCM é o mesmo nos dois casos (InvalidMessage)
            $token,
            $situacao === 'ok' ? null : new RuntimeException("FCM: {$situacao}"),
            $situacao === 'token-invalido',
            $situacao === 'desconhecido',
        );
    }, array_values($tokens)));
}

function registrosDoLog(string $mensagem): array
{
    return array_values(array_filter(Illuminate\Support\Facades\Log::$registros, fn ($registro) => $registro[1] === $mensagem));
}

class EventosFalsos implements Illuminate\Contracts\Events\Dispatcher
{
    public array $eventos = [];

    public function dispatch($event, $payload = [], $halt = false)
    {
        $this->eventos[] = $event;
    }
}

class MotoboyFalso
{
    public string $public_id = 'driver_motoca';

    public function __construct(public array $tokens) {}

    public function routeNotificationFor($canal, $notificacao)
    {
        return $this->tokens;
    }
}

// mensagem que falha ao ser copiada: força o erro na adaptação
class MensagemQuebrada extends NotificationChannels\Fcm\FcmMessage
{
    public function __clone()
    {
        throw new RuntimeException('falha de teste');
    }
}

class AvisoQuebrado extends OrderCanceled
{
    public function toFcm($notifiable)
    {
        return new MensagemQuebrada(data: ['type' => 'order_canceled'], notification: new NotificationChannels\Fcm\Resources\Notification(title: 'Order RP-1 was canceled', body: 'Order RP-1 has been canceled.'));
    }
}

putenv('ENTREGAS_ALARME_POR_DADOS=1');
$padrao     = new MessagingFalso();
$daMensagem = new MessagingFalso();
$eventos    = new EventosFalsos();
$canal      = new App\Notifications\Entregas\CanalFcmEntregas($eventos, $padrao);

Fleetbase\Support\PushNotification::$cliente = $daMensagem;
Illuminate\Support\Facades\Log::$registros   = [];
$canal->send(new MotoboyFalso(['token-1', 'token-2']), new OrderPing(pedidoDoTeste(), 1234));
confere(count($daMensagem->enviados) === 1 && $padrao->enviados === [], 'envia pelo cliente da mensagem (o do Fleetbase)');
[$enviada, $tokens] = $daMensagem->enviados[0] ?? [null, null];
confere($tokens === ['token-1', 'token-2'], 'para os tokens do motoboy');
confere($enviada !== null && $enviada->notification === null && ($enviada->data['title'] ?? null) === 'Novo pedido disponível', 'a mensagem enviada é a adaptada');
confere(count($eventos->eventos) === 1 && $eventos->eventos[0] instanceof Illuminate\Notifications\Events\NotificationFailed, 'token com falha gera NotificationFailed, como no pacote');
$evento = $eventos->eventos[0] ?? null;
confere($evento?->channel === NotificationChannels\Fcm\FcmChannel::class && isset($evento->data['report']), 'o evento leva o canal do pacote (FcmChannel) e o relatório');
confere(count(registrosDoLog('[entregas] push recusado pelo FCM')) === 1, 'e o token com falha também vai para o log');
confere($canal->send(new MotoboyFalso([]), new OrderPing(pedidoDoTeste(), 1234)) === null, 'motoboy sem token: nada é enviado');

Fleetbase\Support\PushNotification::$cliente = null;
Illuminate\Support\Facades\Log::$registros    = [];
$canal->send(new MotoboyFalso(['token-1']), (new ReflectionClass(AvisoQuebrado::class))->newInstanceWithoutConstructor());
$original = $padrao->enviados[0][0] ?? null;
confere($original instanceof MensagemQuebrada && $original->notification->title === 'Order RP-1 was canceled', 'erro na adaptação: envia a mensagem original');
confere((Illuminate\Support\Facades\Log::$registros[0][1] ?? null) === '[entregas] aviso push sem adaptação', 'e registra o erro no log');
$excecao = Illuminate\Support\Facades\Log::$registros[0][2]['exception'] ?? null;
confere($excecao instanceof RuntimeException && $excecao->getMessage() === 'falha de teste', 'o log leva a exceção inteira na chave "exception" (com o rastreio)');

echo '== CanalFcmEntregas: recusa do FCM' . PHP_EOL;

function canalComFcm(MessagingFalso $fcm, EventosFalsos $eventos): App\Notifications\Entregas\CanalFcmEntregas
{
    return new App\Notifications\Entregas\CanalFcmEntregas($eventos, $fcm);
}

// o FCM recusa o formato adaptado como inválido (400) para os dois tokens: eles recebem o push original
$fcm            = new MessagingFalso();
$eventos        = new EventosFalsos();
$fcm->respostas = [fcmResponde(['token-1' => 'invalida', 'token-2' => 'invalida']), fcmResponde([])];
Illuminate\Support\Facades\Log::$registros = [];
$resultado = canalComFcm($fcm, $eventos)->send(new MotoboyFalso(['token-1', 'token-2']), new OrderPing(pedidoDoTeste(), 1234));
confere(count($fcm->enviados) === 2, 'adaptada recusada como inválida: o canal envia de novo');
[$adaptada]                   = $fcm->enviados[0] ?? [null];
[$reenviada, $tokensReenvio]  = $fcm->enviados[1] ?? [null, null];
confere($adaptada?->notification === null && ($adaptada?->data['title'] ?? null) === 'Novo pedido disponível', 'o primeiro envio é a mensagem adaptada');
confere($reenviada?->notification?->title === 'New incoming order!' && !isset($reenviada->data['title']), 'o segundo é o push original do Fleet-Ops (em inglês, com bloco de notificação e sem título nos dados)');
confere($tokensReenvio === ['token-1', 'token-2'], 'reenviado para os tokens recusados');
$recusas = registrosDoLog('[entregas] push recusado pelo FCM');
confere(count($recusas) === 2 && $recusas[0][2]['notificacao'] === OrderPing::class && $recusas[0][2]['mensagem_invalida'] === true, 'cada recusa vai para o log, com a notificação e o motivo');
confere(($recusas[0][2]['motoboy'] ?? null) === 'driver_motoca' && array_column($recusas, 0) === ['warning', 'warning'], 'com o motoboy (public_id), e a recusa de mensagem inválida vai como warning');
$reenvios = registrosDoLog('[entregas] push adaptado recusado pelo FCM; enviado o original');
confere(count($reenvios) === 1 && $reenvios[0][2] === ['notificacao' => OrderPing::class, 'tokens' => 2], 'e o reenvio do original também, com a quantidade de tokens');
confere(count($eventos->eventos) === 2, 'as recusas do envio adaptado continuam gerando NotificationFailed (o reenvio entregou)');
confere($resultado instanceof Illuminate\Support\Collection && count($resultado) === 2, 'o retorno junta o relatório do envio adaptado e o do reenvio');

// só um token recusa a mensagem como inválida: o reenvio vai só para ele
$fcm            = new MessagingFalso();
$fcm->respostas = [fcmResponde(['token-2' => 'invalida']), fcmResponde([])];
canalComFcm($fcm, new EventosFalsos())->send(new MotoboyFalso(['token-1', 'token-2']), new OrderPing(pedidoDoTeste(), 1234));
confere(count($fcm->enviados) === 2 && ($fcm->enviados[1][1] ?? null) === ['token-2'], 'o reenvio vai só para o token que recusou a mensagem');

// o original também é recusado como inválido: só duas chamadas (a adaptada e o reenvio, que não se repete), as duas recusas no log
$fcm            = new MessagingFalso();
$fcm->respostas = [fcmResponde(['token-1' => 'invalida']), fcmResponde(['token-1' => 'invalida'])];
Illuminate\Support\Facades\Log::$registros = [];
canalComFcm($fcm, new EventosFalsos())->send(new MotoboyFalso(['token-1']), new OrderPing(pedidoDoTeste(), 1234));
confere(count($fcm->enviados) === 2, 'adaptada e original recusadas como inválidas: só duas chamadas (o reenvio não se repete)');
confere(count(registrosDoLog('[entregas] push recusado pelo FCM')) === 2 && count(registrosDoLog('[entregas] push adaptado recusado pelo FCM; enviado o original')) === 1, 'as duas recusas vão para o log e o reenvio é registrado uma vez');

// token malformado (o 400 é do token, não da mensagem): o original não adiantaria, sem reenvio
$fcm            = new MessagingFalso();
$fcm->respostas = [fcmResponde(['token-1' => 'token-invalido'])];
Illuminate\Support\Facades\Log::$registros = [];
canalComFcm($fcm, new EventosFalsos())->send(new MotoboyFalso(['token-1']), new OrderPing(pedidoDoTeste(), 1234));
confere(count($fcm->enviados) === 1 && registrosDoLog('[entregas] push adaptado recusado pelo FCM; enviado o original') === [], 'token inválido (messageTargetWasInvalid): sem reenvio');
confere(array_column(registrosDoLog('[entregas] push recusado pelo FCM'), 0) === ['warning'], 'mas a recusa vai para o log, como warning');

// os dois motivos no mesmo envio: só o token que recusou a mensagem recebe o original
$fcm            = new MessagingFalso();
$fcm->respostas = [fcmResponde(['token-1' => 'token-invalido', 'token-2' => 'invalida']), fcmResponde([])];
canalComFcm($fcm, new EventosFalsos())->send(new MotoboyFalso(['token-1', 'token-2']), new OrderPing(pedidoDoTeste(), 1234));
confere(count($fcm->enviados) === 2 && ($fcm->enviados[1][1] ?? null) === ['token-2'], 'token malformado e mensagem recusada no mesmo envio: o reenvio vai só para o da mensagem');

// token que não existe mais (404, token velho que ninguém limpa): sem reenvio, só o log, como info
$fcm            = new MessagingFalso();
$fcm->respostas = [fcmResponde(['token-1' => 'desconhecido', 'token-2' => 'desconhecido'])];
Illuminate\Support\Facades\Log::$registros = [];
canalComFcm($fcm, new EventosFalsos())->send(new MotoboyFalso(['token-1', 'token-2']), new OrderPing(pedidoDoTeste(), 1234));
confere(count($fcm->enviados) === 1, 'recusa que não é de mensagem inválida (token desconhecido): sem reenvio');
$recusas = registrosDoLog('[entregas] push recusado pelo FCM');
confere(count($recusas) === 2 && $recusas[1][2] === ['notificacao' => OrderPing::class, 'motoboy' => 'driver_motoca', 'mensagem_invalida' => false, 'erro' => 'FCM: desconhecido'], 'mas cada recusa vai para o log, com a notificação, o motoboy e o erro do FCM');
confere(array_column($recusas, 0) === ['info', 'info'], 'token desconhecido (404) vai como info, e não como warning');
confere(registrosDoLog('[entregas] push adaptado recusado pelo FCM; enviado o original') === [], 'e não há registro de reenvio');

// notificável sem public_id: o motoboy do log fica nulo, sem aviso do PHP (o teste trata aviso como erro)
$semCodigo = new class {
    public function routeNotificationFor($canal, $notificacao)
    {
        return ['token-1'];
    }
};
$fcm            = new MessagingFalso();
$fcm->respostas = [fcmResponde(['token-1' => 'desconhecido'])];
Illuminate\Support\Facades\Log::$registros = [];
canalComFcm($fcm, new EventosFalsos())->send($semCodigo, new OrderPing(pedidoDoTeste(), 1234));
$recusa = registrosDoLog('[entregas] push recusado pelo FCM')[0] ?? null;
confere($recusa !== null && array_key_exists('motoboy', $recusa[2]) && $recusa[2]['motoboy'] === null, 'notificável sem public_id: motoboy nulo no log');

// mensagem que não foi adaptada (erro na adaptação) e o FCM a recusa como inválida: sem reenvio (seria a mesma mensagem)
$fcm            = new MessagingFalso();
$fcm->respostas = [fcmResponde(['token-1' => 'invalida'])];
Illuminate\Support\Facades\Log::$registros = [];
canalComFcm($fcm, new EventosFalsos())->send(new MotoboyFalso(['token-1']), (new ReflectionClass(AvisoQuebrado::class))->newInstanceWithoutConstructor());
confere(count($fcm->enviados) === 1, 'original recusado como inválido: não é reenviado (sem laço)');
confere(count(registrosDoLog('[entregas] push recusado pelo FCM')) === 1 && registrosDoLog('[entregas] push adaptado recusado pelo FCM; enviado o original') === [], 'só o log da recusa');

// tudo entregue: um envio só e nada de log de recusa
$fcm            = new MessagingFalso();
$fcm->respostas = [fcmResponde([])];
Illuminate\Support\Facades\Log::$registros = [];
canalComFcm($fcm, $eventos = new EventosFalsos())->send(new MotoboyFalso(['token-1', 'token-2']), new OrderPing(pedidoDoTeste(), 1234));
confere(count($fcm->enviados) === 1 && $eventos->eventos === [] && Illuminate\Support\Facades\Log::$registros === [], 'todos entregues: um envio, sem evento e sem log');
putenv('ENTREGAS_ALARME_POR_DADOS');

resumo();
