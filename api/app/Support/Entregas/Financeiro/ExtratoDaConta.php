<?php

namespace App\Support\Entregas\Financeiro;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: o extrato de uma conta (motoboy ou loja) nos formatos "Extrato" e "Lancamento" do contrato
 * (docs/superpowers/plans/2026-10-12-financeiro.md, seção 1.5).
 *
 * - Console (completo): com a observação interna, quem lançou (`criado_por`) e o link assinado do comprovante.
 * - App do motoboy e portal da loja ($publico = true): sem `observacao_interna` e sem `criado_por`. O lançamento da conta
 *   do motoboy só guarda o valor dele (os detalhes da corrida não têm valor nenhum), então o valor da loja nunca sai aqui.
 *
 * A situação de cada crédito (pago, pago em parte, a receber, anulado) é a quitação por antiguidade calculada com a conta
 * inteira (Quitacao::creditos), mesmo quando a lista é filtrada por período ou tipo.
 */
final class ExtratoDaConta
{
    public const TIPOS = ['corrida', 'diaria', 'bonus', 'ajuste', 'saldo_inicial', 'pagamento', 'recebimento', 'estorno'];

    /** Campos dos detalhes da corrida que saem no extrato (nunca um valor). */
    private const CAMPOS_DOS_DETALHES = ['km', 'faixa', 'loja_nome', 'destino', 'cancelado_pago', 'cancelado_origem', 'junto', 'multiplicador'];

    /**
     * Extrato pronto para o console (completo) ou para o app/portal ($publico = true: sem observacao_interna, sem
     * criado_por). Formato JSON "Extrato" (1.5). $inicio/$fim ('Y-m-d', inclusive; null = sem limite) filtram a lista e os
     * totais; $tipo filtra só a lista. `saldo` = soma de todos os lançamentos; `saldo_anterior` = soma antes de $inicio;
     * `totais` = créditos e débitos (os dois positivos) do período.
     */
    public static function montar(string $companyUuid, string $contaTipo, string $contaUuid, ?string $inicio, ?string $fim,
        bool $publico, ?string $tipo = null): array
    {
        $todos     = Lancamentos::daConta($companyUuid, $contaTipo, $contaUuid);
        $situacoes = Quitacao::creditos($todos);

        $saldo     = 0;
        $anterior  = 0;
        $creditos  = 0;
        $debitos   = 0;
        $lista     = [];
        $estornados = [];
        foreach ($todos as $lancamento) {
            if ($lancamento->estorno_de !== null) {
                $estornados[(int) $lancamento->estorno_de] = true;
            }
            $centavos = static::centavos($lancamento->valor);
            $dia      = substr((string) $lancamento->data_referencia, 0, 10);
            $saldo   += $centavos;
            if ($inicio !== null && $dia < $inicio) {
                $anterior += $centavos;
                continue;
            }
            if ($fim !== null && $dia > $fim) {
                continue;
            }
            if ($centavos > 0) {
                $creditos += $centavos;
            } else {
                $debitos -= $centavos;
            }
            if ($tipo === null || $lancamento->tipo === $tipo) {
                $lista[] = $lancamento;
            }
        }

        // mais novo primeiro: data de referência, depois a ordem de lançamento
        usort($lista, fn ($a, $b) => [substr((string) $b->data_referencia, 0, 10), (int) $b->id] <=> [substr((string) $a->data_referencia, 0, 10), (int) $a->id]);

        $ultimo = Pagamentos::ultimoDaConta($companyUuid, $contaTipo, $contaUuid);

        return [
            'conta'            => static::conta($companyUuid, $contaTipo, $contaUuid, $publico),
            'inicio'           => $inicio,
            'fim'              => $fim,
            'saldo'            => static::reais($saldo),
            'saldo_anterior'   => static::reais($anterior),
            'totais'           => ['creditos' => static::reais($creditos), 'debitos' => static::reais($debitos)],
            'ultimo_pagamento' => $ultimo ? [
                'id'    => (string) $ultimo->uuid,
                'valor' => round((float) $ultimo->valor, 2),
                'data'  => substr((string) $ultimo->data, 0, 10),
                'forma' => (string) $ultimo->forma,
            ] : null,
            'lancamentos'      => static::linhas($lista, $situacoes, $publico, $estornados),
        ];
    }

