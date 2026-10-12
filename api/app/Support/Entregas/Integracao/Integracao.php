<?php

namespace App\Support\Entregas\Integracao;

/**
 * Entregas RestaurantePro: integração de pedidos vindos de outros sistemas (o RestaurantePro primeiro). Constantes, o
 * interruptor geral (ENTREGAS_INTEGRACAO, `services.entregas.integracao`), o link de rastreio do cliente final e o nome
 * do sistema que vai nas notas do pedido. Desenho: docs/superpowers/specs/2026-10-12-integracao-restaurantepro-design.md.
 */
final class Integracao
{
    public const LOJAS   = 'entregas_integracao_lojas';
    public const PEDIDOS = 'entregas_integracao_pedidos';

    public const PREFIXO_DA_CHAVE = 'ent_live_';
    public const TAMANHO_DA_CHAVE = 40;

    public const SISTEMA_PADRAO = 'Integração';

    public static function ligada(): bool
    {
        return filter_var(config('services.entregas.integracao'), FILTER_VALIDATE_BOOLEAN);
    }

    /** A página de rastreio do console, já com o código (`?order=` abre preenchida), ou null sem código. */
    public static function linkDeRastreio(?string $codigo): ?string
    {
        $codigo = trim((string) $codigo);
        if ($codigo === '') {
            return null;
        }
        $base = rtrim((string) config('services.entregas.console_url', 'https://entregas.restaurantepro.com.br'), '/');

        return $base . '/~/track-order?order=' . rawurlencode($codigo);
    }

    /** "RestaurantePro": só letras, números, espaço, ponto, hífen e sublinhado, até 40 caracteres; vazio = "Integração". */
    public static function nomeDoSistema(?string $sistema): string
    {
        $limpo = trim(preg_replace('/[^\p{L}\p{N} ._-]+/u', '', (string) $sistema) ?? '');

        return $limpo === '' ? static::SISTEMA_PADRAO : mb_substr($limpo, 0, 40);
    }
}
