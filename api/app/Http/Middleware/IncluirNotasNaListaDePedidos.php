<?php

namespace App\Http\Middleware;

use App\Support\Entregas\PrazoDaEntrega;
use Closure;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\LiveController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController as OrderDoConsole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: põe o `notes` e o prazo de entrega (`meta.entregas_prazo_em`) nos itens das listas de pedidos
 * do console.
 *
 * - Notas: o selo "iFood #4821" do console (tabela, quadro e painel do mapa) reconhece o pedido do iFood pelas notas
 *   "iFood #N" mais o internal_id igual a N (CriadorDoPedidoIfood).
 * - Prazo: a coluna "Prazo" da tabela (cronômetro; PrazoDaEntrega) lê o `meta.entregas_prazo_em`.
 *
 * As listas usam o recurso enxuto do Fleet-Ops (Resources\v1\Index\Order), que traz o `internal_id` mas não o `notes`, e
 * cujo `meta` é só `{"_index_resource": true}`. O código do Fleet-Ops vem do Composer (mudança em packages/*\/server não
 * chega à produção), então o acréscimo é feito aqui, na resposta, com uma consulta só por lista (uuid, notes e meta). Do
 * meta do banco, só o prazo legível vai para o item; o resto (o km da rota, por exemplo) continua só no detalhe.
 *
 * Só em GET e só nas duas rotas de lista do console (o grupo fleetbase.protected, como o RegrasDoPedidoIfood):
 *  - GET int/v1/orders (OrderController@queryRecord, a lista da tabela e do quadro; com `?single` devolve um pedido
 *    completo, que já traz as notas e o meta, e fica de fora);
 *  - GET int/v1/fleet-ops/live/orders (LiveController@orders, o painel de pedidos do mapa; o resultado em cache é o
 *    recurso, não a resposta, então o acréscimo não vai para o cache).
 * O detalhe (int/v1/orders/{id}) usa o recurso completo e não é tocado.
 *
 * Formato das respostas (JSON 200): a lista dos pedidos vem em `{"orders": [...]}` (queryRecord, com `links` e `meta`
 * da paginação ao lado), em `{"data": [...]}` (o padrão do recurso, caso o nome do envelope não esteja definido no
 * processo) ou como array na raiz. Os três são tratados. Cada item tem `uuid` (requisição interna).
 *
 * Item que já traz o `notes` ou o prazo fica com o que trouxe. O filtro da empresa é explícito (nenhum model registra o
 * CompanyScope nesta versão) e o item só recebe nota e prazo lidos da empresa da sessão. Nunca lança: qualquer falha vira
 * um warning curto (só a classe da exceção) e a resposta original segue. Lista vazia, itens completos ou sem empresa na
 * sessão não consultam o banco.
 */
class IncluirNotasNaListaDePedidos
{
    public const LISTA_DO_CONSOLE = OrderDoConsole::class . '@queryRecord';
    public const LISTA_AO_VIVO    = LiveController::class . '@orders';

    public function handle(Request $request, Closure $next)
    {
        $resposta = $next($request);

        try {
            if ($this->ehListaDePedidos($request)) {
                return $this->completarItens($resposta);
            }
        } catch (\Throwable $e) {
            Log::warning('[entregas] notas na lista de pedidos: ' . get_class($e));
        }

        return $resposta;
    }

    private function ehListaDePedidos(Request $request): bool
    {
        if ($request->method() !== 'GET') {
            return false;
        }
        $rota = $request->route();
        $acao = $rota instanceof Route ? ltrim($rota->getActionName(), '\\') : null;

        if ($acao === static::LISTA_AO_VIVO) {
            return true;
        }

        return $acao === static::LISTA_DO_CONSOLE && !$request->boolean('single');
    }

    private function completarItens($resposta)
    {
        $empresa = session('company');
        if (!$resposta instanceof JsonResponse || $resposta->getStatusCode() !== 200 || !is_string($empresa) || $empresa === '') {
            return $resposta;
        }

        // objetos (não arrays): um `{}` vazio do corpo continua `{}` ao reescrever
        $corpo = $resposta->getData(false);
        $itens = $this->listaDe($corpo);
        if ($itens === null || $itens === []) {
            return $resposta;
        }

        $uuids = [];
        foreach ($itens as $item) {
            if ($this->faltaAlgo($item)) {
                $uuids[] = $item->uuid;
            }
        }
        if ($uuids === []) {
            return $resposta;
        }

        $linhas = DB::table('orders')
            ->where('company_uuid', $empresa)
            ->whereIn('uuid', array_values(array_unique($uuids)))
            ->get(['uuid', 'notes', 'meta']);

        $porUuid = [];
        foreach ($linhas as $linha) {
            $porUuid[$linha->uuid] = $linha;
        }

        foreach ($itens as $item) {
            if (!$this->faltaAlgo($item) || !isset($porUuid[$item->uuid])) {
                continue;
            }
            $linha = $porUuid[$item->uuid];

            if (!property_exists($item, 'notes') && ($linha->notes ?? null) !== null) {
                $item->notes = $linha->notes;
            }

            $prazo = PrazoDaEntrega::daMeta($linha->meta ?? null);
            if ($prazo !== null && !$this->temPrazo($item)) {
                if (is_array($item->meta ?? null)) {
                    $item->meta = (object) $item->meta;
                } elseif (!is_object($item->meta ?? null)) {
                    $item->meta = new \stdClass();
                }
                $item->meta->{PrazoDaEntrega::CHAVE} = $prazo;
            }
        }

        // status e cabeçalhos ficam como estão
        $resposta->setData($corpo);

        return $resposta;
    }

    /** Item de pedido (com uuid) a que falta o `notes` ou o prazo no `meta`. */
    private function faltaAlgo($item): bool
    {
        return is_object($item) && isset($item->uuid) && is_string($item->uuid)
            && (!property_exists($item, 'notes') || !$this->temPrazo($item));
    }

    private function temPrazo(object $item): bool
    {
        return isset($item->meta) && is_object($item->meta) && property_exists($item->meta, PrazoDaEntrega::CHAVE);
    }

    /** A lista de pedidos do corpo (os itens são objetos: mudá-los muda o corpo), ou null. */
    private function listaDe($corpo): ?array
    {
        if (is_array($corpo)) {
            return $corpo;
        }
        if (is_object($corpo)) {
            foreach (['orders', 'data'] as $chave) {
                if (isset($corpo->$chave) && is_array($corpo->$chave)) {
                    return $corpo->$chave;
                }
            }
        }

        return null;
    }
}
