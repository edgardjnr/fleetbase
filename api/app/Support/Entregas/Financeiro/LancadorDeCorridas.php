<?php

namespace App\Support\Entregas\Financeiro;

use App\Support\Entregas\CalculoEntregas;
use App\Support\Entregas\StatusDoPedido;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: lança as corridas no financeiro. Pedido que entra no relatório (concluído, ou cancelado pago
 * pelo iFood ou pela integração: a mesma consulta do CalculoEntregas::pedidosConcluidos) com data a partir do corte da
 * empresa gera dois lançamentos `corrida`: o valor_motoboy na conta do motoboy atribuído e o valor_loja na conta da loja
 * (Vendor) dona do pedido, com os valores congelados (ValoresCongelados). A `chave_unica` garante um por pedido e conta.
 *
 * Chamado pelo job do observador (LancarCorridaDoPedido, na conclusão), pela conferência a cada 5 min
 * (entregas:financeiro-conferir) e pelo entregas:financeiro-lancar-historico. Pedido sem valor (sem faixas, OSRM fora e
 * sem estimativa) fica para a próxima conferência.
 *
 * O CalculoEntregas lê as faixas pela empresa da sessão: lancar() põe a empresa na sessão antes de calcular.
 */
final class LancadorDeCorridas
{
    public const MOTIVO_CANCELADO = 'Pedido cancelado após a conclusão';

    /** Motivo da corrida lançada de novo quando o pedido estornado volta a valer (relancarEstornados). */
    public const MOTIVO_RELANCADO = 'Pedido voltou a valer depois do cancelamento';

    /** Rotas que o cálculo pode pedir ao OSRM por chamada (o resto fica sem valor até a próxima conferência). */
    public const LIMITE_CALCULOS = 200;

    private CalculoEntregas $calculo;

    public function __construct(?CalculoEntregas $calculo = null)
    {
        $this->calculo = $calculo ?? new CalculoEntregas();
    }

    /**
     * Lança as corridas dos pedidos (Collection vinda de CalculoEntregas::pedidosConcluidos) da company: um lançamento
     * 'corrida' por pedido e conta (motoboy: valor_motoboy; loja: valor_loja quando há Vendor). Pula pedido sem valor,
     * com data < corte, ou já lançado (chave_unica). Devolve ['lancados' => int, 'sem_valor' => int], com `lancados` =
     * lançamentos gravados agora (dois por pedido com loja). Sem data de corte na empresa, nada é lançado.
     */
    public function lancar(string $companyUuid, Collection $pedidos): array
    {
        $resultado = ['lancados' => 0, 'sem_valor' => 0];
        $corte     = Financeiro::corte($companyUuid);
        if ($corte === null || $pedidos->isEmpty()) {
            return $resultado;
        }

        // as faixas do CalculoEntregas vêm do Setting da empresa da sessão (comando e job não têm sessão)
        session(['company' => $companyUuid]);
        [$entregas] = $this->calculo->entregas($pedidos, Financeiro::fuso($companyUuid), static::LIMITE_CALCULOS);

        foreach ($entregas as $entrega) {
            if (empty($entrega['order_uuid']) || empty($entrega['motoboy_uuid'])) {
                continue;
            }
            // concluido_em vem em ISO no fuso da empresa: a data é o dia da conclusão (ou do cancelamento pago) lá
            $data = substr((string) $entrega['concluido_em'], 0, 10);
            if ($data < $corte) {
                continue;
            }
            if ($entrega['valor_motoboy'] === null) {
                $resultado['sem_valor']++;
                continue;
            }

            $base = [
                'company_uuid'    => $companyUuid,
                'tipo'            => 'corrida',
                'data_referencia' => $data,
                'order_uuid'      => $entrega['order_uuid'],
                'detalhes'        => static::detalhes($entrega),
            ];

            // os dois numa transação: a corrida do motoboy não fica sem a da loja por uma falha no meio (a conferência só
            // refaz a da loja quando a do motoboy existe: pedidosSemLancamentoDaLoja)
            $resultado['lancados'] += DB::transaction(function () use ($base, $entrega) {
                $motoboy = Lancamentos::inserir($base + [
                    'conta_tipo'  => Financeiro::CONTA_MOTOBOY,
                    'conta_uuid'  => $entrega['motoboy_uuid'],
                    'valor'       => (float) $entrega['valor_motoboy'],
                    'chave_unica' => 'corrida:' . Financeiro::CONTA_MOTOBOY . ':' . $entrega['order_uuid'],
                ]);

                $loja = null;
                if (!empty($entrega['loja_uuid']) && $entrega['valor_loja'] !== null) {
                    $loja = Lancamentos::inserir($base + [
                        'conta_tipo'  => Financeiro::CONTA_LOJA,
                        'conta_uuid'  => $entrega['loja_uuid'],
                        'valor'       => (float) $entrega['valor_loja'],
                        'chave_unica' => 'corrida:' . Financeiro::CONTA_LOJA . ':' . $entrega['order_uuid'],
                    ]);
                }

                return ($motoboy ? 1 : 0) + ($loja ? 1 : 0);
            });
        }

        return $resultado;
    }

