<?php

namespace App\Support\Entregas\Integracao;

/**
 * Entregas RestaurantePro: confere e normaliza o corpo do POST v1/entregas/integracao/pedidos (função pura). Devolve
 * ['erros' => [...], 'dados' => [...]|null], com todos os erros de uma vez; as mensagens citam o campo (quem lê é o
 * desenvolvedor do sistema de origem). Contrato: spec 2026-10-12-integracao-restaurantepro-design.md, Parte 1.
 *
 * Normaliza: textos sem espaços sobrando (as observações mantêm a quebra de linha), telefone só com dígitos, UF em
 * maiúsculas, CEP "99999-999", valores em centavos (aceita "58,90"), troco 0 = sem troco, forma em minúsculas.
 */
final class PedidoDaIntegracao
{
    public const FORMAS = ['dinheiro', 'cartao', 'pix', 'pago'];

    /** R$ 100 mil, em centavos: acima disso é erro de digitação ou de unidade (centavos mandados como reais). */
    public const VALOR_MAXIMO_CENTAVOS = 10000000;

    /** @return array{erros: string[], dados: ?array} */
    public static function validar(array $corpo): array
    {
        $erros = [];

        $externo = static::texto($corpo, 'pedido_externo_id', 100, true, $erros, true, true);
        if ($externo !== null && !preg_match('/^[A-Za-z0-9._:-]+$/', $externo)) {
            $erros[] = 'pedido_externo_id aceita só letras, números, ponto, hífen, sublinhado e dois-pontos.';
        }
        $numero      = static::texto($corpo, 'numero', 20, true, $erros, true, true);
        $nome        = static::texto($corpo, 'cliente.nome', 120, true, $erros);
        $telefone    = static::telefone($corpo, $erros);
        $endereco    = static::texto($corpo, 'entrega.endereco', 190, true, $erros);
        $complemento = static::texto($corpo, 'entrega.complemento', 190, false, $erros);
        $bairro      = static::texto($corpo, 'entrega.bairro', 120, false, $erros);
        $cidade      = static::texto($corpo, 'entrega.cidade', 120, true, $erros);
        $uf          = static::uf($corpo, $erros);
        $cep         = static::cep($corpo, $erros);
        $referencia  = static::texto($corpo, 'entrega.referencia', 190, false, $erros);
        [$latitude, $longitude]     = static::coordenadas($corpo, $erros);
        $observacoes                = static::texto($corpo, 'observacoes', 1000, false, $erros, false);
        [$forma, $centavos, $troco] = static::cobranca($corpo, $erros);

        if ($erros) {
            return ['erros' => $erros, 'dados' => null];
        }

        return ['erros' => [], 'dados' => [
            'pedido_externo_id'   => $externo,
            'numero'              => $numero,
            'cliente_nome'        => $nome,
            'cliente_telefone'    => $telefone,
            'endereco'            => $endereco,
            'complemento'         => $complemento,
            'bairro'              => $bairro,
            'cidade'              => $cidade,
            'uf'                  => $uf,
            'cep'                 => $cep,
            'referencia'          => $referencia,
            'latitude'            => $latitude,
            'longitude'           => $longitude,
            'cobranca_forma'      => $forma,
            'cobranca_centavos'   => $centavos,
            'troco_para_centavos' => $troco,
            'observacoes'         => $observacoes,
        ]];
    }

    /** O valor em "a.b" do corpo, ou null (sem o data_get do Laravel: a classe fica pura). */
    public static function campo(array $corpo, string $caminho)
    {
        $valor = $corpo;
        foreach (explode('.', $caminho) as $parte) {
            if (!is_array($valor) || !array_key_exists($parte, $valor)) {
                return null;
            }
            $valor = $valor[$parte];
        }

        return $valor;
    }

    protected static function texto(array $corpo, string $caminho, int $maximo, bool $obrigatorio, array &$erros, bool $umaLinha = true, bool $semFloat = false): ?string
    {
        $valor = static::campo($corpo, $caminho);
        if ($semFloat && is_float($valor)) {
            $erros[] = "{$caminho} precisa ser texto.";

            return null;
        }
        if (is_int($valor) || is_float($valor)) {
            $valor = (string) $valor;
        }
        if ($valor !== null && !is_string($valor)) {
            $erros[] = "{$caminho} precisa ser texto.";

            return null;
        }
        if ($valor !== null && !mb_check_encoding($valor, 'UTF-8')) {
            $erros[] = "{$caminho} precisa ser texto em UTF-8.";

            return null;
        }

        $limpo = null;
        if ($valor !== null) {
            $limpo = $umaLinha ? preg_replace('/\s+/u', ' ', $valor) : preg_replace('/\r\n?/', "\n", $valor);
            // Controles e caracteres invisíveis (nulo, ESC, largura zero...) saem; a quebra de linha das observações fica.
            $limpo = preg_replace($umaLinha ? '/[\p{Cc}\p{Cf}]+/u' : '/[^\P{Cc}\n]|\p{Cf}/u', '', (string) $limpo);
            $limpo = static::apara((string) $limpo);
        }
        if ($limpo === null || $limpo === '') {
            if ($obrigatorio) {
                $erros[] = "{$caminho} é obrigatório.";
            }

            return null;
        }
        if (mb_strlen($limpo) > $maximo) {
            $erros[] = "{$caminho} passa de {$maximo} caracteres.";

            return null;
        }

        return $limpo;
    }

