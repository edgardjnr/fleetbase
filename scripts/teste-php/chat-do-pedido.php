<?php

// Botão "Chat" do cliente nos detalhes do pedido no app (MotoboyController::chatDoPedido): abre a conversa do motoboy
// com a loja do pedido (a mesma do portal, ConversasDaLoja) ou, num pedido sem loja, com a central (ChatComACentral).
// A loja do pedido é o Vendor cliente dele; sem ele (a central criou o pedido sem escolher a loja), a loja dona do Local
// de coleta.
// As duas classes são trocadas aqui por versões que só registram a chamada (a gravação no chat usa os models do core).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/chat-do-pedido.php

namespace App\Support\Entregas {
    class ConversasDaLoja
    {
        public static array $chamadas = [];

        public static function abrir($loja, $motoboy, string $usuario)
        {
            static::$chamadas[] = [$loja->uuid, $motoboy->uuid, $usuario];

            return (object) ['public_id' => 'chat_loja_' . $loja->uuid];
        }
    }

    class ChatComACentral
    {
        public static array $chamadas = [];

        public static function abrir($motoboy)
        {
            static::$chamadas[] = $motoboy->uuid;

            return (object) ['public_id' => 'chat_central'];
        }
    }
}

namespace Fleetbase\Http\Resources {
    class ChatChannel
    {
        public function __construct(public $resource) {}
    }
}

namespace {
    require __DIR__ . '/stubs-ganhos.php';

    use App\Http\Controllers\Entregas\MotoboyController;
    use App\Support\Entregas\ChatComACentral;
    use App\Support\Entregas\ConversasDaLoja;
    use Fleetbase\FleetOps\Models\Driver;
    use Fleetbase\FleetOps\Models\Order;
    use Fleetbase\FleetOps\Models\Payload;
    use Fleetbase\FleetOps\Models\Place;
    use Fleetbase\FleetOps\Models\Vendor;
    use Fleetbase\Http\Resources\ChatChannel as ChatChannelResource;
    use Illuminate\Http\Request;

    function reiniciar(): void
    {
        ConversasDaLoja::$chamadas = [];
        ChatComACentral::$chamadas = [];
    }

    Driver::$todos = [new Driver(['uuid' => 'uuid-driver_motoca', 'public_id' => 'driver_motoca', 'user_uuid' => 'usuario-motoca', 'online' => true])];
    Vendor::$lojas = [
        new Vendor(['uuid' => 'vendor-a', 'public_id' => 'vendor_a', 'name' => 'Loja A']),
        new Vendor(['uuid' => 'vendor-b', 'public_id' => 'vendor_b', 'name' => 'Loja B']),
        new Vendor(['uuid' => 'vendor-outra', 'public_id' => 'vendor_outra', 'name' => 'Outra empresa', 'company_uuid' => 'outra']),
    ];
    $pedido = fn (string $id, array $atributos = []) => new Order($atributos + [
        'uuid'                 => 'uuid-' . $id,
        'public_id'            => $id,
        'status'               => 'started',
        'driver_assigned_uuid' => 'uuid-driver_motoca',
        'customer_uuid'        => 'vendor-a',
    ]);
    $comColeta = fn (?string $dono, string $tipo = 'Fleetbase\FleetOps\Models\Vendor') => new Payload(new Place([
        'uuid'       => 'place-coleta',
        'name'       => 'Local da loja',
        'owner_uuid' => $dono,
        'owner_type' => $dono ? $tipo : null,
    ]));
    Order::$todos = [
        $pedido('order_da-loja'),
        $pedido('order_sem-loja', ['customer_uuid' => null]),
        $pedido('order_do-contato', ['customer_uuid' => 'contato-x']),
        $pedido('order_loja-de-outra-empresa', ['customer_uuid' => 'vendor-outra']),
        $pedido('order_de-outro', ['driver_assigned_uuid' => 'uuid-driver_outro']),
        $pedido('order_aberto', ['status' => 'dispatched', 'adhoc' => true, 'driver_assigned_uuid' => null]),
        $pedido('order_outra-empresa', ['company_uuid' => 'outra']),
        // coleta no Local de uma loja (dono = o Vendor), sem a loja como cliente
        $pedido('order_coleta-da-loja', ['customer_uuid' => null, 'payload' => $comColeta('vendor-b')]),
        $pedido('order_contato-coleta-da-loja', ['customer_uuid' => 'contato-x', 'payload' => $comColeta('vendor-b')]),
        $pedido('order_loja-e-coleta-de-outra', ['payload' => $comColeta('vendor-b')]),
        $pedido('order_coleta-sem-dono', ['customer_uuid' => null, 'payload' => $comColeta(null)]),
        $pedido('order_coleta-de-contato', ['customer_uuid' => null, 'payload' => $comColeta('contato-x', 'Fleetbase\FleetOps\Models\Contact')]),
        $pedido('order_coleta-loja-de-outra-empresa', ['customer_uuid' => null, 'payload' => $comColeta('vendor-outra')]),
        $pedido('order_sem-coleta', ['customer_uuid' => null, 'payload' => new Payload(null, null)]),
    ];
    $controller = new MotoboyController();
    $chat       = fn (string $id, string $token = '12|abc') => $controller->chatDoPedido(new Request([], $token), $id);

