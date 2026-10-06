<?php

namespace App\Support\Entregas\Ifood;

use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: leitura e gravação da entregas_ifood_pedidos pelo uuid do Order, num lugar só (ações de
 * logística, cancelamento, travas, rotas do motoboy e painel do console). Pedido do iFood = Order com linha aqui.
 */
final class PedidosIfood
{
    public const TABELA = 'entregas_ifood_pedidos';

    /** A linha do pedido do iFood deste Order, ou null (não é do iFood). */
    public static function doPedido(?string $orderUuid): ?object
    {
        if ($orderUuid === null || $orderUuid === '') {
            return null;
        }

        return DB::table(static::TABELA)->where('order_uuid', $orderUuid)->first();
    }

    public static function ehDoIfood(?string $orderUuid): bool
    {
        return $orderUuid !== null && $orderUuid !== '' && DB::table(static::TABELA)->where('order_uuid', $orderUuid)->exists();
    }

    /** Grava os valores na linha (com updated_at) e copia para o objeto, para quem chamou seguir com a linha atualizada. */
    public static function atualizar(object $linha, array $valores): void
    {
        $valores['updated_at'] = now()->toDateTimeString();
        DB::table(static::TABELA)->where('id', $linha->id)->update($valores);
        foreach ($valores as $coluna => $valor) {
            $linha->$coluna = $valor;
        }
    }
}
