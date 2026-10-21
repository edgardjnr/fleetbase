<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: entrega junto e multiplicador no valor congelado (CalculoEntregas, ValoresCongelados,
 * ValorDaEntrega). Parte do km: a faixa e os valores da segunda tabela ("a partir da 2ª entrega junto"), congelados
 * com a tabela normal. Parte da atribuição: motoboy, instante, posição e o multiplicador do período (cópias). Todas
 * nulas: as linhas antigas valem como antes.
 */
return new class extends Migration
{
    public const COLUNAS = [
        'junto_de_km', 'junto_ate_km', 'junto_acima', 'junto_motoboy', 'junto_loja',
        'motoboy_uuid', 'atribuido_em', 'posicao', 'multiplicador_uuid', 'multiplicador_nome', 'mult_motoboy', 'mult_loja',
    ];

    public function up(): void
    {
        Schema::table('entregas_valores_pedido', function (Blueprint $table) {
            $table->decimal('junto_de_km', 8, 2)->nullable();
            $table->decimal('junto_ate_km', 8, 2)->nullable();
            $table->boolean('junto_acima')->nullable();
            $table->decimal('junto_motoboy', 10, 2)->nullable();
            $table->decimal('junto_loja', 10, 2)->nullable();
            $table->char('motoboy_uuid', 36)->nullable();
            $table->dateTime('atribuido_em')->nullable();   // Brasília
            $table->unsignedTinyInteger('posicao')->nullable();
            $table->char('multiplicador_uuid', 36)->nullable();
            $table->string('multiplicador_nome', 40)->nullable();
            $table->decimal('mult_motoboy', 4, 2)->nullable();
            $table->decimal('mult_loja', 4, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('entregas_valores_pedido', function (Blueprint $table) {
            $table->dropColumn(self::COLUNAS);
        });
    }
};
