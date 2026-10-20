<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: os produtos do pedido iFood (`items` do GET logistics/orders/{id}) no formato do app do
 * motoboy (App\Support\Entregas\ItensDoPedido), gravados pelo CriadorDoPedidoIfood na criação. Nula nos pedidos antigos
 * e quando o iFood não manda produto com nome. O ORDER_PATCHED não atualiza (risco aceito na spec
 * 2026-10-19-produtos-do-pedido-servidor-design.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entregas_ifood_pedidos', function (Blueprint $table) {
            $table->json('itens')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('entregas_ifood_pedidos', function (Blueprint $table) {
            $table->dropColumn('itens');
        });
    }
};
