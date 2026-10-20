<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: os produtos do pedido de outro sistema (campo opcional `itens` do POST
 * v1/entregas/integracao/pedidos) no formato do app do motoboy (App\Support\Entregas\ItensDoPedido), gravados pelo
 * CriadorDoPedidoDaIntegracao. Nula nos pedidos antigos e quando a origem não manda itens válidos. O reenvio do mesmo
 * pedido_externo_id não atualiza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entregas_integracao_pedidos', function (Blueprint $table) {
            $table->json('itens')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('entregas_integracao_pedidos', function (Blueprint $table) {
            $table->dropColumn('itens');
        });
    }
};
