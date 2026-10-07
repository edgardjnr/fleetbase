<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: distribuição em rodadas (ENTREGAS_DISTRIBUICAO_RODADAS; ver App\Support\Entregas\Distribuicao).
 * Na distribuição: a volta e a rodada atuais, o início da volta e quando a lista "Novos pedidos" abriu. Em cada oferta: a
 * volta, a rodada e o raio em que saiu. As respostas novas (dispensada, aceita_pela_lista) cabem na coluna `resposta`
 * (texto). volta_iniciada_em é nulo nas distribuições de antes (vale o despachada_em): ADD COLUMN de um TIMESTAMP NOT
 * NULL numa tabela com linhas falha no MySQL estrito.
 * O nome termina em "_entregas_distribuicao_table.php" para o banco em memória dos testes (stubs-ifood.php) ler o esquema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entregas_distribuicoes', function (Blueprint $table) {
            $table->unsignedInteger('volta')->default(1)->after('fase');
            $table->unsignedTinyInteger('rodada')->default(1)->after('volta');
            $table->timestamp('volta_iniciada_em')->nullable()->after('rodada');
            $table->timestamp('lista_aberta_em')->nullable()->after('volta_iniciada_em');
        });

        Schema::table('entregas_ofertas', function (Blueprint $table) {
            $table->unsignedInteger('volta')->default(1)->after('posicao');
            $table->unsignedTinyInteger('rodada')->default(1)->after('volta');
            $table->unsignedInteger('raio_m')->nullable()->after('rodada');
            $table->index(['distribuicao_id', 'volta']);
        });
    }

    public function down(): void
    {
        Schema::table('entregas_ofertas', function (Blueprint $table) {
            $table->dropIndex(['distribuicao_id', 'volta']);
            $table->dropColumn(['volta', 'rodada', 'raio_m']);
        });

        Schema::table('entregas_distribuicoes', function (Blueprint $table) {
            $table->dropColumn(['volta', 'rodada', 'volta_iniciada_em', 'lista_aberta_em']);
        });
    }
};
