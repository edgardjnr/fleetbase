<?php

namespace App\Support\Entregas;

use App\Support\Entregas\Ifood\PedidoDoIfood;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Entregas RestaurantePro: o prazo de entrega do pedido, gravado na criação no `meta` (chave CHAVE), em texto ISO com o
 * fuso do app ("2026-10-20T19:57:51-03:00"). O console, o portal da loja e o app do motoboy mostram o cronômetro: verde
 * com mais de 15 min, amarelo com 15 min ou menos, vermelho "Atrasado há N min". Desenho:
 * docs/superpowers/specs/2026-10-20-cronometro-do-prazo-design.md.
 *
 * - iFood imediato: `delivery.deliveryDateTime`, a hora prometida ao cliente (na sonda de 05/10, createdAt + 45 min).
 * - iFood agendado: o fim da janela (`schedule.deliveryDateTimeEnd`); sem o fim, o início + 30 min; sem janela, o padrão.
 * - iFood sem data legível e todos os outros (RestaurantePro, portal da loja, central, API): o padrão, 50 min depois do
 *   max(criação, scheduled_at). O agendado pela central conta do horário agendado.
 *
 * Quem grava: o CriadorDoPedidoIfood (no Order::create, antes do save) e o GravarPrazoDoPedido (Order::creating: o padrão,
 * quando o meta ainda não tem um prazo legível). O prazo é o da criação: ORDER_PATCHED, CHANGE_PREPARATION_TIME e
 * reagendamento não o mudam. Nunca em UTC: a data do iFood (ISO com Z) sai no fuso do app (date_default_timezone_get(),
 * que o Laravel define pelo app.timezone). O prazo não é dado sensível: vai aos motoboys e ao socket.
 */
final class PrazoDaEntrega
{
    /** Chave do prazo no meta do pedido. */
    public const CHAVE = 'entregas_prazo_em';

    /** Prazo padrão: tantos minutos depois do max(criação, scheduled_at). */
    public const MINUTOS_PADRAO = 50;

    /** iFood agendado sem o fim da janela: o início dela mais tantos minutos. */
    public const MINUTOS_DEPOIS_DO_INICIO_DA_JANELA = 30;

    /** Prazo com ano fora deste intervalo é ilegível. */
    public const ANO_MINIMO = 2000;
    public const ANO_MAXIMO = 2037;

    /** O padrão: 50 min depois do max(criação, agendado para). */
    public static function padrao(DateTimeInterface $criacao, ?DateTimeInterface $agendadoPara = null): string
    {
        $base = DateTimeImmutable::createFromInterface($criacao);
        if ($agendadoPara !== null && $agendadoPara > $base) {
            $base = DateTimeImmutable::createFromInterface($agendadoPara);
        }

        return static::formatar($base->modify('+' . static::MINUTOS_PADRAO . ' minutes'));
    }

    /** O prazo do pedido do Logistics do iFood, ou null sem data legível (aí vale o padrão). */
    public static function doIfood(array $pedido): ?string
    {
        $programado = is_string($pedido['orderTiming'] ?? null) && strtoupper(trim($pedido['orderTiming'])) === 'SCHEDULED';
        if ($programado) {
            $janela = is_array($pedido['schedule'] ?? null) ? $pedido['schedule'] : [];
            $fim    = PedidoDoIfood::data($janela['deliveryDateTimeEnd'] ?? null);
            if ($fim !== null) {
                return static::formatar($fim);
            }
            $inicio = PedidoDoIfood::data($janela['deliveryDateTimeStart'] ?? null);

            return $inicio !== null ? static::formatar($inicio->modify('+' . static::MINUTOS_DEPOIS_DO_INICIO_DA_JANELA . ' minutes')) : null;
        }

        $entrega   = is_array($pedido['delivery'] ?? null) ? $pedido['delivery'] : [];
        $prometida = PedidoDoIfood::data($entrega['deliveryDateTime'] ?? null);

        return $prometida !== null ? static::formatar($prometida) : null;
    }

    /** O prazo legível guardado no meta (array do cast, objeto ou o JSON da coluna), ou null. */
    public static function daMeta($meta): ?string
    {
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }
        if (is_object($meta)) {
            $meta = (array) $meta;
        }
        $prazo = is_array($meta) ? ($meta[static::CHAVE] ?? null) : null;

        return static::legivel($prazo) ? trim($prazo) : null;
    }

    /** O prazo do pedido (model do Eloquent ou objeto com `meta`), ou null. Nunca lança. */
    public static function doPedido(object $pedido): ?string
    {
        try {
            return static::daMeta($pedido->meta ?? null);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Texto ISO com data, hora e fuso (Z ou ±hh:mm), ano de ANO_MINIMO a ANO_MAXIMO. */
    public static function legivel($prazo): bool
    {
        if (!is_string($prazo) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', trim($prazo))) {
            return false;
        }

        try {
            $ano = (int) (new DateTimeImmutable(trim($prazo)))->format('Y');
        } catch (\Exception) {
            return false;
        }

        return $ano >= static::ANO_MINIMO && $ano <= static::ANO_MAXIMO;
    }

    /** Formato gravado: exatamente Y-m-d\TH:i:sP, sem microssegundos (o Hermes do app lê sem surpresa). */
    public const FORMATO = 'Y-m-d\TH:i:sP';

    /** ISO 8601 no fuso do app (nunca UTC), sem fração de segundo: "2026-10-20T19:57:51-03:00". */
    public static function formatar(DateTimeInterface $data): string
    {
        return DateTimeImmutable::createFromInterface($data)
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format(static::FORMATO);
    }
}
