<?php

use App\Support\Entregas\ImagensPadrao;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: o avatar_url gravado de motoboys, locais e veículos com as imagens padrão do bucket do
 * Fleetbase passa a apontar para as cópias do console (ver App\Support\Entregas\ImagensPadrao). Os novos já saem certos
 * pelo saving() do AppServiceProvider::imagensPadraoLocais. Em 2026-10-25: 9 motoboys e 2 locais.
 */
return new class extends Migration
{
    private const TABELAS = ['drivers', 'places', 'vehicles'];

    public function up(): void
    {
        foreach (static::TABELAS as $tabela) {
            if (!Schema::hasTable($tabela) || !Schema::hasColumn($tabela, 'avatar_url')) {
                continue;
            }

            DB::table($tabela)
                ->where('avatar_url', 'like', '%amazonaws.com%')
                ->select(['uuid', 'avatar_url'])
                ->orderBy('uuid')
                ->chunk(200, function ($linhas) use ($tabela) {
                    foreach ($linhas as $linha) {
                        $novo = ImagensPadrao::trocar((string) $linha->avatar_url);
                        if ($novo !== $linha->avatar_url) {
                            DB::table($tabela)->where('uuid', $linha->uuid)->update(['avatar_url' => $novo]);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        // sem volta automática: as cópias locais são as mesmas imagens; para voltar ao bucket, troque o prefixo à mão
    }
};