    /**
     * Vários lançamentos no formato "Lancamento", com as consultas em lote (pagamentos, nomes, faturas, pedidos). Para as
     * telas que já têm os lançamentos (itens da fatura). $situacoes = Quitacao::creditos da conta (ou [] sem situação);
     * $estornados = [id => true] dos já estornados (null = consulta).
     */
    public static function linhas(array $lancamentos, array $situacoes, bool $publico, ?array $estornados = null): array
    {
        if (!$lancamentos) {
            return [];
        }
        $companyUuid = (string) $lancamentos[0]->company_uuid;

        if ($estornados === null) {
            $estornados = array_fill_keys(Lancamentos::jaEstornados(array_map(fn ($l) => (int) $l->id, $lancamentos)), true);
        }

        $pagamentos = [];
        $uuidsDePagamento = array_values(array_unique(array_filter(array_map(fn ($l) => $l->pagamento_uuid ?? null, $lancamentos))));
        foreach (array_chunk($uuidsDePagamento, 500) as $lote) {
            foreach (DB::table(Pagamentos::TABELA)->where('company_uuid', $companyUuid)->whereIn('uuid', $lote)->get() as $pagamento) {
                $pagamentos[(string) $pagamento->uuid] = $pagamento;
            }
        }

        $nomes = [];
        if (!$publico) {
            $usuarios = array_values(array_unique(array_filter(array_map(fn ($l) => $l->criado_por ?? null, $lancamentos))));
            foreach (array_chunk($usuarios, 500) as $lote) {
                foreach (DB::table('users')->whereIn('uuid', $lote)->pluck('name', 'uuid')->all() as $uuid => $nome) {
                    $nomes[(string) $uuid] = (string) $nome;
                }
            }
        }

        $faturas = [];
        $uuidsDeFatura = array_values(array_unique(array_filter(array_map(fn ($l) => $l->fatura_uuid ?? null, $lancamentos))));
        foreach (array_chunk($uuidsDeFatura, 500) as $lote) {
            foreach (DB::table('entregas_faturas')->where('company_uuid', $companyUuid)->whereIn('uuid', $lote)->get() as $fatura) {
                $faturas[(string) $fatura->uuid] = $fatura->numero !== null ? (int) $fatura->numero : null;
            }
        }

        // pedidos sem public_id nos detalhes (ajuste lançado à mão por versões antigas): lidos do Order
        $pedidos = [];
        $semDetalhe = [];
        foreach ($lancamentos as $lancamento) {
            if (($lancamento->order_uuid ?? null) && !isset(static::detalhesCrus($lancamento)['pedido'])) {
                $semDetalhe[(string) $lancamento->order_uuid] = true;
            }
        }
        foreach (array_chunk(array_keys($semDetalhe), 500) as $lote) {
            foreach (Order::whereIn('uuid', $lote)->get() as $pedido) {
                $pedidos[(string) $pedido->uuid] = ['id' => $pedido->public_id, 'numero' => $pedido->internal_id ?? null];
            }
        }

        $contexto = ['estornados' => $estornados, 'faturas' => $faturas, 'pedidos' => $pedidos, 'fuso' => Financeiro::fuso($companyUuid)];

        return array_map(
            fn ($lancamento) => static::linha($lancamento, $situacoes[(int) $lancamento->id] ?? null, $publico, $pagamentos, $nomes, $contexto),
            array_values($lancamentos)
        );
    }