    /** trim que também tira espaço Unicode (NBSP, espaço largo, ideográfico). */
    protected static function apara(string $texto): string
    {
        return (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $texto);
    }

    protected static function telefone(array $corpo, array &$erros): ?string
    {
        $valor = static::campo($corpo, 'cliente.telefone');
        if (is_string($valor)) {
            $valor = static::apara($valor);
        }
        if ($valor === null || $valor === '') {
            return null;
        }
        if (!is_string($valor) && !is_int($valor)) {
            $erros[] = 'cliente.telefone precisa ser texto.';

            return null;
        }
        $digitos = preg_replace('/\D+/', '', (string) $valor);
        if ($digitos === '') {
            return null;
        }
        if (strlen($digitos) < 8 || strlen($digitos) > 15) {
            $erros[] = 'cliente.telefone precisa ter de 8 a 15 dígitos.';

            return null;
        }

        return $digitos;
    }

    protected static function uf(array $corpo, array &$erros): ?string
    {
        $valor = static::campo($corpo, 'entrega.uf');
        if (is_string($valor)) {
            $valor = static::apara($valor);
        }
        if ($valor === null || $valor === '') {
            return null;
        }
        if (!is_string($valor) || !preg_match('/^[A-Za-z]{2}$/', $valor)) {
            $erros[] = 'entrega.uf precisa ter 2 letras (ex.: SP).';

            return null;
        }

        return strtoupper($valor);
    }

    protected static function cep(array $corpo, array &$erros): ?string
    {
        $valor = static::campo($corpo, 'entrega.cep');
        if (is_string($valor)) {
            $valor = static::apara($valor);
        }
        if ($valor === null || $valor === '') {
            return null;
        }
        $digitos = is_string($valor) || is_int($valor) ? preg_replace('/\D+/', '', (string) $valor) : '';
        if (strlen($digitos) !== 8) {
            $erros[] = 'entrega.cep precisa ter 8 dígitos.';

            return null;
        }

        return substr($digitos, 0, 5) . '-' . substr($digitos, 5);
    }

    /** @return array{0: ?float, 1: ?float} */
    protected static function coordenadas(array $corpo, array &$erros): array
    {
        $latitude  = static::campo($corpo, 'entrega.latitude');
        $longitude = static::campo($corpo, 'entrega.longitude');
        if (($latitude === null || $latitude === '') && ($longitude === null || $longitude === '')) {
            return [null, null];
        }
        if (!is_numeric($latitude) || !is_numeric($longitude) || abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
            $erros[] = 'entrega.latitude e entrega.longitude vão juntas, em graus decimais (ex.: -21.1931 e -47.8122).';

            return [null, null];
        }

        return [(float) $latitude, (float) $longitude];
    }

    /** @return array{0: ?string, 1: int, 2: ?int} forma, centavos a cobrar e troco para (centavos) */
    protected static function cobranca(array $corpo, array &$erros): array
    {
        $forma = static::campo($corpo, 'cobranca.forma');
        $forma = is_string($forma) ? strtolower(trim($forma)) : null;
        if (!in_array($forma, static::FORMAS, true)) {
            $erros[] = 'cobranca.forma precisa ser dinheiro, cartao, pix ou pago.';

            return [null, 0, null];
        }
        if ($forma === 'pago') {
            return ['pago', 0, null];
        }

        $centavos = static::centavos(static::campo($corpo, 'cobranca.valor'));
        if ($centavos === null || $centavos <= 0 || $centavos > static::VALOR_MAXIMO_CENTAVOS) {
            $erros[] = 'cobranca.valor precisa ser o valor a cobrar na porta, em reais (ex.: 58.90), maior que zero e até 100000.';

            return [$forma, 0, null];
        }

        $bruto = static::campo($corpo, 'cobranca.troco_para');
        $troco = static::centavos($bruto);
        if ($bruto === null || $bruto === '' || $troco === 0) {
            return [$forma, $centavos, null];
        }
        if (!is_numeric($bruto)) {
            $erros[] = 'cobranca.troco_para precisa ser um valor em reais (ex.: 100).';

            return [$forma, $centavos, null];
        }
        if ($troco === null) {
            $erros[] = 'cobranca.troco_para passa de 100000.';

            return [$forma, $centavos, null];
        }
        if ($forma !== 'dinheiro') {
            $erros[] = 'cobranca.troco_para só vale com dinheiro.';

            return [$forma, $centavos, null];
        }
        if ($troco < $centavos) {
            $erros[] = 'cobranca.troco_para não pode ser menor que o valor.';

            return [$forma, $centavos, null];
        }

        return [$forma, $centavos, $troco];
    }

    protected static function centavos($valor): ?int
    {
        if (is_string($valor)) {
            $valor = str_replace(',', '.', trim($valor));
        }

        if (!is_numeric($valor)) {
            return null;
        }
        $f = (float) $valor;
        // Compara o float antes do (int): um float gigante estoura o inteiro e pode voltar como centavos pequenos.
        if (!is_finite($f) || abs($f) > static::VALOR_MAXIMO_CENTAVOS / 100) {
            return null;
        }

        return (int) round($f * 100);
    }
}
