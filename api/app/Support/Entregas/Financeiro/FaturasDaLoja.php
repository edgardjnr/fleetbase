<?php

namespace App\Support\Entregas\Financeiro;

use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: as faturas da loja como o portal e os e-mails as mostram (leitura direta de entregas_faturas e
 * entregas_lancamentos, DB::table). A situação (paga, parcial, aberta, vencida) vem do Quitacao::faturas com os
 * recebimentos da loja; nada é gravado aqui. Formatos do contrato (docs/superpowers/plans/2026-10-12-financeiro.md, 1.5):
 * "Fatura" e "Lancamento" públicos (sem email_enviado_em, observacao_interna e criado_por) e o `ciclo_atual` do portal.
 *
 * Escrito na B4 sem depender da B3 (Faturas, CicloDeCobranca): na integração, a situação e o resumo podem passar a
 * Faturas::situacoesDaLoja e Faturas::resumo (mesmo formato), e o ciclo da loja a Faturas::configDaLoja.
 */
final class FaturasDaLoja
{
    public const TABELA = 'entregas_faturas';

    /** Situações gravadas que a loja vê (o rascunho nunca chega ao portal nem ao e-mail). */
    public const VISIVEIS = ['emitida', 'cancelada'];

    /** Chaves de `detalhes` que saem para a loja (o resto, como o pedido e o número, vai em `pedido`). */
    private const DETALHES_PUBLICOS = ['km', 'faixa', 'loja_nome', 'destino', 'cancelado_pago', 'cancelado_origem'];

    /** A classe da B3 (por nome: na worktree da B4 ela ainda não existe e o imports-dos-providers recusaria o ::class). */
    private const CICLO_DE_COBRANCA = 'App\\Support\\Entregas\\Financeiro\\CicloDeCobranca';

    public static function porUuid(string $companyUuid, string $uuid): ?object
    {
        return DB::table(static::TABELA)->where('company_uuid', $companyUuid)->where('uuid', $uuid)->first();
    }

    /** As faturas da loja nas situações pedidas, mais nova primeiro (fim do período, depois id). */
    public static function daLoja(string $companyUuid, string $vendorUuid, array $situacoes): array
    {
        return DB::table(static::TABELA)
            ->where('company_uuid', $companyUuid)
            ->where('vendor_uuid', $vendorUuid)
            ->whereIn('situacao', $situacoes)
            ->orderBy('periodo_fim', 'desc')
            ->orderBy('id', 'desc')
            ->get()
            ->all();
    }

    /** Recebimento ou estorno de recebimento (o estorno copia o pagamento_uuid): mexe no recebido, nunca entra em fatura. */
    public static function ehRecebimento(object $lancamento): bool
    {
        return $lancamento->tipo === 'recebimento'
            || ($lancamento->tipo === 'estorno' && ($lancamento->pagamento_uuid ?? null) !== null && $lancamento->pagamento_uuid !== '');
    }

    /** Soma positiva dos recebimentos menos os estornos de recebimento (a entrada do Quitacao::faturas). */
    public static function recebido(array $lancamentos): float
    {
        $centavos = 0;
        foreach ($lancamentos as $lancamento) {
            if (static::ehRecebimento($lancamento)) {
                $centavos -= (int) round(((float) $lancamento->valor) * 100);
            }
        }

        return round($centavos / 100, 2);
    }

    /**
     * Situação de cada fatura emitida da loja: [uuid => ['pagamento', 'recebido', 'em_aberto', 'vencida']]
     * (Quitacao::faturas, hoje no fuso da empresa). $lancamentos: os da conta da loja, se já carregados.
     */
    public static function situacoes(string $companyUuid, string $vendorUuid, ?array $lancamentos = null): array
    {
        $lancamentos ??= Lancamentos::daConta($companyUuid, Financeiro::CONTA_LOJA, $vendorUuid);
        $emitidas = array_map(fn ($fatura) => [
            'uuid'       => $fatura->uuid,
            'total'      => (float) $fatura->total,
            'vencimento' => $fatura->vencimento,
            'numero'     => $fatura->numero,
        ], static::daLoja($companyUuid, $vendorUuid, ['emitida']));

        return Quitacao::faturas($emitidas, static::recebido($lancamentos), Financeiro::hoje($companyUuid));
    }

    /** Corridas de cada fatura: [fatura_uuid => quantidade]. */
    public static function entregasPorFatura(array $lancamentos): array
    {
        $porFatura = [];
        foreach ($lancamentos as $lancamento) {
            if ($lancamento->tipo === 'corrida' && ($lancamento->fatura_uuid ?? null) !== null) {
                $porFatura[$lancamento->fatura_uuid] = ($porFatura[$lancamento->fatura_uuid] ?? 0) + 1;
            }
        }

        return $porFatura;
    }

