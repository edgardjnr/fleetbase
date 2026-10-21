<?php

namespace App\Support\Entregas;

use App\Support\Entregas\Financeiro\Lancamentos;
use App\Support\Entregas\Financeiro\Multiplicadores;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\OSRM;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: pagamento dos motoboys e cobrança das lojas por faixa de km.
 * Usado pela tela "Pagamento e cobrança" (PagamentoMotoboysController) e pelo extrato do
 * portal da loja (PortalLojaController).
 *
 * Uma tabela de faixas só (`entregas.faixas`): cada faixa tem o km máximo e dois valores, o pago
 * ao motoboy e o cobrado da loja. A entrega vale o valor da faixa em que o km dela cai
 * (0 < km ≤ 1 → 1ª faixa, 1 < km ≤ 2 → 2ª…). Acima da última faixa vale o valor da última.
 *
 * A loja de cada pedido é o Vendor dono do pedido, achado pelo uuid do cliente (`customer_uuid`),
 * como nos pedidos do portal da loja e nos que a central cria escolhendo a loja. O `customer_type`
 * não serve de critério: varia conforme a rota que criou o pedido (o console e a API v1 gravam a
 * classe com a barra inicial, o portal sem). Pedidos sem loja caem no nome do local de coleta
 * (lojas com o mesmo nome são agrupadas, porque a integração pode criar um Place novo por pedido);
 * sem nome, no próprio Place.
 *
 * O km de cada entrega é a rota de rua loja (pickup) → cliente (dropoff), calculada pelo OSRM
 * uma única vez e guardada no meta do pedido (`entregas.km_rota`); se depois o endereço for apagado
 * ou perder a posição, vale o km guardado. O campo `orders.distance` não serve: é a distância
 * *restante*, que vai a ~0 quando o pedido termina.
 *
 * O período é filtrado pela data em que o pedido foi concluído (tracking status COMPLETED),
 * no fuso da organização. O pedido iFood cancelado pelo iFood depois do dispatch (pago_mesmo_cancelado, na
 * entregas_ifood_pedidos) também entra, pela data do cancelamento (CancelamentoPeloIfood): o motoboy recebe e a loja é
 * cobrada pelo valor da faixa, e a linha sai com cancelado_pago = true.
 *
 * O valor de cada entrega é congelado (ValoresCongelados, tabela entregas_valores_pedido): na primeira vez que o pedido
 * tem km e há faixas cadastradas, a faixa e os dois valores ficam gravados, e uma tabela de faixas nova só vale para as
 * entregas calculadas depois (o app do motoboy calcula ao mostrar o pedido no card de aceitar). Se o km mudar (endereço
 * alterado, estimativa trocada pela rota do OSRM), o valor é recalculado com a tabela vigente, a não ser que o pedido já
 * tenha a corrida lançada no financeiro (entregas_lancamentos, App\Support\Entregas\Financeiro): depois de lançado, o
 * valor não muda mais (correção só por ajuste).
 *
 * Entrega junto e multiplicador (spec docs/superpowers/specs/2026-10-21-entrega-junto-e-multiplicador-design.md): a linha
 * congelada guarda também a faixa da segunda tabela (`entregas.faixas_juntas`, "a partir da 2ª entrega junto") e, quando o
 * pedido ganha motoboy (registrarAtribuicao, pelo ObservadorDoValorDaEntrega), a posição dele (2ª ou mais = segunda tabela)
 * e o multiplicador do período ativo. O valor final sai do ValorDaEntrega::final. Pedido concluído sem atribuição gravada
 * (escapou do observador) é reconstituído pelos horários (aplicarReserva).
 */
class CalculoEntregas
{
    /** Chave da tabela de faixas nas configurações da organização. */
    public const CHAVE_FAIXAS = 'entregas.faixas';

    /** Segunda tabela ("a partir da 2ª entrega junto"), no mesmo formato. Vazia = recurso desligado. */
    public const CHAVE_FAIXAS_JUNTAS = 'entregas.faixas_juntas';

    public const MAX_FAIXAS = 50;

    /** Quantas rotas novas calcular por chamada, por padrão (a tela repete enquanto houver pendentes). */
    public const LIMITE_CALCULOS = 40;

    /**
     * Data da entrega no relatório, na cobrança e nos ganhos: a do COMPLETED (ou o updated_at) para o pedido concluído; a
     * do cancelamento pelo iFood para o pago mesmo cancelado (o único não concluído que entra: ver pedidosConcluidos).
     * O pedido de outro sistema cancelado pela origem depois do "A caminho" (entregas_integracao_pedidos) também conta pela data do cancelamento.
     */
    public const DATA_DA_ENTREGA = "CASE WHEN orders.status = 'completed' THEN COALESCE(conclusoes.concluido_em, orders.updated_at) ELSE COALESCE(entregas_ifood.cancelado_pelo_ifood_em, entregas_integracao.cancelado_em) END";

