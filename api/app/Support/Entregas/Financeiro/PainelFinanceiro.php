<?php

namespace App\Support\Entregas\Financeiro;

use Fleetbase\FleetOps\Models\Vendor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: o Painel do Financeiro no console (formato "Painel" do contrato, seção 1.5).
 *
 * - Saldos de agora: `a_pagar` (soma dos saldos positivos dos motoboys), `adiantado` (soma dos negativos, positiva),
 *   `a_receber` (em aberto das faturas emitidas, pela quitação por vencimento do Quitacao::faturas) e `vencido` (a parte
 *   vencida), `nao_faturado` (lançamentos de loja ainda sem fatura emitida: sem fatura ou num rascunho; sem os
 *   recebimentos).
 * - Período: `cobrado` (corridas e ajustes das lojas, com os estornos deles), `pago_motoboys` (corridas, diárias, bônus e
 *   ajustes dos motoboys, com os estornos deles; o saldo inicial não conta) e `margem` (a diferença).
 * - Atenção: faturas vencidas, rascunhos para conferir, emitidas em aberto sem e-mail, corridas de motoboy sem o
 *   lançamento da loja (últimos 30 dias) e pedidos concluídos há mais de 1 h sem lançamento ("sem valor", com o
 *   financeiro ligado e data de corte; no máximo 30 dias para trás), até 20 de cada.
 *
 * As faturas são lidas direto das tabelas (entregas_faturas, entregas_lancamentos), sem depender da classe Faturas.
 * Só consulta: nunca lança (o LancadorDeCorridas::pedidosSemLancamento é só a consulta da conferência).
 */
final class PainelFinanceiro
{
    /** Máximo de itens de cada tipo na lista "Atenção". */
    public const LIMITE_POR_TIPO = 20;

    /** Dias para trás no "sem loja" e no "sem valor". */
    public const DIAS_PARA_TRAS = 30;

