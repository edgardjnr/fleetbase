<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: os dados de origem de cada pedido criado pela integração (App\Support\Entregas\Integracao).
 * Fica fora do `meta` do Order de propósito: o `meta` sai na API v1 e no socket, e aqui estão o telefone do cliente e a
 * cobrança. (vendor_uuid, pedido_externo_id) único = o mesmo pedido da origem nunca vira dois (o reenvio devolve o
 * mesmo). `ultimo_aviso`/`motoboy_avisado` guiam os avisos de volta (AvisosDaIntegracao); `despachado_em`, a
 * reconciliação do despacho (entregas:integracao-acompanhar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_integracao_pedidos', function (Blueprint $table) {
            $table->id();
            $table->char('company_uuid', 36)->index();
            $table->char('vendor_uuid', 36);
            $table->char('order_uuid', 36)->nullable()->unique();
            $table->string('pedido_externo_id', 100);
            $table->string('sistema', 40)->nullable();
            // número curto da origem (o código da comanda), o mesmo do internal_id do Order
            $table->string('numero', 20)->nullable();
            $table->string('cliente_nome', 120)->nullable();
            $table->string('cliente_telefone', 20)->nullable();
            $table->string('complemento', 190)->nullable();
            $table->string('referencia', 190)->nullable();
            $table->text('observacoes')->nullable();
            // dinheiro | cartao | pix | pago; o valor e o troco em centavos
            $table->string('cobranca_forma', 20);
            $table->unsignedInteger('cobranca_centavos')->default(0);
            $table->unsignedInteger('troco_para_centavos')->nullable();
            $table->boolean('sem_localizacao')->default(false);
            $table->timestamp('despachado_em')->nullable();
            // último aviso aceito pelo sistema de origem: aceito | a_caminho | concluido | cancelado
            $table->string('ultimo_aviso', 20)->nullable();
            $table->char('motoboy_avisado', 36)->nullable();
            $table->timestamp('cancelado_em')->nullable();
            // loja (a origem ou o portal) | central | sistema (expirado)
            $table->string('cancelado_por', 20)->nullable();
            $table->boolean('pago_mesmo_cancelado')->default(false);
            $table->timestamps();
            // nome curto: o gerado pelo Laravel teria 64 caracteres, o limite do MySQL
            $table->unique(['vendor_uuid', 'pedido_externo_id'], 'entregas_integ_pedidos_externo_unique');
            // entregas:integracao-acompanhar: só as últimas 24 h
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_integracao_pedidos');
    }
};
