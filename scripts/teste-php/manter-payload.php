<?php

// Pedido nunca perde a coleta e a entrega (payload) numa gravação (ManterPayloadDoPedido, Order::updating): a troca de
// motoboy pelo console deixou o RP570671 e o RP444998 com payload_uuid NULL (sumiam da lista do motoboy, que exige
// payload, e dos mapas). Payload preenchido → vazio volta ao anterior e vai para o log com a ação da rota; o resto passa.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/manter-payload.php

namespace Illuminate\Support\Facades {
    class Log
    {
        public static array $linhas = [];

        public static function warning(string $mensagem, array $contexto = []): void
        {
            static::$linhas[] = ['warning', $mensagem, $contexto];
        }
    }
}

namespace Teste {
    /** O mínimo do Model do Eloquent que o listener usa: atributos, originais e isDirty/getOriginal. */
    class Pedido
    {
        public array $atributos;

        public function __construct(public array $originais, array $mudancas = [], public array $relacoes = [])
        {
            $this->atributos = array_merge($originais, $mudancas);
        }

        public function __get($chave) { return $this->atributos[$chave] ?? null; }
        public function __set($chave, $valor) { $this->atributos[$chave] = $valor; }
        public function getOriginal($chave) { return $this->originais[$chave] ?? null; }
        public function isDirty($chave) { return ($this->atributos[$chave] ?? null) !== ($this->originais[$chave] ?? null); }
        public function relationLoaded($nome) { return array_key_exists($nome, $this->relacoes); }
        public function unsetRelation($nome) { unset($this->relacoes[$nome]); return $this; }
    }

    class Rota
    {
        public function getActionName() { return 'Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController@updateRecord'; }
    }

    class Requisicao
    {
        public function route() { return new Rota(); }
    }
}

namespace {
    require __DIR__ . '/stubs.php';
    require __DIR__ . '/../../api/app/Listeners/Entregas/ManterPayloadDoPedido.php';

    use App\Listeners\Entregas\ManterPayloadDoPedido;
    use Illuminate\Support\Facades\Log;
    use Teste\Pedido;

    function request() { return new \Teste\Requisicao(); }

    $base = ['uuid' => 'uuid-1', 'public_id' => 'order_um', 'internal_id' => 'RP444998', 'payload_uuid' => 'payload-1', 'driver_assigned_uuid' => 'motoboy-a'];

    echo '== ManterPayloadDoPedido::aoAtualizar' . PHP_EOL;

    $pedido = new Pedido($base, ['payload_uuid' => null, 'driver_assigned_uuid' => 'motoboy-b'], ['payload' => null]);
    ManterPayloadDoPedido::aoAtualizar($pedido);
    confere($pedido->payload_uuid === 'payload-1', 'payload preenchido → NULL: volta ao anterior');
    confere($pedido->driver_assigned_uuid === 'motoboy-b', 'a troca do motoboy na mesma gravação continua');
    confere(!$pedido->relationLoaded('payload'), 'a relação vazia carregada sai (o próximo acesso relê o payload)');
    confere(count(Log::$linhas) === 1 && Log::$linhas[0][1] === '[entregas] pedido ia perder a coleta e a entrega (payload); mantido', 'um warning no log');
    confere((Log::$linhas[0][2] ?? []) === ['pedido' => 'order_um', 'acao' => 'Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController@updateRecord'], 'o log traz o pedido e a ação da rota (só ids)');

    Log::$linhas = [];
    $pedido = new Pedido($base, ['payload_uuid' => '']);
    ManterPayloadDoPedido::aoAtualizar($pedido);
    confere($pedido->payload_uuid === 'payload-1' && count(Log::$linhas) === 1, 'payload preenchido → texto vazio: também volta');

    Log::$linhas = [];
    $pedido = new Pedido($base, ['payload_uuid' => 'payload-2']);
    ManterPayloadDoPedido::aoAtualizar($pedido);
    confere($pedido->payload_uuid === 'payload-2' && Log::$linhas === [], 'troca de um payload por outro passa, sem log');

    $pedido = new Pedido(['payload_uuid' => null] + $base, ['payload_uuid' => 'payload-novo']);
    ManterPayloadDoPedido::aoAtualizar($pedido);
    confere($pedido->payload_uuid === 'payload-novo' && Log::$linhas === [], 'pedido sem payload que ganha um: passa');

    $pedido = new Pedido(['payload_uuid' => null] + $base, ['status' => 'started']);
    ManterPayloadDoPedido::aoAtualizar($pedido);
    confere($pedido->payload_uuid === null && Log::$linhas === [], 'pedido que já estava sem payload: nada muda');

    $pedido = new Pedido($base, ['status' => 'enroute']);
    ManterPayloadDoPedido::aoAtualizar($pedido);
    confere($pedido->payload_uuid === 'payload-1' && $pedido->status === 'enroute' && Log::$linhas === [], 'gravação sem mexer no payload: passa');

    ManterPayloadDoPedido::aoAtualizar(new \stdClass());
    confere(true, 'objeto inesperado não lança');

    resumo();
}
