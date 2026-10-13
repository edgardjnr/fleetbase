<?php

namespace App\Support\Entregas\Financeiro;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Entregas RestaurantePro: acesso ao livro de lançamentos (entregas_lancamentos). Sem Model: DB::table; o MySQL devolve
 * os decimais como texto (use (float)). Nada se apaga nem se edita (só o fatura_uuid, pela B3): erro se corrige com
 * estorno, que é outro lançamento.
 */
final class Lancamentos
{
    public const TABELA = 'entregas_lancamentos';

    /** Campos obrigatórios de um lançamento. */
    private const OBRIGATORIOS = ['company_uuid', 'conta_tipo', 'conta_uuid', 'tipo', 'valor', 'data_referencia'];

    /**
     * Insere e devolve o id; com chave_unica repetida devolve null sem erro (insertOrIgnore; se o insert foi ignorado e a
     * chave não existe, lança \RuntimeException). Gera uuid e as datas (hora de Brasília, o fuso do app); `detalhes` em
     * array vira JSON.
     */
    public static function inserir(array $valores): ?int
    {
        foreach (static::OBRIGATORIOS as $campo) {
            if (!isset($valores[$campo]) || $valores[$campo] === '') {
                throw new \InvalidArgumentException("Lançamento sem {$campo}.");
            }
        }

        $agora  = now()->format('Y-m-d H:i:s');
        $linha  = array_merge($valores, [
            'uuid'       => (string) Str::uuid(),
            'valor'      => round((float) $valores['valor'], 2),
            'created_at' => $agora,
            'updated_at' => $agora,
        ]);
        if (isset($linha['detalhes']) && is_array($linha['detalhes'])) {
            $linha['detalhes'] = json_encode($linha['detalhes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (($linha['chave_unica'] ?? null) === null) {
            return (int) DB::table(static::TABELA)->insertGetId($linha);
        }

        if (DB::table(static::TABELA)->insertOrIgnore($linha) < 1) {
            // o INSERT IGNORE do MySQL engole qualquer erro (dado truncado, outra chave única): só a chave_unica que já
            // existe é "já lançado"; o resto é falha de verdade e não pode passar calada
            if (DB::table(static::TABELA)->where('chave_unica', $linha['chave_unica'])->exists()) {
                return null;
            }

            throw new \RuntimeException('Lançamento não gravado (o banco ignorou o insert): ' . $linha['chave_unica']);
        }

        return (int) DB::table(static::TABELA)->where('uuid', $linha['uuid'])->value('id');
    }

    public static function porId(string $companyUuid, int $id): ?object
    {
        return DB::table(static::TABELA)->where('company_uuid', $companyUuid)->where('id', $id)->first();
    }

    public static function porUuid(string $companyUuid, string $uuid): ?object
    {
        return DB::table(static::TABELA)->where('company_uuid', $companyUuid)->where('uuid', $uuid)->first();
    }

    /** Todos os lançamentos da conta, ordenados por data_referencia, id (a quitação precisa de todos). */
    public static function daConta(string $companyUuid, string $contaTipo, string $contaUuid): array
    {
        return DB::table(static::TABELA)
            ->where('company_uuid', $companyUuid)
            ->where('conta_tipo', $contaTipo)
            ->where('conta_uuid', $contaUuid)
            ->orderBy('data_referencia')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** Saldo de cada conta do tipo: [conta_uuid => float] (só as que têm lançamento). */
    public static function saldos(string $companyUuid, string $contaTipo): array
    {
        $saldos = [];
        $linhas = DB::table(static::TABELA)
            ->where('company_uuid', $companyUuid)
            ->where('conta_tipo', $contaTipo)
            ->groupBy('conta_uuid')
            ->selectRaw('conta_uuid, SUM(valor) as saldo')
            ->get();
        foreach ($linhas as $linha) {
            $saldos[(string) $linha->conta_uuid] = round((float) $linha->saldo, 2);
        }

        return $saldos;
    }

    /**
     * Cria o estorno (valor oposto, estorno_de, chave_unica 'estorno:<id>'); null se já estornado ou se for estorno.
     * Mesma conta, pedido, pagamento e detalhes; data de hoje no fuso da empresa. O estorno de um lançamento de loja que
     * está num rascunho entra no mesmo rascunho; o de lançamento de fatura emitida (ou sem fatura) fica sem fatura
     * (entra no próximo rascunho).
     *
     * $parte (opcional, em reais, positivo): estorna só essa parte do valor (o estorno automático do pedido cancelado
     * depois de um "Não pagar"/desconto parcial, LancadorDeCorridas::estornarCancelados). Sem ela, o valor inteiro.
     */
    public static function estornar(object $lancamento, ?string $usuarioUuid, ?string $motivo, ?float $parte = null): ?int
    {
        if ($lancamento->tipo === 'estorno') {
            return null;
        }

        $valor = round((float) $lancamento->valor, 2);
        if ($parte !== null) {
            $valor = ($valor < 0 ? -1 : 1) * min(abs($valor), round(abs($parte), 2));
        }

        // o estorno de um lançamento que está num rascunho entra no mesmo rascunho (senão a fatura sairia cobrando o que foi
        // estornado); a fatura fica travada até o fim, para não ser emitida no meio
        return DB::transaction(function () use ($lancamento, $usuarioUuid, $motivo, $valor) {
            return static::inserir([
                'company_uuid'    => $lancamento->company_uuid,
                'conta_tipo'      => $lancamento->conta_tipo,
                'conta_uuid'      => $lancamento->conta_uuid,
                'tipo'            => 'estorno',
                'valor'           => -$valor,
                'data_referencia' => Financeiro::hoje((string) $lancamento->company_uuid),
                'order_uuid'      => $lancamento->order_uuid ?? null,
                'pagamento_uuid'  => $lancamento->pagamento_uuid ?? null,
                'fatura_uuid'     => static::rascunhoDoLancamento($lancamento),
                'estorno_de'      => (int) $lancamento->id,
                'motivo'          => $motivo !== null && trim($motivo) !== '' ? mb_substr(trim($motivo), 0, 255) : null,
                'detalhes'        => $lancamento->detalhes ?? null,
                'chave_unica'     => 'estorno:' . (int) $lancamento->id,
                'criado_por'      => $usuarioUuid,
            ]);
        });
    }

    /** O uuid do rascunho em que o lançamento está (relido do banco, com a fatura travada); null se não está num rascunho. */
    private static function rascunhoDoLancamento(object $lancamento): ?string
    {
        if ($lancamento->conta_tipo !== Financeiro::CONTA_LOJA) {
            return null;
        }
        $faturaUuid = DB::table(static::TABELA)->where('company_uuid', $lancamento->company_uuid)->where('id', (int) $lancamento->id)->value('fatura_uuid');
        if ($faturaUuid === null) {
            return null;
        }
        $situacao = DB::table(Faturas::TABELA)
            ->where('company_uuid', $lancamento->company_uuid)
            ->where('uuid', $faturaUuid)
            ->lockForUpdate()
            ->value('situacao');

        return $situacao === 'rascunho' ? (string) $faturaUuid : null;
    }

    /** Os ids (da lista) que têm estorno. */
    public static function jaEstornados(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }

        $estornados = [];
        foreach (array_chunk($ids, 1000) as $lote) {
            foreach (DB::table(static::TABELA)->whereIn('estorno_de', $lote)->pluck('estorno_de') as $id) {
                $estornados[(int) $id] = true;
            }
        }

        return array_values(array_filter($ids, fn ($id) => isset($estornados[$id])));
    }
}
