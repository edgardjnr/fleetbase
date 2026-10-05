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
 *
 * O mapa do cartão (coleta, entrega e a linha da rota) vai em entregas_coleta e entregas_entrega ("lat,lng") e
 * entregas_rota (o traçado do RotaDoPedido, o mesmo OSRM do mapa do pedido, reduzido a MAX_PONTOS_DA_ROTA e codificado
 * como polyline do Google): o push de dados do FCM tem limite de 4 KB.
 */
class CartaoDoAlarme
{
    /** Pontos da linha da rota no push (com o início e o fim): cerca de 500 caracteres codificados. */
    public const MAX_PONTOS_DA_ROTA = 80;

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

        $dados = static::dados(
            $loja ?: $coleta?->name,
            $entrega ? static::destino($entrega->neighborhood, $entrega->city, $entrega->street1) : null,
            $valor['km'] ?? null,
            $valor['valor_motoboy'] ?? null,
            ($valor['fonte'] ?? null) === 'estimativa'
        );

        // a linha da rota sai do cache do RotaDoPedido (OSRM, ou a linha reta quando ele falha)
        $rota = (new RotaDoPedido($calculo))->doPedido($pedido);

        return array_merge($dados, static::mapa(
            static::coordenada($coleta?->location?->getLat(), $coleta?->location?->getLng()),
            static::coordenada($entrega?->location?->getLat(), $entrega?->location?->getLng()),
            static::polyline(static::reduzir($rota['linha'] ?? [], static::MAX_PONTOS_DA_ROTA))
        ));
    }

    /** As chaves do mapa do cartão, só com o que existe. */
    public static function mapa(?string $coleta, ?string $entrega, ?string $rota): array
    {
        return array_filter([
            'entregas_coleta'  => $coleta,
            'entregas_entrega' => $entrega,
            'entregas_rota'    => $rota,
        ], fn ($valor) => $valor !== null && $valor !== '');
    }

    /** "lat,lng" com 6 casas; null sem coordenada ou em 0,0 (lugar sem posição). */
    public static function coordenada($latitude, $longitude): ?string
    {
        if ($latitude === null || $longitude === null || ((float) $latitude === 0.0 && (float) $longitude === 0.0)) {
            return null;
        }

        return sprintf('%.6F,%.6F', (float) $latitude, (float) $longitude);
    }

    /** No máximo $maximo pontos, a intervalos iguais, sempre com o primeiro e o último. */
    public static function reduzir(array $pontos, int $maximo): array
    {
        $total = count($pontos);
        if ($total <= $maximo || $maximo < 2) {
            return array_values($pontos);
        }

        $reduzidos = [];
        for ($i = 0; $i < $maximo - 1; $i++) {
            $reduzidos[] = $pontos[(int) floor($i * ($total - 1) / ($maximo - 1))];
        }
        $reduzidos[] = $pontos[$total - 1];

        return $reduzidos;
    }

    /** Pontos [lat, lng] no formato "encoded polyline" do Google (precisão 5), que o app decodifica. */
    public static function polyline(array $pontos): string
    {
        $texto       = '';
        $latAnterior = 0;
        $lngAnterior = 0;

        foreach ($pontos as [$lat, $lng]) {
            $latAtual = (int) round($lat * 1e5);
            $lngAtual = (int) round($lng * 1e5);
            $texto .= static::numeroDaPolyline($latAtual - $latAnterior) . static::numeroDaPolyline($lngAtual - $lngAnterior);
            [$latAnterior, $lngAnterior] = [$latAtual, $lngAtual];
        }

        return $texto;
    }

    protected static function numeroDaPolyline(int $valor): string
    {
        $valor = $valor < 0 ? ~($valor << 1) : ($valor << 1);
        $texto = '';
        while ($valor >= 0x20) {
            $texto .= chr((0x20 | ($valor & 0x1f)) + 63);
            $valor >>= 5;
        }

        return $texto . chr($valor + 63);
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
