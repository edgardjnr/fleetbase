<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\LiderDosMotoboys;
use App\Support\Entregas\MapaDoLider;
use App\Support\Entregas\TrocaDoMotoboy;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: a aba Mapa do líder dos motoboys no app (Navigator), na API v1, com o token dele.
 * - acesso: {"lider": true|false}, nunca 403 (o app mostra ou esconde a aba);
 * - mapa: os pedidos em andamento de todas as lojas e os motoboys no mapa (MapaDoLider), só para o líder;
 * - trocarMotoboy: passa o pedido para outro motoboy (TrocaDoMotoboy), só para o líder.
 * Líder = LiderDosMotoboys (o motoboy da sessão que é admin ou tem a permissão "fleet-ops assign-driver-for order").
 * Usuário de loja nem chega aqui: o ProtegerPortalLoja nega a API v1 a ele.
 */
class LiderController extends Controller
{
    public function acesso(Request $request)
    {
        return response()->json(['lider' => LiderDosMotoboys::daSessao($request)]);
    }

    public function mapa(Request $request)
    {
        if (!LiderDosMotoboys::daSessao($request)) {
            return $this->soParaLider();
        }

        return response()->json(MapaDoLider::mapa((string) session('company')));
    }

    public function trocarMotoboy(Request $request, string $id)
    {
        if (!LiderDosMotoboys::daSessao($request)) {
            return $this->soParaLider();
        }

        [$status, $corpo] = TrocaDoMotoboy::trocar((string) session('company'), $id, trim((string) $request->input('motoboy', '')), (string) session('user'));

        return response()->json($corpo, $status);
    }

    protected function soParaLider()
    {
        return response()->json(['errors' => ['Disponível só para o líder dos motoboys.']], 403);
    }
}