    /** Linha reta → rua, usado só quando o OSRM não responde. */
    public const FATOR_ESTIMATIVA = 1.3;

    /**
     * Disjuntor do OSRM. Depois da primeira falha do serviço numa chamada de entregas() (ver
     * metrosPeloOsrm), as rotas seguintes vão direto para a estimativa (linha reta × FATOR_ESTIMATIVA),
     * sem chamar o OSRM. O OSRM configurado é o servidor público de demonstração, e cada tentativa
     * espera até ~1 s (timeout do Fleet-Ops). A estimativa continua sendo refeita nas próximas
     * consultas. Zerado no início de entregas(), para o estado não vazar entre chamadas se a classe
     * for reaproveitada (singleton, injeção no construtor com Octane).
     */
    protected bool $osrmFalhou = false;

    protected ValoresCongelados $congelados;

    protected AtribuicaoDoValor $atribuicao;

    public function __construct(?ValoresCongelados $congelados = null, ?AtribuicaoDoValor $atribuicao = null)
    {
        $this->congelados = $congelados ?? new ValoresCongelados();
        $this->atribuicao = $atribuicao ?? new AtribuicaoDoValor();
    }

    /**
     * Pedidos concluídos no período, com a data de conclusão em `entregas_concluido_em`, e os pedidos iFood e da integração pagos mesmo
     * cancelados, pela data do cancelamento (`entregas_cancelado_pago` = 1).
     * $filtro recebe a query para restringir (por motoboy, por loja); use colunas com a tabela (orders.…).
     */
    public function pedidosConcluidos(string $companyUuid, Carbon $inicio, Carbon $fim, ?\Closure $filtro = null): Collection
    {
        $conclusoes = DB::table('tracking_statuses')
            ->select('tracking_number_uuid', DB::raw('MIN(created_at) as concluido_em'))
            ->where('company_uuid', $companyUuid)
            ->where('code', 'COMPLETED')
            ->whereNull('deleted_at')
            ->groupBy('tracking_number_uuid');

        return Order::query()
            ->leftJoinSub($conclusoes, 'conclusoes', 'conclusoes.tracking_number_uuid', '=', 'orders.tracking_number_uuid')
            // a linha do iFood (uma por pedido, order_uuid único): o pago mesmo cancelado
            ->leftJoin('entregas_ifood_pedidos as entregas_ifood', 'entregas_ifood.order_uuid', '=', 'orders.uuid')
            // a linha da integração de outros sistemas (uma por pedido, order_uuid único): o pago mesmo cancelado pela origem
            ->leftJoin('entregas_integracao_pedidos as entregas_integracao', 'entregas_integracao.order_uuid', '=', 'orders.uuid')
            ->where('orders.company_uuid', $companyUuid)
            ->where(fn ($situacao) => $situacao->where('orders.status', 'completed')
                ->orWhere(fn ($pago) => $pago->where('entregas_ifood.pago_mesmo_cancelado', true)->whereNotNull('entregas_ifood.cancelado_pelo_ifood_em'))
                ->orWhere(fn ($pago) => $pago->where('entregas_integracao.pago_mesmo_cancelado', true)->whereNotNull('entregas_integracao.cancelado_em')))
            ->whereNull('orders.deleted_at')
            ->whereNotNull('orders.driver_assigned_uuid')
            ->whereBetween(DB::raw(static::DATA_DA_ENTREGA), [$inicio, $fim])
            // o filtro vai entre parênteses: um orWhere dele não escapa da empresa, do status e do período
            // (é o isolamento do extrato da loja). A condição é booleana porque o when() executaria a closure
            ->when($filtro !== null, fn ($query) => $query->where(fn ($grupo) => $filtro($grupo)))
            ->select('orders.*', DB::raw(static::DATA_DA_ENTREGA . ' as entregas_concluido_em'), DB::raw("CASE WHEN orders.status = 'completed' THEN 0 ELSE 1 END as entregas_cancelado_pago"), DB::raw("CASE WHEN entregas_ifood.cancelado_pelo_ifood_em IS NOT NULL THEN 'ifood' WHEN entregas_integracao.cancelado_em IS NOT NULL THEN 'integracao' END as entregas_cancelado_origem"))
            // coleta, destino e nome do motoboy em lote, sem uma consulta por pedido
            ->with(['payload.pickup', 'payload.dropoff', 'payload.waypoints', 'driverAssigned.user'])
            ->orderBy('entregas_concluido_em')
            ->get();
    }

