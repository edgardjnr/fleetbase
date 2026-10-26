<?php

namespace App\Support\Entregas\Saques;

use App\Support\Entregas\Financeiro\ChavePix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: o saque Pix do motoboy (entregas_saques) e o que ele exige da linha do motoboy no Financeiro
 * (entregas_motoboys_financeiro: chave Pix, bloqueio, troca da chave). Desenho:
 * docs/superpowers/specs/2026-10-26-saque-pix-asaas-design.md.
 */
final class Saques
{
    /** A linha do motoboy no Financeiro (chave Pix, bloqueio, troca); com $travar, lockForUpdate (dentro de transação). */
    public static function linhaDoMotoboy(string $companyUuid, string $driverUuid, bool $travar = false): ?object
    {
        $consulta = DB::table(ChavePix::TABELA)->where('company_uuid', $companyUuid)->where('driver_uuid', $driverUuid);
        if ($travar) {
            $consulta->lockForUpdate();
        }

        return $consulta->first();
    }

    /** Bloqueia ou libera o saque do motoboy (cria a linha sem chave Pix, se não houver). */
    public static function bloquear(string $companyUuid, string $driverUuid, bool $bloqueado): void
    {
        $agora = now()->format('Y-m-d H:i:s');
        if (static::linhaDoMotoboy($companyUuid, $driverUuid)) {
            DB::table(ChavePix::TABELA)->where('company_uuid', $companyUuid)->where('driver_uuid', $driverUuid)->update(['saque_bloqueado' => $bloqueado, 'updated_at' => $agora]);
        } else {
            DB::table(ChavePix::TABELA)->insert(['company_uuid' => $companyUuid, 'driver_uuid' => $driverUuid, 'saque_bloqueado' => $bloqueado, 'created_at' => $agora, 'updated_at' => $agora]);
        }
        Log::info('[entregas] saque: bloqueio do motoboy', ['empresa' => $companyUuid, 'motoboy' => $driverUuid, 'bloqueado' => $bloqueado]);
    }

    /** Até quando ('Y-m-d H:i:s') o saque fica bloqueado pela troca da chave Pix; null se não há carência agora. */
    public static function liberadoEm(?object $linha): ?string
    {
        $alterada = $linha->pix_alterada_em ?? null;
        if (!$alterada) {
            return null;
        }
        $liberado = (new \DateTimeImmutable((string) $alterada))->modify('+' . Saque::CARENCIA_PIX_HORAS . ' hours')->format('Y-m-d H:i:s');

        return $liberado > now()->format('Y-m-d H:i:s') ? $liberado : null;
    }
}
