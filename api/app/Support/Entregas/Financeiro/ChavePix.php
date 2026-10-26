<?php

namespace App\Support\Entregas\Financeiro;

use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: a chave Pix do motoboy (entregas_motoboys_financeiro), cadastrada pelo admin no extrato do
 * console. O motoboy vê no app (conta.pix do extrato), não edita. O saque Pix pelo app usa esta chave
 * (App\Support\Entregas\Saques); a troca bloqueia o saque por 24 h.
 */
final class ChavePix
{
    public const TABELA = 'entregas_motoboys_financeiro';

    public const TAMANHO_MAXIMO = 140;

    /** {chave, tipo} do motoboy, ou null sem chave. */
    public static function doMotoboy(string $companyUuid, string $driverUuid): ?array
    {
        $linha = DB::table(static::TABELA)->where('company_uuid', $companyUuid)->where('driver_uuid', $driverUuid)->first();
        if (!$linha || $linha->chave_pix === null || $linha->chave_pix === '') {
            return null;
        }

        return ['chave' => (string) $linha->chave_pix, 'tipo' => (string) $linha->tipo_chave];
    }

    /** Os uuids dos motoboys da empresa que têm chave Pix: [driver_uuid => true]. */
    public static function motoboysComChave(string $companyUuid): array
    {
        $com = [];
        foreach (DB::table(static::TABELA)->where('company_uuid', $companyUuid)->whereNotNull('chave_pix')->pluck('driver_uuid') as $uuid) {
            $com[(string) $uuid] = true;
        }

        return $com;
    }

    /**
     * A chave pronta para gravar, ou a mensagem de erro em pt-BR: ['chave' => ..., 'tipo' => ...] | ['erro' => ...].
     * CPF, CNPJ e telefone aceitam pontuação e são gravados só com os dígitos (como o banco pede); e-mail e chave
     * aleatória, em minúsculas. As mesmas regras do console (validarPix em packages/fleetops/addon/utils/financeiro.js).
     */
    public static function validar($tipo, $chave): array
    {
        if (!is_string($tipo) || !in_array($tipo, Financeiro::TIPOS_CHAVE_PIX, true)) {
            return ['erro' => 'Tipo de chave Pix inválido.'];
        }
        $texto = is_scalar($chave) ? trim((string) $chave) : '';
        if ($texto === '') {
            return ['erro' => 'Informe a chave Pix.'];
        }
        if (mb_strlen($texto) > static::TAMANHO_MAXIMO) {
            return ['erro' => 'A chave Pix pode ter até 140 caracteres.'];
        }

        $digitos = preg_replace('/[\s.\-\/()+]/', '', $texto);
        $valida  = match ($tipo) {
            'cpf'       => preg_match('/^\d{11}$/', $digitos) === 1,
            'cnpj'      => preg_match('/^\d{14}$/', $digitos) === 1,
            'telefone'  => preg_match('/^\d{10,13}$/', $digitos) === 1,
            'email'     => preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $texto) === 1,
            'aleatoria' => preg_match('/^[0-9a-f-]{32,36}$/i', $texto) === 1,
        };
        if (!$valida) {
            return ['erro' => 'Chave Pix inválida para o tipo escolhido.'];
        }

        $normalizada = in_array($tipo, ['cpf', 'cnpj', 'telefone'], true) ? $digitos : mb_strtolower($texto);

        return ['chave' => $normalizada, 'tipo' => $tipo];
    }

    /**
     * Grava (cria ou troca) a chave do motoboy. Devolve true quando TROCOU uma chave que já existia por outra (chave ou tipo
     * diferente): aí grava quem e quando (pix_alterada_por/_em), o que bloqueia o saque Pix por 24 h (Saques::liberadoEm).
     * O primeiro cadastro e a mesma chave de novo não contam como troca.
     */
    public static function salvar(string $companyUuid, string $driverUuid, string $chave, string $tipo, ?string $usuarioUuid = null): bool
    {
        $agora  = now()->format('Y-m-d H:i:s');
        $atual  = DB::table(static::TABELA)->where('driver_uuid', $driverUuid)->first();
        $antiga = $atual ? (string) ($atual->chave_pix ?? '') : '';
        $trocou = $antiga !== '' && ($antiga !== $chave || (string) $atual->tipo_chave !== $tipo);

        $campos = ['company_uuid' => $companyUuid, 'chave_pix' => $chave, 'tipo_chave' => $tipo, 'updated_at' => $agora];
        if ($trocou) {
            $campos['pix_alterada_em']  = $agora;
            $campos['pix_alterada_por'] = $usuarioUuid;
        }
        if ($atual) {
            DB::table(static::TABELA)->where('driver_uuid', $driverUuid)->update($campos);
        } else {
            DB::table(static::TABELA)->insert($campos + ['driver_uuid' => $driverUuid, 'created_at' => $agora]);
        }

        return $trocou;
    }
}
