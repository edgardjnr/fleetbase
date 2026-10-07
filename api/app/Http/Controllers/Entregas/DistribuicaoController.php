<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Distribuicoes;
use App\Support\Entregas\Distribuicao\Distribuidor;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Support\Auth;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: painel "Distribuição" no detalhe do pedido do console (só administradores) e o botão
 * "Abrir a todos agora". GET int/v1/entregas/pedidos/{id}/distribuicao e POST .../distribuicao/abrir.
 */
class DistribuicaoController extends Controller
{
    public function painel(Request $request, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $pedido = $this->pedido($id);
        if (!$pedido) {
            return response()->json(['errors' => ['Pedido não encontrado.']], 404);
        }

        return response()->json(static::resposta((string) $pedido->uuid));
    }

    public function abrir(Request $request, string $id, Distribuidor $distribuidor)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $pedido = $this->pedido($id);
        if (!$pedido) {
            return response()->json(['errors' => ['Pedido não encontrado.']], 404);
        }

        try {
            $abriu = $distribuidor->abrirATodos((string) $pedido->uuid, Distribuicao::ABERTA_PELA_CENTRAL);
        } catch (LockTimeoutException $e) {
            return response()->json(['errors' => ['O pedido está sendo atualizado. Tente de novo.']], 503);
        }
        if (!$abriu) {
            return response()->json(['errors' => ['Este pedido não está em oferta.']], 409);
        }

        return response()->json(static::resposta((string) $pedido->uuid));
    }

    /** O painel: a distribuição não encerrada do pedido ou, sem ela, a última (para o histórico); `distribuicao: false` se nunca houve. */
    public static function resposta(string $pedidoUuid): array
    {
        $distribuicao = Distribuicoes::doPedido($pedidoUuid) ?? Distribuicoes::ultimaDoPedido($pedidoUuid);
        if (!$distribuicao) {
            return ['distribuicao' => false];
        }
        $ofertas = Distribuicoes::ofertas((int) $distribuicao->id);
        $nomes   = static::nomes(array_map(fn ($o) => (string) $o->motoboy_uuid, $ofertas));
        $atual   = null;
        foreach ($ofertas as $oferta) {
            if ($oferta->resposta === Distribuicao::PENDENTE) {
                $atual = $oferta;
            }
        }
        $linha = fn ($o) => [
            'posicao'          => (int) $o->posicao,
            'motoboy'          => $nomes[(string) $o->motoboy_uuid] ?? '—',
            'tempo_estimado_s' => $o->tempo_estimado_s !== null ? (int) $o->tempo_estimado_s : null,
            'encaixe'          => (bool) $o->encaixe,
            'aproximado'       => (bool) $o->aproximado,
            'oferecida_em'     => Distribuicoes::data($o->oferecida_em)?->toIso8601String(),
            'vence_em'         => Distribuicoes::data($o->vence_em)?->toIso8601String(),
            'resposta'         => $o->resposta,
            'respondida_em'    => Distribuicoes::data($o->respondida_em)?->toIso8601String(),
        ];

        return [
            'distribuicao'  => true,
            'fase'          => $distribuicao->fase,
            'motivo'        => $distribuicao->motivo,
            'despachada_em' => Distribuicoes::data($distribuicao->despachada_em)?->toIso8601String(),
            'aberta_em'     => Distribuicoes::data($distribuicao->aberta_em)?->toIso8601String(),
            'encerrada_em'  => Distribuicoes::data($distribuicao->encerrada_em)?->toIso8601String(),
            'oferta'        => $atual ? $linha($atual) : null,
            'fila'          => $distribuicao->fila ? (json_decode((string) $distribuicao->fila, true) ?: []) : [],
            'historico'     => array_map($linha, $ofertas),
        ];
    }

    /** @return array<string, string> uuid => nome */
    protected static function nomes(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }
        $nomes = [];
        foreach (Driver::whereIn('uuid', array_values(array_unique($uuids)))->get() as $motoboy) {
            $nomes[(string) $motoboy->uuid] = (string) $motoboy->name;
        }

        return $nomes;
    }

    protected function pedido(string $id): ?Order
    {
        // nenhum model registra o CompanyScope nesta versão: a empresa é filtrada aqui
        return Order::where('company_uuid', session('company'))
            ->where(fn ($query) => $query->where('public_id', $id)->orWhere('uuid', $id))
            ->first();
    }

    protected function negarSeNaoAdmin(Request $request)
    {
        $usuario = Auth::getUserFromSession($request);
        if (!$usuario || $usuario->isNotAdmin()) {
            return response()->json(['errors' => ['Somente administradores podem ver ou abrir a distribuição do pedido.']], 403);
        }

        return null;
    }
}