    /**
     * Uma linha por pedido, na ordem de conclusão: loja, motoboy, km, faixa e os dois valores (congelados: ver
     * valorCongelado). Calcula no máximo $limiteCalculos rotas (o extrato do portal da loja e o app do motoboy passam um
     * limite menor), primeiro as das entregas sem km e depois as estimativas a refazer.
     * Retorna [entregas, pendentes] (pendentes = pedidos ainda sem km).
     */
    public function entregas(Collection $pedidos, string $fuso, int $limiteCalculos = self::LIMITE_CALCULOS): array
    {
        $this->osrmFalhou = false;

        $pendentes    = 0;
        $entregas     = [];
        $gravar       = [];
        $reserva      = [];
        $faixas       = $this->faixas();
        $faixasJuntas = $this->faixasJuntas();
        $lojas        = $this->lojasDosPedidos($pedidos);
        $comCota      = $this->pedidosComCota($pedidos, $limiteCalculos);
        $congelados   = $this->congelados->carregar($pedidos->pluck('uuid')->all());
        $lancados     = $this->pedidosLancados($pedidos->pluck('uuid')->all());

        foreach ($pedidos as $i => $pedido) {
            $rota = $this->rotaDoPedido($pedido, isset($comCota[$i]));
            if ($rota === null) {
                $pendentes++;
            }

            $motoboy           = $pedido->driverAssigned;
            $coleta            = $pedido->payload?->getPickupOrFirstWaypoint();
            $km                = $rota ? round($rota['metros'] / 1000, 2) : null;
            $usada             = null;
            $lancado           = isset($lancados[$pedido->uuid]);
            $valor             = $rota ? $this->valorCongelado($pedido, $rota, $faixas, $faixasJuntas, $congelados[$pedido->uuid] ?? null, $gravar, $lancado, $usada) : null;
            [$loja, $lojaNome] = $this->lojaDoPedido($pedido, $coleta, $lojas);

            // pedido que escapou do observador (sem a atribuição deste motoboy): reconstituído pelos horários no fim
            $motoboyUuid = (string) ($pedido->driver_assigned_uuid ?: $motoboy?->uuid);
            if ($usada !== null && !$lancado && $motoboyUuid !== '' && (string) ($usada['motoboy_uuid'] ?? '') !== $motoboyUuid) {
                $instante = $this->instanteDoAceite($pedido);
                if ($instante !== null) {
                    $reserva[count($entregas)] = ['uuid' => (string) $pedido->uuid, 'motoboy' => $motoboyUuid, 'instante' => $instante, 'linha' => $usada];
                }
            }

            $entregas[] = [
                'loja'          => $loja,
                'loja_nome'     => $lojaNome,
                'pedido'        => $pedido->public_id,
                'id_interno'    => $pedido->internal_id,
                'motoboy'       => $motoboy?->public_id,
                'motoboy_nome'  => $motoboy?->name,
                // texto cru do COALESCE, na hora da sessão do MySQL, que é a do fuso do app
                'concluido_em'  => Carbon::parse($pedido->entregas_concluido_em, date_default_timezone_get())->setTimezone($fuso)->toIso8601String(),
                'origem'        => $this->enderecoCurto($coleta),
                'destino'       => $this->enderecoCurto($pedido->payload?->getDropoffOrLastWaypoint()),
                'km'            => $km,
                'fonte'         => $rota['fonte'] ?? null,
                'faixa'         => $valor['faixa'] ?? null,
                'valor_motoboy' => $valor['motoboy'] ?? null,
                'valor_loja'    => $valor['loja'] ?? null,
                // pedido iFood cancelado pelo iFood depois do dispatch: conta pela data do cancelamento
                'cancelado_pago' => (bool) ($pedido->entregas_cancelado_pago ?? false),
                // quem cancelou o pago mesmo cancelado: 'ifood' ou 'integracao' (o sistema de origem); null no concluído
                'cancelado_origem' => ($pedido->entregas_cancelado_pago ?? false) ? ($pedido->entregas_cancelado_origem ?? null) : null,
                // para o lançador do financeiro (LancadorDeCorridas): o pedido, o motoboy atribuído e a loja (Vendor) dona
                'order_uuid'    => $pedido->uuid,
                'motoboy_uuid'  => $pedido->driver_assigned_uuid ?: $motoboy?->uuid,
                'loja_uuid'     => $lojas->get($pedido->customer_uuid)?->uuid,
                // entrega junto e multiplicador (ValorDaEntrega): a posição, a tabela usada e os fatores (só para a central:
                // o app e o portal recebem o fator da própria conta)
                'posicao'       => $valor['posicao'] ?? null,
                'tabela'        => $valor['tabela'] ?? null,
                'multiplicador' => $valor['multiplicador'] ?? null,
            ];
        }

        $this->congelados->gravar($gravar);
        $this->aplicarReserva((string) ($pedidos->first()?->company_uuid ?? ''), $reserva, $entregas);

        return [$entregas, $pendentes];
    }