    /**
     * Um lançamento no formato JSON "Lancamento" (1.5). $situacao = a do Quitacao::creditos (só créditos); $pagamentos =
     * [uuid => linha de entregas_pagamentos]; $nomes = [users.uuid => nome]. $contexto (opcional, montado pelo `linhas`):
     * estornados [id => true], faturas [uuid => numero], pedidos [order_uuid => {id, numero}], fuso. O que faltar no
     * contexto é consultado aqui, um lançamento por vez.
     */
    public static function linha(object $lancamento, ?array $situacao, bool $publico, array $pagamentos, array $nomes, array $contexto = []): array
    {
        $id      = (int) $lancamento->id;
        $credito = static::centavos($lancamento->valor) > 0 && $lancamento->tipo !== 'estorno';

        $estornado = isset($contexto['estornados'])
            ? isset($contexto['estornados'][$id])
            : Lancamentos::jaEstornados([$id]) !== [];

        $linha = [
            'id'                 => $id,
            'tipo'               => (string) $lancamento->tipo,
            'valor'              => round((float) $lancamento->valor, 2),
            'data'               => substr((string) $lancamento->data_referencia, 0, 10),
            'criado_em'          => static::instante($lancamento->created_at ?? null, $contexto['fuso'] ?? Financeiro::fuso((string) $lancamento->company_uuid)),
            'pedido'             => static::pedido($lancamento, $contexto['pedidos'] ?? null),
            'detalhes'           => static::detalhes($lancamento),
            'motivo'             => ($lancamento->motivo ?? null) !== null ? (string) $lancamento->motivo : null,
            'observacao_interna' => ($lancamento->observacao_interna ?? null) !== null ? (string) $lancamento->observacao_interna : null,
            'situacao'           => $credito && $situacao ? $situacao['situacao'] : null,
            'valor_pago'         => $credito && $situacao ? round((float) $situacao['pago'], 2) : null,
            'pagamento'          => static::pagamentoDaLinha($lancamento, $pagamentos, $publico),
            'estorno_de'         => ($lancamento->estorno_de ?? null) !== null ? (int) $lancamento->estorno_de : null,
            'estornado'          => $estornado,
            'fatura'             => static::fatura($lancamento, $contexto['faturas'] ?? null),
            'criado_por'         => ($lancamento->criado_por ?? null) !== null ? ['nome' => $nomes[(string) $lancamento->criado_por] ?? null] : null,
        ];

        if ($publico) {
            unset($linha['observacao_interna'], $linha['criado_por']);
        }

        return $linha;
    }

    /**
     * O pagamento no formato do recibo (app, GET v1/entregas/motoboy/pagamentos/{id}) e da resposta do console:
     * {id, valor, forma, data, estornado, comprovante: {url, tipo, nome} | null}. Nunca a observação interna.
     */
    public static function pagamento(object $pagamento): array
    {
        $url = Pagamentos::urlDoComprovante($pagamento);

        return [
            'id'          => (string) $pagamento->uuid,
            'valor'       => round((float) $pagamento->valor, 2),
            'forma'       => (string) $pagamento->forma,
            'data'        => substr((string) $pagamento->data, 0, 10),
            'estornado'   => ($pagamento->estornado_em ?? null) !== null,
            'comprovante' => $url ? [
                'url'  => $url,
                'tipo' => $pagamento->comprovante_tipo ?? null,
                'nome' => $pagamento->comprovante_nome ?? null,
            ] : null,
        ];
    }

    /** O saldo da conta agora (soma de todos os lançamentos). */
    public static function saldo(string $companyUuid, string $contaTipo, string $contaUuid): float
    {
        $soma = DB::table(Lancamentos::TABELA)
            ->where('company_uuid', $companyUuid)
            ->where('conta_tipo', $contaTipo)
            ->where('conta_uuid', $contaUuid)
            ->sum('valor');

        return round((float) $soma, 2);
    }

    /**
     * {tipo, id (public_id), nome} da conta e, no motoboy, o `pix` ({chave, tipo} ou null) e, só no console (não
     * público), `excluido` (motoboy excluído com soft delete; o console mostra o selo).
     */
    private static function conta(string $companyUuid, string $contaTipo, string $contaUuid, bool $publico = false): array
    {
        if ($contaTipo === Financeiro::CONTA_MOTOBOY) {
            $motoboy = Driver::withTrashed()->where('company_uuid', $companyUuid)->where('uuid', $contaUuid)->with(['user'])->first();

            $conta = [
                'tipo' => $contaTipo,
                'id'   => $motoboy?->public_id,
                'nome' => $motoboy?->name,
                'pix'  => ChavePix::doMotoboy($companyUuid, $contaUuid),
            ];
            if (!$publico) {
                $conta['excluido'] = $motoboy !== null && $motoboy->deleted_at !== null;
            }

            return $conta;
        }

        $loja = Vendor::where('company_uuid', $companyUuid)->where('uuid', $contaUuid)->first();

        return ['tipo' => $contaTipo, 'id' => $loja?->public_id, 'nome' => $loja?->name];
    }

    private static function pagamentoDaLinha(object $lancamento, array $pagamentos, bool $publico): ?array
    {
        $uuid = $lancamento->pagamento_uuid ?? null;
        if (!$uuid || !in_array($lancamento->tipo, ['pagamento', 'recebimento'], true)) {
            return null;
        }
        $pagamento = $pagamentos[(string) $uuid] ?? Pagamentos::porUuid((string) $lancamento->company_uuid, (string) $uuid);
        if (!$pagamento) {
            return null;
        }

        $saida = [
            'id'              => (string) $pagamento->uuid,
            'forma'           => (string) $pagamento->forma,
            'tem_comprovante' => !empty($pagamento->comprovante_path),
            'estornado'       => ($pagamento->estornado_em ?? null) !== null,
        ];
        if (!$publico) {
            $saida['comprovante_url'] = Pagamentos::urlDoComprovante($pagamento);
        }

        return $saida;
    }

