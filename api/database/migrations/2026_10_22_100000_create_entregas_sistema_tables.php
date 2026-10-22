<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: corridas do sistema (relatório do dono, todas as organizações). Desenho:
 * docs/superpowers/specs/2026-10-22-corridas-do-sistema-design.md.
 *
 * - entregas_corridas_finalizadas: uma linha por pedido finalizado (order_uuid único), de qualquer organização. Sem
 *   updated_at e sem deleted_at: o código nunca atualiza nem apaga (cancelar, excluir o pedido ou o
 *   deploy/zerar-pedidos não tiram). Nomes da loja e do motoboy gravados na hora; finalizada_em em Brasília.
 * - entregas_precos_centrais: o preço por corrida de cada central (organização), um por data "vale a partir de".
 *
 * O nome tem `_entregas_sistema_` porque o banco em memória dos testes lê o esquema por glob. Roda no
 * `php artisan migrate --force` do deploy.sh, no banco principal e no sandbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_corridas_finalizadas', function (Blueprint $table) {
            $table->id();
            $table->char('order_uuid', 36)->unique();
            $table->char('company_uuid', 36);           // a central (organização dona do pedido)
            $table->string('pedido', 64);               // orders.public_id
            $table->string('numero', 191)->nullable();  // orders.internal_id (número da loja / iFood)
            $table->char('loja_uuid', 36)->nullable();  // vendors.uuid quando o cliente do pedido é uma loja
            $table->string('loja_nome', 191)->nullable();
            $table->char('motoboy_uuid', 36)->nullable(); // drivers.uuid (driver_assigned_uuid na conclusão)
            $table->string('motoboy_nome', 191)->nullable();
            $table->dateTime('finalizada_em')->index(); // Brasília
            $table->string('origem', 16);               // conclusao | conferencia | historico
            $table->timestamp('created_at')->nullable();
            $table->index(['company_uuid', 'finalizada_em'], 'ent_corr_fin_empresa_data');
        });

        Schema::create('entregas_precos_centrais', function (Blueprint $table) {
            $table->id();
            $table->char('company_uuid', 36);
            $table->decimal('valor', 10, 2);           // R$ por corrida, de 0,00 a 1000,00
            $table->date('vale_a_partir_de');          // dia em Brasília
            $table->char('criado_por', 36)->nullable(); // users.uuid
            $table->timestamp('created_at')->nullable();
            $table->unique(['company_uuid', 'vale_a_partir_de'], 'ent_precos_centrais_empresa_data');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_precos_centrais');
        Schema::dropIfExists('entregas_corridas_finalizadas');
    }
};
