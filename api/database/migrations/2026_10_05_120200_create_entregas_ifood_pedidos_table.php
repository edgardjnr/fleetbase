<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: dados iFood de cada pedido criado pela integração (App\Support\Entregas\Ifood). Fica fora do
 * `meta` do Order de propósito: o `meta` sai na API v1 e no socket, e aqui estão o 0800 com o localizador e a cobrança.
 * `pedido_ifood_id` único = o mesmo pedido do iFood nunca vira dois pedidos. `despachar_em`/`despachado_em` guiam o
 * entregas:ifood-agendados (agendados e despacho que falhou); o pedido de teste não tem `despachar_em`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_ifood_pedidos', function (Blueprint $table) {
            $table->id();
            $table->char('company_uuid', 36)->index();
            $table->char('order_uuid', 36)->nullable()->unique();
            $table->string('pedido_ifood_id', 64)->unique();
            // número curto do iFood (displayId), o mesmo do internal_id do Order
            $table->string('numero', 20)->nullable();
            $table->string('merchant_id', 64)->index();
            $table->char('vendor_uuid', 36)->nullable()->index();
            $table->string('telefone_0800', 30)->nullable();
            $table->string('localizador', 20)->nullable();
            $table->timestamp('telefone_expira_em')->nullable();
            // a cobrar na porta (payments.pending), em centavos; 0 = pago online
            $table->unsignedInteger('cobrar_centavos')->default(0);
            $table->string('forma_pagamento', 30)->nullable();
            $table->unsignedInteger('troco_para_centavos')->nullable();
            $table->text('observacoes')->nullable();
            $table->string('complemento', 190)->nullable();
            $table->string('referencia', 190)->nullable();
            // DDCR recebido: a conclusão exige o código do cliente (etapa 4)
            $table->boolean('exige_codigo')->default(false);
            // última ação de logística aceita pelo iFood (etapa 3)
            $table->string('ultima_acao', 30)->nullable();
            $table->timestamp('cancelado_pelo_ifood_em')->nullable();
            $table->boolean('pago_mesmo_cancelado')->default(false);
            $table->boolean('teste')->default(false);
            $table->boolean('agendado')->default(false);
            $table->timestamp('despachar_em')->nullable()->index();
            $table->timestamp('despachado_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_ifood_pedidos');
    }
};