    /**
     * Pedidos concluídos (ou cancelados pagos) de $inicio até agora sem lançamento de corrida do motoboy: lança o que der e
     * devolve ['lancados' => int, 'sem_valor' => int, 'sem_lancamento' => [['id' => public_id, 'numero' => internal_id,
     * 'concluido_em' => 'Y-m-d H:i:s' no fuso do app]]] (os que continuam sem lançamento: sem valor, antes do corte...).
     * O painel usa `sem_lancamento` para as "corridas sem valor". Também lança a corrida da loja que faltou
     * (pedidosSemLancamentoDaLoja; entra em `lancados`). Sem data de corte, nem consulta.
     */
    public function conferir(string $companyUuid, Carbon $inicio): array
    {
        $resultado = ['lancados' => 0, 'sem_valor' => 0, 'sem_lancamento' => []];
        if (Financeiro::corte($companyUuid) === null) {
            return $resultado;
        }

        $pedidos = $this->pedidosSemLancamento($companyUuid, $inicio);
        if (!$pedidos->isEmpty()) {
            $resultado = $this->lancar($companyUuid, $pedidos) + $resultado;
        }

        // a corrida da loja que faltou (pedido com a do motoboy, sem a da loja e com uma loja de cliente): o lancar só
        // grava a dela (a do motoboy já tem a chave)
        $semLoja = $this->pedidosSemLancamentoDaLoja($companyUuid, $inicio);
        if (!$semLoja->isEmpty()) {
            $resultado['lancados'] += $this->lancar($companyUuid, $semLoja)['lancados'];
        }
        if ($pedidos->isEmpty()) {
            return $resultado;
        }

        $lancados = [];
        foreach (array_chunk(array_values(array_filter($pedidos->pluck('uuid')->all())), 1000) as $lote) {
            $chaves = DB::table(Lancamentos::TABELA)
                ->whereIn('order_uuid', $lote)
                ->where('conta_tipo', Financeiro::CONTA_MOTOBOY)
                ->where('tipo', 'corrida')
                ->pluck('order_uuid');
            foreach ($chaves as $uuid) {
                $lancados[(string) $uuid] = true;
            }
        }
        foreach ($pedidos as $pedido) {
            if (!isset($lancados[(string) $pedido->uuid])) {
                $resultado['sem_lancamento'][] = [
                    'id'           => $pedido->public_id,
                    'numero'       => $pedido->internal_id ?? null,
                    'concluido_em' => $pedido->entregas_concluido_em !== null ? substr((string) $pedido->entregas_concluido_em, 0, 19) : null,
                ];
            }
        }

        return $resultado;
    }

    /**
     * Só a consulta da conferência, sem lançar (o painel usa para as "corridas sem valor"): pedidos concluídos (ou
     * cancelados pagos) de $inicio até agora sem lançamento de corrida do motoboy, como vêm do
     * CalculoEntregas::pedidosConcluidos (entregas_concluido_em = texto no fuso do app).
     */
    public function pedidosSemLancamento(string $companyUuid, Carbon $inicio): Collection
    {
        return $this->calculo->pedidosConcluidos($companyUuid, $inicio, now(), fn ($query) => $query->whereNotExists(
            fn ($sub) => $sub->selectRaw('1')
                ->from(Lancamentos::TABELA)
                ->whereColumn(Lancamentos::TABELA . '.order_uuid', 'orders.uuid')
                ->where(Lancamentos::TABELA . '.conta_tipo', Financeiro::CONTA_MOTOBOY)
                ->where(Lancamentos::TABELA . '.tipo', 'corrida')
        ));
    }

