<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: multiplicadores por período do Financeiro (chuva, intempérie). A entrega atribuída dentro do
 * período vale a faixa × o fator (App\Support\Entregas\Financeiro\Multiplicadores). Horários em Brasília, como as
 * DATETIME do Fleet-Ops. Roda no `php artisan migrate --force` do deploy.sh, no banco principal e no sandbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_multiplicadores', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->char('company_uuid', 36);
            $table->string('nome', 40);
            $table->decimal('mult_motoboy', 4, 2);       // 1.00 a 5.00
            $table->decimal('mult_loja', 4, 2);          // 1.00 a 5.00 (1.00 = a loja paga o normal)
            $table->dateTime('inicio');                  // Brasília; vale para atribuição em [inicio, fim)
            $table->dateTime('fim');
            $table->char('criado_por', 36)->nullable();  // users.uuid
            $table->char('encerrado_por', 36)->nullable();
            $table->timestamps();
            $table->dateTime('deleted_at')->nullable();  // só o agendado é apagado
            $table->index(['company_uuid', 'inicio'], 'ent_mult_empresa_inicio');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_multiplicadores');
    }
};
