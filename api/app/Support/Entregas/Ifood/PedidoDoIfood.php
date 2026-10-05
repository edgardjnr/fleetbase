<?php

namespace App\Support\Entregas\Ifood;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Entregas RestaurantePro: função pura que traduz o pedido do módulo Logistics do iFood (GET /logistics/v1.0/orders/{id},
 * campos vistos na sonda de 2026-10-05) nos dados do pedido do Fleetbase e da linha de entregas_ifood_pedidos.
 *
 * - Número: `displayId` (ex.: 4821) vira o internal_id e o "iFood #4821" das notas.
 * - Entrega: coordenadas, rua + número, bairro, complemento e referência (no street2, para o motoboy ler) e o nome do
 *   cliente (nome do Local). Sem geocodificação: as coordenadas do iFood valem.
 * - Pedido de teste (`isTest: true`, entrega em 0,0): entrega na coleta deslocada ~1 km para o norte (o km não fica
 *   absurdo), "[TESTE]" nas notas e sem despacho aos motoboys (só a central atribui). Pedido real sem coordenadas
 *   (0,0) recebe o mesmo deslocamento, mas fica `sem_coordenadas` e também não é despachado: a central confere.
 * - Agendado (`orderTiming = SCHEDULED`): vai aos motoboys 40 min antes do início da janela (`schedule.
 *   deliveryDateTimeStart`; sem ele, `delivery.deliveryDateTime`). A sonda só viu pedidos imediatos: o formato do
 *   `schedule` é o da documentação (a conferir no primeiro agendado). Se faltar menos de 40 min, despacha na hora.
 * - Cobrança: sem `payments` (pedido pago online, visto na sonda) ou `pending` = 0, nada a cobrar; com `pending` > 0,
 *   o valor, a forma (o método não pago) e o troco (`methods[].cash.changeFor`) no formato da documentação (a conferir
 *   na homologação: o gerador de pedidos de teste só cria pedido pago online).
 * - 0800 e localizador do cliente (`customer.phone`) com a expiração do localizador.
 *
 * Datas em texto 'Y-m-d H:i:s', UTC (o fuso do banco).
 */
final class PedidoDoIfood
{
    /** O agendado vai aos motoboys este tanto antes do início da janela de entrega. */
    public const ANTECEDENCIA_AGENDADO_MINUTOS = 40;

    /** Deslocamento da entrega do pedido de teste: ~1 km para o norte. */
    public const DESLOCAMENTO_TESTE_GRAUS = 0.009;

