<?php

namespace App\Support\Entregas\Financeiro;

/**
 * Entregas RestaurantePro: quitação por antiguidade, calculada na hora (nada é gravado). Funções puras, sem banco.
 * Desenho: docs/superpowers/specs/2026-10-12-financeiro-design.md, "Quitação por antiguidade".
 *
 * As contas são feitas em centavos (inteiros): 0,1 + 0,2 pagos com 0,3 ficam pagos, sem sobra de ponto flutuante.
 */
final class Quitacao
{
    /**
     * Situação de cada crédito da conta (motoboy ou loja sem fatura). Entrada: lançamentos (objetos ou arrays com
     * id, tipo, valor, data_referencia, order_uuid, estorno_de), em qualquer ordem.
     * Saída: [id => ['situacao' => 'pago'|'parcial'|'aberto'|'anulado', 'pago' => float, 'restante' => float]] só para créditos.
     *
     * Regras:
     *  1. crédito = valor > 0 e tipo != 'estorno'.
     *  2. débito com estorno_de apontando para crédito abate esse crédito; ajuste negativo com order_uuid abate a corrida
     *     do mesmo pedido (até o que ela tem); o que sobra vai para o "caixa".
     *  3. os demais débitos (pagamento, recebimento, ajuste sem pedido, saldo_inicial negativo) vão para o caixa;
     *     estorno positivo (de pagamento/recebimento) tira do caixa; caixa nunca < 0. Um débito estornado e o estorno
     *     dele se anulam (o estorno de um ajuste "Não pagar" devolve a corrida a receber).
     *  4. créditos ordenados por (data_referencia, id); restante = valor - abatido; abatido == valor → 'anulado';
     *     o caixa quita do mais antigo: tudo → 'pago', parte → 'parcial', nada → 'aberto'.
     */
    public static function creditos(array $lancamentos): array
    {
        $linhas = [];
        foreach ($lancamentos as $lancamento) {
            $linha                   = static::normalizar($lancamento);
            $linhas[$linha['id']]    = $linha;
        }

        // estornos positivos: anulam o débito que estornam (os dois saem da conta); sem o débito, tiram do caixa
        $estornados = [];
        $caixa      = 0;
        foreach ($linhas as $linha) {
            if ($linha['tipo'] !== 'estorno' || $linha['centavos'] <= 0) {
                continue;
            }
            $alvo = $linha['estorno_de'] !== null ? ($linhas[$linha['estorno_de']] ?? null) : null;
            if ($alvo !== null && $alvo['centavos'] < 0 && !isset($estornados[$alvo['id']])) {
                $estornados[$alvo['id']] = true;
                continue;
            }
            $caixa -= $linha['centavos'];
        }

        $creditos = array_filter($linhas, fn ($linha) => $linha['centavos'] > 0 && $linha['tipo'] !== 'estorno');
        $abatido  = array_fill_keys(array_keys($creditos), 0);

        // a corrida de cada pedido (uma por conta: chave_unica), para o ajuste com pedido
        $corridaDoPedido = [];
        foreach ($creditos as $credito) {
            if ($credito['tipo'] === 'corrida' && $credito['order_uuid'] !== null && !isset($corridaDoPedido[$credito['order_uuid']])) {
                $corridaDoPedido[$credito['order_uuid']] = $credito['id'];
            }
        }

        foreach ($linhas as $linha) {
            if ($linha['centavos'] >= 0 || isset($estornados[$linha['id']])) {
                continue;
            }
            $debito = -$linha['centavos'];

            $alvo = null;
            if ($linha['estorno_de'] !== null && isset($creditos[$linha['estorno_de']])) {
                $alvo = $linha['estorno_de'];
            } elseif ($linha['tipo'] === 'ajuste' && $linha['order_uuid'] !== null && isset($corridaDoPedido[$linha['order_uuid']])) {
                $alvo = $corridaDoPedido[$linha['order_uuid']];
            }

            if ($alvo !== null) {
                $cabe             = max(0, $creditos[$alvo]['centavos'] - $abatido[$alvo]);
                $usado            = min($cabe, $debito);
                $abatido[$alvo]  += $usado;
                $debito          -= $usado;
            }
            $caixa += $debito;
        }
        $caixa = max(0, $caixa);

        uasort($creditos, fn ($a, $b) => [$a['data'], $a['id']] <=> [$b['data'], $b['id']]);

        $saida = [];
        foreach ($creditos as $id => $credito) {
            $restante = $credito['centavos'] - $abatido[$id];
            if ($restante <= 0) {
                $saida[$id] = ['situacao' => 'anulado', 'pago' => 0.0, 'restante' => 0.0];
                continue;
            }
            $pago      = min($caixa, $restante);
            $caixa    -= $pago;
            $restante -= $pago;
            $situacao  = $restante === 0 ? 'pago' : ($pago > 0 ? 'parcial' : 'aberto');

            $saida[$id] = ['situacao' => $situacao, 'pago' => static::reais($pago), 'restante' => static::reais($restante)];
        }

        return $saida;
    }

