<?php

namespace App\Support\Entregas\Financeiro;

use App\Support\Entregas\PrePago\PrePago;
use Fleetbase\FleetOps\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Entregas RestaurantePro: as faturas das lojas (entregas_faturas) e o ciclo de cobrança de cada loja
 * (entregas_cobranca_lojas). Sem Model: DB::table.
 *
 * - Itens = lançamentos da conta da loja com `fatura_uuid` da fatura (o lançamento é imutável; a ligação é o retrato).
 * - Rascunho: junta os lançamentos da loja ainda sem fatura com data até o fim do período (os atrasados também), menos
 *   recebimentos e estornos de recebimento (lançamentos com `pagamento_uuid`). Não cria vazio nem repete o fim do período
 *   de um rascunho ou emitida da loja.
 * - Conferência (só no rascunho): ajuste avulso, "Não cobrar" (ajuste negativo do valor inteiro da corrida, com o pedido)
 *   e vencimento.
 * - Emitir: número sequencial por empresa (FAT-0001, nunca reaproveitado) e total gravado.
 * - Cancelar (rascunho ou emitida): solta os lançamentos, que entram no próximo rascunho; o número fica.
 * - Apagar (só rascunho, para gerar de novo): solta os lançamentos e apaga os ajustes feitos na conferência dele.
 * - Situação da emitida (aberta, parcial, paga, vencida) calculada na hora pelos recebimentos da loja (Quitacao::faturas).
 *
 * Erros de entrada lançam \InvalidArgumentException; operação que a situação da fatura não permite (ex.: ajuste em fatura
 * emitida) lança \DomainException. As duas com a mensagem em pt-BR (o controller devolve 422).
 */
final class Faturas
{
    public const TABELA = 'entregas_faturas';

    public const TABELA_DOS_CICLOS = 'entregas_cobranca_lojas';

    /** Motivo do ajuste "Não cobrar" (a loja vê). */
    public const MOTIVO_NAO_COBRAR = 'Entrega não cobrada';

    /** Maior valor de um ajuste, em reais (de um lado ou de outro). */
    public const AJUSTE_MAXIMO = 1000000;

    /** Ciclo da loja ({ciclo, dia_fechamento, prazo_dias}) ou o padrão da empresa, com ['padrao' => bool]. */
    public static function configDaLoja(string $companyUuid, string $vendorUuid): array
    {
        $linha = DB::table(static::TABELA_DOS_CICLOS)->where('company_uuid', $companyUuid)->where('vendor_uuid', $vendorUuid)->first();

        return static::configOuPadrao($companyUuid, $linha);
    }

    /** Grava o ciclo da loja (valida antes: \InvalidArgumentException com a mensagem do CicloDeCobranca::validar). */
    public static function salvarConfig(string $companyUuid, string $vendorUuid, array $config): void
    {
        if ($erro = CicloDeCobranca::validar($config)) {
            throw new \InvalidArgumentException($erro);
        }
        $config = CicloDeCobranca::normalizar($config);
        $agora  = now()->format('Y-m-d H:i:s');

        $existe = DB::table(static::TABELA_DOS_CICLOS)->where('company_uuid', $companyUuid)->where('vendor_uuid', $vendorUuid)->exists();
        if ($existe) {
            DB::table(static::TABELA_DOS_CICLOS)->where('company_uuid', $companyUuid)->where('vendor_uuid', $vendorUuid)->update($config + ['updated_at' => $agora]);

            return;
        }
        DB::table(static::TABELA_DOS_CICLOS)->insert($config + ['company_uuid' => $companyUuid, 'vendor_uuid' => $vendorUuid, 'created_at' => $agora, 'updated_at' => $agora]);
    }