    public static function montar(string $companyUuid, string $inicio, string $fim, ?LancadorDeCorridas $lancador = null): array
    {
        $hoje = Financeiro::hoje($companyUuid);

        // motoboys: saldos de agora
        $aPagar    = 0;
        $adiantado = 0;
        foreach (Lancamentos::saldos($companyUuid, Financeiro::CONTA_MOTOBOY) as $saldo) {
            $centavos = static::centavos($saldo);
            if ($centavos > 0) {
                $aPagar += $centavos;
            } else {
                $adiantado -= $centavos;
            }
        }

        // faturas emitidas e rascunhos (as canceladas não contam)
        $faturas = DB::table('entregas_faturas')
            ->where('company_uuid', $companyUuid)
            ->whereIn('situacao', ['emitida', 'rascunho'])
            ->orderBy('vencimento')
            ->orderBy('id')
            ->get()
            ->all();
        $emitidas  = array_values(array_filter($faturas, fn ($f) => $f->situacao === 'emitida'));
        $rascunhos = array_values(array_filter($faturas, fn ($f) => $f->situacao === 'rascunho'));

        // recebido de cada loja: recebimentos menos os estornos deles (os dois com pagamento_uuid)
        $recebido = [];
        $recebimentos = DB::table(Lancamentos::TABELA)
            ->where('company_uuid', $companyUuid)
            ->where('conta_tipo', Financeiro::CONTA_LOJA)
            ->whereIn('tipo', ['recebimento', 'estorno'])
            ->whereNotNull('pagamento_uuid')
            ->get();
        foreach ($recebimentos as $lancamento) {
            $recebido[(string) $lancamento->conta_uuid] = ($recebido[(string) $lancamento->conta_uuid] ?? 0) - static::centavos($lancamento->valor);
        }

        $porLoja = [];
        foreach ($emitidas as $fatura) {
            $porLoja[(string) $fatura->vendor_uuid][] = ['uuid' => (string) $fatura->uuid, 'total' => (float) $fatura->total, 'vencimento' => (string) $fatura->vencimento, 'numero' => $fatura->numero];
        }
        $situacoes = [];
        foreach ($porLoja as $loja => $lista) {
            $situacoes += Quitacao::faturas($lista, max(0, $recebido[$loja] ?? 0) / 100, $hoje);
        }
        $aReceber = 0;
        $vencido  = 0;
        foreach ($situacoes as $situacao) {
            $aReceber += static::centavos($situacao['em_aberto']);
            $vencido  += $situacao['vencida'] ? static::centavos($situacao['em_aberto']) : 0;
        }

        // não faturado: sem fatura ou num rascunho (os recebimentos e seus estornos têm pagamento_uuid e ficam de fora)
        $naoFaturado = static::centavos(DB::table(Lancamentos::TABELA)
            ->where('company_uuid', $companyUuid)
            ->where('conta_tipo', Financeiro::CONTA_LOJA)
            ->whereNull('fatura_uuid')
            ->whereNull('pagamento_uuid')
            ->sum('valor'));
        $itensDosRascunhos = [];
        if ($rascunhos) {
            $itens = DB::table(Lancamentos::TABELA)
                ->where('company_uuid', $companyUuid)
                ->whereIn('fatura_uuid', array_map(fn ($f) => (string) $f->uuid, $rascunhos))
                ->whereNull('pagamento_uuid')
                ->get();
            foreach ($itens as $item) {
                $itensDosRascunhos[(string) $item->fatura_uuid] = ($itensDosRascunhos[(string) $item->fatura_uuid] ?? 0) + static::centavos($item->valor);
                $naoFaturado += static::centavos($item->valor);
            }
        }

        // período
        $cobrado      = static::somaDoPeriodo($companyUuid, Financeiro::CONTA_LOJA, ['corrida', 'ajuste', 'estorno'], $inicio, $fim);
        $pagoMotoboys = static::somaDoPeriodo($companyUuid, Financeiro::CONTA_MOTOBOY, ['corrida', 'diaria', 'bonus', 'ajuste', 'estorno'], $inicio, $fim);

        // atenção
        $nomes   = static::nomesDasLojas(array_map(fn ($f) => (string) $f->vendor_uuid, $faturas));
        $atencao = [];
        $vencidas = array_filter($emitidas, fn ($f) => $situacoes[(string) $f->uuid]['vencida'] ?? false);
        foreach (array_slice($vencidas, 0, static::LIMITE_POR_TIPO) as $fatura) {
            $atencao[] = [
                'tipo'       => 'fatura_vencida',
                'fatura'     => (string) $fatura->uuid,
                'numero'     => Financeiro::numeroDaFatura($fatura->numero !== null ? (int) $fatura->numero : null),
                'loja'       => $nomes[(string) $fatura->vendor_uuid] ?? null,
                'valor'      => $situacoes[(string) $fatura->uuid]['em_aberto'],
                'vencimento' => substr((string) $fatura->vencimento, 0, 10),
            ];
        }
        foreach (array_slice($rascunhos, 0, static::LIMITE_POR_TIPO) as $fatura) {
            $atencao[] = [
                'tipo'   => 'rascunho',
                'fatura' => (string) $fatura->uuid,
                'loja'   => $nomes[(string) $fatura->vendor_uuid] ?? null,
                'valor'  => static::reais($itensDosRascunhos[(string) $fatura->uuid] ?? 0),
            ];
        }
        // emitida ainda em aberto sem o e-mail (loja sem e-mail, ou falha no envio)
        $semEmail = array_filter($emitidas, fn ($f) => ($f->email_enviado_em ?? null) === null && ($situacoes[(string) $f->uuid]['em_aberto'] ?? 0) > 0);
        foreach (array_slice($semEmail, 0, static::LIMITE_POR_TIPO) as $fatura) {
            $atencao[] = [
                'tipo'   => 'email_nao_enviado',
                'fatura' => (string) $fatura->uuid,
                'numero' => Financeiro::numeroDaFatura($fatura->numero !== null ? (int) $fatura->numero : null),
                'loja'   => $nomes[(string) $fatura->vendor_uuid] ?? null,
            ];
        }
        foreach (static::semLoja($companyUuid, $hoje) as $item) {
            $atencao[] = $item;
        }
        foreach (static::semValor($companyUuid, $lancador) as $item) {
            $atencao[] = $item;
        }

        return [
            'inicio'       => $inicio,
            'fim'          => $fim,
            'a_pagar'      => static::reais($aPagar),
            'adiantado'    => static::reais($adiantado),
            'a_receber'    => static::reais($aReceber),
            'vencido'      => static::reais($vencido),
            'nao_faturado' => static::reais($naoFaturado),
            'periodo'      => [
                'cobrado'       => static::reais($cobrado),
                'pago_motoboys' => static::reais($pagoMotoboys),
                'margem'        => static::reais($cobrado - $pagoMotoboys),
            ],
            'atencao'      => $atencao,
        ];
    }

    /**
     * Soma (centavos) dos lançamentos da conta com esses tipos e data no período. Os estornos de pagamento e de
     * recebimento (com pagamento_uuid) ficam de fora: só entram os estornos de corrida, diária, ajuste...
     */
    private static function somaDoPeriodo(string $companyUuid, string $contaTipo, array $tipos, string $inicio, string $fim): int
    {
        return static::centavos(DB::table(Lancamentos::TABELA)
            ->where('company_uuid', $companyUuid)
            ->where('conta_tipo', $contaTipo)
            ->whereIn('tipo', $tipos)
            ->whereNull('pagamento_uuid')
            ->where('data_referencia', '>=', $inicio)
            ->where('data_referencia', '<=', $fim)
            ->sum('valor'));
    }

