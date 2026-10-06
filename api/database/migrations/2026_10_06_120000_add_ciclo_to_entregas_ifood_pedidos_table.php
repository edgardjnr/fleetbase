<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: colunas do ciclo da entrega iFood (etapa 3; App\Support\Entregas\Ifood\AcoesIfood e
 * ConclusaoIfood). Ficam na entregas_ifood_pedidos, fora do `meta` do Order, como as da etapa 2.
 * - `motoboy_no_ifood`: o Driver do último assignDriver aceito; outro motoboy no pedido = troca (assignDriver de novo);
 * - `recusa_acao`, `recusa_status`, `recusa_em`: a última ação que o iFood recusou (4xx), para o painel do console e para
 *   o entregas:ifood-acompanhar não repetir sozinho uma ação recusada (uma ação aceita depois limpa as três);
 * - `conclusao_liberada_em`: o iFood já sabe que o motoboy chegou e, quando exigido, conferiu o código: a conclusão
 *   comum do app passa pela trava "Atualize o app" (RegrasDoPedidoIfood);
 * - `conclusao_sem_codigo`: o pedido exigia o código e foi concluído sem ele (a central liberou no console, ou concluiu
 *   ela mesma): o iFood fica com a confirmação pendente e conclui sozinho 4 h depois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entregas_ifood_pedidos', function (Blueprint $table) {
            $table->char('motoboy_no_ifood', 36)->nullable();
            $table->string('recusa_acao', 30)->nullable();
            $table->unsignedSmallInteger('recusa_status')->nullable();
            $table->timestamp('recusa_em')->nullable();
            $table->timestamp('conclusao_liberada_em')->nullable();
            $table->boolean('conclusao_sem_codigo')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('entregas_ifood_pedidos', function (Blueprint $table) {
            $table->dropColumn(['motoboy_no_ifood', 'recusa_acao', 'recusa_status', 'recusa_em', 'conclusao_liberada_em', 'conclusao_sem_codigo']);
        });
    }
};
