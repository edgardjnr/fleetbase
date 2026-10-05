<?php

// Pedidos do Logistics do iFood para os testes, com dados fictícios. A estrutura é a vista na sonda de 2026-10-05
// (deploy/ifood-sonda/pedidos/*-logistics.json); o pedido com cobrança na porta e o agendado seguem o formato da
// documentação (docs/ifood/referencia-logistics.md, 3.2), que a sonda não pôde ver.

/** Pedido de teste pago online, como os da sonda: isTest, entrega em 0,0, sem `payments`. */
function pedidoDeTestePagoOnline(): array
{
    return [
        'id'          => 'pedido-teste-1',
        'orderType'   => 'DELIVERY',
        'delivery'    => [
            'mode'            => 'DEFAULT',
            'deliveredBy'     => 'MERCHANT',
            'deliveryDateTime' => '2026-10-05T18:45:00.000Z',
            'observations'    => 'Pedido de teste gerado automaticamente.',
            'deliveryAddress' => [
                'streetName' => 'Rua TESTE', 'streetNumber' => '999999', 'formattedAddress' => 'Rua TESTE, 999999', 'neighborhood' => 'Bairro TESTE',
                'complement' => 'Complemento TESTE', 'postalCode' => '99999999', 'city' => 'TESTE', 'state' => 'XX', 'country' => 'XX', 'reference' => 'TESTE',
                'coordinates' => ['latitude' => 0, 'longitude' => 0],
            ],
        ],
        'orderTiming' => 'IMMEDIATE',
        'displayId'   => '9753',
        'createdAt'   => '2026-10-05T17:59:00.000Z',
        'isTest'      => true,
        'merchant'    => [
            'id' => 'merchant-1', 'name' => 'Loja de Teste',
            'merchantAddress' => ['country' => 'BR', 'state' => 'AC', 'city' => 'Bujari', 'district' => 'Centro', 'street' => 'Ramal Bujari', 'number' => '0', 'postalCode' => '12345678', 'latitude' => -9.822384, 'longitude' => -67.948589],
        ],
        'customer'    => ['name' => 'PEDIDO DE TESTE - Cliente Ficticio', 'id' => 'cliente-1', 'phone' => ['number' => '0800 000 0001', 'localizer' => '11112222', 'localizerExpiration' => '2026-10-05T21:59:00.000Z']],
        'items'       => [['index' => 1, 'name' => 'PRODUTO TESTE', 'quantity' => 1, 'options' => []]],
        'total'       => ['additionalFees' => 1, 'subTotal' => 21, 'deliveryFee' => 5, 'benefits' => 0, 'orderAmount' => 27],
    ];
}

/** Pedido real com cobrança na porta em dinheiro, troco para R$ 100 (formato da documentação). */
function pedidoEmDinheiroComTroco(): array
{
    return [
        'id'          => 'pedido-real-1',
        'orderType'   => 'DELIVERY',
        'delivery'    => [
            'mode'             => 'DEFAULT',
            'deliveredBy'      => 'MERCHANT',
            'deliveryDateTime' => '2026-10-05T18:40:00.000Z',
            'observations'     => 'Interfone quebrado, ligar ao chegar.',
            'deliveryAddress'  => [
                'streetName' => 'Rua Ficticia', 'streetNumber' => '123', 'formattedAddress' => 'Rua Ficticia, 123', 'neighborhood' => 'Centro',
                'complement' => 'Apto 501', 'postalCode' => '14000000', 'city' => 'Ribeirão Preto', 'state' => 'SP', 'country' => 'BR', 'reference' => 'Perto da praça',
                'coordinates' => ['latitude' => -21.1700, 'longitude' => -47.8100],
            ],
        ],
        'orderTiming' => 'IMMEDIATE',
        'displayId'   => '4821',
        'createdAt'   => '2026-10-05T17:58:00.000Z',
        'isTest'      => false,
        'merchant'    => [
            'id' => 'merchant-1', 'name' => 'Pizzaria Ficticia',
            'merchantAddress' => ['country' => 'BR', 'state' => 'SP', 'city' => 'Ribeirão Preto', 'district' => 'Centro', 'street' => 'Rua da Loja', 'number' => '10', 'postalCode' => '14000000', 'latitude' => -21.1775, 'longitude' => -47.8103],
        ],
        'customer'    => ['name' => 'Cliente Ficticio', 'id' => 'cliente-2', 'phone' => ['number' => '0800 000 0002', 'localizer' => '33334444', 'localizerExpiration' => '2026-10-05T21:58:00.000Z']],
        'items'       => [['index' => 1, 'name' => 'Pizza grande', 'quantity' => 1, 'options' => []]],
        'payments'    => [
            'prepaid' => 0,
            'pending' => 58.9,
            'methods' => [['value' => 58.9, 'currency' => 'BRL', 'method' => 'CASH', 'prepaid' => false, 'type' => 'OFFLINE', 'cash' => ['changeFor' => 100]]],
        ],
        'total'       => ['additionalFees' => 0, 'subTotal' => 52.9, 'deliveryFee' => 6, 'benefits' => 0, 'orderAmount' => 58.9],
    ];
}

/** Pedido agendado: janela das 20:00 às 20:30 UTC (formato `schedule` da documentação). */
function pedidoAgendado(string $inicio = '2026-10-05T20:00:00.000Z'): array
{
    $pedido                = pedidoEmDinheiroComTroco();
    $pedido['id']          = 'pedido-agendado-1';
    $pedido['displayId']   = '5150';
    $pedido['orderTiming'] = 'SCHEDULED';
    $pedido['schedule']    = ['deliveryDateTimeStart' => $inicio, 'deliveryDateTimeEnd' => '2026-10-05T20:30:00.000Z'];
    unset($pedido['payments']);

    return $pedido;
}
