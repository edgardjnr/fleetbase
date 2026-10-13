<?php

namespace App\Support\Entregas\Financeiro;

use Fleetbase\Models\Company;
use Fleetbase\Models\Setting;

/**
 * Entregas RestaurantePro: o financeiro (extrato e pagamento dos motoboys, faturas das lojas). Constantes, o interruptor
 * geral (ENTREGAS_FINANCEIRO, `services.entregas.financeiro`: liga o lançamento automático das corridas, as rotinas e os
 * e-mails; desligado, as telas e os lançamentos manuais continuam) e a configuração por empresa (Setting, lida pela
 * chave inteira porque comando e job não têm sessão). Desenho: docs/superpowers/specs/2026-10-12-financeiro-design.md.
 */
final class Financeiro
{
    public const CONTA_MOTOBOY = 'motoboy';
    public const CONTA_LOJA    = 'loja';

    public const TIPOS_MANUAIS_MOTOBOY = ['diaria', 'bonus', 'ajuste', 'saldo_inicial'];
    public const TIPOS_MANUAIS_LOJA    = ['ajuste', 'saldo_inicial'];

    public const FORMAS = ['pix', 'dinheiro', 'transferencia', 'outro'];

    public const TIPOS_CHAVE_PIX = ['cpf', 'cnpj', 'telefone', 'email', 'aleatoria'];

    /** Fuso quando a empresa não tem um (o do servidor: Brasília). */
    public const FUSO_PADRAO = 'America/Sao_Paulo';

    /** Ciclo de cobrança sugerido para as lojas sem ciclo próprio: semanal, fecha no domingo, vence em 3 dias. */
    public const COBRANCA_PADRAO = ['ciclo' => 'semanal', 'dia_fechamento' => 0, 'prazo_dias' => 3];

    public static function ligada(): bool
    {
        return filter_var(config('services.entregas.financeiro'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Data de corte da empresa ('Y-m-d'): corridas anteriores não são lançadas. null (ausente ou inválida) = nada é lançado
     * automaticamente nessa empresa.
     */
    public static function corte(string $companyUuid): ?string
    {
        $corte = Setting::lookup('company.' . $companyUuid . '.entregas.financeiro_corte');
        if (!is_string($corte) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $corte)) {
            return null;
        }
        [$ano, $mes, $dia] = array_map('intval', explode('-', $corte));

        return checkdate($mes, $dia, $ano) ? $corte : null;
    }

    /** Ciclo de cobrança padrão da empresa ({ciclo, dia_fechamento, prazo_dias}), completado com COBRANCA_PADRAO. */
    public static function cobrancaPadrao(string $companyUuid): array
    {
        $salvo = Setting::lookup('company.' . $companyUuid . '.entregas.cobranca_padrao');
        if (is_string($salvo)) {
            $salvo = json_decode($salvo, true);
        }
        $salvo = is_array($salvo) ? $salvo : [];

        return [
            'ciclo'          => (string) ($salvo['ciclo'] ?? static::COBRANCA_PADRAO['ciclo']),
            'dia_fechamento' => (int) ($salvo['dia_fechamento'] ?? static::COBRANCA_PADRAO['dia_fechamento']),
            'prazo_dias'     => (int) ($salvo['prazo_dias'] ?? static::COBRANCA_PADRAO['prazo_dias']),
        ];
    }

    public static function fuso(string $companyUuid): string
    {
        $fuso = Company::where('uuid', $companyUuid)->value('timezone');

        return is_string($fuso) && $fuso !== '' && in_array($fuso, \DateTimeZone::listIdentifiers(), true) ? $fuso : static::FUSO_PADRAO;
    }

    /** O dia de hoje ('Y-m-d') no fuso da empresa. */
    public static function hoje(string $companyUuid): string
    {
        return (new \DateTimeImmutable('@' . now()->getTimestamp()))->setTimezone(new \DateTimeZone(static::fuso($companyUuid)))->format('Y-m-d');
    }

    /** 12 → 'FAT-0012'. */
    public static function numeroDaFatura(?int $numero): ?string
    {
        return $numero === null ? null : sprintf('FAT-%04d', $numero);
    }
}