    /**
     * Pedidos do relatório de $inicio até agora com a corrida do motoboy lançada, sem a da loja e cujo cliente é uma loja
     * (Vendor, inclusive excluída, como no CalculoEntregas::lojasDosPedidos): a corrida da loja que faltou (lançamento de
     * antes da transação, ou loja ligada ao pedido depois). A conferência lança a da loja.
     */
    public function pedidosSemLancamentoDaLoja(string $companyUuid, Carbon $inicio): Collection
    {
        $corrida = fn (string $conta) => fn ($sub) => $sub->selectRaw('1')
            ->from(Lancamentos::TABELA)
            ->whereColumn(Lancamentos::TABELA . '.order_uuid', 'orders.uuid')
            ->where(Lancamentos::TABELA . '.conta_tipo', $conta)
            ->where(Lancamentos::TABELA . '.tipo', 'corrida');

        return $this->calculo->pedidosConcluidos($companyUuid, $inicio, now(), fn ($query) => $query
            ->whereExists($corrida(Financeiro::CONTA_MOTOBOY))
            ->whereNotExists($corrida(Financeiro::CONTA_LOJA))
            ->whereExists(fn ($sub) => $sub->selectRaw('1')->from('vendors')->whereColumn('vendors.uuid', 'orders.customer_uuid')));
    }

    /**
     * Corridas lançadas (data_referencia a partir de $desde) cujo pedido deixou de ser pagável → estorno automático,
     * motivo 'Pedido cancelado após a conclusão'. Só estorna pedido hoje cancelado e fora do relatório (o cancelado pago
     * pelo iFood ou pela integração continua pagável). Devolve quantos estornos criou.
     */
    public function estornarCancelados(string $companyUuid, Carbon $desde): int
    {
        $dia = $desde->format('Y-m-d');

        // candidatos: corridas do período cujo pedido está cancelado agora
        $candidatos = DB::table(Lancamentos::TABELA)
            ->join('orders', 'orders.uuid', '=', Lancamentos::TABELA . '.order_uuid')
            ->where(Lancamentos::TABELA . '.company_uuid', $companyUuid)
            ->where(Lancamentos::TABELA . '.tipo', 'corrida')
            ->where(Lancamentos::TABELA . '.data_referencia', '>=', $dia)
            ->whereIn('orders.status', StatusDoPedido::CANCELADOS)
            ->pluck('order_uuid')
            ->all();
        $candidatos = array_values(array_unique(array_filter(array_map('strval', $candidatos))));
        if (!$candidatos) {
            return 0;
        }

        // o cancelado pago continua no relatório: não estorna. Um dia de folga antes de $desde (a data de referência é no
        // fuso da empresa e o período do relatório no do app)
        session(['company' => $companyUuid]);
        $inicio   = Carbon::parse($dia . ' 00:00:00', date_default_timezone_get())->subDays(1);
        $pagaveis = [];
        foreach ($this->calculo->pedidosConcluidos($companyUuid, $inicio, now(), fn ($query) => $query->whereIn('orders.uuid', $candidatos)) as $pedido) {
            $pagaveis[(string) $pedido->uuid] = true;
        }
        $alvos = array_values(array_filter($candidatos, fn ($uuid) => !isset($pagaveis[$uuid])));
        if (!$alvos) {
            return 0;
        }

        // todos os lançamentos desses pedidos (corridas, ajustes e estornos), separados por conta e pedido
        $grupos = [];
        $linhas = DB::table(Lancamentos::TABELA)
            ->where('company_uuid', $companyUuid)
            ->whereIn('order_uuid', $alvos)
            ->orderBy('id')
            ->get();
        foreach ($linhas as $lancamento) {
            $grupos[$lancamento->conta_tipo . '|' . $lancamento->conta_uuid . '|' . $lancamento->order_uuid][] = $lancamento;
        }

        $estornos = 0;
        foreach ($grupos as $grupo) {
            foreach (static::estornosDevidos($grupo) as [$corrida, $parte]) {
                if (Lancamentos::estornar($corrida, null, static::MOTIVO_CANCELADO, $parte)) {
                    $estornos++;
                }
            }
        }

        return $estornos;
    }

