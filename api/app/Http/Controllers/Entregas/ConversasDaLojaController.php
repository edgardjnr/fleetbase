<?php

namespace App\Http\Controllers\Entregas;

use App\Support\Entregas\ConversasDaLoja;
use App\Support\Entregas\MotoboysNoMapaDaLoja;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: o chat da loja com os motoboys no portal (int/v1/entregas/loja/conversas*). Desenho:
 * docs/superpowers/specs/2026-10-04-chat-da-loja-design.md.
 *
 * Tudo pela loja da sessão (lojaDaSessao e donosDosPedidos, do PortalLojaController); a loja nunca vem por parâmetro e o
 * motoboy vem pelo id opaco do mapa ou pelo pedido da loja, nunca pelo id dele. As regras e a gravação ficam no
 * ConversasDaLoja.
 */
class ConversasDaLojaController extends PortalLojaController
{
    public function index()
    {
        $loja = $this->lojaDaSessao();

        return response()->json(['conversas' => ConversasDaLoja::listar($loja, (string) session('user'))]);
    }

    /**
     * Abre a conversa: `central` = true (a conversa da loja com a central), `motoboy` = id opaco do mapa do portal ou
     * `pedido` = public_id de um pedido da loja.
     */
    public function abrir(Request $request)
    {
        $loja  = $this->lojaDaSessao();
        $donos = $this->donosDosPedidos($loja);

        // a conversa da loja com a central (uma por loja)
        if ($request->boolean('central')) {
            $canal = ConversasDaLoja::abrirComACentral($loja, (string) session('user'));
            if (!$canal) {
                return $this->erro(422, 'A central não está disponível para conversa agora.');
            }

            return response()->json(['conversa' => static::itemDaLista($loja, $canal)]);
        }

        $idOpaco = $request->input('motoboy');
        $pedido  = $request->input('pedido');

        if (is_string($idOpaco) && $idOpaco !== '') {
            $motoboy = MotoboysNoMapaDaLoja::motoboyDoIdOpaco((string) session('company'), $donos, $idOpaco);
            if (!$motoboy) {
                return $this->erro(404, 'Este motoboy não está mais disponível no mapa.');
            }
        } elseif (is_string($pedido) && $pedido !== '') {
            $doPedido = Order::where('company_uuid', session('company'))
                ->whereIn('customer_uuid', $donos)
                ->where(fn ($query) => $query->where('public_id', $pedido)->orWhere('uuid', $pedido))
                ->with('driverAssigned.user')
                ->first();
            if (!$doPedido) {
                return $this->erro(404, 'Pedido não encontrado.');
            }

            $motoboy = $doPedido->driverAssigned;
            if (!$motoboy) {
                return $this->erro(422, 'Este pedido ainda não tem motoboy.');
            }
        } else {
            return $this->erro(422, 'Escolha um motoboy.');
        }

        $canal = ConversasDaLoja::abrir($loja, $motoboy, (string) session('user'));
        if (!$canal) {
            return $this->erro(422, 'Não foi possível abrir a conversa com este motoboy.');
        }

        return response()->json(['conversa' => static::itemDaLista($loja, $canal)]);
    }

    public function mensagens(string $id)
    {
        $canal = ConversasDaLoja::daLoja($this->lojaDaSessao(), $id);
        if (!$canal) {
            return $this->erro(404, 'Conversa não encontrada.');
        }

        return response()->json(['mensagens' => ConversasDaLoja::mensagens($canal, (string) session('user'))]);
    }

    public function enviar(Request $request, string $id)
    {
        $canal = ConversasDaLoja::daLoja($this->lojaDaSessao(), $id);
        if (!$canal) {
            return $this->erro(404, 'Conversa não encontrada.');
        }

        $texto = ConversasDaLoja::textoValido($request->input('texto'));
        if ($texto === null) {
            return $this->erro(422, 'Escreva uma mensagem de até ' . ConversasDaLoja::MAX_CARACTERES . ' caracteres.');
        }

        return response()->json(['mensagem' => ConversasDaLoja::enviar($canal, (string) session('user'), $texto)]);
    }

    /** O canal no formato da lista (GET loja/conversas). */
    protected static function itemDaLista($loja, $canal): ?array
    {
        return collect(ConversasDaLoja::listar($loja, (string) session('user')))->firstWhere('id', $canal->public_id);
    }

    protected function erro(int $status, string $mensagem)
    {
        return response()->json(['errors' => [$mensagem]], $status);
    }
}
