<?php

namespace App\Http\Middleware;

use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Distribuicoes;
use App\Support\Entregas\Distribuicao\Pontos;
use App\Support\Entregas\MotoboyDaSessao;
use App\Support\Entregas\StatusDoPedido;
use Closure;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController;
use Fleetbase\FleetOps\Http\Resources\v1\Order as OrderResource;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: a lista "Novos pedidos" do app (GET v1/orders?adhoc=1&unassigned=1, Api\v1\OrderController@query)
 * com a distribuição de pedidos abertos ligada: o pedido em fase `ofertas` só aparece para o motoboy que tem a oferta
 * pendente dele, com `entregas_oferta: {vence_em, tempo_estimado_s, segundos_restantes}` (os segundos que faltam, calculados aqui: o app conta a partir do recebimento e não depende do relógio do celular); os abertos a todos e os sem distribuição
 * continuam. Grupo fleetbase.api, depois do $next. Nunca lança: em erro devolve a resposta original e registra.
 * Formato da resposta: na produção a lista é um array JSON simples `[...]` (o core aplica JsonResource::withoutWrapping());
 * o `{data: [...]}` do recurso com envelope também é tratado. Cada item tem `id` = public_id.
 * Funciona com o APK atual (que só lista): o APK novo lê o entregas_oferta para o cronômetro.
 *
 * Em rodadas (ENTREGAS_DISTRIBUICAO_RODADAS): com a lista aberta (`lista_aberta_em`) o pedido em `ofertas` aparece para
 * todos, menos quem recusou ou dispensou na volta atual; todo pedido em `ofertas` leva `entregas_distribuicao: true` (o
 * app chama a rota de recusa no Dispensar). Como a lista do Fleet-Ops (`nearby`) só traz pedidos até R, acrescenta os
 * pedidos da empresa com a lista aberta cuja coleta está até 2R da posição do motoboy (drivers.location) e que não
 * vieram, no formato do Http\Resources\v1\Order, com as mesmas regras.
 */
class FiltrarPedidosAbertosDoMotoboy
{
    public const LISTA = OrderController::class . '@query';

    public function handle(Request $request, Closure $next)
    {
        $resposta = $next($request);

        try {
            if ($this->ehListaDeAbertos($request) && Distribuicao::ligada()) {
                return $this->filtrar($request, $resposta);
            }
        } catch (\Throwable $e) {
            Log::warning('[entregas] distribuição: filtro da lista: ' . get_class($e));
        }

        return $resposta;
    }

    private function ehListaDeAbertos(Request $request): bool
    {
        if ($request->method() !== 'GET' || !$request->boolean('adhoc') || !$request->boolean('unassigned')) {
            return false;
        }
        $rota = $request->route();
        $acao = $rota instanceof Route ? ltrim($rota->getActionName(), '\\') : null;

        return $acao === static::LISTA;
    }

    private function filtrar(Request $request, $resposta)
    {
        if (!$resposta instanceof JsonResponse || $resposta->getStatusCode() !== 200) {
            return $resposta;
        }
        $corpo   = $resposta->getData(false);
        $itens   = $this->listaDe($corpo);
        $rodadas = Distribuicao::emRodadas();
        // em rodadas, até a lista vazia pode ganhar os pedidos com a lista aberta além de R
        if ($itens === null || (!$itens && !$rodadas)) {
            return $resposta;
        }

        // o item da lista traz `id` = public_id (Resources/v1/Order, requisição pública). Sem join (a empresa é filtrada
        // nas duas tabelas): os uuids dos pedidos da lista e, entre eles, os que estão em fase ofertas
        $ids = [];
        foreach ($itens as $item) {
            if (is_object($item) && isset($item->id) && is_string($item->id)) {
                $ids[] = $item->id;
            }
        }
        if ($ids === [] && !$rodadas) {
            return $resposta;
        }
        // só agora o motoboy da sessão: lista vazia ou sem id nem chega aqui (fora das rodadas)
        $motoboy = MotoboyDaSessao::motoboy($request);
        if (!$motoboy) {
            return $resposta;
        }
        $motoboyUuid = (string) $motoboy->uuid;
        $empresa     = (string) session('company');
        $uuidDe      = $ids ? DB::table('orders')->where('company_uuid', $empresa)->whereIn('public_id', array_values(array_unique($ids)))->pluck('uuid', 'public_id')->all() : [];
        $porPublicId = [];
        if ($uuidDe !== []) {
            $emOfertas = DB::table(Distribuicoes::TABELA)
                ->where('company_uuid', $empresa)
                ->where('fase', Distribuicao::FASE_OFERTAS)
                ->whereIn('pedido_uuid', array_values($uuidDe))
                ->get(['id', 'pedido_uuid', 'volta', 'lista_aberta_em']);
            $publicIdDe = array_flip($uuidDe); // uuid => public_id
            foreach ($emOfertas as $linha) {
                if (isset($publicIdDe[$linha->pedido_uuid])) {
                    $porPublicId[$publicIdDe[$linha->pedido_uuid]] = $linha;
                }
            }
        }

        $filtrados = [];
        foreach ($itens as $item) {
            $id = is_object($item) ? ($item->id ?? null) : null;
            if (!is_string($id) || !isset($porPublicId[$id])) {
                $filtrados[] = $item;
                continue;
            }
            $paraEle = $this->paraOMotoboy($item, $porPublicId[$id], $motoboyUuid, $rodadas);
            if ($paraEle) {
                $filtrados[] = $paraEle;
            }
        }
        if ($rodadas) {
            foreach ($this->alemDeR($request, $motoboy, $empresa, array_values($uuidDe)) as [$item, $linha]) {
                $paraEle = $this->paraOMotoboy($item, $linha, $motoboyUuid, true);
                if ($paraEle) {
                    $filtrados[] = $paraEle;
                }
            }
        }

        $resposta->setData($this->comLista($corpo, $filtrados));

        return $resposta;
    }