    /**
     * O caminho de volta do estornarCancelados: o pedido cuja corrida foi estornada pelo sistema (motivo
     * MOTIVO_CANCELADO, estorno com data_referencia a partir de $desde) e que voltou ao relatório (cancelado pago marcado
     * depois, ou voltou a concluído) ganha a corrida de novo, em cada conta em que a última corrida do pedido tem esse
     * estorno automático (estorno feito à mão pelo admin não volta). A corrida nova vale o que o estorno tirou (o desconto
     * de antes continua), na data e com os detalhes da corrida estornada, com chave_unica `corrida:<conta>:<pedido>:<n>`
     * (n = 2, 3…) e o motivo MOTIVO_RELANCADO. Devolve quantas corridas lançou.
     */
    public function relancarEstornados(string $companyUuid, Carbon $desde): int
    {
        $dia = $desde->format('Y-m-d');

        // estornos automáticos do período → as corridas estornadas
        $automaticos = [];
        $estornos    = DB::table(Lancamentos::TABELA)
            ->where('company_uuid', $companyUuid)
            ->where('tipo', 'estorno')
            ->where('motivo', static::MOTIVO_CANCELADO)
            ->whereNull('criado_por')
            ->where('data_referencia', '>=', $dia)
            ->get();
        foreach ($estornos as $estorno) {
            if ($estorno->estorno_de !== null) {
                $automaticos[(int) $estorno->estorno_de] = $estorno;
            }
        }
        if (!$automaticos) {
            return 0;
        }
        $pedidos = DB::table(Lancamentos::TABELA)
            ->where('company_uuid', $companyUuid)
            ->where('tipo', 'corrida')
            ->whereIn('id', array_keys($automaticos))
            ->pluck('order_uuid')
            ->all();
        $pedidos = array_values(array_unique(array_filter(array_map('strval', $pedidos))));
        if (!$pedidos) {
            return 0;
        }

        // todas as corridas desses pedidos, por conta e pedido: só volta onde a última corrida tem o estorno automático
        $corridas = DB::table(Lancamentos::TABELA)
            ->where('company_uuid', $companyUuid)
            ->where('tipo', 'corrida')
            ->whereIn('order_uuid', $pedidos)
            ->orderBy('id')
            ->get()
            ->all();
        $estornadas = array_flip(Lancamentos::jaEstornados(array_map(fn ($l) => (int) $l->id, $corridas)));
        $grupos     = [];
        foreach ($corridas as $corrida) {
            $grupos[$corrida->conta_tipo . '|' . $corrida->conta_uuid . '|' . $corrida->order_uuid][] = $corrida;
        }
        $aRelancar = [];
        foreach ($grupos as $chave => $grupo) {
            $ultima = end($grupo);
            if (!isset($automaticos[(int) $ultima->id])) {
                continue; // a última corrida vale, ou foi estornada à mão
            }
            foreach ($grupo as $corrida) {
                if (!isset($estornadas[(int) $corrida->id])) {
                    continue 2; // há corrida valendo
                }
            }
            $aRelancar[$chave] = [$ultima, $automaticos[(int) $ultima->id], count($grupo) + 1];
        }
        if (!$aRelancar) {
            return 0;
        }

        // só os pedidos que estão de novo no relatório, desde a data da corrida mais antiga (um dia de folga: a data de
        // referência é no fuso da empresa e o período do relatório no do app)
        $alvos    = array_values(array_unique(array_map(fn ($item) => (string) $item[0]->order_uuid, $aRelancar)));
        $primeira = min(array_map(fn ($item) => substr((string) $item[0]->data_referencia, 0, 10), $aRelancar));
        session(['company' => $companyUuid]);
        $inicio   = Carbon::parse($primeira . ' 00:00:00', date_default_timezone_get())->subDays(1);
        $pagaveis = [];
        foreach ($this->calculo->pedidosConcluidos($companyUuid, $inicio, now(), fn ($query) => $query->whereIn('orders.uuid', $alvos)) as $pedido) {
            $pagaveis[(string) $pedido->uuid] = true;
        }

        $lancadas = 0;
        foreach ($aRelancar as [$corrida, $estorno, $n]) {
            if (!isset($pagaveis[(string) $corrida->order_uuid])) {
                continue;
            }
            $id = Lancamentos::inserir([
                'company_uuid'    => $companyUuid,
                'conta_tipo'      => $corrida->conta_tipo,
                'conta_uuid'      => $corrida->conta_uuid,
                'tipo'            => 'corrida',
                'valor'           => -round((float) $estorno->valor, 2),
                'data_referencia' => substr((string) $corrida->data_referencia, 0, 10),
                'order_uuid'      => $corrida->order_uuid,
                'motivo'          => static::MOTIVO_RELANCADO,
                'detalhes'        => $corrida->detalhes ?? null,
                'chave_unica'     => 'corrida:' . $corrida->conta_tipo . ':' . $corrida->order_uuid . ':' . $n,
            ]);
            $lancadas += $id ? 1 : 0;
        }

        return $lancadas;
    }

