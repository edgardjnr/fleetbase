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
     * Insere e devolve o id; com chave_unica repetida devolve null sem erro (insertOrIgnore). Gera uuid e as datas (hora
     * de Brasília, o fuso do app); `detalhes` em array vira JSON.
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
            return null;
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
     * Mesma conta, pedido, pagamento e detalhes; data de hoje no fuso da empresa. O estorno de um lançamento de loja
     * fica sem fatura (entra no próximo rascunho).
     */
    public static function estornar(object $lancamento, ?string $usuarioUuid, ?string $motivo): ?int
    {
        if ($lancamento->tipo === 'estorno') {
            return null;
        }

        return static::inserir([
            'company_uuid'    => $lancamento->company_uuid,
            'conta_tipo'      => $lancamento->conta_tipo,
            'conta_uuid'      => $lancamento->conta_uuid,
            'tipo'            => 'estorno',
            'valor'           => -round((float) $lancamento->valor, 2),
            'data_referencia' => Financeiro::hoje((string) $lancamento->company_uuid),
            'order_uuid'      => $lancamento->order_uuid ?? null,
            'pagamento_uuid'  => $lancamento->pagamento_uuid ?? null,
            'estorno_de'      => (int) $lancamento->id,
            'motivo'          => $motivo !== null && trim($motivo) !== '' ? mb_substr(trim($motivo), 0, 255) : null,
            'detalhes'        => $lancamento->detalhes ?? null,
            'chave_unica'     => 'estorno:' . (int) $lancamento->id,
            'criado_por'      => $usuarioUuid,
        ]);
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
