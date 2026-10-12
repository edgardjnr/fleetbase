<?php

namespace App\Support\Entregas\Integracao;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: leitura e gravação da entregas_integracao_pedidos num lugar só. Pedido da integração = Order
 * com linha aqui. A loja de um pedido é o vendor_uuid da linha (sobrevive à troca da chave: a chave nova acha os
 * pedidos feitos com a anterior).
 */
final class PedidosDaIntegracao
{
    public static function doPedido(?string $orderUuid): ?object
    {
        if ($orderUuid === null || $orderUuid === '') {
            return null;
        }

        return DB::table(Integracao::PEDIDOS)->where('order_uuid', $orderUuid)->first();
    }

    public static function ehDaIntegracao(?string $orderUuid): bool
    {
        return $orderUuid !== null && $orderUuid !== '' && DB::table(Integracao::PEDIDOS)->where('order_uuid', $orderUuid)->exists();
    }

    /** O pedido da loja pelo id da origem (pedido_externo_id) ou pelo public_id do Order (order_…); null se não é dela. */
    public static function daLoja(object $loja, string $id): ?object
    {
        $id = trim($id);
        // A mesma regra do pedido_externo_id: id fora do padrão nem chega à coluna ascii_bin (collation).
        if (!preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $id)) {
            return null;
        }

        $porExterno = DB::table(Integracao::PEDIDOS)
            ->where('company_uuid', $loja->company_uuid)
            ->where('vendor_uuid', $loja->vendor_uuid)
            ->where('pedido_externo_id', $id)
            ->first();
        if ($porExterno || !str_starts_with($id, 'order_')) {
            return $porExterno;
        }

        $pedido = Order::where('company_uuid', $loja->company_uuid)->where('public_id', $id)->first();

        return $pedido
            ? DB::table(Integracao::PEDIDOS)->where('order_uuid', $pedido->uuid)->where('vendor_uuid', $loja->vendor_uuid)->first()
            : null;
    }

    /** Grava os valores na linha (com updated_at) e copia para o objeto de quem chamou. */
    public static function atualizar(object $linha, array $valores): void
    {
        $valores['updated_at'] = now()->toDateTimeString();
        DB::table(Integracao::PEDIDOS)->where('id', $linha->id)->update($valores);
        foreach ($valores as $coluna => $valor) {
            $linha->$coluna = $valor;
        }
    }
}
