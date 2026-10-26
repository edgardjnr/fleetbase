<?php

namespace App\Support\Entregas\Saques;

use App\Support\Entregas\Financeiro\ChavePix;
use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: o saque Pix nas telas do Financeiro que já existiam (plano 2, console). O bloco `saque` do
 * extrato do motoboy (interruptor de bloqueio e carência depois da troca da chave Pix, com o nome de quem trocou) e os
 * motoboys bloqueados (selo na lista). Só com o saque ligado no servidor (ENTREGAS_SAQUE) e a central com a conta Asaas
 * conectada; fora disso, nada (o console esconde o interruptor e o selo). API antes da migration: nada.
 */
final class SaqueNoConsole
{
    /** {bloqueado, liberado_em (ISO, só durante a carência), alterada_por (nome de quem trocou a chave, idem)} ou null. */
    public static function doMotoboy(string $companyUuid, string $driverUuid): ?array
    {
        if (!Saque::ligada()) {
            return null;
        }
        try {
            if (!ConexaoAsaas::daEmpresa($companyUuid)) {
                return null;
            }
            $linha    = Saques::linhaDoMotoboy($companyUuid, $driverUuid);
            $liberado = Saques::liberadoEm($linha);
            $por      = $liberado !== null && ($linha->pix_alterada_por ?? null)
                ? DB::table('users')->where('uuid', (string) $linha->pix_alterada_por)->value('name')
                : null;
        } catch (\Throwable $e) {
            return null;
        }

        return [
            'bloqueado'    => (bool) ($linha->saque_bloqueado ?? false),
            'liberado_em'  => Saque::iso($liberado, $companyUuid),
            'alterada_por' => $por !== null && $por !== '' ? (string) $por : null,
        ];
    }

    /** Os motoboys com o saque bloqueado: [driver_uuid => true]. Desligado ou sem a coluna: []. */
    public static function bloqueados(string $companyUuid): array
    {
        if (!Saque::ligada()) {
            return [];
        }
        try {
            $uuids = DB::table(ChavePix::TABELA)->where('company_uuid', $companyUuid)->where('saque_bloqueado', true)->pluck('driver_uuid')->all();
        } catch (\Throwable $e) {
            return [];
        }

        return array_fill_keys(array_map('strval', $uuids), true);
    }
}