    echo '== MotoboyController::chatDoPedido' . PHP_EOL;
    reiniciar();
    $resposta = $chat('order_da-loja');
    confere($resposta instanceof ChatChannelResource && $resposta->resource->public_id === 'chat_loja_vendor-a', 'pedido com loja: a conversa com a loja');
    confere(ConversasDaLoja::$chamadas === [['vendor-a', 'uuid-driver_motoca', 'usuario-motoca']] && ChatComACentral::$chamadas === [], 'loja do pedido (customer_uuid), o motoboy da sessão e o usuário dele como criador');

    reiniciar();
    confere($chat('uuid-order_da-loja')->resource->public_id === 'chat_loja_vendor-a', 'pedido achado também pelo uuid');

    reiniciar();
    $resposta = $chat('order_sem-loja');
    confere($resposta->resource->public_id === 'chat_central' && ConversasDaLoja::$chamadas === [] && ChatComACentral::$chamadas === ['uuid-driver_motoca'], 'pedido sem cliente: a conversa com a central');
    reiniciar();
    confere($chat('order_do-contato')->resource->public_id === 'chat_central', 'cliente que não é loja (contato): a central');
    reiniciar();
    confere($chat('order_loja-de-outra-empresa')->resource->public_id === 'chat_central' && ConversasDaLoja::$chamadas === [], 'loja de outra empresa não vale: a central');

    reiniciar();
    $resposta = $chat('order_coleta-da-loja');
    confere($resposta->resource->public_id === 'chat_loja_vendor-b' && ConversasDaLoja::$chamadas === [['vendor-b', 'uuid-driver_motoca', 'usuario-motoca']] && ChatComACentral::$chamadas === [], 'sem cliente, coleta no Local da loja: a conversa com a loja dona do Local');
    reiniciar();
    confere($chat('order_contato-coleta-da-loja')->resource->public_id === 'chat_loja_vendor-b', 'cliente contato, coleta no Local da loja: a loja dona do Local');
    reiniciar();
    confere($chat('order_loja-e-coleta-de-outra')->resource->public_id === 'chat_loja_vendor-a', 'a loja cliente vale antes da dona do Local de coleta');
    reiniciar();
    confere($chat('order_coleta-sem-dono')->resource->public_id === 'chat_central' && ConversasDaLoja::$chamadas === [], 'Local de coleta sem dono: a central');
    reiniciar();
    confere($chat('order_coleta-de-contato')->resource->public_id === 'chat_central' && ConversasDaLoja::$chamadas === [], 'Local de coleta de um contato (não é loja): a central');
    reiniciar();
    confere($chat('order_coleta-loja-de-outra-empresa')->resource->public_id === 'chat_central' && ConversasDaLoja::$chamadas === [], 'Local de coleta de uma loja de outra empresa: a central');
    reiniciar();
    confere($chat('order_sem-coleta')->resource->public_id === 'chat_central', 'pedido sem coleta: a central');

    reiniciar();
    $resposta = $chat('order_de-outro');
    confere($resposta->status === 403 && $resposta->dados['errors'] === ['Aceite o pedido para conversar com a loja.'], 'pedido de outro motoboy: 403');
    confere($chat('order_aberto')->status === 403, 'pedido aberto ainda sem aceite: 403');
    confere(ConversasDaLoja::$chamadas === [] && ChatComACentral::$chamadas === [], 'sem conversa aberta nos dois casos');
    confere($chat('order_outra-empresa')->status === 404 && $chat('order_nao-existe')->status === 404, 'pedido de outra empresa ou inexistente: 404');
    confere($chat('order_da-loja', 'flb_live_abc')->status === 403, 'chave de API (não é motoboy): 403');

    echo PHP_EOL . 'FALHAS: ' . $GLOBALS['falhas'] . PHP_EOL;
}