    /**
     * A fatura no formato "Fatura" público: sem email_enviado_em. A cancelada não tem pagamento, não vence e não tem nada
     * em aberto. $loja = ['id' => vendor public_id, 'nome' => nome].
     */
    public static function publica(object $fatura, array $situacoes, array $loja, int $entregas, string $fuso): array
    {
        $total    = round((float) $fatura->total, 2);
        $emitida  = $fatura->situacao === 'emitida';
        $situacao = $emitida ? ($situacoes[$fatura->uuid] ?? ['pagamento' => 'aberta', 'recebido' => 0.0, 'em_aberto' => $total, 'vencida' => false]) : null;

        return [
            'id'             => $fatura->uuid,
            'numero'         => Financeiro::numeroDaFatura($fatura->numero === null ? null : (int) $fatura->numero),
            'loja'           => ['id' => $loja['id'] ?? null, 'nome' => $loja['nome'] ?? null],
            'periodo_inicio' => substr((string) $fatura->periodo_inicio, 0, 10),
            'periodo_fim'    => substr((string) $fatura->periodo_fim, 0, 10),
            'vencimento'     => substr((string) $fatura->vencimento, 0, 10),
            'situacao'       => $fatura->situacao,
            'pagamento'      => $situacao['pagamento'] ?? null,
            'vencida'        => (bool) ($situacao['vencida'] ?? false),
            'total'          => $total,
            'recebido'       => (float) ($situacao['recebido'] ?? 0.0),
            'em_aberto'      => (float) ($situacao['em_aberto'] ?? 0.0),
            'entregas'       => $entregas,
            'emitida_em'     => static::instante($fatura->emitida_em ?? null, $fuso),
        ];
    }

    /** Os itens da fatura (lançamentos ligados), mais antigos primeiro, no formato "Lancamento" público. */
    public static function itensPublicos(object $fatura, string $fuso): array
    {
        $itens = DB::table(Lancamentos::TABELA)
            ->where('company_uuid', $fatura->company_uuid)
            ->where('conta_tipo', Financeiro::CONTA_LOJA)
            ->where('conta_uuid', $fatura->vendor_uuid)
            ->where('fatura_uuid', $fatura->uuid)
            ->orderBy('data_referencia')
            ->orderBy('id')
            ->get()
            ->all();
        if (!$itens) {
            return [];
        }

        $estornados = array_flip(Lancamentos::jaEstornados(array_map(fn ($item) => (int) $item->id, $itens)));
        $detalhes   = [];
        $semPedido  = [];
        foreach ($itens as $item) {
            $detalhes[$item->id] = static::detalhes($item->detalhes ?? null);
            if (($item->order_uuid ?? null) !== null && empty($detalhes[$item->id]['pedido'])) {
                $semPedido[] = $item->order_uuid;
            }
        }
        $pedidos = static::pedidos($semPedido);
        $numero  = Financeiro::numeroDaFatura($fatura->numero === null ? null : (int) $fatura->numero);

        return array_map(function ($item) use ($detalhes, $pedidos, $estornados, $fatura, $numero, $fuso) {
            $todos   = $detalhes[$item->id];
            $pedido  = null;
            if (($item->order_uuid ?? null) !== null) {
                $pedido = !empty($todos['pedido'])
                    ? ['id' => (string) $todos['pedido'], 'numero' => isset($todos['numero']) ? (string) $todos['numero'] : null]
                    : ($pedidos[$item->order_uuid] ?? null);
            }
            $publicos = $todos === null ? null : array_intersect_key($todos, array_flip(static::DETALHES_PUBLICOS));
            if (isset($publicos['km'])) {
                $publicos['km'] = (float) $publicos['km'];
            }
            $motivo = trim((string) ($item->motivo ?? ''));

            return [
                'id'         => (int) $item->id,
                'tipo'       => $item->tipo,
                'valor'      => round((float) $item->valor, 2),
                'data'       => substr((string) $item->data_referencia, 0, 10),
                'criado_em'  => static::instante($item->created_at ?? null, $fuso),
                'pedido'     => $pedido,
                'detalhes'   => $publicos ?: null,
                'motivo'     => $motivo === '' ? null : $motivo,
                // a situação de cada item não vale na conta da loja: quem quita é a fatura
                'situacao'   => null,
                'valor_pago' => null,
                'pagamento'  => null,
                'estorno_de' => ($item->estorno_de ?? null) === null ? null : (int) $item->estorno_de,
                'estornado'  => isset($estornados[(int) $item->id]),
                'fatura'     => ['id' => $fatura->uuid, 'numero' => $numero],
            ];
        }, $itens);
    }

