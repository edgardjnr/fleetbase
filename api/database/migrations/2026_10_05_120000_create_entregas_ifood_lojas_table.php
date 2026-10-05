<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: vínculo de cada Loja (Vendor) com uma loja do iFood (merchantId), pelo app distribuído
 * (App\Support\Entregas\Ifood\VinculosIfood). Os tokens ficam cifrados com encrypt() (APP_KEY); merchant_id é nulo
 * depois de desvincular, para o merchant poder ir para outra loja (no MySQL, NULL não conta na chave única).
 * Roda no `php artisan migrate --force` do deploy.sh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_ifood_lojas', function (Blueprint $table) {
            $table->id();
            $table->char('company_uuid', 36)->index();
            $table->char('vendor_uuid', 36)->unique();
            $table->string('merchant_id', 64)->nullable()->unique();
            $table->string('nome_ifood', 190)->nullable();
            // até 8000 caracteres cada (documentação do iFood), cifrados: text
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expira_em')->nullable();
            // vinculada | vinculo_perdido | desvinculada
            $table->string('situacao', 20)->index();
            $table->timestamp('vinculado_em')->nullable();
            $table->timestamp('renovado_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_ifood_lojas');
    }
};
