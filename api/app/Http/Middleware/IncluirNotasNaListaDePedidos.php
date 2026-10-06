<?php

namespace App\Http\Middleware;

use Closure;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\LiveController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController as OrderDoConsole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: põe o `notes` nos itens das listas de pedidos do console.
 *
 * O selo "iFood #4821" do console (tabela, quadro e painel do mapa) reconhece o pedido do iFood pelas notas "iFood #N"
 * mais o internal_id igual a N (CriadorDoPedidoIfood; nada disso fica no `meta`, que sai na API v1 e no socket). Só que
 * as listas usam o recurso enxuto do Fleet-Ops (Resources\v1\Index\Order), que traz o `internal_id` mas não o `notes`.
 * O código do Fleet-Ops vem do Composer (mudança em packages/*\/server não chega à produção), então o acréscimo é feito
 * aqui, na resposta, com uma consulta só por lista.
 *
 * Só em GET e só nas duas rotas de lista do console (o grupo fleetbase.protected, como o RegrasDoPedidoIfood):
 *  - GET int/v1/orders (OrderController@queryRecord, a lista da tabela e do quadro; com `?single` devolve um pedido
 *    completo, que já traz as notas, e fica de fora);
 *  - GET int/v1/fleet-ops/live/orders (LiveController@orders, o painel de pedidos do mapa; o resultado em cache é o
 *    recurso, não a resposta, então o acréscimo não vai para o cache).
 * O detalhe (int/v1/orders/{id}) usa o recurso completo e não é tocado.
 *
 * Formato das respostas (JSON 200): a lista dos pedidos vem em `{"orders": [...]}` (queryRecord, com `links` e `meta`
 * da paginação ao lado), em `{"data": [...]}` (o padrão do recurso, caso o nome do envelope não esteja definido no
 * processo) ou como array na raiz. Os três são tratados. Cada item tem `uuid` (requisição interna).
 *
 * O filtro da empresa é explícito (nenhum model registra o CompanyScope nesta versão) e o item só recebe a nota se ela
 * foi lida da empresa da sessão. Nunca lança: qualquer falha vira um warning curto (só a classe da exceção) e a resposta
 * original segue. Lista vazia ou sem empresa na sessão não consulta o banco.
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
                return $this->incluirNotas($resposta);
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

    private function incluirNotas($resposta)
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
            if (is_object($item) && isset($item->uuid) && is_string($item->uuid) && !property_exists($item, 'notes')) {
                $uuids[] = $item->uuid;
            }
        }
        if ($uuids === []) {
            return $resposta;
        }

        $notas = DB::table('orders')
            ->where('company_uuid', $empresa)
            ->whereIn('uuid', array_values(array_unique($uuids)))
            ->pluck('notes', 'uuid')
            ->all();

        foreach ($itens as $item) {
            if (is_object($item) && isset($item->uuid) && is_string($item->uuid) && !property_exists($item, 'notes') && isset($notas[$item->uuid])) {
                $item->notes = $notas[$item->uuid];
            }
        }

        // status e cabeçalhos ficam como estão
        $resposta->setData($corpo);

        return $resposta;
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