    /** O ciclo de cobrança da loja ({ciclo, dia_fechamento, prazo_dias}, em números): o dela ou o padrão da empresa. */
    public static function configDaLoja(string $companyUuid, string $vendorUuid): array
    {
        $linha = DB::table('entregas_cobranca_lojas')->where('company_uuid', $companyUuid)->where('vendor_uuid', $vendorUuid)->first();
        if (!$linha) {
            return Financeiro::cobrancaPadrao($companyUuid);
        }

        return ['ciclo' => (string) $linha->ciclo, 'dia_fechamento' => (int) $linha->dia_fechamento, 'prazo_dias' => (int) $linha->prazo_dias];
    }

    /** O período do ciclo da loja que contém $hoje (CicloDeCobranca::periodoQueContem, da B3); null sem a classe ou em erro. */
    public static function periodoEmAndamento(string $companyUuid, string $vendorUuid, string $hoje): ?array
    {
        $ciclo = static::CICLO_DE_COBRANCA;
        if (!class_exists($ciclo)) {
            return null;
        }

        try {
            $periodo = $ciclo::periodoQueContem(static::configDaLoja($companyUuid, $vendorUuid), $hoje);
        } catch (\Throwable $erro) {
            return null;
        }

        return isset($periodo['inicio'], $periodo['fim']) ? ['inicio' => (string) $periodo['inicio'], 'fim' => (string) $periodo['fim']] : null;
    }

    /**
     * O ciclo em andamento do portal (função pura): os lançamentos da loja ainda sem fatura, menos recebimentos (vão para
     * a próxima fatura). `entregas` = corridas não estornadas; `valor` = soma (com ajustes e estornos). `inicio` = o
     * início do ciclo da loja ou o lançamento sem fatura mais antigo, o que vier antes (uma corrida atrasada ou de fatura
     * cancelada também entra); `fim` = fim do ciclo. Sem o ciclo ($periodo null): do mais antigo (ou hoje) até hoje.
     */
    public static function cicloAtual(array $lancamentos, string $hoje, ?array $periodo): array
    {
        $estornados = [];
        foreach ($lancamentos as $lancamento) {
            if (($lancamento->estorno_de ?? null) !== null) {
                $estornados[(int) $lancamento->estorno_de] = true;
            }
        }

        $entregas  = 0;
        $centavos  = 0;
        $maisAntigo = null;
        foreach ($lancamentos as $lancamento) {
            if (($lancamento->fatura_uuid ?? null) !== null || static::ehRecebimento($lancamento)) {
                continue;
            }
            $centavos += (int) round(((float) $lancamento->valor) * 100);
            if ($lancamento->tipo === 'corrida' && !isset($estornados[(int) $lancamento->id])) {
                $entregas++;
            }
            $dia = substr((string) $lancamento->data_referencia, 0, 10);
            if ($maisAntigo === null || $dia < $maisAntigo) {
                $maisAntigo = $dia;
            }
        }

        $inicio = $periodo['inicio'] ?? $hoje;
        if ($maisAntigo !== null && ($periodo === null || $maisAntigo < $inicio)) {
            $inicio = $maisAntigo;
        }

        return [
            'inicio'   => $inicio,
            'fim'      => $periodo['fim'] ?? $hoje,
            'entregas' => $entregas,
            'valor'    => round($centavos / 100, 2),
        ];
    }

    /** Texto do banco ('Y-m-d H:i:s', hora do fuso do app) em ISO 8601 no fuso da empresa; null sem valor. */
    public static function instante(?string $texto, string $fuso): ?string
    {
        if ($texto === null || $texto === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($texto, new \DateTimeZone(date_default_timezone_get())))->setTimezone(new \DateTimeZone($fuso))->format('Y-m-d\TH:i:sP');
        } catch (\Throwable $erro) {
            return null;
        }
    }

    /** `detalhes` do lançamento em array (o MySQL devolve o JSON em texto); null sem detalhes. */
    private static function detalhes($detalhes): ?array
    {
        if (is_string($detalhes)) {
            $detalhes = json_decode($detalhes, true);
        }

        return is_array($detalhes) && $detalhes ? $detalhes : null;
    }

    /** [order_uuid => ['id' => public_id, 'numero' => internal_id]] dos pedidos (lançamentos sem os detalhes da corrida). */
    private static function pedidos(array $uuids): array
    {
        $uuids = array_values(array_unique($uuids));
        if (!$uuids) {
            return [];
        }

        $pedidos = [];
        foreach (DB::table('orders')->whereIn('uuid', $uuids)->get() as $pedido) {
            $pedidos[$pedido->uuid] = ['id' => (string) $pedido->public_id, 'numero' => ($pedido->internal_id ?? null) === null ? null : (string) $pedido->internal_id];
        }

        return $pedidos;
    }
}
