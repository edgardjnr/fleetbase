<?php

namespace App\Support\Entregas\Integracao;

use App\Support\Entregas\Ifood\CobrancaIfood;

/**
 * Entregas RestaurantePro: o texto da cobrança na porta de um pedido de outro sistema, no mesmo formato da faixa amarela
 * do iFood no app ("Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100"). Função pura sobre as colunas da
 * entregas_integracao_pedidos (cobranca_forma, cobranca_centavos, troco_para_centavos). Pago = null (sem faixa).
 */
final class CobrancaDaIntegracao
{
    public const FORMAS = ['dinheiro' => 'dinheiro', 'cartao' => 'cartão (maquininha)', 'pix' => 'Pix'];

    public static function texto(?string $forma, int $centavos, ?int $trocoParaCentavos): ?string
    {
        if ($forma === 'pago' || $centavos <= 0) {
            return null;
        }

        $partes = ['Cobrar ' . CobrancaIfood::reais($centavos)];
        if (isset(static::FORMAS[$forma])) {
            $partes[] = static::FORMAS[$forma];
        }
        if ($forma === 'dinheiro' && $trocoParaCentavos !== null && $trocoParaCentavos > $centavos) {
            $partes[] = 'troco p/ ' . CobrancaIfood::reais($trocoParaCentavos, true);
        }

        return implode(' · ', $partes);
    }

    /** A forma em pt-BR ("cartão (maquininha)"), "pago" ou null. */
    public static function forma(?string $forma): ?string
    {
        return $forma === 'pago' ? 'pago' : (static::FORMAS[$forma] ?? null);
    }
}
