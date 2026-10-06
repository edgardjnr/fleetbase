<?php

namespace App\Support\Entregas\Ifood;

/**
 * Entregas RestaurantePro: o texto da cobrança na porta de um pedido iFood, para o motoboy (alarme, card de aceitar e
 * detalhes do pedido no app): "Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100". Função pura sobre as colunas da
 * entregas_ifood_pedidos (cobrar_centavos, forma_pagamento, troco_para_centavos), gravadas pelo PedidoDoIfood.
 *
 * - Nada a cobrar (0, pago online) = null: o app não mostra a faixa.
 * - A forma vem como o iFood manda, em maiúsculas (CASH, CREDIT, DEBIT…), duas formas juntas com "+" ou "MISTO"
 *   (PedidoDoIfood::cobranca). Forma desconhecida sai em minúsculas, como veio.
 * - O troco só aparece com dinheiro entre as formas e quando é maior que o valor a cobrar (troco para R$ 50 num pedido
 *   de R$ 58,90 não faz sentido: o cliente pagaria a diferença de outro jeito).
 */
final class CobrancaIfood
{
    /** Formas de pagamento do iFood em pt-BR (o que não estiver aqui sai em minúsculas). */
    public const FORMAS = [
        'CASH'           => 'dinheiro',
        'CREDIT'         => 'cartão de crédito',
        'DEBIT'          => 'cartão de débito',
        'MEAL_VOUCHER'   => 'vale-refeição',
        'FOOD_VOUCHER'   => 'vale-alimentação',
        'PIX'            => 'Pix',
        'DIGITAL_WALLET' => 'carteira digital',
        'MISTO'          => 'formas variadas',
    ];

    /** "Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100", ou null quando não há nada a cobrar na porta. */
    public static function texto(int $centavos, ?string $forma, ?int $trocoParaCentavos): ?string
    {
        if ($centavos <= 0) {
            return null;
        }

        $partes = ['Cobrar ' . static::reais($centavos)];

        $formaLegivel = static::forma($forma);
        if ($formaLegivel !== null) {
            $partes[] = $formaLegivel;
        }

        if ($trocoParaCentavos !== null && $trocoParaCentavos > $centavos && static::temDinheiro($forma)) {
            $partes[] = 'troco p/ ' . static::reais($trocoParaCentavos, true);
        }

        return implode(' · ', $partes);
    }

    /** A forma em pt-BR ("CASH+CREDIT" → "dinheiro + cartão de crédito"), ou null sem forma. */
    public static function forma(?string $forma): ?string
    {
        $forma = $forma === null ? '' : strtoupper(trim($forma));
        if ($forma === '') {
            return null;
        }

        $nomes = array_map(
            fn (string $parte) => static::FORMAS[$parte] ?? strtolower($parte),
            array_values(array_filter(array_map('trim', explode('+', $forma)), fn (string $parte) => $parte !== ''))
        );

        return $nomes ? implode(' + ', $nomes) : null;
    }

    /** "R$ 58,90"; com $semCentavosSeInteiro, "R$ 100" em vez de "R$ 100,00". */
    public static function reais(int $centavos, bool $semCentavosSeInteiro = false): string
    {
        if ($semCentavosSeInteiro && $centavos % 100 === 0) {
            return 'R$ ' . number_format(intdiv($centavos, 100), 0, ',', '.');
        }

        return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
    }

    protected static function temDinheiro(?string $forma): bool
    {
        return in_array('CASH', array_map('trim', explode('+', strtoupper((string) $forma))), true);
    }
}
