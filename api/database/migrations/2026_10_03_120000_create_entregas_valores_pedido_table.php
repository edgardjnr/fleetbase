<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: valor congelado de cada entrega (App\Support\Entregas\CalculoEntregas e ValoresCongelados).
 * Fica numa tabela própria, e não no `meta` do pedido, porque o `meta` sai na API v1 e no socket: aqui estão o valor
 * pago ao motoboy e o cobrado da loja. Roda no `php artisan migrate --force` do deploy.sh, no banco principal e no sandbox
 * (o sandbox:migrate dos pacotes não roda esta pasta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_valores_pedido', function (Blueprint $table) {
            $table->id();
            $table->char('company_uuid', 36)->index();
            $table->char('order_uuid', 36)->unique();
            // chave das coordenadas da rota (a mesma do meta.entregas.km_rota do pedido)
            $table->string('chave', 32)->nullable();
            // km usado no cálculo, em metros, e de onde veio (osrm ou estimativa)
            $table->unsignedInteger('metros');
            $table->string('fonte', 20);
            // faixa e valores da tabela vigente quando a entrega foi calculada
            $table->decimal('de_km', 8, 2);
            $table->decimal('ate_km', 8, 2);
            $table->boolean('acima')->default(false);
            $table->decimal('valor_motoboy', 10, 2);
            $table->decimal('valor_loja', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_valores_pedido');
    }
};
