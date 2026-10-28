<?php

namespace App\Support\Entregas\Portal;

use App\Support\Entregas\Coordenada;

/**
 * Entregas RestaurantePro: confere e normaliza o corpo do POST int/v1/entregas/loja/chamar-motoboy (o painel "Chamar
 * motoboy" do portal da loja). Função pura. Devolve ['erros' => [...], 'dados' => [...]|null], todos os erros de uma vez,
 * com mensagens para a loja (sem nome de campo: o front já confere antes, e o que chega aqui aparece no aviso de erro).
 * Texto longo é cortado (o front limita), UF e CEP inválidos (vêm do Google) viram null. O telefone segue a mesma regra
 * do front (packages/customer-portal/addon/utils/chamar-motoboy.js, digitosDoTelefone): mudou aqui, mude lá.
 * Spec: docs/superpowers/specs/2026-10-28-chamar-motoboy-portal-design.md.
 */
final class ChamadoDaLoja
{
    public const FORMAS = ['pago', 'dinheiro', 'cartao', 'pix'];

    /** R$ 100 mil, em centavos (a regra da integração). */
    public const VALOR_MAXIMO_CENTAVOS = 10000000;

    public const LIMITES = ['nome' => 120, 'rua' => 190, 'bairro' => 120, 'cidade' => 120, 'complemento' => 190, 'referencia' => 190, 'observacao' => 500];

    public const PADRAO_DA_CHAVE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** @return array{erros: string[], dados: ?array} */
    public static function validar(array $corpo): array
    {
        $erros    = [];
        $cliente  = is_array($corpo['cliente'] ?? null) ? $corpo['cliente'] : [];
        $entrega  = is_array($corpo['entrega'] ?? null) ? $corpo['entrega'] : [];
        $cobranca = is_array($corpo['cobranca'] ?? null) ? $corpo['cobranca'] : [];

        $chave = is_string($corpo['chave'] ?? null) ? strtolower(trim($corpo['chave'])) : '';
        if (!preg_match(static::PADRAO_DA_CHAVE, $chave)) {
            $erros[] = 'Atualize a página e tente de novo.';
        }

        $nome = static::texto($cliente['nome'] ?? null, static::LIMITES['nome']);
        if ($nome === null) {
            $erros[] = 'Informe o nome do cliente.';
        }
        $telefone = static::telefone($cliente['telefone'] ?? null);
        if ($telefone === null) {
            $erros[] = 'Informe o telefone do cliente com DDD.';
        }
        $rua = static::texto($entrega['rua'] ?? null, static::LIMITES['rua']);
        if ($rua === null) {
            $erros[] = 'Informe a rua e o número da entrega.';
        }
        $latitude  = $entrega['latitude'] ?? null;
        $longitude = $entrega['longitude'] ?? null;
        $temPonto  = is_numeric($latitude) && is_numeric($longitude) && Coordenada::valida((float) $latitude, (float) $longitude);
        if (!$temPonto) {
            $erros[] = 'Marque no mapa o local da entrega.';
        }
        [$forma, $centavos, $troco] = static::cobranca($cobranca, $erros);

        if ($erros) {
            return ['erros' => $erros, 'dados' => null];
        }

        return ['erros' => [], 'dados' => [
            'chave'               => $chave,
            'cliente_nome'        => $nome,
            'cliente_telefone'    => $telefone,
            'rua'                 => $rua,
            'bairro'              => static::texto($entrega['bairro'] ?? null, static::LIMITES['bairro']),
            'cidade'              => static::texto($entrega['cidade'] ?? null, static::LIMITES['cidade']),
            'uf'                  => static::uf($entrega['uf'] ?? null),
            'cep'                 => static::cep($entrega['cep'] ?? null),
            'latitude'            => (float) $latitude,
            'longitude'           => (float) $longitude,
            'complemento'         => static::texto($entrega['complemento'] ?? null, static::LIMITES['complemento']),
            'referencia'          => static::texto($entrega['referencia'] ?? null, static::LIMITES['referencia']),
            'cobranca_forma'      => $forma,
            'cobranca_centavos'   => $centavos,
            'troco_para_centavos' => $troco,
            'observacoes'         => static::texto($corpo['observacao'] ?? null, static::LIMITES['observacao'], true),
        ]];
    }