    /**
     * Km e valor de um pedido para o app do motoboy (card de aceitar e detalhes): calcula a rota agora, se faltar, e
     * congela o valor como em entregas(). Pedido ainda sem motoboy (o card de aceitar, inclusive a oferta da distribuição,
     * que não preenche o driver_assigned_uuid): a prévia (comPrevia), com a posição que o pedido teria para $motoboyUuid
     * (sem ele, a 1ª) e o multiplicador de agora; nada da prévia é gravado. Pedido com motoboy fica com a atribuição
     * gravada; se ela faltar (escapou do observador), com o valor sem a prévia: um pedido antigo, concluído ou lançado,
     * não ganha o multiplicador de agora nos detalhes, e a reserva do entregas() reconstitui pelos horários.
     *
     * @return array{km: ?float, fonte: ?string, faixa: ?array, valor_motoboy: ?float, posicao: ?int, tabela: ?string, multiplicador: ?array}
     */
    public function valorDoPedido(Order $pedido, ?string $motoboyUuid = null): array
    {
        $this->osrmFalhou = false;

        $rota = $this->rotaDoPedido($pedido, true);
        if ($rota === null) {
            return ['km' => null, 'fonte' => null, 'faixa' => null, 'valor_motoboy' => null, 'posicao' => null, 'tabela' => null, 'multiplicador' => null];
        }

        $gravar = [];
        $usada  = null;
        $linha  = $this->congelados->carregar([$pedido->uuid])[$pedido->uuid] ?? null;
        // a consulta ao financeiro só quando o km mudou (o card de aceitar chama a cada exibição)
        $mudou  = $linha !== null && (int) $linha['metros'] !== (int) round((float) $rota['metros']);
        $valor  = $this->valorCongelado($pedido, $rota, $this->faixas(), $this->faixasJuntas(), $linha, $gravar, $mudou && isset($this->pedidosLancados([$pedido->uuid])[$pedido->uuid]), $usada);
        $this->congelados->gravar($gravar);

        if ($usada !== null && empty($usada['motoboy_uuid']) && empty($pedido->driver_assigned_uuid)) {
            $valor = $this->comPrevia($pedido, $usada, $motoboyUuid) ?? $valor;
        }

        return [
            'km'            => round($rota['metros'] / 1000, 2),
            'fonte'         => $rota['fonte'] ?? null,
            'faixa'         => $valor['faixa'] ?? null,
            'valor_motoboy' => $valor['motoboy'] ?? null,
            'posicao'       => $valor['posicao'] ?? null,
            'tabela'        => $valor['tabela'] ?? null,
            'multiplicador' => $valor['multiplicador'] ?? null,
        ];
    }

    /**
     * O pedido ganhou (ou perdeu) motoboy: grava a parte da atribuição do valor congelado (motoboy, agora, posição e o
     * multiplicador do período ativo), congelando o km antes se faltar. Pedido com a corrida lançada no financeiro não
     * muda. Sem km (sem endereço) não grava: a reserva do entregas() reconstitui pelos horários quando ele concluir.
     * Chamado pelo ObservadorDoValorDaEntrega, com a empresa do pedido na sessão (as faixas são da empresa da sessão).
     */
    public function registrarAtribuicao(Order $pedido): void
    {
        $this->osrmFalhou = false;

        $uuid = (string) $pedido->uuid;
        if (isset($this->pedidosLancados([$uuid])[$uuid])) {
            return;
        }

        $motoboy = (string) ($pedido->driver_assigned_uuid ?? '');
        $linha   = $this->congelados->carregar([$uuid])[$uuid] ?? null;

        if ($motoboy === '') {
            if ($linha !== null && !empty($linha['motoboy_uuid'])) {
                $this->congelados->gravarAtribuicoes([array_fill_keys(ValorDaEntrega::COLUNAS_DA_ATRIBUICAO, null) + $linha]);
            }

            return;
        }

        $rota = $this->rotaDoPedido($pedido, true);
        if ($rota === null) {
            Log::info('[entregas] valor: pedido sem km na atribuição; fica para a reserva por horários', ['pedido' => $pedido->public_id]);

            return;
        }

        $gravar = [];
        $usada  = null;
        $this->valorCongelado($pedido, $rota, $this->faixas(), $this->faixasJuntas(), $linha, $gravar, false, $usada);
        $this->congelados->gravar($gravar);
        if ($usada === null) {
            return; // sem faixas cadastradas: não há valor para atribuir
        }

        $empresa    = (string) $pedido->company_uuid;
        $agora      = now()->format('Y-m-d H:i:s');
        $atribuicao = $this->atribuicaoNova($motoboy, $agora, 1 + $this->atribuicao->emAberto($empresa, $motoboy, $uuid), $this->atribuicao->multiplicadorEm($empresa, $agora));
        $this->congelados->gravarAtribuicoes([$atribuicao + $usada]);
    }

