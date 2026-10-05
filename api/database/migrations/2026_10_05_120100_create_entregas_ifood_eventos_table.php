<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: eventos do polling do iFood (entregas:ifood-polling), gravados antes do ack. O `evento_id`
 * (o `id` do iFood) é único: o iFood reenvia eventos e o insertOrIgnore descarta os repetidos. O ProcessarPedidoIfood
 * marca `processado_em` (e `ignorado`, para código desconhecido ou loja não vinculada); os processados há mais de 7
 * dias são apagados pelo próprio polling, uma vez por dia. O payload é o evento como veio (não traz dados do cliente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_ifood_eventos', function (Blueprint $table) {
            $table->id();
            $table->string('evento_id', 64)->unique();
            $table->string('merchant_id', 64)->index();
            $table->string('pedido_ifood_id', 64)->index();
            $table->string('codigo', 10);
            // createdAt do iFood, em UTC, com milissegundos (a ordem dos eventos sai daqui)
            $table->timestamp('criado_no_ifood', 3)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('processado_em')->nullable()->index();
            $table->boolean('ignorado')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_ifood_eventos');
    }
};