    /**
     * O item de um pedido em fase ofertas para este motoboy, ou null (não aparece para ele). Com a oferta pendente dele:
     * `entregas_oferta`. Em rodadas: com a lista aberta aparece para todos, menos quem recusou ou dispensou na volta
     * atual, e todo item leva `entregas_distribuicao: true`.
     */
    private function paraOMotoboy(object $item, object $distribuicao, string $motoboyUuid, bool $rodadas): ?object
    {
        $oferta = Distribuicoes::ofertaPendente((int) $distribuicao->id);
        $dele   = $oferta && (string) $oferta->motoboy_uuid === $motoboyUuid;
        if (!$dele) {
            if (!$rodadas || !$distribuicao->lista_aberta_em) {
                return null; // oferecido a outro, lista fechada: o motoboy não vê
            }
            if (in_array($motoboyUuid, Distribuicoes::motoboysQueDispensaramNaVolta((int) $distribuicao->id, (int) ($distribuicao->volta ?? 1)), true)) {
                return null; // recusou ou dispensou nesta volta
            }
        }
        if ($dele) {
            $venceEm               = Distribuicoes::data($oferta->vence_em);
            $item->entregas_oferta = [
                'vence_em'           => $venceEm?->toIso8601String(),
                'tempo_estimado_s'   => (int) $oferta->tempo_estimado_s,
                'segundos_restantes' => $venceEm ? max(0, $venceEm->getTimestamp() - now()->getTimestamp()) : 0,
            ];
        }
        if ($rodadas) {
            $item->entregas_distribuicao = true;
        }

        return $item;
    }

    /**
     * Em rodadas: os pedidos da empresa em ofertas com a lista aberta que não vieram na lista do Fleet-Ops, abertos, sem
     * motoboy, não encerrados e com a coleta até MULTIPLICADOR_DA_LISTA × R da posição do motoboy, no formato do
     * Http\Resources\v1\Order.
     *
     * @return array<int, array{0: object, 1: object}> [item, distribuição]
     */
    private function alemDeR(Request $request, $motoboy, string $empresa, array $jaVieram): array
    {
        $posicao = Pontos::de($motoboy->location);
        if (!$posicao) {
            return [];
        }
        $distribuicoes = [];
        foreach (Distribuicoes::comListaAberta($empresa) as $linha) {
            if (!in_array((string) $linha->pedido_uuid, $jaVieram, true)) {
                $distribuicoes[(string) $linha->pedido_uuid] = $linha;
            }
        }
        if ($distribuicoes === []) {
            return [];
        }
        $pedidos = Order::whereIn('uuid', array_keys($distribuicoes))
            ->where('company_uuid', $empresa)
            ->with(['payload.pickup', 'trackingStatuses', 'driverAssigned', 'vehicleAssigned', 'customer', 'facilitator'])
            ->get();

        $extras = [];
        foreach ($pedidos as $pedido) {
            if (!$pedido->adhoc || $pedido->driver_assigned_uuid || in_array(strtolower((string) $pedido->status), StatusDoPedido::ENCERRADOS, true)) {
                continue;
            }
            $coleta = Pontos::de($pedido->getPickupLocation());
            if (!$coleta || Pontos::metros($posicao, $coleta) > Distribuicao::MULTIPLICADOR_DA_LISTA * max(1, (int) $pedido->getAdhocDistance())) {
                continue;
            }
            $item = json_decode(json_encode((new OrderResource($pedido))->resolve($request)));
            if (is_object($item)) {
                $extras[] = [$item, $distribuicoes[(string) $pedido->uuid]];
            }
        }

        return $extras;
    }

    /** A lista de pedidos do corpo (array simples, ou objeto com `data`/`orders`), ou null. */
    private function listaDe($corpo): ?array
    {
        if (is_array($corpo)) {
            return $corpo;
        }
        if (is_object($corpo)) {
            foreach (['data', 'orders'] as $chave) {
                if (isset($corpo->$chave) && is_array($corpo->$chave)) {
                    return $corpo->$chave;
                }
            }
        }

        return null;
    }

    private function comLista($corpo, array $lista)
    {
        if (is_array($corpo)) {
            return $lista;
        }
        foreach (['data', 'orders'] as $chave) {
            if (isset($corpo->$chave) && is_array($corpo->$chave)) {
                $corpo->$chave = $lista;
                break;
            }
        }

        return $corpo;
    }
}