    /** A prévia antes da atribuição (não grava). Falha na consulta: null (vale o valor sem a prévia), e o log. */
    protected function comPrevia(Order $pedido, array $linha, ?string $motoboyUuid): ?array
    {
        try {
            $empresa = (string) $pedido->company_uuid;
            $agora   = now()->format('Y-m-d H:i:s');
            $posicao = $motoboyUuid ? 1 + $this->atribuicao->emAberto($empresa, $motoboyUuid, (string) $pedido->uuid) : 1;
            $periodo = $this->atribuicao->multiplicadorEm($empresa, $agora);
        } catch (\Throwable $e) {
            Log::warning('[entregas] valor: não deu para montar a prévia do pedido; vale o valor sem ela', ['pedido' => $pedido->public_id, 'erro' => get_class($e)]);

            return null;
        }

        return ValorDaEntrega::final($this->atribuicaoNova((string) $motoboyUuid, $agora, $posicao, $periodo) + $linha);
    }

    /** A parte da atribuição de uma linha (ValorDaEntrega::COLUNAS_DA_ATRIBUICAO), com as cópias do período. */
    protected function atribuicaoNova(string $motoboyUuid, string $instante, int $posicao, ?object $periodo): array
    {
        return [
            'motoboy_uuid'       => $motoboyUuid !== '' ? $motoboyUuid : null,
            'atribuido_em'       => $instante,
            'posicao'            => max(1, min(255, $posicao)),
            'multiplicador_uuid' => $periodo ? (string) $periodo->uuid : null,
            'multiplicador_nome' => $periodo ? (string) $periodo->nome : null,
            'mult_motoboy'       => $periodo ? round((float) $periodo->mult_motoboy, 2) : null,
            'mult_loja'          => $periodo ? round((float) $periodo->mult_loja, 2) : null,
        ];
    }

    /**
     * Reconstitui a atribuição dos pedidos concluídos que escaparam do observador (atribuição pelo query builder,
     * saveQuietly, pedido de antes do deploy), pelo horário do aceite. Só quando muda o valor: havia segunda tabela no
     * cálculo ou um período no instante. Uma consulta de períodos e uma de posições para a lista toda.
     *
     * @param array<int, array{uuid: string, motoboy: string, instante: string, linha: array}> $reserva índice em $entregas => pedido
     */
    protected function aplicarReserva(string $empresa, array $reserva, array &$entregas): void
    {
        if (!$reserva || $empresa === '') {
            return;
        }

        $instantes = array_column($reserva, 'instante');
        $periodos  = $this->atribuicao->multiplicadoresDoPeriodo($empresa, min($instantes), max($instantes));

        $precisa = [];
        foreach ($reserva as $indice => $item) {
            $periodo = Multiplicadores::noInstante($periodos, $item['instante']);
            if ($periodo !== null || ($item['linha']['junto_motoboy'] ?? null) !== null) {
                $precisa[$indice] = $item + ['periodo' => $periodo];
            }
        }
        if (!$precisa) {
            return;
        }

        $posicoes = $this->atribuicao->posicoesPorHorarios($empresa, array_values(array_map(fn ($item) => ['uuid' => $item['uuid'], 'motoboy' => $item['motoboy'], 'instante' => $item['instante']], $precisa)));

        $gravar = [];
        foreach ($precisa as $indice => $item) {
            $linha    = $this->atribuicaoNova($item['motoboy'], $item['instante'], $posicoes[$item['uuid']] ?? 1, $item['periodo']) + $item['linha'];
            $gravar[] = $linha;
            $valor    = ValorDaEntrega::final($linha);

            $entregas[$indice]['faixa']         = $valor['faixa'];
            $entregas[$indice]['valor_motoboy'] = $valor['motoboy'];
            $entregas[$indice]['valor_loja']    = $valor['loja'];
            $entregas[$indice]['posicao']       = $valor['posicao'];
            $entregas[$indice]['tabela']        = $valor['tabela'];
            $entregas[$indice]['multiplicador'] = $valor['multiplicador'];
        }

        $this->congelados->gravarAtribuicoes($gravar);
    }

    /** Aceite do pedido em Brasília ('Y-m-d H:i:s'): started_at, ou o dispatched_at (atribuído pela central); null sem eles. */
    protected function instanteDoAceite(Order $pedido): ?string
    {
        $valor = $pedido->started_at ?: $pedido->dispatched_at;
        if (!$valor) {
            return null;
        }

        return $valor instanceof \DateTimeInterface ? $valor->format('Y-m-d H:i:s') : substr((string) $valor, 0, 19);
    }

