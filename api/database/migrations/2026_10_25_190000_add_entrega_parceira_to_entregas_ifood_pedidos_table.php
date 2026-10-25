<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: a loja passou o pedido para o entregador do iFood (evento REQUEST_DRIVER_SUCCESS, RDS). O
 * pedido sai do Entregas como no CAN (CancelamentoPeloIfood, que também grava o cancelado_pelo_ifood_em); esta coluna
 * só guarda o motivo, para o push ao motoboy dizer "passou para o entregador do iFood" em vez de "cancelado".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entregas_ifood_pedidos', function (Blueprint $table) {
            $table->timestamp('entrega_parceira_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('entregas_ifood_pedidos', function (Blueprint $table) {
            $table->dropColumn('entrega_parceira_em');
        });
    }
};