    /**
     * Rascunho com os lançamentos da loja sem fatura e data_referencia <= $fim (exceto recebimento/estorno de recebimento).
     * null se não há lançamento ou se já existe rascunho/emitida com o mesmo periodo_fim. Vencimento = fim + prazo do ciclo.
     */
    public static function gerarRascunho(string $companyUuid, string $vendorUuid, string $inicio, string $fim, ?string $usuarioUuid): ?object
    {
        static::exigirData($inicio, 'Data de início inválida.');
        static::exigirData($fim, 'Data de fim inválida.');
        if ($inicio > $fim) {
            throw new \InvalidArgumentException('O início do período precisa ser antes do fim.');
        }
        if (PrePago::ehPrePaga($companyUuid, $vendorUuid)) {
            throw new \InvalidArgumentException('Loja pré-paga não recebe fatura: as corridas saem do crédito da carteira.');
        }

        return DB::transaction(function () use ($companyUuid, $vendorUuid, $inicio, $fim) {
            $repetida = DB::table(static::TABELA)
                ->where('company_uuid', $companyUuid)
                ->where('vendor_uuid', $vendorUuid)
                ->where('periodo_fim', $fim)
                ->whereIn('situacao', ['rascunho', 'emitida'])
                ->lockForUpdate()
                ->value('id');
            if ($repetida !== null) {
                return null;
            }

            $ids = static::naoFaturados($companyUuid)
                ->where('conta_uuid', $vendorUuid)
                ->where('data_referencia', '<=', $fim)
                ->lockForUpdate()
                ->pluck('id')
                ->all();
            if (!$ids) {
                return null;
            }

            $uuid  = (string) Str::uuid();
            $agora = now()->format('Y-m-d H:i:s');
            DB::table(static::TABELA)->insert([
                'uuid'           => $uuid,
                'company_uuid'   => $companyUuid,
                'vendor_uuid'    => $vendorUuid,
                'numero'         => null,
                'periodo_inicio' => $inicio,
                'periodo_fim'    => $fim,
                'vencimento'     => CicloDeCobranca::vencimento(static::configDaLoja($companyUuid, $vendorUuid), $fim),
                'situacao'       => 'rascunho',
                'total'          => null,
                'created_at'     => $agora,
                'updated_at'     => $agora,
            ]);
            DB::table(Lancamentos::TABELA)
                ->where('company_uuid', $companyUuid)
                ->whereIn('id', array_map('intval', $ids))
                ->whereNull('fatura_uuid')
                ->update(['fatura_uuid' => $uuid, 'updated_at' => $agora]);

            return static::porUuid($companyUuid, $uuid);
        });
    }

    public static function porUuid(string $companyUuid, string $uuid): ?object
    {
        return DB::table(static::TABELA)->where('company_uuid', $companyUuid)->where('uuid', $uuid)->first();
    }

