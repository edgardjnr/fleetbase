<?php

namespace App\Support\Entregas\PrePago;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: o modelo de cobrança de cada loja. Pós-pago (o padrão, sem linha na tabela) = as faturas do
 * Financeiro; pré-pago = a loja recarrega a carteira por Pix na conta Asaas da central (Recargas) e as corridas consomem
 * o crédito (CarteiraDaLoja). Quem escolhe é o administrador do sistema, no cadastro da loja (LojasController). Aqui ficam
 * as constantes, o interruptor geral (ENTREGAS_RECARGA, `services.entregas.recarga`), o modelo da loja e o CPF/CNPJ dela
 * (o Asaas exige um documento no cliente da cobrança). Regras e segurança: o CLAUDE.md desta pasta.
 */
final class PrePago
{
    public const CARTEIRAS = 'entregas_carteiras_lojas';
    public const RECARGAS  = 'entregas_recargas';

    public const POS     = 'pos';
    public const PRE     = 'pre';
    public const MODELOS = [self::POS, self::PRE];

    public const CRIANDO      = 'criando';
    public const AGUARDANDO   = 'aguardando';
    public const PAGA         = 'paga';
    public const EXPIRADA     = 'expirada';
    public const FALHOU       = 'falhou';
    public const EM_ANDAMENTO = [self::CRIANDO, self::AGUARDANDO];

    /** Limites de uma recarga, em reais. */
    public const VALOR_MINIMO = 100.0;
    public const VALOR_MAXIMO = 5000.0;

    /** Recargas pedidas por loja por dia (de Brasília): segura quem tenta encher o Asaas da central de cobranças. */
    public const RECARGAS_POR_DIA = 10;

    /** Minutos em que o QR code vale; depois a cobrança é apagada no Asaas. */
    public const MINUTOS_DO_PIX = 30;

    /** Intervalo mínimo entre duas consultas ao Asaas da mesma recarga, pelo portal (a tela pergunta a cada 5 s). */
    public const SEGUNDOS_ENTRE_CONSULTAS = 5;

    /** Minutos até a rotina procurar no Asaas a recarga que ficou sem o id da cobrança (o Asaas não respondeu). */
    public const MINUTOS_CRIANDO = 2;

    /** Minutos procurando a cobrança pelo externalReference até dar a recarga "criando" por falha (nada nasceu). */
    public const MINUTOS_SEM_COBRANCA = 10;

    /** Minutos depois de expirada em que a rotina olha a cobrança uma última vez (um Pix pago no limite não se perde). */
    public const MINUTOS_CONFERENCIA_TARDIA = 60;

    /** Status do Asaas em que o dinheiro entrou na conta da central. RECEIVED_IN_CASH (baixa manual no painel) não vale. */
    public const PAGOS_NO_ASAAS = ['RECEIVED', 'CONFIRMED'];

    public const OBSERVACAO_DO_RECEBIMENTO = 'Recarga Pix pelo portal';
    public const DESCRICAO_NO_ASAAS        = 'Recarga de créditos Entregas';

    public static function ligada(): bool
    {
        return filter_var(config('services.entregas.recarga'), FILTER_VALIDATE_BOOLEAN);
    }

    /** A linha da loja (com $travar, lockForUpdate: dentro de transação); null se não há (pós-pago). */
    public static function linha(string $companyUuid, string $lojaUuid, bool $travar = false): ?object
    {
        $consulta = DB::table(static::CARTEIRAS)->where('company_uuid', $companyUuid)->where('loja_uuid', $lojaUuid);
        if ($travar) {
            $consulta->lockForUpdate();
        }

        return $consulta->first();
    }

    /** 'pre' ou 'pos'. Sem a tabela (API nova antes da migration) ou com o banco falhando: pós-pago, como era. */
    public static function modelo(string $companyUuid, string $lojaUuid): string
    {
        try {
            $modelo = static::linha($companyUuid, $lojaUuid)->modelo ?? static::POS;
        } catch (\Throwable $e) {
            Log::warning('[entregas] pré-pago: não deu para ler o modelo da loja', ['loja' => $lojaUuid, 'erro' => get_class($e)]);

            return static::POS;
        }

        return $modelo === static::PRE ? static::PRE : static::POS;
    }

    public static function ehPrePaga(string $companyUuid, string $lojaUuid): bool
    {
        return static::modelo($companyUuid, $lojaUuid) === static::PRE;
    }