    /**
     * Valor da entrega para esta rota: o da linha congelada, se foi calculada com o mesmo km (metros); senão, as faixas
     * das duas tabelas vigentes, que entram em $gravar (congela, ou recongela quando o km mudou; a atribuição já gravada
     * continua valendo). Pedido com a corrida lançada no financeiro ($lancado) fica com o congelado, mesmo com outro km.
     * Sem faixas cadastradas e sem valor congelado para este km, null. $usada recebe a linha que deu o valor (para a
     * prévia, a atribuição e a reserva).
     *
     * @return array{faixa: array, motoboy: float, loja: float, tabela: string, posicao: ?int, multiplicador: ?array}|null
     */
    protected function valorCongelado(Order $pedido, array $rota, array $faixas, array $faixasJuntas, ?array $linha, array &$gravar, bool $lancado = false, ?array &$usada = null): ?array
    {
        $usada  = null;
        $metros = (int) round((float) $rota['metros']);
        if ($linha !== null && ($lancado || (int) $linha['metros'] === $metros)) {
            $usada = $linha;

            return ValorDaEntrega::final($linha);
        }

        // a faixa sai do mesmo km exibido (metros reais); os metros inteiros só identificam o km da linha congelada
        $km    = round((float) $rota['metros'] / 1000, 2);
        $faixa = $this->faixaDoKm($faixas, $km);
        if ($faixa === null) {
            return null;
        }
        $junto = $this->faixaDoKm($faixasJuntas, $km);

        $nova = [
            'company_uuid'  => $pedido->company_uuid,
            'order_uuid'    => $pedido->uuid,
            'chave'         => $rota['chave'] ?? null,
            'metros'        => $metros,
            'fonte'         => $rota['fonte'] ?? 'osrm',
            'de_km'         => $faixa['de_km'],
            'ate_km'        => $faixa['ate_km'],
            'acima'         => !empty($faixa['acima']),
            'valor_motoboy' => $faixa['motoboy'],
            'valor_loja'    => $faixa['loja'],
            'junto_de_km'   => $junto['de_km'] ?? null,
            'junto_ate_km'  => $junto['ate_km'] ?? null,
            'junto_acima'   => $junto !== null ? !empty($junto['acima']) : null,
            'junto_motoboy' => $junto['motoboy'] ?? null,
            'junto_loja'    => $junto['loja'] ?? null,
        ];
        $gravar[] = $nova;
        $usada    = $nova + array_intersect_key($linha ?? [], array_flip(ValorDaEntrega::COLUNAS_DA_ATRIBUICAO));

        return ValorDaEntrega::final($usada);
    }