    /**
     * Situação das faturas emitidas de uma loja. $faturas: [{uuid, total, vencimento}] (só emitidas; `numero` opcional,
     * desempata o mesmo vencimento); $recebido: soma positiva dos recebimentos menos estornos de recebimento.
     * Saída: [uuid => ['pagamento' => 'aberta'|'parcial'|'paga', 'recebido' => float, 'em_aberto' => float, 'vencida' => bool]]
     * Quita por vencimento (depois numero); vencida = em_aberto > 0 e vencimento < $hoje. O recebido além do total das
     * faturas fica como crédito da loja (não aparece aqui).
     */
    public static function faturas(array $faturas, float $recebido, string $hoje): array
    {
        $lista = [];
        foreach (array_values($faturas) as $ordem => $fatura) {
            $fatura  = (array) $fatura;
            $lista[] = [
                'uuid'       => (string) $fatura['uuid'],
                'centavos'   => max(0, static::centavos($fatura['total'] ?? 0)),
                'vencimento' => substr((string) $fatura['vencimento'], 0, 10),
                'numero'     => isset($fatura['numero']) ? (int) $fatura['numero'] : PHP_INT_MAX,
                'ordem'      => $ordem,
            ];
        }
        usort($lista, fn ($a, $b) => [$a['vencimento'], $a['numero'], $a['ordem']] <=> [$b['vencimento'], $b['numero'], $b['ordem']]);

        $caixa = max(0, static::centavos($recebido));
        $saida = [];
        foreach ($lista as $fatura) {
            $pago      = min($caixa, $fatura['centavos']);
            $caixa    -= $pago;
            $emAberto  = $fatura['centavos'] - $pago;

            $saida[$fatura['uuid']] = [
                'pagamento' => $emAberto === 0 ? 'paga' : ($pago > 0 ? 'parcial' : 'aberta'),
                'recebido'  => static::reais($pago),
                'em_aberto' => static::reais($emAberto),
                'vencida'   => $emAberto > 0 && $fatura['vencimento'] < $hoje,
            ];
        }

        return $saida;
    }

    /** Lançamento (objeto ou array, decimal em texto ou número) no formato interno. */
    private static function normalizar($lancamento): array
    {
        $lancamento = (array) $lancamento;

        return [
            'id'         => (int) $lancamento['id'],
            'tipo'       => (string) $lancamento['tipo'],
            'centavos'   => static::centavos($lancamento['valor'] ?? 0),
            'data'       => substr((string) ($lancamento['data_referencia'] ?? ''), 0, 10),
            'order_uuid' => ($lancamento['order_uuid'] ?? null) !== null && $lancamento['order_uuid'] !== '' ? (string) $lancamento['order_uuid'] : null,
            'estorno_de' => ($lancamento['estorno_de'] ?? null) !== null ? (int) $lancamento['estorno_de'] : null,
        ];
    }

    private static function reais(int $centavos): float
    {
        return round($centavos / 100, 2);
    }

    private static function centavos($valor): int
    {
        return (int) round(((float) $valor) * 100);
    }
}
