<?php

namespace App\Http\Middleware;

use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Distribuicoes;
use App\Support\Entregas\MotoboyDaSessao;
use Closure;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController;
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
        $corpo = $resposta->getData(false);
        $itens = $this->listaDe($corpo);
        if (!$itens) {
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
        if ($ids === []) {
            return $resposta;
        }
        // só agora o motoboy da sessão: lista vazia ou sem id nem chega aqui
        $motoboy = MotoboyDaSessao::motoboy($request);
        if (!$motoboy) {
            return $resposta;
        }
        $motoboyUuid = (string) $motoboy->uuid;
        $empresa     = (string) session('company');
        $uuidDe = DB::table('orders')->where('company_uuid', $empresa)->whereIn('public_id', array_values(array_unique($ids)))->pluck('uuid', 'public_id')->all();
        if ($uuidDe === []) {
            return $resposta;
        }
        $emOfertas = DB::table(Distribuicoes::TABELA)
            ->where('company_uuid', $empresa)
            ->where('fase', Distribuicao::FASE_OFERTAS)
            ->whereIn('pedido_uuid', array_values($uuidDe))
            ->get(['id', 'pedido_uuid']);
        if ($emOfertas->isEmpty()) {
            return $resposta;
        }
        $publicIdDe  = array_flip($uuidDe); // uuid => public_id
        $porPublicId = [];
        foreach ($emOfertas as $linha) {
            if (isset($publicIdDe[$linha->pedido_uuid])) {
                $porPublicId[$publicIdDe[$linha->pedido_uuid]] = $linha;
            }
        }

        $filtrados = [];
        foreach ($itens as $item) {
            $id = is_object($item) ? ($item->id ?? null) : null;
            if (!is_string($id) || !isset($porPublicId[$id])) {
                $filtrados[] = $item;
                continue;
            }
            $oferta = Distribuicoes::ofertaPendente((int) $porPublicId[$id]->id);
            if (!$oferta || (string) $oferta->motoboy_uuid !== $motoboyUuid) {
                continue; // oferecido a outro: o motoboy não vê
            }
            $venceEm = Distribuicoes::data($oferta->vence_em);
            $item->entregas_oferta = [
                'vence_em'           => $venceEm?->toIso8601String(),
                'tempo_estimado_s'   => (int) $oferta->tempo_estimado_s,
                'segundos_restantes' => $venceEm ? max(0, $venceEm->getTimestamp() - now()->getTimestamp()) : 0,
            ];
            $filtrados[] = $item;
        }

        $resposta->setData($this->comLista($corpo, $filtrados));

        return $resposta;
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
