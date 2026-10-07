<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: distribuição de pedidos abertos (oferta um a um pelo tempo até o cliente; ver
 * App\Support\Entregas\Distribuicao). Uma distribuição por despacho do pedido; uma oferta por motoboy oferecido.
 * O nome do arquivo termina em "_entregas_distribuicao_table.php" porque o banco em memória dos testes
 * (scripts/teste-php/stubs-ifood.php) lê o esquema dos arquivos *_entregas_ifood_*_table.php e *_entregas_distribuicao_*.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_distribuicoes', function (Blueprint $table) {
            $table->id();
            $table->char('pedido_uuid', 36);
            $table->char('company_uuid', 36)->index();
            $table->timestamp('despachada_em');
            // ofertas | aberta | encerrada
            $table->string('fase', 16)->index();
            // aceita | atribuida | cancelada | aberta_pela_central | fila_esgotada | prazo | sem_candidato | redespachada
            $table->string('motivo', 32)->nullable();
            // a última fila calculada: [{motoboy_uuid, public_id, nome, tempo_s, encaixe, aproximado, livre}]
            $table->json('fila')->nullable();
            $table->timestamp('aberta_em')->nullable();
            $table->timestamp('encerrada_em')->nullable();
            $table->timestamps();
            $table->index(['pedido_uuid', 'fase']);
        });

        Schema::create('entregas_ofertas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distribuicao_id')->index();
            $table->char('pedido_uuid', 36);
            $table->char('motoboy_uuid', 36);
            $table->unsignedSmallInteger('posicao');
            $table->unsignedInteger('tempo_estimado_s')->nullable();
            $table->boolean('encaixe')->default(false);
            $table->boolean('aproximado')->default(false);
            $table->timestamp('oferecida_em');
            $table->timestamp('vence_em');
            // pendente | aceita | recusada | vencida | cancelada
            $table->string('resposta', 16);
            $table->timestamp('respondida_em')->nullable();
            $table->timestamps();
            $table->index(['pedido_uuid', 'resposta']);
            $table->index(['motoboy_uuid', 'resposta']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_ofertas');
        Schema::dropIfExists('entregas_distribuicoes');
    }
};
