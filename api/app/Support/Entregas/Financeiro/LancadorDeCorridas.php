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

            $motoboy = Lancamentos::inserir($base + [
                'conta_tipo'  => Financeiro::CONTA_MOTOBOY,
                'conta_uuid'  => $entrega['motoboy_uuid'],
                'valor'       => (float) $entrega['valor_motoboy'],
                'chave_unica' => 'corrida:' . Financeiro::CONTA_MOTOBOY . ':' . $entrega['order_uuid'],
            ]);
            $resultado['lancados'] += $motoboy ? 1 : 0;

            if (!empty($entrega['loja_uuid']) && $entrega['valor_loja'] !== null) {
                $loja = Lancamentos::inserir($base + [
                    'conta_tipo'  => Financeiro::CONTA_LOJA,
                    'conta_uuid'  => $entrega['loja_uuid'],
                    'valor'       => (float) $entrega['valor_loja'],
                    'chave_unica' => 'corrida:' . Financeiro::CONTA_LOJA . ':' . $entrega['order_uuid'],
                ]);
                $resultado['lancados'] += $loja ? 1 : 0;
            }
        }

        return $resultado;
    }

    /**
     * Pedidos concluídos (ou cancelados pagos) de $inicio até agora sem lançamento de corrida do motoboy: lança o que der e
     * devolve ['lancados' => int, 'sem_valor' => int, 'sem_lancamento' => [['id' => public_id, 'numero' => internal_id,
     * 'concluido_em' => 'Y-m-d H:i:s' no fuso do app]]] (os que continuam sem lançamento: sem valor, antes do corte...).
     * O painel usa `sem_lancamento` para as "corridas sem valor". Sem data de corte, nem consulta.
     */
    public function conferir(string $companyUuid, Carbon $inicio): array
    {
        $resultado = ['lancados' => 0, 'sem_valor' => 0, 'sem_lancamento' => []];
        if (Financeiro::corte($companyUuid) === null) {
            return $resultado;
        }

        $pedidos = $this->pedidosSemLancamento($companyUuid, $inicio);
        if ($pedidos->isEmpty()) {
            return $resultado;
        }

        $resultado = $this->lancar($companyUuid, $pedidos) + $resultado;

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

        $lancamentos = DB::table(Lancamentos::TABELA)
            ->where('company_uuid', $companyUuid)
            ->where('tipo', 'corrida')
            ->whereIn('order_uuid', $alvos)
            ->get()
            ->all();
        $jaEstornados = array_flip(Lancamentos::jaEstornados(array_map(fn ($l) => (int) $l->id, $lancamentos)));

        $estornos = 0;
        foreach ($lancamentos as $lancamento) {
            if (isset($jaEstornados[(int) $lancamento->id])) {
                continue;
            }
            if (Lancamentos::estornar($lancamento, null, static::MOTIVO_CANCELADO)) {
                $estornos++;
            }
        }

        return $estornos;
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