    /** Os uuids das lojas pré-pagas da empresa ([loja_uuid => true]); sem a tabela, nenhuma. */
    public static function lojasPrePagas(string $companyUuid): array
    {
        try {
            $uuids = DB::table(static::CARTEIRAS)->where('company_uuid', $companyUuid)->where('modelo', static::PRE)->pluck('loja_uuid')->all();
        } catch (\Throwable $e) {
            return [];
        }

        return array_fill_keys(array_map('strval', $uuids), true);
    }

    /**
     * Grava o modelo e o documento da loja. Pré-pago exige CPF/CNPJ válido (o novo ou o já gravado). Documento vazio
     * mantém o gravado; inválido recusa. Erros: \InvalidArgumentException com a mensagem pt-BR (422).
     */
    public static function definir(string $companyUuid, string $lojaUuid, $modelo, $documento, ?string $usuarioUuid): void
    {
        if (!in_array($modelo, static::MODELOS, true)) {
            throw new \InvalidArgumentException('Escolha o modelo de cobrança: pré-pago ou pós-pago.');
        }
        $digitos = preg_replace('/\D/', '', is_scalar($documento) ? (string) $documento : '');
        if ($digitos !== '' && static::documento($digitos) === null) {
            throw new \InvalidArgumentException('CPF ou CNPJ inválido.');
        }

        DB::transaction(function () use ($companyUuid, $lojaUuid, $modelo, $digitos, $usuarioUuid) {
            $atual = static::linha($companyUuid, $lojaUuid, true);
            $final = $digitos !== '' ? $digitos : ($atual->documento ?? null);
            if ($modelo === static::PRE && $final === null) {
                throw new \InvalidArgumentException('Informe o CPF ou CNPJ da loja: o Pix da recarga sai no nome dela.');
            }
            $mudouModelo = ($atual->modelo ?? static::POS) !== $modelo;
            if (!$atual && $modelo === static::POS && $final === null) {
                // pós-pago sem documento é o padrão: não precisa de linha
                return;
            }

            $agora  = now()->format('Y-m-d H:i:s');
            $campos = ['modelo' => $modelo, 'documento' => $final, 'updated_at' => $agora];
            if ($mudouModelo) {
                $campos += ['modelo_alterado_em' => $agora, 'modelo_alterado_por' => $usuarioUuid];
            }
            if ($atual) {
                DB::table(static::CARTEIRAS)->where('id', $atual->id)->update($campos);
            } else {
                DB::table(static::CARTEIRAS)->insert($campos + ['company_uuid' => $companyUuid, 'loja_uuid' => $lojaUuid, 'created_at' => $agora]);
            }
            if ($mudouModelo) {
                Log::info('[entregas] pré-pago: modelo da loja alterado', ['empresa' => $companyUuid, 'loja' => $lojaUuid, 'modelo' => $modelo, 'por' => $usuarioUuid]);
            }
        });
    }

    /** Os dígitos de um CPF (11) ou CNPJ (14) válidos (dígitos verificadores conferidos); outra coisa → null. */
    public static function documento($valor): ?string
    {
        $digitos = preg_replace('/\D/', '', is_scalar($valor) ? (string) $valor : '');
        if (strlen($digitos) === 11) {
            return static::cpfValido($digitos) ? $digitos : null;
        }
        if (strlen($digitos) === 14) {
            return static::cnpjValido($digitos) ? $digitos : null;
        }

        return null;
    }

    private static function cpfValido(string $cpf): bool
    {
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }
        for ($posicao = 9; $posicao <= 10; $posicao++) {
            $soma = 0;
            for ($i = 0; $i < $posicao; $i++) {
                $soma += (int) $cpf[$i] * ($posicao + 1 - $i);
            }
            $digito = ((10 * $soma) % 11) % 10;
            if ((int) $cpf[$posicao] !== $digito) {
                return false;
            }
        }

        return true;
    }

    private static function cnpjValido(string $cnpj): bool
    {
        if (preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }
        foreach ([12 => [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], 13 => [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $posicao => $pesos) {
            $soma = 0;
            foreach ($pesos as $i => $peso) {
                $soma += (int) $cnpj[$i] * $peso;
            }
            $resto  = $soma % 11;
            $digito = $resto < 2 ? 0 : 11 - $resto;
            if ((int) $cnpj[$posicao] !== $digito) {
                return false;
            }
        }

        return true;
    }

    public static function centavos($valor): int
    {
        return (int) round(((float) $valor) * 100);
    }

    public static function reais(int $centavos): float
    {
        return round($centavos / 100, 2);
    }

    /** 1234.5 → "R$ 1.234,50". */
    public static function dinheiro(float $valor): string
    {
        return 'R$ ' . number_format($valor, 2, ',', '.');
    }
}
