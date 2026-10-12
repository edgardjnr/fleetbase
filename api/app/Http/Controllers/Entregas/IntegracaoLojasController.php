<?php

namespace App\Http\Controllers\Entregas;

use App\Support\Entregas\Integracao\ChavesDeIntegracao;
use App\Support\Entregas\Integracao\Integracao;
use App\Support\Entregas\Integracao\PainelDaIntegracao;
use App\Support\Entregas\Integracao\PedidosDaIntegracao;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: a integração no console (só admin): o bloco "Integração" do card da loja (Fleet-Ops → Lojas)
 * e o painel "Integração" no detalhe do pedido.
 * - situacao (GET int/v1/entregas/lojas/{id}/integracao);
 * - gerarChave (POST .../integracao/chave): a chave em texto, uma vez; revoga a anterior. 409 com a integração desligada;
 * - revogar (DELETE .../integracao): funciona sempre;
 * - painel (GET int/v1/entregas/pedidos/{id}/integracao): {"integracao": false} se o pedido não é da integração.
 */
class IntegracaoLojasController extends LojasController
{
    public function situacao(Request $request, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $vendor = $this->acharLoja($id);

        return response()->json(['integracao' => PainelDaIntegracao::daLoja(ChavesDeIntegracao::ativa((string) session('company'), (string) $vendor->uuid))]);
    }

    public function gerarChave(Request $request, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        if (!Integracao::ligada()) {
            return response()->json(['errors' => ['Integração desligada.']], 409);
        }
        $vendor = $this->acharLoja($id);
        $chave  = ChavesDeIntegracao::gerar((string) session('company'), (string) $vendor->uuid);

        return response()->json(['chave' => $chave, 'integracao' => PainelDaIntegracao::daLoja(ChavesDeIntegracao::ativa((string) session('company'), (string) $vendor->uuid))]);
    }

    public function revogar(Request $request, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $vendor = $this->acharLoja($id);
        ChavesDeIntegracao::revogar((string) session('company'), (string) $vendor->uuid);

        return response()->json(['integracao' => PainelDaIntegracao::daLoja(null)]);
    }

    public function painel(Request $request, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }

        // nenhum model registra o CompanyScope nesta versão: a empresa é filtrada aqui
        $pedido = Order::where('company_uuid', session('company'))->where(fn ($q) => $q->where('public_id', $id)->orWhere('uuid', $id))->first();
        if (!$pedido) {
            return response()->json(['errors' => ['Pedido não encontrado.']], 404);
        }

        $linha = PedidosDaIntegracao::doPedido((string) $pedido->uuid);
        $loja  = $linha ? ChavesDeIntegracao::ativa((string) $linha->company_uuid, (string) $linha->vendor_uuid) : null;

        return response()->json(PainelDaIntegracao::doPedido($linha, $loja));
    }
}
