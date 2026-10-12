<?php

// Stubs dos testes da integração de pedidos de outros sistemas (scripts/teste-php/integracao-*.php), sobre os do iFood
// (banco em memória, Cache, Http, Log, models do Fleetbase): o User do core (quem cancelou), a loja integrada de teste,
// o pedido de teste e o corpo padrão do POST pedidos.

namespace {
    require_once __DIR__ . '/stubs-ifood.php';
    require_once __DIR__ . '/stubs-ifood-fleetbase.php';
}

namespace Fleetbase\Models {
    if (!class_exists(User::class, false)) {
        #[\AllowDynamicProperties]
        class User
        {
            use \Teste\ModeloDeTeste;
            public $uuid;
            public $type;
        }
    }
}

namespace {
    define('CHAVE_DA_LOJA_A', 'ent_live_' . str_repeat('A', 40));

    /** Zera tudo e liga a integração (com a chave do Google para a geocodificação de reserva). */
    function reiniciarIntegracao(): void
    {
        reiniciarIfood();
        reiniciarFleetbase();
        \Fleetbase\Models\User::$todos                                   = [];
        \Teste\Config::$valores['services.entregas.integracao']          = '1';
        \Teste\Config::$valores['services.entregas.console_url']         = 'https://entregas.restaurantepro.com.br';
        \Teste\Config::$valores['services.google_maps.api_key']          = 'chave-google';
    }

    /** A loja A (vendor-a) com a chave CHAVE_DA_LOJA_A; conectada = com webhook e segredo ("segredo-a"). Devolve a linha. */
    function lojaIntegradaA(bool $conectada = true): object
    {
        \Teste\Banco::inserir('entregas_integracao_lojas', [
            'company_uuid'    => 'empresa-1',
            'vendor_uuid'     => 'vendor-a',
            'chave_hash'      => hash('sha256', CHAVE_DA_LOJA_A),
            'chave_final'     => 'AAAA',
            'sistema'         => $conectada ? 'RestaurantePro' : null,
            'webhook_url'     => $conectada ? 'https://api.restaurantepro.com.br/functions/v1/entregas-webhook?t=abc' : null,
            'webhook_segredo' => $conectada ? encrypt('segredo-a') : null,
            'conectada_em'    => $conectada ? '2026-10-05 09:00:00' : null,
            'created_at'      => '2026-10-05 08:00:00',
            'updated_at'      => '2026-10-05 08:00:00',
        ], false);

        return (new \Teste\Consulta('entregas_integracao_lojas'))->where('vendor_uuid', 'vendor-a')->first();
    }

    /** Resolvedor de DNS falso: todo nome aponta para um IP público. */
    function resolverPublico(): \Closure
    {
        return fn (string $host) => ['200.150.10.20'];
    }

    /** O corpo padrão do POST pedidos (dados fictícios), com $mudancas por cima (array_replace_recursive). */
    function corpoDoPedido(array $mudancas = []): array
    {
        return array_replace_recursive([
            'pedido_externo_id' => 'ord-6f1c',
            'numero'            => '4821',
            'cliente'           => ['nome' => 'Maria Ficticia', 'telefone' => '(16) 99999-0000'],
            'entrega'           => [
                'endereco'    => 'Rua Ficticia, 45',
                'complemento' => 'Ap 12',
                'bairro'      => 'Jardim Ficticio',
                'cidade'      => 'Ribeirão Preto',
                'uf'          => 'sp',
                'cep'         => '14090000',
                'referencia'  => 'Perto da praça',
                'latitude'    => -21.17,
                'longitude'   => -47.81,
            ],
            'cobranca'          => ['forma' => 'dinheiro', 'valor' => 58.9, 'troco_para' => 100],
            'observacoes'       => 'Sem cebola',
        ], $mudancas);
    }

    /**
     * Um Order de teste da loja A (aceito pelo driver-1, ainda na coleta) e a linha dele na entregas_integracao_pedidos.
     * Devolve o Order.
     */
    function pedidoDaIntegracao(array $order = [], array $linha = []): \Fleetbase\FleetOps\Models\Order
    {
        $pedido = new \Fleetbase\FleetOps\Models\Order($order + [
            'uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'customer_uuid' => 'vendor-a',
            'status' => 'started', 'started' => true, 'dispatched' => true, 'adhoc' => false, 'driver_assigned_uuid' => 'driver-1',
            'tracking' => 'RP0001',
        ]);
        if ($pedido->driver_assigned_uuid) {
            $motoboy = \Fleetbase\FleetOps\Models\Driver::where('uuid', $pedido->driver_assigned_uuid)->first();
            if (!$motoboy) {
                $motoboy = new \Fleetbase\FleetOps\Models\Driver(['uuid' => $pedido->driver_assigned_uuid, 'public_id' => 'driver_' . substr($pedido->driver_assigned_uuid, -1), 'name' => 'Motoboy Ficticio', 'photo_url' => 'https://exemplo.com/foto.png']);
                \Fleetbase\FleetOps\Models\Driver::$todos[] = $motoboy;
            }
            $pedido->driverAssigned = $motoboy;
        }
        \Fleetbase\FleetOps\Models\Order::$todos[] = $pedido;

        \Teste\Banco::inserir('entregas_integracao_pedidos', $linha + [
            'company_uuid' => 'empresa-1', 'vendor_uuid' => 'vendor-a', 'order_uuid' => $pedido->uuid, 'pedido_externo_id' => 'ord-1',
            'sistema' => 'RestaurantePro', 'numero' => '4821', 'cliente_nome' => 'Maria Ficticia', 'cliente_telefone' => '16999990000',
            'cobranca_forma' => 'dinheiro', 'cobranca_centavos' => 5890, 'troco_para_centavos' => 10000,
            'despachado_em' => '2026-10-05 14:50:00', 'created_at' => '2026-10-05 14:50:00', 'updated_at' => '2026-10-05 14:50:00',
        ], false);

        return $pedido;
    }

    /** A linha do pedido (relida do banco em memória). */
    function linhaDaIntegracao(string $orderUuid = 'order-1'): ?object
    {
        return (new \Teste\Consulta('entregas_integracao_pedidos'))->where('order_uuid', $orderUuid)->first();
    }
}
