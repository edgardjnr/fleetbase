<?php

namespace App\Support\Entregas;

use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: acesso à tabela entregas_valores_pedido, o valor congelado de cada entrega.
 * Quando congelar e quando recalcular é regra do CalculoEntregas; aqui só a leitura e a gravação.
 *
 * Fica fora do `meta` do pedido de propósito: o `meta` sai na API v1 (recurso Order) e nos eventos do socket, e aqui
 * estão o valor pago ao motoboy e o cobrado da loja.
 */
class ValoresCongelados
{
    public const TABELA = 'entregas_valores_pedido';

    /** Colunas regravadas quando o pedido já tem valor e o km mudou (a parte do km, com a segunda tabela). */
    public const COLUNAS_ATUALIZADAS = ['chave', 'metros', 'fonte', 'de_km', 'ate_km', 'acima', 'valor_motoboy', 'valor_loja', 'junto_de_km', 'junto_ate_km', 'junto_acima', 'junto_motoboy', 'junto_loja', 'updated_at'];

    /**
     * As colunas da linha (sem o id) que o gravarAtribuicoes envia. O lote mistura linhas lidas do banco e linhas montadas
     * pelo CalculoEntregas, e o upsert usa as colunas da primeira linha para todas: uma coluna nova na tabela (que só as
     * lidas do banco trariam) desalinharia os valores.
     */
    public const COLUNAS_DA_LINHA = [
        'company_uuid', 'order_uuid', 'chave', 'metros', 'fonte', 'de_km', 'ate_km', 'acima', 'valor_motoboy', 'valor_loja',
        'junto_de_km', 'junto_ate_km', 'junto_acima', 'junto_motoboy', 'junto_loja',
        ...ValorDaEntrega::COLUNAS_DA_ATRIBUICAO,
        'created_at', 'updated_at',
    ];

    /**
     * Linhas dos pedidos, indexadas pelo order_uuid (arrays, com os decimais como o banco devolve: texto).
     *
     * @param array<int, string|null> $orderUuids
     */
    public function carregar(array $orderUuids): array
    {
        $orderUuids = array_values(array_unique(array_filter($orderUuids)));
        $linhas     = [];

        // em lotes: o relatório de 3 meses da central pode passar de mil pedidos
        foreach (array_chunk($orderUuids, 1000) as $lote) {
            foreach (DB::table(static::TABELA)->whereIn('order_uuid', $lote)->get() as $linha) {
                $linhas[$linha->order_uuid] = (array) $linha;
            }
        }

        return $linhas;
    }

    /** Grava as linhas novas e regrava as dos pedidos cujo km mudou: um upsert por lote. */
    public function gravar(array $linhas): void
    {
        if (!$linhas) {
            return;
        }

        $agora  = now();
        $linhas = array_map(fn (array $linha) => $linha + ['created_at' => $agora, 'updated_at' => $agora], array_values($linhas));

        foreach (array_chunk($linhas, 500) as $lote) {
            DB::table(static::TABELA)->upsert($lote, ['order_uuid'], static::COLUNAS_ATUALIZADAS);
        }
    }

    /**
     * Grava a parte da atribuição (ValorDaEntrega::COLUNAS_DA_ATRIBUICAO) de linhas completas (a parte do km junto, para o
     * caso de a linha ainda não existir). Numa linha que já existe, só a atribuição muda: o km fica como está.
     */
    public function gravarAtribuicoes(array $linhas): void
    {
        if (!$linhas) {
            return;
        }

        $agora  = now();
        $colunas = array_flip(static::COLUNAS_DA_LINHA);
        $linhas  = array_map(function (array $linha) use ($agora, $colunas) {
            // linhas lidas do banco trazem o id, as datas e qualquer coluna futura: só as COLUNAS_DA_LINHA vão, o
            // created_at só vale para a linha nova e o updated_at é o de agora
            $linha['created_at'] = $agora;
            $linha['updated_at'] = $agora;

            return array_intersect_key($linha, $colunas);
        }, array_values($linhas));

        foreach (array_chunk($linhas, 500) as $lote) {
            DB::table(static::TABELA)->upsert($lote, ['order_uuid'], array_merge(ValorDaEntrega::COLUNAS_DA_ATRIBUICAO, ['updated_at']));
        }
    }
}