    /** Corridas de motoboy (últimos 30 dias, não estornadas) cujo pedido não tem lançamento na conta de uma loja. */
    private static function semLoja(string $companyUuid, string $hoje): array
    {
        $desde    = (new \DateTimeImmutable($hoje))->modify('-' . static::DIAS_PARA_TRAS . ' days')->format('Y-m-d');
        $corridas = DB::table(Lancamentos::TABELA)
            ->where('company_uuid', $companyUuid)
            ->where('conta_tipo', Financeiro::CONTA_MOTOBOY)
            ->where('tipo', 'corrida')
            ->where('data_referencia', '>=', $desde)
            ->whereNotNull('order_uuid')
            ->orderBy('data_referencia', 'desc')
            ->orderBy('id', 'desc')
            ->get()
            ->all();
        if (!$corridas) {
            return [];
        }

        $comLoja = [];
        foreach (array_chunk(array_values(array_unique(array_map(fn ($c) => (string) $c->order_uuid, $corridas))), 1000) as $lote) {
            $uuids = DB::table(Lancamentos::TABELA)
                ->where('company_uuid', $companyUuid)
                ->where('conta_tipo', Financeiro::CONTA_LOJA)
                ->where('tipo', 'corrida')
                ->whereIn('order_uuid', $lote)
                ->pluck('order_uuid');
            foreach ($uuids as $uuid) {
                $comLoja[(string) $uuid] = true;
            }
        }
        $semLoja    = array_values(array_filter($corridas, fn ($c) => !isset($comLoja[(string) $c->order_uuid])));
        $estornadas = array_flip(Lancamentos::jaEstornados(array_map(fn ($c) => (int) $c->id, $semLoja)));

        $itens = [];
        foreach ($semLoja as $corrida) {
            if (isset($estornadas[(int) $corrida->id])) {
                continue;
            }
            $detalhes = is_string($corrida->detalhes ?? null) ? json_decode($corrida->detalhes, true) : (array) ($corrida->detalhes ?? []);
            $itens[]  = ['tipo' => 'sem_loja', 'pedido' => $detalhes['pedido'] ?? null, 'numero' => $detalhes['numero'] ?? null];
            if (count($itens) >= static::LIMITE_POR_TIPO) {
                break;
            }
        }

        return $itens;
    }

    /**
     * Pedidos concluídos (ou cancelados pagos) há mais de 1 h sem lançamento de corrida: a mesma consulta da conferência
     * (pedidosSemLancamento), desde o corte (no máximo 30 dias), os mais recentes primeiro. Só com o financeiro ligado e
     * data de corte (desligado, nada é lançado sozinho e todo pedido apareceria). Falha na consulta não derruba o painel.
     */
    private static function semValor(string $companyUuid, ?LancadorDeCorridas $lancador): array
    {
        $corte = Financeiro::corte($companyUuid);
        if (!Financeiro::ligada() || $corte === null) {
            return [];
        }

        try {
            $fuso   = new \DateTimeZone(date_default_timezone_get());
            $inicio = Carbon::parse($corte . ' 00:00:00', Financeiro::fuso($companyUuid))->setTimezone($fuso);
            $limite = Carbon::parse(now())->subDays(static::DIAS_PARA_TRAS);
            $limite = Carbon::parse($limite->format('Y-m-d') . ' 00:00:00', date_default_timezone_get());
            if ($inicio < $limite) {
                $inicio = $limite;
            }
            $umaHoraAtras = Carbon::parse(now())->subHours(1)->format('Y-m-d H:i:s');

            session(['company' => $companyUuid]);
            $pedidos = ($lancador ?? app(LancadorDeCorridas::class))->pedidosSemLancamento($companyUuid, $inicio)->all();
        } catch (\Throwable $e) {
            Log::warning('[entregas] financeiro: painel sem a lista de corridas sem valor', ['erro' => $e->getMessage()]);

            return [];
        }

        $itens = [];
        foreach (array_reverse($pedidos) as $pedido) {
            $concluido = substr((string) ($pedido->entregas_concluido_em ?? ''), 0, 19);
            if ($concluido === '' || $concluido > $umaHoraAtras) {
                continue;
            }
            $itens[] = ['tipo' => 'sem_valor', 'pedido' => $pedido->public_id ?? null, 'numero' => $pedido->internal_id ?? null];
            if (count($itens) >= static::LIMITE_POR_TIPO) {
                break;
            }
        }

        return $itens;
    }

    /** [vendor uuid => nome] das lojas. */
    private static function nomesDasLojas(array $uuids): array
    {
        $uuids = array_values(array_unique(array_filter($uuids)));
        $nomes = [];
        foreach (array_chunk($uuids, 500) as $lote) {
            foreach (Vendor::whereIn('uuid', $lote)->get() as $loja) {
                $nomes[(string) $loja->uuid] = $loja->name;
            }
        }

        return $nomes;
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
