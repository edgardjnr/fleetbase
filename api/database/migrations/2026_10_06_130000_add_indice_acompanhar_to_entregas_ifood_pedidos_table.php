<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: índice em `created_at` na entregas_ifood_pedidos para o entregas:ifood-acompanhar (a cada
 * 30 s), que lê só os pedidos da janela: despachados nas últimas 24 h (`despachado_em`, já no índice
 * [despachado_em, despachar_em] da etapa 2) ou criados nelas (`created_at`, este índice). Sem ele, o OU percorreria todo
 * o histórico da tabela a cada rodada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entregas_ifood_pedidos', function (Blueprint $table) {
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('entregas_ifood_pedidos', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
