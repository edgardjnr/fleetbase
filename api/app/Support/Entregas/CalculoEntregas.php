<?php

namespace App\Support\Entregas;

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
 * no fuso da organização.
 *
 * O valor de cada entrega é congelado (ValoresCongelados, tabela entregas_valores_pedido): na primeira vez que o pedido
 * tem km e há faixas cadastradas, a faixa e os dois valores ficam gravados, e uma tabela de faixas nova só vale para as
 * entregas calculadas depois (o app do motoboy calcula ao mostrar o pedido no card de aceitar). Se o km mudar (endereço
 * alterado, estimativa trocada pela rota do OSRM), o valor é recalculado com a tabela vigente.
 */
class CalculoEntregas
{
    /** Chave da tabela de faixas nas configurações da organização. */
    public const CHAVE_FAIXAS = 'entregas.faixas';

    public const MAX_FAIXAS = 50;

    /** Quantas rotas novas calcular por chamada, por padrão (a tela repete enquanto houver pendentes). */
    public const LIMITE_CALCULOS = 40;

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

    public function __construct(?ValoresCongelados $congelados = null)
    {
        $this->congelados = $congelados ?? new ValoresCongelados();
    }

    /**
     * Pedidos concluídos no período, com a data de conclusão em `entregas_concluido_em`.
     * $filtro recebe a query para restringir (por motoboy, por loja).
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
            ->where('orders.company_uuid', $companyUuid)
            ->where('orders.status', 'completed')
            ->whereNull('orders.deleted_at')
            ->whereNotNull('orders.driver_assigned_uuid')
            ->whereBetween(DB::raw('COALESCE(conclusoes.concluido_em, orders.updated_at)'), [$inicio, $fim])
            // o filtro vai entre parênteses: um orWhere dele não escapa da empresa, do status e do período
            // (é o isolamento do extrato da loja). A condição é booleana porque o when() executaria a closure
            ->when($filtro !== null, fn ($query) => $query->where(fn ($grupo) => $filtro($grupo)))
            ->select('orders.*', DB::raw('COALESCE(conclusoes.concluido_em, orders.updated_at) as entregas_concluido_em'))
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

        $pendentes  = 0;
        $entregas   = [];
        $gravar     = [];
        $faixas     = $this->faixas();
        $lojas      = $this->lojasDosPedidos($pedidos);
        $comCota    = $this->pedidosComCota($pedidos, $limiteCalculos);
        $congelados = $this->congelados->carregar($pedidos->pluck('uuid')->all());

        foreach ($pedidos as $i => $pedido) {
            $rota = $this->rotaDoPedido($pedido, isset($comCota[$i]));
            if ($rota === null) {
                $pendentes++;
            }

            $motoboy           = $pedido->driverAssigned;
            $coleta            = $pedido->payload?->getPickupOrFirstWaypoint();
            $km                = $rota ? round($rota['metros'] / 1000, 2) : null;
            $valor             = $rota ? $this->valorCongelado($pedido, $rota, $faixas, $congelados[$pedido->uuid] ?? null, $gravar) : null;
            [$loja, $lojaNome] = $this->lojaDoPedido($pedido, $coleta, $lojas);

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
            ];
        }

        $this->congelados->gravar($gravar);

        return [$entregas, $pendentes];
    }

    /**
     * Km e valor de um pedido para o app do motoboy (card de aceitar e detalhes): calcula a rota agora, se faltar, e
     * congela o valor como em entregas().
     *
     * @return array{km: ?float, fonte: ?string, faixa: ?array, valor_motoboy: ?float}
     */
    public function valorDoPedido(Order $pedido): array
    {
        $this->osrmFalhou = false;

        $rota = $this->rotaDoPedido($pedido, true);
        if ($rota === null) {
            return ['km' => null, 'fonte' => null, 'faixa' => null, 'valor_motoboy' => null];
        }

        $gravar = [];
        $linha  = $this->congelados->carregar([$pedido->uuid])[$pedido->uuid] ?? null;
        $valor  = $this->valorCongelado($pedido, $rota, $this->faixas(), $linha, $gravar);
        $this->congelados->gravar($gravar);

        return [
            'km'            => round($rota['metros'] / 1000, 2),
            'fonte'         => $rota['fonte'] ?? null,
            'faixa'         => $valor['faixa'] ?? null,
            'valor_motoboy' => $valor['motoboy'] ?? null,
        ];
    }

    /**
     * Valor da entrega para esta rota: o congelado, se foi calculado com o mesmo km (metros); senão, a faixa da tabela
     * vigente, que entra em $gravar (congela, ou recongela quando o km mudou). Sem faixas cadastradas e sem valor
     * congelado para este km, null.
     *
     * @return array{faixa: array, motoboy: float, loja: float}|null
     */
    protected function valorCongelado(Order $pedido, array $rota, array $faixas, ?array $linha, array &$gravar): ?array
    {
        $metros = (int) round((float) $rota['metros']);
        if ($linha !== null && (int) $linha['metros'] === $metros) {
            return $this->valorDaLinha($linha);
        }

        // a faixa sai do mesmo km exibido (metros reais); os metros inteiros só identificam o km da linha congelada
        $faixa = $this->faixaDoKm($faixas, round((float) $rota['metros'] / 1000, 2));
        if ($faixa === null) {
            return null;
        }

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
        ];
        $gravar[] = $nova;

        return $this->valorDaLinha($nova);
    }

    /** Linha congelada no formato do relatório: a faixa (com os dois valores) e os valores soltos, em números. */
    protected function valorDaLinha(array $linha): array
    {
        $faixa = [
            'de_km'   => (float) $linha['de_km'],
            'ate_km'  => (float) $linha['ate_km'],
            'motoboy' => (float) $linha['valor_motoboy'],
            'loja'    => (float) $linha['valor_loja'],
        ];
        if (!empty($linha['acima'])) {
            $faixa['acima'] = true;
        }

        return ['faixa' => $faixa, 'motoboy' => $faixa['motoboy'], 'loja' => $faixa['loja']];
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
