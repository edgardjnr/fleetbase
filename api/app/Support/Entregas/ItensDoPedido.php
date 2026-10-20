<?php

namespace App\Support\Entregas;

use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: os produtos do pedido (iFood e integração) no formato que o app do motoboy lê, o campo
 * `itens` de GET v1/entregas/motoboy/pedidos/{id}/ifood e .../integracao (spec
 * docs/superpowers/specs/2026-10-19-produtos-do-pedido-servidor-design.md):
 *
 *   [{"quantidade": 1, "nome": "Pizza", "opcoes": [{"quantidade": 1, "nome": "Borda"}], "observacao": "sem cebola"}]
 *
 * - `quantidade`: inteiro de 1 a 999 (aceita 2, 2.0 e "2"); ausente ou inválida vale 1 (o app soma as quantidades);
 * - `nome`: uma linha, sem espaços sobrando, cortado em 120 caracteres; item sem nome não entra;
 * - `opcoes`: até 20, cada uma com `quantidade` (padrão 1) e `nome`; opção sem nome não entra; sem nenhuma, sai;
 * - `observacao`: uma linha, cortada em 200 caracteres; vazia ou inválida, a chave sai;
 * - até 50 itens (os primeiros válidos); lista vazia, sem item válido ou que não é lista = null.
 *
 * Nunca lança nem recusa: os itens são um extra e não podem derrubar o pedido nem a resposta ao app. Função pura,
 * fora o aviso do doBanco (só com os ids que quem chama passa, nunca o conteúdo).
 */
final class ItensDoPedido
{
    public const MAXIMO_ITENS      = 50;
    public const MAXIMO_OPCOES     = 20;
    public const NOME_MAXIMO       = 120;
    public const OBSERVACAO_MAXIMO = 200;
    public const QUANTIDADE_MAXIMA = 999;

    /** A lista no formato do app, ou null. */
    public static function normalizar($itens): ?array
    {
        if (!is_array($itens) || !array_is_list($itens)) {
            return null;
        }

        $lista = [];
        foreach ($itens as $item) {
            if (count($lista) >= static::MAXIMO_ITENS) {
                break;
            }
            $normal = is_array($item) ? static::item($item) : null;
            if ($normal !== null) {
                $lista[] = $normal;
            }
        }

        return $lista ?: null;
    }

    /** O texto JSON da coluna `itens` (normalizado de novo, por segurança), ou null. */
    public static function paraOBanco(?array $itens): ?string
    {
        $itens = static::normalizar($itens);
        if ($itens === null) {
            return null;
        }
        $json = json_encode($itens, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? null : $json;
    }

    /**
     * A coluna `itens` lida do banco, normalizada (o MySQL devolve o JSON com as chaves em outra ordem). Nula ou
     * vazia = null. JSON quebrado ou que não é lista = null e o warning `[entregas] itens do pedido ilegíveis no banco`
     * com o $contexto (só ids), sem derrubar a resposta.
     */
    public static function doBanco($valor, array $contexto = []): ?array
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        try {
            $dados = is_string($valor) ? json_decode($valor, true, 16, JSON_THROW_ON_ERROR) : $valor;
            if (!is_array($dados) || !array_is_list($dados)) {
                throw new \UnexpectedValueException('não é lista');
            }

            return static::normalizar($dados);
        } catch (\Throwable $e) {
            Log::warning('[entregas] itens do pedido ilegíveis no banco', $contexto + ['erro' => get_class($e)]);

            return null;
        }
    }

    protected static function item(array $item): ?array
    {
        $nome = static::texto($item['nome'] ?? null, static::NOME_MAXIMO);
        if ($nome === null) {
            return null;
        }

        $normal = ['quantidade' => static::quantidade($item['quantidade'] ?? null), 'nome' => $nome];
        $opcoes = static::opcoes($item['opcoes'] ?? null);
        if ($opcoes) {
            $normal['opcoes'] = $opcoes;
        }
        $observacao = static::texto($item['observacao'] ?? null, static::OBSERVACAO_MAXIMO);
        if ($observacao !== null) {
            $normal['observacao'] = $observacao;
        }

        return $normal;
    }

    protected static function opcoes($opcoes): array
    {
        if (!is_array($opcoes) || !array_is_list($opcoes)) {
            return [];
        }

        $lista = [];
        foreach ($opcoes as $opcao) {
            if (count($lista) >= static::MAXIMO_OPCOES) {
                break;
            }
            $nome = is_array($opcao) ? static::texto($opcao['nome'] ?? null, static::NOME_MAXIMO) : null;
            if ($nome !== null) {
                $lista[] = ['quantidade' => static::quantidade($opcao['quantidade'] ?? null), 'nome' => $nome];
            }
        }

        return $lista;
    }

    /** Inteiro de 1 a 999; 2.0 e "2" valem 2; o resto (0, negativo, fração, texto, booleano, ausente) vale 1. */
    protected static function quantidade($valor): int
    {
        if (is_string($valor) && preg_match('/^\s*\d{1,3}\s*$/', $valor)) {
            $valor = (int) trim($valor);
        }
        if (is_float($valor) && is_finite($valor) && floor($valor) === $valor && $valor >= 1 && $valor <= static::QUANTIDADE_MAXIMA) {
            $valor = (int) $valor;
        }

        return is_int($valor) && $valor >= 1 && $valor <= static::QUANTIDADE_MAXIMA ? $valor : 1;
    }

    /**
     * Uma linha: quebras e tabs viram espaço, controles e invisíveis saem (o ZWJ do emoji composto fica), espaços
     * (inclusive os Unicode) colapsam e as pontas são aparadas; depois corta em $maximo caracteres. Número vira texto.
     * Vazio, não texto ou UTF-8 inválido = null.
     */
    protected static function texto($valor, int $maximo): ?string
    {
        if (is_int($valor) || is_float($valor)) {
            $valor = (string) $valor;
        }
        if (!is_string($valor) || !mb_check_encoding($valor, 'UTF-8')) {
            return null;
        }
        // Texto gigante: reduz antes das regex (cada caractere cabe em até 4 bytes; a folga cobre os espaços que colapsam).
        $teto = $maximo * 4 + 64;
        if (strlen($valor) > $teto) {
            $valor = mb_strcut($valor, 0, $teto, 'UTF-8');
        }

        $limpo = preg_replace('/[\t\n\r\f\v\x{85}]/u', ' ', $valor);
        $limpo = $limpo === null ? null : preg_replace('/\p{Cc}|(?!\x{200D})\p{Cf}/u', '', $limpo);
        $limpo = $limpo === null ? null : preg_replace('/[\s\p{Z}]+/u', ' ', $limpo);
        $limpo = $limpo === null ? '' : trim($limpo);

        return $limpo === '' ? null : rtrim(mb_substr($limpo, 0, $maximo));
    }
}