    private static function pedido(object $lancamento, ?array $pedidos): ?array
    {
        $uuid = $lancamento->order_uuid ?? null;
        if (!$uuid) {
            return null;
        }
        $detalhes = static::detalhesCrus($lancamento);
        if (isset($detalhes['pedido'])) {
            return ['id' => (string) $detalhes['pedido'], 'numero' => isset($detalhes['numero']) ? (string) $detalhes['numero'] : null];
        }
        if ($pedidos !== null) {
            return $pedidos[(string) $uuid] ?? null;
        }
        $pedido = Order::where('uuid', (string) $uuid)->first();

        return $pedido ? ['id' => $pedido->public_id, 'numero' => $pedido->internal_id ?? null] : null;
    }

    private static function fatura(object $lancamento, ?array $faturas): ?array
    {
        $uuid = $lancamento->fatura_uuid ?? null;
        if (!$uuid) {
            return null;
        }
        if ($faturas !== null && array_key_exists((string) $uuid, $faturas)) {
            $numero = $faturas[(string) $uuid];
        } else {
            $numero = DB::table('entregas_faturas')->where('uuid', (string) $uuid)->value('numero');
        }

        return ['id' => (string) $uuid, 'numero' => Financeiro::numeroDaFatura($numero !== null ? (int) $numero : null)];
    }

    /**
     * Os detalhes do contrato (km, faixa, loja, destino, cancelado pago, entrega junto e multiplicador), ou null se o
     * lançamento não tem nenhum.
     */
    private static function detalhes(object $lancamento): ?array
    {
        $crus = static::detalhesCrus($lancamento);
        if (!array_intersect(array_keys($crus), static::CAMPOS_DOS_DETALHES)) {
            return null;
        }
        $faixa = is_array($crus['faixa'] ?? null) ? $crus['faixa'] : null;

        return [
            'km'               => isset($crus['km']) && is_numeric($crus['km']) ? round((float) $crus['km'], 2) : null,
            'faixa'            => $faixa ? [
                'de_km'  => (float) ($faixa['de_km'] ?? 0),
                'ate_km' => (float) ($faixa['ate_km'] ?? 0),
                'acima'  => !empty($faixa['acima']),
            ] : null,
            'loja_nome'        => isset($crus['loja_nome']) ? (string) $crus['loja_nome'] : null,
            'destino'          => isset($crus['destino']) ? (string) $crus['destino'] : null,
            'cancelado_pago'   => !empty($crus['cancelado_pago']),
            'cancelado_origem' => isset($crus['cancelado_origem']) ? (string) $crus['cancelado_origem'] : null,
            // entrega junto (a posição, quando a segunda tabela foi usada) e o multiplicador desta conta ({nome, fator})
            'junto'            => isset($crus['junto']) && is_numeric($crus['junto']) ? (int) $crus['junto'] : null,
            'multiplicador'    => is_array($crus['multiplicador'] ?? null) ? [
                'nome'  => (string) ($crus['multiplicador']['nome'] ?? ''),
                'fator' => round((float) ($crus['multiplicador']['fator'] ?? 1), 2),
            ] : null,
        ];
    }

    /** O JSON `detalhes` como array (o MySQL devolve texto). */
    private static function detalhesCrus(object $lancamento): array
    {
        $detalhes = $lancamento->detalhes ?? null;
        if (is_string($detalhes)) {
            $detalhes = json_decode($detalhes, true);
        }

        return is_array($detalhes) ? $detalhes : [];
    }

    /** O instante gravado (texto na hora do app, Brasília) em ISO 8601 no fuso da empresa. */
    private static function instante($valor, string $fuso): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        try {
            $instante = $valor instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($valor)
                : new \DateTimeImmutable((string) $valor, new \DateTimeZone(date_default_timezone_get()));

            return $instante->setTimezone(new \DateTimeZone($fuso))->format('Y-m-d\TH:i:sP');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function centavos($valor): int
    {
        return (int) round(((float) $valor) * 100);
    }

    private static function reais(int $centavos): float
    {
        return round($centavos / 100, 2);
    }
}
