<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: o financeiro (App\Support\Entregas\Financeiro). Desenho:
 * docs/superpowers/specs/2026-10-12-financeiro-design.md.
 *
 * - entregas_lancamentos: o livro. Cada crédito (> 0) ou débito (< 0) numa conta, que é um motoboy (drivers.uuid) ou
 *   uma loja (vendors.uuid). Nada se apaga: erro se corrige com estorno (estorno_de). `chave_unica` impede lançar a
 *   mesma corrida duas vezes (corrida:<conta_tipo>:<order_uuid>) e estornar duas vezes (estorno:<id>).
 * - entregas_pagamentos: pagamento ao motoboy ou recebimento da loja (o lançamento negativo aponta para ele).
 * - entregas_faturas: faturas das lojas (rascunho, emitida, cancelada); os itens são os lançamentos com fatura_uuid.
 * - entregas_cobranca_lojas: ciclo e prazo de cada loja (sem linha = o padrão da empresa, no Setting).
 * - entregas_motoboys_financeiro: a chave Pix do motoboy.
 *
 * Fica fora do `meta` do pedido e do motorista de propósito: o `meta` sai na API v1 e no socket, e aqui estão o valor
 * pago ao motoboy, o cobrado da loja e a chave Pix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_lancamentos', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->char('company_uuid', 36);
            $table->string('conta_tipo', 10);            // motoboy | loja
            $table->char('conta_uuid', 36);              // drivers.uuid | vendors.uuid
            $table->string('tipo', 16);                  // corrida diaria bonus ajuste saldo_inicial pagamento recebimento estorno
            $table->decimal('valor', 10, 2);             // com sinal: crédito > 0, débito < 0
            $table->date('data_referencia');             // dia em Brasília
            $table->char('order_uuid', 36)->nullable();
            $table->char('pagamento_uuid', 36)->nullable();
            $table->char('fatura_uuid', 36)->nullable(); // só conta loja
            $table->unsignedBigInteger('estorno_de')->nullable(); // id do lançamento estornado
            $table->string('motivo', 255)->nullable();            // visível ao motoboy / à loja
            $table->string('observacao_interna', 500)->nullable(); // nunca sai para app/portal
            $table->json('detalhes')->nullable();        // corrida: {pedido, numero, km, faixa:{de_km,ate_km,acima}, loja_nome, destino, cancelado_pago, cancelado_origem}
            $table->string('chave_unica', 80)->nullable()->unique(); // corrida:<conta_tipo>:<order_uuid> | estorno:<id>
            $table->char('criado_por', 36)->nullable();  // users.uuid; null = sistema
            $table->timestamps();
            $table->index(['company_uuid', 'conta_tipo', 'conta_uuid', 'data_referencia'], 'ent_lanc_conta_data');
            $table->index('fatura_uuid');
            $table->index('order_uuid');
        });

        Schema::create('entregas_pagamentos', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->char('company_uuid', 36)->index();
            $table->string('conta_tipo', 10);
            $table->char('conta_uuid', 36);
            $table->decimal('valor', 10, 2);             // positivo
            $table->string('forma', 16);                 // pix dinheiro transferencia outro
            $table->date('data');
            $table->string('comprovante_path', 255)->nullable(); // disco 'local' (privado)
            $table->string('comprovante_nome', 255)->nullable();
            $table->string('comprovante_tipo', 100)->nullable(); // mime
            $table->string('observacao_interna', 500)->nullable();
            $table->timestamp('estornado_em')->nullable();
            $table->char('estornado_por', 36)->nullable();
            $table->char('criado_por', 36)->nullable();
            $table->timestamps();
            $table->index(['company_uuid', 'conta_tipo', 'conta_uuid'], 'ent_pag_conta');
        });

        Schema::create('entregas_faturas', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->char('company_uuid', 36);
            $table->char('vendor_uuid', 36);
            $table->unsignedInteger('numero')->nullable(); // sequencial por company, na emissão; exibido FAT-0001
            $table->date('periodo_inicio');
            $table->date('periodo_fim');
            $table->date('vencimento');
            $table->string('situacao', 12);              // rascunho emitida cancelada
            $table->decimal('total', 10, 2)->nullable(); // gravado na emissão
            $table->timestamp('emitida_em')->nullable();
            $table->char('emitida_por', 36)->nullable();
            $table->timestamp('cancelada_em')->nullable();
            $table->char('cancelada_por', 36)->nullable();
            $table->timestamp('email_enviado_em')->nullable();
            $table->timestamp('lembrete_enviado_em')->nullable();
            $table->timestamp('aviso_vencida_enviado_em')->nullable();
            $table->timestamps();
            $table->index(['company_uuid', 'situacao'], 'ent_fat_situacao');
            $table->index(['vendor_uuid', 'periodo_fim'], 'ent_fat_loja_fim');
            $table->unique(['company_uuid', 'numero'], 'ent_fat_numero');
        });

        Schema::create('entregas_cobranca_lojas', function (Blueprint $table) {
            $table->id();
            $table->char('company_uuid', 36)->index();
            $table->char('vendor_uuid', 36)->unique();
            $table->string('ciclo', 10);                 // semanal quinzenal mensal
            $table->unsignedTinyInteger('dia_fechamento'); // semanal: 0=dom..6=sáb; mensal: 1..28 ou 0=último dia; quinzenal: ignorado (0)
            $table->unsignedSmallInteger('prazo_dias');
            $table->timestamps();
        });

        Schema::create('entregas_motoboys_financeiro', function (Blueprint $table) {
            $table->id();
            $table->char('company_uuid', 36)->index();
            $table->char('driver_uuid', 36)->unique();
            $table->string('chave_pix', 140)->nullable();
            $table->string('tipo_chave', 12)->nullable(); // cpf cnpj telefone email aleatoria
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_motoboys_financeiro');
        Schema::dropIfExists('entregas_cobranca_lojas');
        Schema::dropIfExists('entregas_faturas');
        Schema::dropIfExists('entregas_pagamentos');
        Schema::dropIfExists('entregas_lancamentos');
    }
};
