<?php

namespace App\Support\Entregas;

use App\Support\Entregas\Ifood\CobrancaIfood;
use App\Support\Entregas\Ifood\PedidosIfood;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vendor;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: o que o cartão do alarme de pedido mostra no app (AlarmePedidoActivity), como o aviso da Uber:
 * loja, destino, km (loja → cliente) e valor do motoboy. Vai nos dados do push de alarme (AvisosDoMotoboy), já em texto
 * pt-BR, com as chaves entregas_loja, entregas_destino, entregas_km, entregas_tempo (de moto, pelo OSRM) e entregas_valor;
 * o que faltar não vai.
 *
 * O valor é o mesmo do card de aceitar do app (CalculoEntregas::valorDoPedido, que congela o valor da entrega na primeira
 * vez: ver "Valor congelado por entrega" no CLAUDE.md). Nunca vai o valor cobrado da loja.
 *
 * O mapa do cartão (coleta, entrega e a linha da rota) vai em entregas_coleta e entregas_entrega ("lat,lng") e
 * entregas_rota (o traçado do RotaDoPedido, o mesmo OSRM do mapa do pedido, reduzido a MAX_PONTOS_DA_ROTA e codificado
 * como polyline do Google): o push de dados do FCM tem limite de 4 KB.
 *
 * Pedido iFood (etapa 3): entregas_ifood (o número) e entregas_cobrar (o texto da cobrança na porta, CobrancaIfood).
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

        $tempo = static::tempo(empty($rota['aproximado']) ? ($rota['segundos'] ?? null) : null);

        return array_merge($dados, $tempo ? ['entregas_tempo' => $tempo] : [], static::mapa(
            static::coordenada($coleta?->location?->getLat(), $coleta?->location?->getLng()),
            static::coordenada($entrega?->location?->getLat(), $entrega?->location?->getLng()),
            static::polyline(static::reduzir($rota['linha'] ?? [], static::MAX_PONTOS_DA_ROTA)),
            $pedido->status,
            !empty($rota['aproximado'])
        ), static::ifoodDoPedido($pedido));
    }

    /**
     * Pedido iFood: entregas_ifood (o número, "4821") e entregas_cobrar ("Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100",
     * só com valor a cobrar na porta). O cartão do APK da etapa 4 mostra os dois em destaque; o APK antigo ignora.
     */
    public static function ifood(?object $linha): array
    {
        if (!$linha) {
            return [];
        }

        return array_filter([
            'entregas_ifood'  => isset($linha->numero) && $linha->numero !== '' ? (string) $linha->numero : null,
            'entregas_cobrar' => CobrancaIfood::texto((int) ($linha->cobrar_centavos ?? 0), $linha->forma_pagamento ?? null, isset($linha->troco_para_centavos) ? (int) $linha->troco_para_centavos : null),
        ], fn ($valor) => $valor !== null);
    }

    /** Os dados do iFood do pedido; uma falha na consulta não tira o resto do cartão. */
    protected static function ifoodDoPedido(Order $pedido): array
    {
        try {
            return static::ifood(PedidosIfood::doPedido((string) $pedido->uuid));
        } catch (\Throwable $e) {
            Log::warning('[entregas] alarme sem os dados do iFood', ['pedido' => $pedido->public_id, 'erro' => get_class($e)]);

            return [];
        }
    }

    /**
     * As chaves do mapa do cartão, só com o que existe. O status dá a cor da linha (a do mapa do pedido no app e no
     * console) e entregas_rota_aproximada = "1" deixa a linha tracejada, como a linha reta do mapa do pedido.
     */
    public static function mapa(?string $coleta, ?string $entrega, ?string $rota, ?string $status = null, bool $aproximada = false): array
    {
        return array_filter([
            'entregas_coleta'          => $coleta,
            'entregas_entrega'         => $entrega,
            'entregas_rota'            => $rota,
            'entregas_status'          => $status,
            'entregas_rota_aproximada' => $aproximada && $rota ? '1' : null,
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

    /**
     * "3,2 km", "400 m" abaixo de 1 km, "menos de 100 m" (coleta e entrega quase no mesmo lugar), com "≈ " quando é a
     * estimativa em linha reta.
     */
    public static function km($km, bool $aproximado = false): ?string
    {
        if ($km === null || (float) $km < 0) {
            return null;
        }

        $km    = (float) $km;
        $texto = match (true) {
            $km < 0.1 => 'menos de 100 m',
            $km < 1   => round($km * 1000) . ' m',
            default   => number_format($km, 1, ',', '.') . ' km',
        };

        return ($aproximado ? '≈ ' : '') . $texto;
    }

    /** O tempo da rota de moto, como o resumo do mapa do pedido: "9 min", "1 h 05"; null sem o tempo do OSRM. */
    public static function tempo($segundos): ?string
    {
        if ($segundos === null || (float) $segundos <= 0) {
            return null;
        }

        $minutos = max(1, (int) round((float) $segundos / 60));
        if ($minutos < 60) {
            return $minutos . ' min';
        }

        $resto = $minutos % 60;

        return intdiv($minutos, 60) . ' h' . ($resto ? ' ' . str_pad((string) $resto, 2, '0', STR_PAD_LEFT) : '');
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