    /**
     * Pedidos (uuid => true) que já têm a corrida lançada no financeiro, numa consulta por lote de mil. Se a tabela ainda
     * não existe (API nova antes da migration) ou o banco falha, nenhum: o relatório e o app seguem como antes.
     */
    protected function pedidosLancados(array $orderUuids): array
    {
        $orderUuids = array_values(array_unique(array_filter($orderUuids)));
        $lancados   = [];

        try {
            foreach (array_chunk($orderUuids, 1000) as $lote) {
                foreach (DB::table(Lancamentos::TABELA)->whereIn('order_uuid', $lote)->where('tipo', 'corrida')->pluck('order_uuid') as $uuid) {
                    $lancados[(string) $uuid] = true;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[entregas] financeiro: não deu para ler as corridas lançadas; o valor congelado segue a regra antiga', ['erro' => get_class($e)]);

            return [];
        }

        return $lancados;
    }

    /**
     * Loja do pedido: o Vendor cujo uuid é o do cliente do pedido (seja qual for o `customer_type`);
     * sem loja, o nome do local de coleta; sem nome, o Place.
     *
     * @return array{0: ?string, 1: ?string} [chave, nome]
     */
    public function lojaDoPedido(Order $pedido, ?Place $coleta, Collection $lojas): array
    {
        $vendor = $lojas->get($pedido->customer_uuid);
        if ($vendor) {
            return ['loja:' . $vendor->public_id, $vendor->name ?: $vendor->public_id];
        }

        if (!$coleta) {
            return [null, null];
        }

        $nome = trim(mb_strtolower((string) $coleta->name));

        return [$nome !== '' ? 'nome:' . $nome : $coleta->public_id, $coleta->name ?: $this->enderecoCurto($coleta)];
    }

    /**
     * Lojas (Vendor) donas dos pedidos, numa consulta só, indexadas pelo uuid.
     * Procura pelo uuid do cliente de todos os pedidos, sem olhar o `customer_type`: o uuid de um
     * contato nunca está em `vendors`. Inclui lojas excluídas, para o histórico da cobrança não mudar
     * de agrupamento, e dispensa o `place` que o Vendor sempre carrega ($with), que aqui não é usado.
     */
    protected function lojasDosPedidos(Collection $pedidos): Collection
    {
        $uuids = $pedidos->pluck('customer_uuid')->filter()->unique()->values();

        return $uuids->isEmpty() ? collect() : Vendor::withTrashed()->without('place')->whereIn('uuid', $uuids)->get()->keyBy('uuid');
    }

    /** Faixas salvas, em ordem crescente de km. */
    public function faixas(): array
    {
        $faixas = Setting::lookupCompany(static::CHAVE_FAIXAS, []);

        return $this->normalizarFaixas(is_array($faixas) ? $faixas : []);
    }

    /** Faixas da segunda tabela, em ordem crescente de km (vazia = todas as entregas pela tabela normal). */
    public function faixasJuntas(): array
    {
        $faixas = Setting::lookupCompany(static::CHAVE_FAIXAS_JUNTAS, []);

        return $this->normalizarFaixas(is_array($faixas) ? $faixas : []);
    }

    public function normalizarFaixas(array $faixas): array
    {
        $faixas = array_map(fn ($faixa) => [
            'ate_km'  => round((float) data_get($faixa, 'ate_km'), 2),
            'motoboy' => round((float) data_get($faixa, 'motoboy'), 2),
            'loja'    => round((float) data_get($faixa, 'loja'), 2),
        ], array_values($faixas));

        usort($faixas, fn ($a, $b) => $a['ate_km'] <=> $b['ate_km']);

        return $faixas;
    }

    /** Faixa em que o km cai; acima da última, a última. Sem faixas cadastradas, null. */
    public function faixaDoKm(array $faixas, float $km): ?array
    {
        if (!$faixas) {
            return null;
        }

        $anterior = 0.0;
        foreach ($faixas as $faixa) {
            if ($km <= $faixa['ate_km']) {
                return ['de_km' => $anterior] + $faixa;
            }
            $anterior = $faixa['ate_km'];
        }

        $ultima = end($faixas);

        return ['de_km' => $ultima['ate_km'], 'acima' => true] + $ultima;
    }

    /** Resumo por motoboy ou por loja: entregas, km e soma dos valores da faixa. */
    public function agrupar(array $entregas, string $chave, array $campos, string $campoValor)
    {
        return collect($entregas)
            ->groupBy(fn ($item) => $item[$chave] ?? 'sem-' . $chave)
            ->map(function ($itens) use ($campos, $campoValor) {
                $resumo = [];
                foreach ($campos as $campo) {
                    $resumo[$campo] = $itens->first()[$campo];
                }

                return $resumo + [
                    'entregas' => $itens->count(),
                    'sem_km'   => $itens->whereNull('km')->count(),
                    'km'       => round($itens->sum(fn ($item) => $item['km'] ?? 0), 2),
                    'valor'    => round($itens->sum(fn ($item) => $item[$campoValor] ?? 0), 2),
                ];
            });
    }

    /**
     * Rota loja → cliente em metros, do cache no meta do pedido ou calculada agora.
     * Retorna null quando falta endereço (e não há km guardado) ou quando o limite de cálculos
     * desta chamada acabou. Uma estimativa vinda de resposta definitiva do OSRM (`definitiva`) não é
     * refeita; uma vinda de falha do serviço é refeita quando houver folga.
     */
    public function rotaDoPedido(Order $pedido, bool $podeCalcular, ?bool &$calculado = null): ?array
    {
        $calculado = false;

        [$situacao, $origem, $destino, $chave, $cache] = $this->situacaoDaRota($pedido);

        // endereço apagado ou sem posição depois do cálculo: vale o km já calculado
        if ($situacao === 'sem_endereco') {
            return is_array($cache) && isset($cache['metros']) ? $cache : null;
        }

        // estimativa por falha do OSRM é recalculada quando houver folga; a de resposta definitiva, não
        if ($situacao === 'pronto' || ($situacao === 'refazer' && !$podeCalcular)) {
            return $cache;
        }

        if (!$podeCalcular) {
            return null;
        }

        $calculado = true;
        $rota      = ['metros' => 0, 'fonte' => 'osrm', 'chave' => $chave];
        $metros    = $this->osrmFalhou ? null : $this->metrosPeloOsrm($pedido, $origem, $destino);

        if ($metros !== null && $metros > 0) {
            $rota['metros'] = $metros;
        } else {
            $linhaReta      = Utils::calculateDrivingDistanceAndTime($origem->location, $destino->location);
            $rota['metros'] = round($linhaReta->distance * static::FATOR_ESTIMATIVA);
            $rota['fonte']  = 'estimativa';

            // resposta definitiva do OSRM (0 m, sem rota): não refaz enquanto as coordenadas forem as mesmas
            if ($metros !== null) {
                $rota['definitiva'] = true;
            }
        }

        // sem mexer no updated_at: ele é o fallback da data de conclusão no filtro do período
        $pedido->timestamps = false;
        $pedido->updateMeta('entregas.km_rota', $rota);

        return $rota;
    }

    /**
     * Situação do km do pedido: [situação, origem, destino, chave das coordenadas, cache].
     * - 'sem_endereco': coleta ou destino sem posição (vale o km guardado, se houver);
     * - 'pronto': km guardado para estas coordenadas, do OSRM ou de resposta definitiva dele;
     * - 'refazer': estimativa guardada por falha do OSRM, refeita quando houver folga;
     * - 'calcular': sem km para estas coordenadas.
     */
    protected function situacaoDaRota(Order $pedido): array
    {
        $cache   = $pedido->getMeta('entregas.km_rota');
        $origem  = $pedido->payload?->getPickupOrFirstWaypoint();
        $destino = $pedido->payload?->getDropoffOrLastWaypoint();

        if (!$this->temCoordenadas($origem) || !$this->temCoordenadas($destino)) {
            return ['sem_endereco', $origem, $destino, null, $cache];
        }

        $chave = md5(implode(',', [$origem->location->getLat(), $origem->location->getLng(), $destino->location->getLat(), $destino->location->getLng()]));

        if (!is_array($cache) || ($cache['chave'] ?? null) !== $chave) {
            $situacao = 'calcular';
        } elseif (($cache['fonte'] ?? null) === 'osrm' || !empty($cache['definitiva'])) {
            $situacao = 'pronto';
        } else {
            $situacao = 'refazer';
        }

        return [$situacao, $origem, $destino, $chave, $cache];
    }

    /**
     * Pedidos que recebem a cota de cálculos desta chamada (as chaves da coleção): primeiro os sem
     * km, depois as estimativas a refazer, cada grupo na ordem de conclusão. Assim estimativas
     * antigas não deixam entregas novas sem km.
     */
    protected function pedidosComCota(Collection $pedidos, int $limiteCalculos): array
    {
        $fila = ['calcular' => [], 'refazer' => []];
        foreach ($pedidos as $i => $pedido) {
            $situacao = $this->situacaoDaRota($pedido)[0];
            if (isset($fila[$situacao])) {
                $fila[$situacao][] = $i;
            }
        }

        return array_flip(array_slice(array_merge($fila['calcular'], $fila['refazer']), 0, max(0, $limiteCalculos)));
    }

    /**
     * Distância de rua em metros pelo OSRM, com o mesmo serviço e a mesma distância que o
     * Utils::getDistanceMatrixFromOSRM, mas sem a geometria (`overview=false`), para a resposta vir
     * menor. A chamada é direta porque ele esconde o `code` da resposta. Fica sem o cache em Redis
     * dele, porque o km já é guardado no meta do pedido.
     *
     * Devolve null quando o serviço falha, e aí liga o disjuntor: exceção, timeout (o Fleet-Ops devolve
     * `code` "Error"), resposta que não é do OSRM ou "Ok" sem rotas. As outras respostas são
     * definitivas para esses pontos e devolvem a distância, que é 0 sem rota:
     * - "Ok" com 0 m, que o OSRM dá para pontos distintos projetados no mesmo trecho da via;
     * - "NoRoute" e "NoSegment";
     * - "InvalidValue" ("Invalid coordinate value."). A URL e os parâmetros são montados aqui, e um
     *   parâmetro errado volta como "InvalidQuery", que continua sendo falha.
     */
    protected function metrosPeloOsrm(Order $pedido, Place $origem, Place $destino): ?float
    {
        $resposta = null;
        $erro     = null;

        try {
            // o OSRM recebe lng,lat: é a mesma string que o Utils monta invertendo o "lat,lng".
            // 'false' vai em texto: o booleano viraria overview=0, que o OSRM recusa
            $resposta = OSRM::getRouteFromCoordinatesString(
                $origem->location->getLng() . ',' . $origem->location->getLat() . ';' . $destino->location->getLng() . ',' . $destino->location->getLat(),
                ['overview' => 'false']
            );
        } catch (\Throwable $e) {
            $erro = $e->getMessage();
        }

        $codigo = is_array($resposta) ? ($resposta['code'] ?? null) : null;
        if (!in_array($codigo, ['Ok', 'NoRoute', 'NoSegment', 'InvalidValue'], true) || ($codigo === 'Ok' && empty($resposta['routes']))) {
            $this->osrmFalhou = true;
            Log::warning('[entregas] OSRM falhou no cálculo do km; o resto desta consulta usa a estimativa', ['pedido' => $pedido->public_id, 'code' => $codigo, 'erro' => $erro]);

            return null;
        }

        return (float) data_get($resposta, 'routes.0.distance', 0);
    }

    public function temCoordenadas(?Place $lugar): bool
    {
        if (!$lugar || !$lugar->location) {
            return false;
        }

        return abs((float) $lugar->location->getLat()) > 0.0001 || abs((float) $lugar->location->getLng()) > 0.0001;
    }

    public function enderecoCurto(?Place $lugar): ?string
    {
        if (!$lugar) {
            return null;
        }

        $partes = array_filter([$lugar->street1, $lugar->street2, $lugar->neighborhood]);

        return $partes ? implode(', ', $partes) : ($lugar->name ?: $lugar->address);
    }
}