    /**
     * O que estornar das corridas de um pedido numa conta (lançamentos da conta com aquele order_uuid, em ordem de id):
     * [[corrida, parte em reais]]. Desconta o que o pedido já perdeu por ajuste negativo ("Não pagar", "Não cobrar",
     * desconto parcial) e pelos estornos de antes, para o pedido cancelado ficar com zero na conta, sem debitar duas vezes.
     * O líquido do pedido = corridas + ajustes negativos com o pedido + os estornos desses dois; cada corrida ainda não
     * estornada é estornada até esse líquido (nada se ele já é zero ou menos).
     */
    public static function estornosDevidos(array $grupo): array
    {
        $centavos = fn ($lancamento) => (int) round(((float) $lancamento->valor) * 100);
        $contam   = [];
        foreach ($grupo as $lancamento) {
            if ($lancamento->tipo === 'corrida' || ($lancamento->tipo === 'ajuste' && $centavos($lancamento) < 0)) {
                $contam[(int) $lancamento->id] = true;
            }
        }

        $liquido    = 0;
        $estornados = [];
        foreach ($grupo as $lancamento) {
            if (isset($contam[(int) $lancamento->id])) {
                $liquido += $centavos($lancamento);
            } elseif ($lancamento->tipo === 'estorno' && $lancamento->estorno_de !== null && isset($contam[(int) $lancamento->estorno_de])) {
                $liquido                                  += $centavos($lancamento);
                $estornados[(int) $lancamento->estorno_de] = true;
            }
        }

        $devidos = [];
        foreach ($grupo as $lancamento) {
            if ($liquido <= 0) {
                break;
            }
            if ($lancamento->tipo !== 'corrida' || isset($estornados[(int) $lancamento->id])) {
                continue;
            }
            $parte = min($centavos($lancamento), $liquido);
            if ($parte > 0) {
                $devidos[] = [$lancamento, $parte / 100];
                $liquido  -= $parte;
            }
        }

        return $devidos;
    }

    /** Usado pelo job do observador (LancarCorridaDoPedido): lança a corrida de um pedido, se ele estiver no relatório. */
    public function lancarPedido(string $orderUuid): void
    {
        $pedido = Order::where('uuid', $orderUuid)->first();
        if (!$pedido) {
            return;
        }
        $companyUuid = (string) $pedido->company_uuid;
        $corte       = Financeiro::corte($companyUuid);
        if ($corte === null) {
            return;
        }

        session(['company' => $companyUuid]);
        // do início do dia do corte (no fuso da empresa), levado ao fuso do app, que é o da sessão do MySQL
        $inicio  = Carbon::parse($corte . ' 00:00:00', Financeiro::fuso($companyUuid))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $pedidos = $this->calculo->pedidosConcluidos($companyUuid, $inicio, now(), fn ($query) => $query->where('orders.uuid', $orderUuid));

        $this->lancar($companyUuid, $pedidos);
    }

    /** Detalhes da corrida (iguais nas duas contas: nenhum valor, só a faixa com os limites). */
    private static function detalhes(array $entrega): array
    {
        $faixa = $entrega['faixa'] ?? null;

        return [
            'pedido'           => $entrega['pedido'] ?? null,
            'numero'           => $entrega['id_interno'] ?? null,
            'km'               => $entrega['km'] ?? null,
            'faixa'            => is_array($faixa) ? [
                'de_km'  => (float) ($faixa['de_km'] ?? 0),
                'ate_km' => (float) ($faixa['ate_km'] ?? 0),
                'acima'  => !empty($faixa['acima']),
            ] : null,
            'loja_nome'        => $entrega['loja_nome'] ?? null,
            'destino'          => $entrega['destino'] ?? null,
            'cancelado_pago'   => (bool) ($entrega['cancelado_pago'] ?? false),
            'cancelado_origem' => $entrega['cancelado_origem'] ?? null,
        ];
    }
}