    public static function mapear(array $pedido, float $latitudeColeta, float $longitudeColeta, DateTimeInterface $agora): array
    {
        $utc      = new DateTimeZone('UTC');
        $agora    = DateTimeImmutable::createFromInterface($agora)->setTimezone($utc);
        $numero   = static::texto($pedido['displayId'] ?? null, 20) ?? substr((string) ($pedido['id'] ?? ''), 0, 8);
        $teste    = ($pedido['isTest'] ?? false) === true;
        $entrega  = is_array($pedido['delivery'] ?? null) ? $pedido['delivery'] : [];
        $endereco = is_array($entrega['deliveryAddress'] ?? null) ? $entrega['deliveryAddress'] : [];
        $cliente  = is_array($pedido['customer'] ?? null) ? $pedido['customer'] : [];
        $telefone = is_array($cliente['phone'] ?? null) ? $cliente['phone'] : [];

        $latitude       = (float) ($endereco['coordinates']['latitude'] ?? 0);
        $longitude      = (float) ($endereco['coordinates']['longitude'] ?? 0);
        $semCoordenadas = !$teste && $latitude == 0.0 && $longitude == 0.0;
        if ($teste || $semCoordenadas) {
            $latitude  = $latitudeColeta + static::DESLOCAMENTO_TESTE_GRAUS;
            $longitude = $longitudeColeta;
        }

        $inicio = null;
        if (($pedido['orderTiming'] ?? null) === 'SCHEDULED') {
            $inicio = static::data($pedido['schedule']['deliveryDateTimeStart'] ?? null) ?? static::data($entrega['deliveryDateTime'] ?? null);
        }
        $despacharEm = $inicio ? $inicio->modify('-' . static::ANTECEDENCIA_AGENDADO_MINUTOS . ' minutes') : $agora;
        $agendado    = $despacharEm > $agora;
        if (!$agendado) {
            $despacharEm = $agora;
        }

        [$cobrar, $forma, $troco] = static::cobranca($pedido['payments'] ?? null);

        $complemento = static::texto($endereco['complement'] ?? null, 190);
        $referencia  = static::texto($endereco['reference'] ?? null, 190);
        $rua         = static::texto(trim(trim((string) ($endereco['streetName'] ?? '')) . ', ' . trim((string) ($endereco['streetNumber'] ?? '')), ', '), 190);
        $pais        = strtoupper((string) ($endereco['country'] ?? ''));
        $semDespacho = $teste || $semCoordenadas;

        return [
            'numero'          => $numero,
            'teste'           => $teste,
            'agendado'        => $agendado,
            'sem_coordenadas' => $semCoordenadas,
            'despachar_agora' => !$semDespacho && !$agendado,
            'scheduled_at'    => $agendado ? $despacharEm->format('Y-m-d H:i:s') : null,
            'notas'           => 'iFood #' . $numero . ($teste ? ' [TESTE]' : ''),
            'entrega'         => [
                'nome'         => static::texto($cliente['name'] ?? null, 120) ?? 'Cliente iFood',
                'street1'      => $rua ?? static::texto($endereco['formattedAddress'] ?? null, 190) ?? 'Endereço do iFood',
                'street2'      => static::texto(implode(' · ', array_filter([$complemento, $referencia ? 'Ref.: ' . $referencia : null])), 190),
                'neighborhood' => static::texto($endereco['neighborhood'] ?? null, 120),
                'city'         => static::texto($endereco['city'] ?? null, 120),
                'province'     => static::texto($endereco['state'] ?? null, 60),
                'postal_code'  => static::texto($endereco['postalCode'] ?? null, 20),
                'country'      => preg_match('/^[A-Z]{2}$/', $pais) && $pais !== 'XX' ? $pais : 'BR',
                'latitude'     => $latitude,
                'longitude'    => $longitude,
            ],
            'linha'           => [
                'numero'              => $numero,
                'telefone_0800'       => static::texto($telefone['number'] ?? null, 30),
                'localizador'         => static::texto($telefone['localizer'] ?? null, 20),
                'telefone_expira_em'  => static::data($telefone['localizerExpiration'] ?? null)?->format('Y-m-d H:i:s'),
                'cobrar_centavos'     => $cobrar,
                'forma_pagamento'     => $forma,
                'troco_para_centavos' => $troco,
                'observacoes'         => static::texto($entrega['observations'] ?? null, 1000),
                'complemento'         => $complemento,
                'referencia'          => $referencia,
                'exige_codigo'        => false,
                'teste'               => $teste,
                'agendado'            => $agendado,
                'despachar_em'        => $semDespacho ? null : $despacharEm->format('Y-m-d H:i:s'),
            ],
        ];
    }

    /** [centavos a cobrar, forma (CASH, CREDIT…), troco para (centavos) ou null]. */
    public static function cobranca($pagamentos): array
    {
        if (!is_array($pagamentos)) {
            return [0, null, null];
        }

        $pendente = (int) round(((float) ($pagamentos['pending'] ?? 0)) * 100);
        if ($pendente <= 0) {
            return [0, null, null];
        }

        $metodos = array_values(array_filter((array) ($pagamentos['methods'] ?? []), 'is_array'));
        $naPorta = array_values(array_filter($metodos, fn (array $metodo) => ($metodo['prepaid'] ?? null) === false || ($metodo['type'] ?? null) === 'OFFLINE'));
        $metodo  = $naPorta[0] ?? $metodos[0] ?? [];
        $forma   = static::texto($metodo['method'] ?? null, 30);
        $para    = (float) ($metodo['cash']['changeFor'] ?? 0);

        return [$pendente, $forma, $forma === 'CASH' && $para > 0 ? (int) round($para * 100) : null];
    }

    /** Distância em linha reta, em metros (haversine). */
    public static function metrosEntre(float $latitude1, float $longitude1, float $latitude2, float $longitude2): float
    {
        $dLatitude  = deg2rad($latitude2 - $latitude1);
        $dLongitude = deg2rad($longitude2 - $longitude1);
        $a          = sin($dLatitude / 2) ** 2 + cos(deg2rad($latitude1)) * cos(deg2rad($latitude2)) * sin($dLongitude / 2) ** 2;

        return 2 * 6371000 * asin(min(1, sqrt($a)));
    }

    protected static function texto($valor, int $maximo): ?string
    {
        $texto = is_scalar($valor) ? trim((string) $valor) : '';

        return $texto === '' ? null : mb_substr($texto, 0, $maximo);
    }

    protected static function data($valor): ?DateTimeImmutable
    {
        if (!is_string($valor) || trim($valor) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($valor, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
