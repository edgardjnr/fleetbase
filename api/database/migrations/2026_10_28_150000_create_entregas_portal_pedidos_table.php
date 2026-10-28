<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: o que a loja informa ao chamar motoboy pelo portal e que o Fleetbase não guarda (telefone do
 * cliente, cobrança na entrega, observação para o motoboy, complemento e referência), uma linha por pedido. Lida pelo
 * App\Support\Entregas\Portal\PedidosDoPortal. Spec: docs/superpowers/specs/2026-10-28-chamar-motoboy-portal-design.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_portal_pedidos', function (Blueprint $table) {
            $table->id();
            $table->char('company_uuid', 36)->index();
            $table->char('vendor_uuid', 36);
            $table->char('order_uuid', 36)->unique();
            // o Local de entrega (dono = a loja), reaproveitado no próximo pedido do mesmo cliente no mesmo ponto
            $table->char('place_uuid', 36)->nullable();
            // uuid gerado pelo portal ao abrir o painel: a repetição (rede caiu) devolve o mesmo pedido
            $table->char('chave', 36)->charset('ascii')->collation('ascii_bin');
            $table->string('cliente_nome', 120);
            // só dígitos, com DDD (ChamadoDaLoja::telefone)
            $table->string('cliente_telefone', 20);
            $table->string('complemento', 190)->nullable();
            $table->string('referencia', 190)->nullable();
            $table->text('observacoes')->nullable();
            // pago | dinheiro | cartao | pix; valor e troco em centavos (como a entregas_integracao_pedidos)
            $table->string('cobranca_forma', 20);
            $table->unsignedInteger('cobranca_centavos')->default(0);
            $table->unsignedInteger('troco_para_centavos')->nullable();
            $table->timestamps();
            $table->unique(['vendor_uuid', 'chave'], 'entregas_portal_ped_chave_unique');
            $table->index(['vendor_uuid', 'cliente_telefone'], 'entregas_portal_ped_tel_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_portal_pedidos');
    }
};
