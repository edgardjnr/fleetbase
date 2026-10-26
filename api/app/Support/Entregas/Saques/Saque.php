<?php

namespace App\Support\Entregas\Saques;

use App\Support\Entregas\Financeiro\Financeiro;

/**
 * Entregas RestaurantePro: saque Pix do motoboy pelo app, pela conta Asaas da central. Constantes, o interruptor geral
 * (ENTREGAS_SAQUE, `services.entregas.saque`) e funções puras (centavos, dinheiro, chave Pix com o meio escondido,
 * situação a partir do status do Asaas). Desenho: docs/superpowers/specs/2026-10-26-saque-pix-asaas-design.md.
 */
final class Saque
{
    public const TABELA   = 'entregas_saques';
    public const CONEXOES = 'entregas_asaas_conexoes';

    public const ENVIANDO     = 'enviando';
    public const PROCESSANDO  = 'processando';
    public const CONCLUIDO    = 'concluido';
    public const FALHOU       = 'falhou';
    public const EM_ANDAMENTO = [self::ENVIANDO, self::PROCESSANDO];
    public const SITUACOES    = [self::ENVIANDO, self::PROCESSANDO, self::CONCLUIDO, self::FALHOU];

    /** Horas sem saque depois de a central trocar a chave Pix do motoboy. */
    public const CARENCIA_PIX_HORAS = 24;

    /** Minutos até a rotina procurar no Asaas o saque que ficou sem transfer_id (a API caiu no meio do envio). */
    public const MINUTOS_SEM_TRANSFERENCIA = 5;

    /**
     * Minutos até a rotina tratar como órfão o saque cujo transfer_id dá 404 no Asaas: procura pelo externalReference
     * (achou, adota; não achou, falha e devolve). Antes disso, um 404 só espera.
     */
    public const MINUTOS_ORFAO = 30;

    /** Minutos em que a validação aceita um saque (ou o teste) ainda sem transfer_id gravado. */
    public const MINUTOS_DA_VALIDACAO = 2;

    /** Em andamento há mais que isso: "saque parado" no painel. */
    public const MINUTOS_PARADO = 60;

    /** Itens de cada tipo no "Atenção" do painel. */
    public const LIMITE_NO_PAINEL = 20;

    public const PADROES = ['minimo' => 10.0, 'maximo_dia' => 500.0, 'saques_dia' => 3, 'taxa' => 0.0];

    public const TIPOS_ASAAS = ['cpf' => 'CPF', 'cnpj' => 'CNPJ', 'telefone' => 'PHONE', 'email' => 'EMAIL', 'aleatoria' => 'EVP'];

    public const OBSERVACAO_DO_PAGAMENTO = 'Saque Pix pelo app';
    public const MOTIVO_DA_TAXA          = 'Taxa do saque Pix';
    public const MOTIVO_TAXA_DEVOLVIDA   = 'Taxa do saque devolvida';
    public const DESCRICAO_NO_ASAAS      = 'Saque Entregas';

    /** O motivo que o motoboy vê quando a falha vem do Asaas (o texto do Asaas fica em motivo_asaas, só no console). */
    public const MOTIVO_FALHA_DO_ASAAS = 'Não foi possível concluir o saque agora.';

    public static function ligada(): bool
    {
        return filter_var(config('services.entregas.saque'), FILTER_VALIDATE_BOOLEAN);
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

    /** A situação nossa para um status de transferência do Asaas: DONE fecha, FAILED/CANCELLED falham, o resto processa. */
    public static function situacaoDoAsaas(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'DONE'                => self::CONCLUIDO,
            'FAILED', 'CANCELLED' => self::FALHOU,
            default               => self::PROCESSANDO,
        };
    }

    /** A chave Pix com o meio escondido: telefone/CPF/CNPJ só os 4 últimos, e-mail 2 letras + domínio, aleatória começo e fim. */
    public static function chaveEscondida(string $chave, string $tipo): string
    {
        if ($tipo === 'email') {
            [$nome, $dominio] = array_pad(explode('@', $chave, 2), 2, '');

            return mb_substr($nome, 0, 2) . '***@' . $dominio;
        }
        if ($tipo === 'aleatoria') {
            return mb_substr($chave, 0, 4) . '…' . mb_substr($chave, -4);
        }

        return str_repeat('*', max(0, mb_strlen($chave) - 4)) . mb_substr($chave, -4);
    }

    /**
     * O endereço do comprovante, só se for https num domínio do Asaas (asaas.com ou um subdomínio dele), até 500
     * caracteres; outra coisa vira null (o app e o console abrem o link: nada de endereço de terceiros).
     */
    public static function comprovante($url): ?string
    {
        if (!is_string($url) || strlen($url) > 500 || !preg_match('#^https://([a-z0-9-]+\.)*asaas\.com/#i', $url)) {
            return null;
        }

        return $url;
    }

    /** 'Y-m-d H:i:s' gravado em hora de Brasília → ISO 8601 no fuso da empresa (null fica null). */
    public static function iso(?string $quando, string $companyUuid): ?string
    {
        if ($quando === null || $quando === '') {
            return null;
        }
        $fusoDoApp = (string) (config('app.timezone') ?: Financeiro::FUSO_PADRAO);

        return (new \DateTimeImmutable($quando, new \DateTimeZone($fusoDoApp)))
            ->setTimezone(new \DateTimeZone(Financeiro::fuso($companyUuid)))
            ->format(DATE_ATOM);
    }
}
