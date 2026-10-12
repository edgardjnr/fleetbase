<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: a chave de integração de cada loja (App\Support\Entregas\Integracao). Guarda só o SHA-256 da
 * chave (`chave_hash`, único) e os 4 últimos caracteres; a chave em texto aparece uma vez, na tela Lojas. Uma ativa por
 * loja (`revogada_em` nulo), garantido no código (ChavesDeIntegracao::gerar revoga a anterior na mesma transação).
 * `webhook_url` e `webhook_segredo` (cifrado com encrypt()) vêm do POST v1/entregas/integracao/conexao.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_integracao_lojas', function (Blueprint $table) {
            $table->id();
            $table->char('company_uuid', 36)->index();
            $table->char('vendor_uuid', 36)->index();
            $table->char('chave_hash', 64)->unique();
            $table->string('chave_final', 4);
            // o sistema que conectou ("RestaurantePro"): vai nas notas do pedido e no selo
            $table->string('sistema', 40)->nullable();
            $table->string('webhook_url', 500)->nullable();
            $table->text('webhook_segredo')->nullable();
            $table->timestamp('conectada_em')->nullable();
            $table->timestamp('ultimo_uso_em')->nullable();
            $table->timestamp('ultima_falha_aviso_em')->nullable();
            $table->string('ultima_falha_aviso', 190)->nullable();
            $table->timestamp('revogada_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_integracao_lojas');
    }
};