    /**
     * Só os dígitos, com DDD: tira o 55 da frente (12 ou 13 dígitos) e depois o 0 do interurbano (11 ou 12 dígitos).
     * Vale com 10 (fixo) ou 11 (celular) dígitos e o DDD sem 0 na frente; senão null.
     */
    public static function telefone($valor): ?string
    {
        if (is_int($valor)) {
            $valor = (string) $valor;
        }
        if (!is_string($valor)) {
            return null;
        }
        $digitos = preg_replace('/\D+/', '', $valor) ?? '';
        if (strlen($digitos) >= 12 && str_starts_with($digitos, '55')) {
            $digitos = substr($digitos, 2);
        }
        if ((strlen($digitos) === 11 || strlen($digitos) === 12) && str_starts_with($digitos, '0')) {
            $digitos = substr($digitos, 1);
        }

        return (strlen($digitos) === 10 || strlen($digitos) === 11) && $digitos[0] !== '0' ? $digitos : null;
    }

    /** Valor em reais vindo do campo ("58,90", "1.234,56", "R$ 10", 58.9) em centavos; null se não for valor. */
    public static function centavos($valor): ?int
    {
        if (is_string($valor)) {
            $valor = trim(str_replace(['R$', ' ', "\u{00A0}"], '', $valor));
            if (str_contains($valor, ',')) {
                $valor = str_replace(['.', ','], ['', '.'], $valor);
            }
        }
        if (!is_numeric($valor)) {
            return null;
        }
        $reais = (float) $valor;
        if (!is_finite($reais) || $reais < 0 || $reais > static::VALOR_MAXIMO_CENTAVOS / 100) {
            return null;
        }

        return (int) round($reais * 100);
    }

    /**
     * Texto limpo: sem controles nem espaço Unicode sobrando (uma linha) ou com as quebras de linha mantidas
     * ($variasLinhas), cortado no limite; vazio = null.
     */
    public static function texto($valor, int $maximo, bool $variasLinhas = false): ?string
    {
        if (is_int($valor) || is_float($valor)) {
            $valor = (string) $valor;
        }
        if (!is_string($valor) || !mb_check_encoding($valor, 'UTF-8')) {
            return null;
        }
        $limpo = $variasLinhas
            ? preg_replace(['/\r\n?/u', '/\t/u', '/[^\P{Cc}\n]/u', '/[ \x{00A0}]{2,}/u', '/\n{3,}/u'], ["\n", ' ', '', ' ', "\n\n"], $valor)
            : preg_replace(['/\p{Cc}+/u', '/[\s\p{Z}]+/u'], [' ', ' '], $valor);
        $limpo = $limpo === null ? '' : trim($limpo);

        return $limpo === '' ? null : mb_substr($limpo, 0, $maximo);
    }

    protected static function uf($valor): ?string
    {
        return is_string($valor) && preg_match('/^\s*([A-Za-z]{2})\s*$/', $valor, $m) ? strtoupper($m[1]) : null;
    }

    protected static function cep($valor): ?string
    {
        $digitos = is_string($valor) || is_int($valor) ? preg_replace('/\D+/', '', (string) $valor) : '';

        return strlen((string) $digitos) === 8 ? substr($digitos, 0, 5) . '-' . substr($digitos, 5) : null;
    }

    /** @return array{0: ?string, 1: int, 2: ?int} forma, centavos a cobrar e troco para (centavos) */
    protected static function cobranca(array $cobranca, array &$erros): array
    {
        $forma = is_string($cobranca['forma'] ?? null) ? strtolower(trim($cobranca['forma'])) : null;
        if (!in_array($forma, static::FORMAS, true)) {
            $erros[] = 'Diga se o cliente já pagou ou como ele vai pagar.';

            return [null, 0, null];
        }
        if ($forma === 'pago') {
            return ['pago', 0, null];
        }

        $centavos = static::centavos($cobranca['valor'] ?? null);
        if ($centavos === null || $centavos <= 0) {
            $erros[] = 'Informe o valor a cobrar na entrega.';

            return [$forma, 0, null];
        }

        $bruto = $cobranca['troco_para'] ?? null;
        if ($forma !== 'dinheiro' || $bruto === null || $bruto === '') {
            return [$forma, $centavos, null];
        }
        $troco = static::centavos($bruto);
        if ($troco === null) {
            $erros[] = 'Confira o valor do troco.';

            return [$forma, $centavos, null];
        }
        if ($troco === 0 || $troco === $centavos) {
            return [$forma, $centavos, null];
        }
        if ($troco < $centavos) {
            $erros[] = 'O troco precisa ser para um valor maior que o da cobrança.';

            return [$forma, $centavos, null];
        }

        return [$forma, $centavos, $troco];
    }
}
