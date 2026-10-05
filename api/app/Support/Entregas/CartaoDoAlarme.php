<?php

namespace App\Support\Entregas;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vendor;

/**
 * Entregas RestaurantePro: o que o cartão do alarme de pedido mostra no app (AlarmePedidoActivity), como o aviso da Uber:
 * loja, destino, km (loja → cliente) e valor do motoboy. Vai nos dados do push de alarme (AvisosDoMotoboy), já em texto
 * pt-BR, com as chaves entregas_loja, entregas_destino, entregas_km e entregas_valor; o que faltar não vai.
 *
 * O valor é o mesmo do card de aceitar do app (CalculoEntregas::valorDoPedido, que congela o valor da entrega na primeira
 * vez: ver "Valor congelado por entrega" no CLAUDE.md). Nunca vai o valor cobrado da loja.
 */
class CartaoDoAlarme
{
    /** Os dados do cartão para um pedido. Pode consultar o banco e o OSRM: quem chama trata a falha. */
    public static function doPedido(Order $pedido, CalculoEntregas $calculo): array
    {
        $pedido->loadMissing(['payload.pickup', 'payload.dropoff', 'payload.waypoints']);

        $valor   = $calculo->valorDoPedido($pedido);
        $coleta  = $pedido->payload?->getPickupOrFirstWaypoint();
        $entrega = $pedido->payload?->getDropoffOrLastWaypoint();

        // a loja do pedido é o Vendor dono dele (customer_uuid); sem loja, o nome do local de coleta
        $loja = $pedido->customer_uuid
            ? Vendor::where('company_uuid', $pedido->company_uuid)->where('uuid', $pedido->customer_uuid)->value('name')
            : null;

        return static::dados(
            $loja ?: $coleta?->name,
            $entrega ? static::destino($entrega->neighborhood, $entrega->city, $entrega->street1) : null,
            $valor['km'] ?? null,
            $valor['valor_motoboy'] ?? null,
            ($valor['fonte'] ?? null) === 'estimativa'
        );
    }

    /** As chaves do push, só com o que existe. */
    public static function dados(?string $loja, ?string $destino, $km, $valor, bool $kmAproximado = false): array
    {
        return array_filter([
            'entregas_loja'    => static::texto($loja),
            'entregas_destino' => static::texto($destino),
            'entregas_km'      => static::km($km, $kmAproximado),
            'entregas_valor'   => static::valor($valor),
        ], fn ($valor) => $valor !== null);
    }

    /** "3,2 km", "400 m" abaixo de 1 km, com "≈ " quando é a estimativa em linha reta. */
    public static function km($km, bool $aproximado = false): ?string
    {
        if ($km === null || (float) $km <= 0) {
            return null;
        }

        $km    = (float) $km;
        $texto = $km < 1 ? round($km * 1000) . ' m' : number_format($km, 1, ',', '.') . ' km';

        return ($aproximado ? '≈ ' : '') . $texto;
    }

    /** "R$ 8,00". */
    public static function valor($valor): ?string
    {
        return $valor === null ? null : 'R$ ' . number_format((float) $valor, 2, ',', '.');
    }

    /** "Bairro, Cidade"; sem bairro, "Rua, Cidade". */
    public static function destino(?string $bairro, ?string $cidade, ?string $rua): ?string
    {
        $primeiro = static::texto($bairro) ?? static::texto($rua);

        return static::texto(implode(', ', array_filter([$primeiro, static::texto($cidade)])));
    }

    protected static function texto(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