    /** Os lançamentos ligados à fatura, mais antigos primeiro (data_referencia, id). */
    public static function itens(object $fatura): array
    {
        return DB::table(Lancamentos::TABELA)
            ->where('company_uuid', $fatura->company_uuid)
            ->where('fatura_uuid', $fatura->uuid)
            ->orderBy('data_referencia')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** Emite o rascunho: número sequencial da empresa (com trava) e total gravado. Devolve a fatura relida. */
    public static function emitir(object $fatura, ?string $usuarioUuid): object
    {
        return DB::transaction(function () use ($fatura, $usuarioUuid) {
            $atual = static::rascunhoTravado($fatura, 'A fatura já foi emitida.');
            $itens = static::itens($atual);
            if (!$itens) {
                throw new \DomainException('A fatura não tem lançamentos.');
            }
            // total zero ou negativo (tudo "Não cobrar", descontos): nada a cobrar, a fatura não é emitida nem enviada
            $total = static::soma($itens);
            if ($total <= 0) {
                throw new \DomainException('Fatura sem valor a cobrar; cancele o rascunho.');
            }

            // o maior número da empresa, travado até o fim da transação (duas emissões ao mesmo tempo não repetem)
            $ultimo = DB::table(static::TABELA)
                ->where('company_uuid', $atual->company_uuid)
                ->whereNotNull('numero')
                ->orderBy('numero', 'desc')
                ->lockForUpdate()
                ->value('numero');
            $agora = now()->format('Y-m-d H:i:s');
            DB::table(static::TABELA)->where('id', $atual->id)->update([
                'numero'      => (int) $ultimo + 1,
                'total'       => $total,
                'situacao'    => 'emitida',
                'emitida_em'  => $agora,
                'emitida_por' => $usuarioUuid,
                'updated_at'  => $agora,
            ]);

            return static::porUuid($atual->company_uuid, $atual->uuid);
        });
    }

    /** Cancela (rascunho ou emitida) e solta os lançamentos para o próximo rascunho. O número da emitida fica. */
    public static function cancelar(object $fatura, ?string $usuarioUuid): void
    {
        DB::transaction(function () use ($fatura, $usuarioUuid) {
            $atual = static::travada($fatura);
            if ($atual->situacao === 'cancelada') {
                throw new \DomainException('A fatura já foi cancelada.');
            }

            $agora = now()->format('Y-m-d H:i:s');
            DB::table(static::TABELA)->where('id', $atual->id)->update([
                'situacao'      => 'cancelada',
                'cancelada_em'  => $agora,
                'cancelada_por' => $usuarioUuid,
                'updated_at'    => $agora,
            ]);
            DB::table(Lancamentos::TABELA)
                ->where('company_uuid', $atual->company_uuid)
                ->where('fatura_uuid', $atual->uuid)
                ->update(['fatura_uuid' => null, 'updated_at' => $agora]);
        });
    }

    /**
     * Apaga o rascunho (feito errado ou de teste) para gerar de novo. Os lançamentos que ele juntou voltam a ficar sem
     * fatura (entram no próximo rascunho); os ajustes feitos na conferência dele (avulso e "Não cobrar": criados depois
     * do segundo em que o rascunho nasceu e já com ele) e os estornos desses ajustes são apagados junto. O ajuste lançado
     * no extrato antes do rascunho (mesmo no mesmo segundo) fica. Só rascunho: a emitida a loja já viu
     * (cancela-se). A única exceção ao "nada se apaga" do Financeiro: o rascunho nunca saiu do console.
     */
    public static function apagar(object $fatura): void
    {
        DB::transaction(function () use ($fatura) {
            $atual   = static::rascunhoTravado($fatura, 'A fatura já foi emitida: cancele em vez de apagar.');
            $ajustes = array_map('intval', DB::table(Lancamentos::TABELA)
                ->where('company_uuid', $atual->company_uuid)
                ->where('fatura_uuid', $atual->uuid)
                ->where('tipo', 'ajuste')
                ->where('created_at', '>', $atual->created_at)
                ->lockForUpdate()
                ->pluck('id')
                ->all());
            if ($ajustes) {
                DB::table(Lancamentos::TABELA)
                    ->where('company_uuid', $atual->company_uuid)
                    ->whereIn('estorno_de', $ajustes)
                    ->delete();
                DB::table(Lancamentos::TABELA)
                    ->where('company_uuid', $atual->company_uuid)
                    ->whereIn('id', $ajustes)
                    ->delete();
            }
            DB::table(Lancamentos::TABELA)
                ->where('company_uuid', $atual->company_uuid)
                ->where('fatura_uuid', $atual->uuid)
                ->update(['fatura_uuid' => null, 'updated_at' => now()->format('Y-m-d H:i:s')]);
            DB::table(static::TABELA)->where('id', $atual->id)->delete();
        });
    }

    /**
     * Ajuste avulso no rascunho (desconto, taxa, mensalidade): positivo cobra a mais, negativo desconta. O motivo aparece
     * para a loja. Data = fim do período. Devolve o id do lançamento.
     */
    public static function ajuste(object $fatura, float $valor, string $motivo, ?string $obs, ?string $usuarioUuid): int
    {
        $valor  = round($valor, 2);
        $motivo = trim($motivo);
        if (!is_finite($valor) || $valor == 0) {
            throw new \InvalidArgumentException('O valor do ajuste não pode ser zero.');
        }
        if (abs($valor) > static::AJUSTE_MAXIMO) {
            throw new \InvalidArgumentException('Valor do ajuste alto demais.');
        }
        if ($motivo === '') {
            throw new \InvalidArgumentException('Informe o motivo do ajuste (a loja vê este texto).');
        }
        $obs = $obs !== null && trim($obs) !== '' ? mb_substr(trim($obs), 0, 500) : null;

        return DB::transaction(function () use ($fatura, $valor, $motivo, $obs, $usuarioUuid) {
            $atual = static::rascunhoTravado($fatura);

            return (int) Lancamentos::inserir([
                'company_uuid'       => $atual->company_uuid,
                'conta_tipo'         => Financeiro::CONTA_LOJA,
                'conta_uuid'         => $atual->vendor_uuid,
                'tipo'               => 'ajuste',
                'valor'              => $valor,
                'data_referencia'    => substr((string) $atual->periodo_fim, 0, 10),
                'fatura_uuid'        => $atual->uuid,
                'motivo'             => mb_substr($motivo, 0, 255),
                'observacao_interna' => $obs,
                'criado_por'         => $usuarioUuid,
            ]);
        });
    }

    /**
     * "Não cobrar" uma corrida do rascunho: ajuste negativo do valor inteiro, com o pedido (a Quitacao abate a corrida) e
     * os detalhes dela, na própria fatura. Devolve o id do lançamento.
     */
    public static function naoCobrar(object $fatura, object $lancamento, ?string $usuarioUuid): int
    {
        return DB::transaction(function () use ($fatura, $lancamento, $usuarioUuid) {
            $atual   = static::rascunhoTravado($fatura);
            $corrida = Lancamentos::porId((string) $atual->company_uuid, (int) $lancamento->id);
            if (!$corrida || $corrida->fatura_uuid !== $atual->uuid) {
                throw new \DomainException('Este lançamento não está nesta fatura.');
            }
            if ($corrida->tipo !== 'corrida' || empty($corrida->order_uuid)) {
                throw new \DomainException('Só uma entrega (corrida) pode ser marcada como não cobrada.');
            }
            $jaMarcada = DB::table(Lancamentos::TABELA)
                ->where('company_uuid', $atual->company_uuid)
                ->where('fatura_uuid', $atual->uuid)
                ->where('tipo', 'ajuste')
                ->where('order_uuid', $corrida->order_uuid)
                ->where('valor', '<', 0)
                ->exists();
            if ($jaMarcada) {
                throw new \DomainException('Esta entrega já está marcada como não cobrada.');
            }
            if (Lancamentos::jaEstornados([(int) $corrida->id])) {
                throw new \DomainException('Esta entrega foi estornada.');
            }

            return (int) Lancamentos::inserir([
                'company_uuid'    => $atual->company_uuid,
                'conta_tipo'      => Financeiro::CONTA_LOJA,
                'conta_uuid'      => $atual->vendor_uuid,
                'tipo'            => 'ajuste',
                'valor'           => -round((float) $corrida->valor, 2),
                'data_referencia' => substr((string) $corrida->data_referencia, 0, 10),
                'order_uuid'      => $corrida->order_uuid,
                'fatura_uuid'     => $atual->uuid,
                'motivo'          => static::MOTIVO_NAO_COBRAR,
                'detalhes'        => $corrida->detalhes ?? null,
                'criado_por'      => $usuarioUuid,
            ]);
        });
    }

    /** Novo vencimento do rascunho ('Y-m-d', não antes do fim do período). */
    public static function mudarVencimento(object $fatura, string $vencimento): void
    {
        static::exigirData($vencimento, 'Data de vencimento inválida.');
        if ($vencimento < substr((string) $fatura->periodo_fim, 0, 10)) {
            throw new \InvalidArgumentException('O vencimento não pode ser antes do fim do período.');
        }

        DB::transaction(function () use ($fatura, $vencimento) {
            $atual = static::rascunhoTravado($fatura);
            DB::table(static::TABELA)->where('id', $atual->id)->update(['vencimento' => $vencimento, 'updated_at' => now()->format('Y-m-d H:i:s')]);
        });
    }

    /**
     * Formato JSON "Fatura" (plano, 1.5) com a situação calculada. $situacoes = situacoesDaLoja (sem a fatura nela, lê do
     * banco). Sem o nome ou o id público da loja, lê do Vendor. $itens = os da fatura, se já lidos (listas).
     * Público (portal): sem email_enviado_em.
     */
    public static function resumo(object $fatura, array $situacoes, ?string $lojaNome, bool $publico, ?string $lojaId = null, ?array $itens = null): array
    {
        $companyUuid = (string) $fatura->company_uuid;
        if ($lojaNome === null || $lojaId === null) {
            $vendor   = Vendor::where('uuid', $fatura->vendor_uuid)->first();
            $lojaNome = $lojaNome ?? ($vendor ? (string) $vendor->name : null);
            $lojaId   = $lojaId ?? ($vendor ? (string) $vendor->public_id : null);
        }
        $itens ??= static::itens($fatura);

        $situacao = (string) $fatura->situacao;
        $total    = $situacao === 'rascunho' ? static::soma($itens) : round((float) ($fatura->total ?? 0), 2);
        $quitacao = null;
        if ($situacao === 'emitida') {
            $quitacao = $situacoes[$fatura->uuid] ?? (static::situacoesDaLoja($companyUuid, (string) $fatura->vendor_uuid)[$fatura->uuid] ?? null);
        }
        $fuso = Financeiro::fuso($companyUuid);

        $resumo = [
            'id'               => (string) $fatura->uuid,
            'numero'           => Financeiro::numeroDaFatura($fatura->numero !== null ? (int) $fatura->numero : null),
            'loja'             => ['id' => $lojaId, 'nome' => $lojaNome],
            'periodo_inicio'   => substr((string) $fatura->periodo_inicio, 0, 10),
            'periodo_fim'      => substr((string) $fatura->periodo_fim, 0, 10),
            'vencimento'       => substr((string) $fatura->vencimento, 0, 10),
            'situacao'         => $situacao,
            'pagamento'        => $situacao === 'emitida' ? ($quitacao['pagamento'] ?? 'aberta') : null,
            'vencida'          => $situacao === 'emitida' ? (bool) ($quitacao['vencida'] ?? false) : false,
            'total'            => $total,
            'recebido'         => $situacao === 'emitida' ? (float) ($quitacao['recebido'] ?? 0) : 0.0,
            'em_aberto'        => $situacao === 'emitida' ? (float) ($quitacao['em_aberto'] ?? $total) : 0.0,
            'entregas'         => count(array_filter($itens, fn ($item) => $item->tipo === 'corrida')),
            'emitida_em'       => static::instante($fatura->emitida_em ?? null, $fuso),
            'email_enviado_em' => static::instante($fatura->email_enviado_em ?? null, $fuso),
        ];
        if ($publico) {
            unset($resumo['email_enviado_em']);
        }

        return $resumo;
    }

    /** Várias faturas da empresa no formato "Fatura", com as lojas, as situações e os itens lidos de uma vez. */
    public static function resumos(string $companyUuid, array $faturas, bool $publico): array
    {
        if (!$faturas) {
            return [];
        }

        $vendorUuids = array_values(array_unique(array_map(fn ($f) => (string) $f->vendor_uuid, $faturas)));
        $lojas       = [];
        foreach (Vendor::whereIn('uuid', $vendorUuids)->get() as $vendor) {
            $lojas[(string) $vendor->uuid] = $vendor;
        }
        $situacoes = static::situacoesDaEmpresa($companyUuid);

        $itens = [];
        foreach (array_chunk(array_map(fn ($f) => (string) $f->uuid, $faturas), 500) as $lote) {
            $linhas = DB::table(Lancamentos::TABELA)->where('company_uuid', $companyUuid)->whereIn('fatura_uuid', $lote)->get();
            foreach ($linhas as $linha) {
                $itens[(string) $linha->fatura_uuid][] = $linha;
            }
        }

        return array_map(function ($fatura) use ($lojas, $situacoes, $itens, $publico) {
            $vendor = $lojas[(string) $fatura->vendor_uuid] ?? null;

            return static::resumo($fatura, $situacoes[(string) $fatura->vendor_uuid] ?? [], $vendor ? (string) $vendor->name : '', $publico,
                $vendor ? (string) $vendor->public_id : '', $itens[(string) $fatura->uuid] ?? []);
        }, array_values($faturas));
    }

    /** [uuid => situação] de todas as emitidas da loja (Quitacao::faturas com os recebimentos da loja). */
    public static function situacoesDaLoja(string $companyUuid, string $vendorUuid): array
    {
        $faturas = DB::table(static::TABELA)
            ->where('company_uuid', $companyUuid)
            ->where('vendor_uuid', $vendorUuid)
            ->where('situacao', 'emitida')
            ->get()
            ->all();
        if (!$faturas) {
            return [];
        }

        $soma = DB::table(Lancamentos::TABELA)
            ->where('company_uuid', $companyUuid)
            ->where('conta_tipo', Financeiro::CONTA_LOJA)
            ->where('conta_uuid', $vendorUuid)
            ->whereNotNull('pagamento_uuid')
            ->whereIn('tipo', ['recebimento', 'estorno'])
            ->sum('valor');

        return static::quitar($faturas, -(float) $soma, Financeiro::hoje($companyUuid));
    }

    /** [vendor_uuid => [uuid => situação]] das emitidas de todas as lojas da empresa (duas consultas). */
    public static function situacoesDaEmpresa(string $companyUuid): array
    {
        $porLoja = [];
        foreach (DB::table(static::TABELA)->where('company_uuid', $companyUuid)->where('situacao', 'emitida')->get() as $fatura) {
            $porLoja[(string) $fatura->vendor_uuid][] = $fatura;
        }
        if (!$porLoja) {
            return [];
        }

        $recebidos = [];
        $somas     = DB::table(Lancamentos::TABELA)
            ->where('company_uuid', $companyUuid)
            ->where('conta_tipo', Financeiro::CONTA_LOJA)
            ->whereNotNull('pagamento_uuid')
            ->whereIn('tipo', ['recebimento', 'estorno'])
            ->groupBy('conta_uuid')
            ->selectRaw('conta_uuid, SUM(valor) as soma')
            ->get();
        foreach ($somas as $linha) {
            $recebidos[(string) $linha->conta_uuid] = -(float) $linha->soma;
        }

        $hoje  = Financeiro::hoje($companyUuid);
        $saida = [];
        foreach ($porLoja as $vendorUuid => $faturas) {
            $saida[$vendorUuid] = static::quitar($faturas, $recebidos[$vendorUuid] ?? 0.0, $hoje);
        }

        return $saida;
    }

    /** Valor ainda não faturado de cada loja da empresa ([vendor_uuid => float]): o que o próximo rascunho pegaria. */
    public static function naoFaturadoPorLoja(string $companyUuid): array
    {
        $saida = [];
        foreach (static::naoFaturados($companyUuid)->groupBy('conta_uuid')->selectRaw('conta_uuid, SUM(valor) as soma')->get() as $linha) {
            $saida[(string) $linha->conta_uuid] = round((float) $linha->soma, 2);
        }

        return $saida;
    }

    /**
     * As linhas do formato "Lojas" (plano, 1.5) para as lojas dadas (Vendors da empresa): saldo, em aberto, vencido, não
     * faturado, ciclo (com `padrao`), ciclo em andamento (no fuso da empresa) e e-mail. Ordenadas pelo nome.
     */
    public static function linhasDasLojas(string $companyUuid, iterable $vendors): array
    {
        $saldos      = Lancamentos::saldos($companyUuid, Financeiro::CONTA_LOJA);
        $situacoes   = static::situacoesDaEmpresa($companyUuid);
        $naoFaturado = static::naoFaturadoPorLoja($companyUuid);
        $ciclos      = [];
        foreach (DB::table(static::TABELA_DOS_CICLOS)->where('company_uuid', $companyUuid)->get() as $linha) {
            $ciclos[(string) $linha->vendor_uuid] = $linha;
        }
        $hoje      = Financeiro::hoje($companyUuid);
        $prePagas  = PrePago::lojasPrePagas($companyUuid);

        $linhas = [];
        foreach ($vendors as $vendor) {
            $uuid      = (string) $vendor->uuid;
            $config    = static::configOuPadrao($companyUuid, $ciclos[$uuid] ?? null);
            $daLoja    = $situacoes[$uuid] ?? [];
            $linhas[]  = [
                'id'           => (string) $vendor->public_id,
                'nome'         => (string) $vendor->name,
                'saldo'        => round((float) ($saldos[$uuid] ?? 0), 2),
                'em_aberto'    => round(array_sum(array_column($daLoja, 'em_aberto')), 2),
                'vencido'      => round(array_sum(array_map(fn ($s) => $s['vencida'] ? $s['em_aberto'] : 0, $daLoja)), 2),
                'nao_faturado' => round((float) ($naoFaturado[$uuid] ?? 0), 2),
                'ciclo'        => $config,
                'ciclo_atual'  => CicloDeCobranca::periodoQueContem($config, $hoje),
                'email'        => static::emailDaLoja($companyUuid, $vendor),
                // pré-pago: sem fatura; o crédito da carteira é −saldo
                'modelo'       => isset($prePagas[$uuid]) ? PrePago::PRE : PrePago::POS,
            ];
        }
        usort($linhas, fn ($a, $b) => strcasecmp($a['nome'], $b['nome']));

        return $linhas;
    }

    /**
     * Os itens da fatura no formato JSON "Lancamento" (plano, 1.5), mais antigos primeiro, pelo mesmo formatador do
     * extrato (ExtratoDaConta::linhas): console ($publico = false) e portal ($publico = true) mostram o mesmo item. Sem
     * situação por item (`situacao`/`valor_pago` nulos): na conta da loja, quem quita é a fatura.
     */
    public static function itensFormatados(object $fatura, bool $publico): array
    {
        return ExtratoDaConta::linhas(static::itens($fatura), [], $publico);
    }

    /**
     * O e-mail que a lista de lojas mostra (o aviso "Sem e-mail para receber a fatura" aparece quando é null): o da loja
     * (Vendor) ou, sem ele, o primeiro dos usuários ativos do portal dela, que também recebem a fatura
     * (AvisosDaFatura::emailsDaLoja). Só consulta os usuários quando a loja não tem e-mail.
     */
    private static function emailDaLoja(string $companyUuid, object $vendor): ?string
    {
        $proprio = trim((string) ($vendor->email ?? ''));
        if ($proprio !== '') {
            return $proprio;
        }

        return AvisosDaFatura::emailsDaLoja($companyUuid, (string) $vendor->uuid)[0] ?? null;
    }

    /** Lançamentos de loja da empresa ainda sem fatura, menos recebimentos e estornos de recebimento (têm pagamento_uuid). */
    private static function naoFaturados(string $companyUuid)
    {
        return DB::table(Lancamentos::TABELA)
            ->where('company_uuid', $companyUuid)
            ->where('conta_tipo', Financeiro::CONTA_LOJA)
            ->whereNull('fatura_uuid')
            ->whereNull('pagamento_uuid')
            ->whereNotIn('tipo', ['pagamento', 'recebimento']);
    }

    /** A config da linha de entregas_cobranca_lojas, ou o padrão da empresa (inválido: o sugerido), com ['padrao' => bool]. */
    private static function configOuPadrao(string $companyUuid, ?object $linha): array
    {
        $config = $linha ? ['ciclo' => $linha->ciclo, 'dia_fechamento' => $linha->dia_fechamento, 'prazo_dias' => $linha->prazo_dias] : Financeiro::cobrancaPadrao($companyUuid);
        if (CicloDeCobranca::validar($config) !== null) {
            $config = Financeiro::COBRANCA_PADRAO;
            $linha  = null;
        }

        return CicloDeCobranca::normalizar($config) + ['padrao' => $linha === null];
    }

    /** Quitacao::faturas das emitidas (objetos do banco). */
    private static function quitar(array $faturas, float $recebido, string $hoje): array
    {
        $lista = array_map(fn ($f) => [
            'uuid'       => (string) $f->uuid,
            'total'      => (float) ($f->total ?? 0),
            'vencimento' => substr((string) $f->vencimento, 0, 10),
            'numero'     => $f->numero !== null ? (int) $f->numero : null,
        ], $faturas);

        return Quitacao::faturas($lista, max(0.0, $recebido), $hoje);
    }

    /** A fatura relida com trava (dentro da transação); some → \DomainException. */
    private static function travada(object $fatura): object
    {
        $atual = DB::table(static::TABELA)->where('company_uuid', $fatura->company_uuid)->where('uuid', $fatura->uuid)->lockForUpdate()->first();
        if (!$atual) {
            throw new \DomainException('Fatura não encontrada.');
        }

        return $atual;
    }

    /** A fatura relida com trava; se não for mais rascunho, \DomainException com a mensagem da situação. */
    private static function rascunhoTravado(object $fatura, ?string $seEmitida = null): object
    {
        $atual = static::travada($fatura);
        if ($atual->situacao === 'emitida') {
            throw new \DomainException($seEmitida ?? 'A fatura já foi emitida: só o rascunho pode ser alterado.');
        }
        if ($atual->situacao !== 'rascunho') {
            throw new \DomainException('A fatura foi cancelada.');
        }

        return $atual;
    }

    private static function soma(array $itens): float
    {
        $centavos = 0;
        foreach ($itens as $item) {
            $centavos += (int) round(((float) $item->valor) * 100);
        }

        return round($centavos / 100, 2);
    }

    private static function exigirData(string $dia, string $mensagem): void
    {
        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $dia);
        if (!$data || $data->format('Y-m-d') !== $dia) {
            throw new \InvalidArgumentException($mensagem);
        }
    }

    /** Texto do banco ('Y-m-d H:i:s', hora do fuso do app) em ISO 8601 no fuso da empresa; null fica null. */
    private static function instante(?string $texto, string $fuso): ?string
    {
        if ($texto === null || $texto === '') {
            return null;
        }

        return (new \DateTimeImmutable($texto, new \DateTimeZone(date_default_timezone_get())))->setTimezone(new \DateTimeZone($fuso))->format('Y-m-d\TH:i:sP');
    }
}
