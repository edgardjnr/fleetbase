<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: loja pré-paga (App\Support\Entregas\PrePago). A loja pré-paga recarrega a carteira por Pix na
 * conta Asaas da central e as corridas consomem o crédito; a pós-paga segue com as faturas.
 *
 * - entregas_carteiras_lojas: o modelo de cobrança de cada loja (sem linha = pós-pago), o CPF/CNPJ (exigido pelo Asaas
 *   para a cobrança) e o cliente da loja no Asaas da central.
 * - entregas_recargas: cada Pix de recarga pedido no portal (cobrança PIX no Asaas, conferida pela nossa rotina com a
 *   chave da central: sem webhook).
 *
 * O nome tem `_entregas_financeiro_` porque o banco em memória dos testes lê o esquema por glob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_carteiras_lojas', function (Blueprint $table) {
            $table->id();
            $table->char('company_uuid', 36);
            $table->char('loja_uuid', 36);                              // vendors.uuid
            $table->string('modelo', 8)->default('pos');               // pos (fatura) | pre (crédito por Pix)
            $table->string('documento', 14)->nullable();               // só os dígitos do CPF/CNPJ da loja
            $table->string('asaas_cliente_id', 64)->nullable();        // o cliente da loja no Asaas da central
            $table->string('asaas_cliente_wallet', 64)->nullable();    // a wallet da central quando o cliente nasceu
            $table->string('asaas_cliente_documento', 14)->nullable(); // o documento com que o cliente nasceu
            $table->timestamp('modelo_alterado_em')->nullable();
            $table->char('modelo_alterado_por', 36)->nullable();
            $table->timestamps();
            $table->unique(['company_uuid', 'loja_uuid'], 'ent_cart_loja');
        });

        Schema::create('entregas_recargas', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->char('company_uuid', 36);
            $table->char('loja_uuid', 36);
            $table->decimal('valor', 10, 2);
            $table->string('situacao', 12);                       // criando aguardando paga expirada falhou
            $table->string('external_reference', 80);             // recarga:<uuid>
            $table->string('wallet_id', 64);                      // a wallet da conexão quando a cobrança nasceu
            $table->string('cliente_id', 64)->nullable();         // o cliente do Asaas usado na cobrança
            $table->string('payment_id', 64)->nullable()->unique();
            $table->string('asaas_status', 30)->nullable();
            $table->text('pix_copia_cola')->nullable();
            $table->mediumText('pix_imagem')->nullable();         // PNG em base64 (encodedImage do Asaas)
            $table->timestamp('expira_em')->nullable();
            $table->timestamp('paga_em')->nullable();
            $table->char('pagamento_uuid', 36)->nullable();       // entregas_pagamentos.uuid (recebimento pix)
            $table->string('motivo_falha', 255)->nullable();
            $table->timestamp('consultada_em')->nullable();       // última consulta ao Asaas
            $table->timestamp('conferida_depois_em')->nullable(); // a conferência tardia da expirada já rodou
            $table->char('pedida_por', 36)->nullable();
            $table->char('cancelada_por', 36)->nullable();
            $table->timestamps();
            $table->index(['company_uuid', 'situacao'], 'ent_rec_situacao');
            $table->index(['loja_uuid', 'created_at'], 'ent_rec_loja');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_recargas');
        Schema::dropIfExists('entregas_carteiras_lojas');
    }
};
