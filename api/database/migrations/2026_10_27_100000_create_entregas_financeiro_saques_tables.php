<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: saque Pix do motoboy pelo app, pela conta Asaas da central (App\Support\Entregas\Saques).
 * Desenho: docs/superpowers/specs/2026-10-26-saque-pix-asaas-design.md.
 *
 * - entregas_asaas_conexoes: a conta Asaas da central (uma por empresa). A chave de API fica cifrada (Cofre, com a chave
 *   do Docker secret, fora do banco); do token da validação de saque, só o hash.
 * - entregas_saques: cada saque pedido no app (o uuid vem do app e é a chave de idempotência).
 * - entregas_motoboys_financeiro: + bloqueio do saque e quem/quando trocou a chave Pix (carência de 24 h).
 *
 * O nome tem `_entregas_financeiro_` porque o banco em memória dos testes lê o esquema por glob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_asaas_conexoes', function (Blueprint $table) {
            $table->id();
            $table->char('company_uuid', 36)->unique();
            $table->text('chave_cifrada');                        // Cofre::cifrar: v1:base64(iv + tag + cifra)
            $table->string('conta_nome', 255)->nullable();
            $table->string('conta_documento', 20)->nullable();    // só os dígitos do CPF/CNPJ da conta
            $table->string('wallet_id', 64);                      // fixada ao conectar; conferida antes de cada saque
            $table->char('conectada_por', 36)->nullable();
            $table->timestamp('conectada_em')->nullable();
            $table->char('token_hash', 64)->nullable()->unique(); // sha256 do token da validação de saque
            $table->timestamp('token_gerado_em')->nullable();
            $table->timestamp('validacao_confirmada_em')->nullable();
            $table->string('teste_transfer_id', 64)->nullable();
            $table->timestamp('teste_pedido_em')->nullable();
            $table->char('teste_pedido_por', 36)->nullable();
            $table->unsignedTinyInteger('testes_no_dia')->default(0); // testes da validação pedidos em testes_dia (máx. 5)
            $table->date('testes_dia')->nullable();                   // dia de Brasília (Financeiro::hoje)
            $table->boolean('saque_ligado')->default(false);
            $table->decimal('minimo', 10, 2)->default(10);
            $table->decimal('maximo_dia', 10, 2)->default(500);
            $table->unsignedTinyInteger('saques_dia')->default(3);
            $table->decimal('taxa', 10, 2)->default(0);
            $table->char('atualizada_por', 36)->nullable();
            $table->timestamps();
        });

        Schema::create('entregas_saques', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();                  // gerado no app: o mesmo pedido repetido não cria outro
            $table->char('company_uuid', 36);
            $table->char('driver_uuid', 36);
            $table->decimal('valor', 10, 2);                     // o que cai no Pix
            $table->decimal('taxa', 10, 2)->default(0);           // debitada do saldo à parte
            $table->string('chave_pix', 140);                    // copiada do cadastro no instante do pedido
            $table->string('tipo_chave', 12);
            $table->string('situacao', 12);                      // enviando processando concluido falhou
            $table->string('motivo_falha', 255)->nullable();
            $table->string('transfer_id', 64)->nullable()->unique();
            $table->string('external_reference', 80);            // saque:<uuid>
            $table->string('asaas_status', 30)->nullable();
            $table->char('pagamento_uuid', 36)->nullable();       // entregas_pagamentos.uuid (forma pix)
            $table->unsignedBigInteger('taxa_lancamento_id')->nullable();
            $table->string('comprovante_url', 500)->nullable();  // transactionReceiptUrl do Asaas
            $table->timestamp('concluido_em')->nullable();
            $table->timestamps();
            $table->index(['company_uuid', 'situacao'], 'ent_saq_situacao');
            $table->index(['driver_uuid', 'created_at'], 'ent_saq_motoboy');
            $table->index('pagamento_uuid');
        });

        Schema::table('entregas_motoboys_financeiro', function (Blueprint $table) {
            $table->boolean('saque_bloqueado')->default(false);
            $table->timestamp('pix_alterada_em')->nullable();
            $table->char('pix_alterada_por', 36)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('entregas_motoboys_financeiro', function (Blueprint $table) {
            $table->dropColumn(['saque_bloqueado', 'pix_alterada_em', 'pix_alterada_por']);
        });
        Schema::dropIfExists('entregas_saques');
        Schema::dropIfExists('entregas_asaas_conexoes');
    }
};
