<?php

namespace App\Support\Entregas;

use Fleetbase\FleetOps\Models\Order;

/**
 * Entregas RestaurantePro: a situação do motoboy que vira a cor do capacete no mapa ao vivo do console
 * (MapaController@motoboys → packages/fleetops/addon/utils/entregas-capacete.js) e no mapa de motoboys do portal da loja
 * (MotoboysNoMapaDaLoja → packages/customer-portal/addon/utils/motoboys-no-mapa.js).
 *
 * - entrega (vermelho): tem pedido a caminho do cliente (o motoboy já tocou "a caminho", status enroute);
 * - coleta (amarelo): tem pedido aceito ou atribuído que ainda não saiu da loja (started, dispatched...);
 * - livre (verde): online e sem pedido em andamento;
 * - offline (cinza): fora do ar e sem pedido em andamento.
 * Pedido em andamento vale mais que o "online": o motoboy com entrega na mão continua ocupado mesmo se o app
 * desligar o online. Com mais de um pedido, vale o mais adiantado (entrega antes de coleta).
 */
class SituacaoDoMotoboy
{
    public const LIVRE   = 'livre';
    public const COLETA  = 'coleta';
    public const ENTREGA = 'entrega';
    public const OFFLINE = 'offline';

    /** Status de pedido que já saiu da loja (fluxo transport: created → dispatched → started → enroute → completed). */
    public const STATUS_EM_ENTREGA = ['enroute', 'picked_up', 'dropping_off', 'in_progress'];

    /** Pedido parado há mais que isto não ocupa o motoboy no mapa (pedido de teste ou esquecido sem concluir). */
    public const HORAS_PEDIDO_EM_ANDAMENTO = 12;

    /**
     * Pedidos em andamento de cada motoboy, agrupados pelo uuid dele: não encerrados e atualizados nas últimas
     * HORAS_PEDIDO_EM_ANDAMENTO horas, de qualquer loja da empresa. Consulta única do mapa do console e do portal.
     *
     * @param iterable<string> $motoboyUuids
     *
     * @return array<string, array<int, object>> uuid do motoboy => pedidos (driver_assigned_uuid, status, started, public_id, customer_uuid)
     */
    public static function pedidosEmAndamento(string $companyUuid, iterable $motoboyUuids): array
    {
        $pedidos = Order::where('company_uuid', $companyUuid)
            ->whereIn('driver_assigned_uuid', $motoboyUuids)
            ->whereNotIn('status', StatusDoPedido::ENCERRADOS)
            ->where('updated_at', '>=', now()->subHours(static::HORAS_PEDIDO_EM_ANDAMENTO))
            ->get(['driver_assigned_uuid', 'status', 'started', 'public_id', 'customer_uuid']);

        $porMotoboy = [];
        foreach ($pedidos as $pedido) {
            $porMotoboy[$pedido->driver_assigned_uuid][] = $pedido;
        }

        return $porMotoboy;
    }

    /**
     * @param iterable<string|null> $statusDosPedidos status dos pedidos atribuídos ao motoboy (orders.status)
     */
    public static function classificar(bool $online, iterable $statusDosPedidos): string
    {
        $situacao = $online ? static::LIVRE : static::OFFLINE;

        foreach ($statusDosPedidos as $status) {
            $status = strtolower((string) $status);
            if (in_array($status, StatusDoPedido::ENCERRADOS, true)) {
                continue;
            }
            if (in_array($status, static::STATUS_EM_ENTREGA, true)) {
                return static::ENTREGA;
            }
            $situacao = static::COLETA;
        }

        return $situacao;
    }
}
