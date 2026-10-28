<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\LojaDoUsuario;
use App\Support\Entregas\PedidoRecusado;
use App\Support\Entregas\Portal\ChamadoDaLoja;
use App\Support\Entregas\Portal\CriadorDoChamado;
use App\Support\Entregas\Portal\PedidosDoPortal;
use App\Support\Entregas\PrePago\CreditoNoPedido;
use Closure;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: o painel "Chamar motoboy" do portal da loja (int/v1/entregas/loja/*, liberadas à loja pelo
 * ProtegerPortalLoja; a loja é sempre a da sessão, nunca por parâmetro). Spec:
 * docs/superpowers/specs/2026-10-28-chamar-motoboy-portal-design.md.
 * - GET loja/clientes?telefone=: o "Já pediu antes" (PedidosDoPortal::clienteDoTelefone), só da loja da sessão;
 *   telefone incompleto = {"cliente": null}.
 * - POST loja/chamar-motoboy: valida (ChamadoDaLoja), devolve o pedido da mesma chave (200) sem passar pelo crédito,
 *   confere o crédito da loja pré-paga (CreditoNoPedido) e cria e despacha (CriadorDoChamado): 201.
 */
class ChamarMotoboyController extends Controller
{
    public function clientes(Request $request)
    {
        $vendor = $this->lojaDaSessao();
        if (!$vendor) {
            return $this->semLoja();
        }

        $telefone = ChamadoDaLoja::telefone($request->input('telefone'));

        return response()->json([
            'cliente' => $telefone ? PedidosDoPortal::clienteDoTelefone((string) $vendor->company_uuid, (string) $vendor->uuid, $telefone) : null,
        ]);
    }

    public function chamar(Request $request, CriadorDoChamado $criador)
    {
        $vendor = $this->lojaDaSessao();
        if (!$vendor) {
            return $this->semLoja();
        }

        $validacao = ChamadoDaLoja::validar($request->all());
        if ($validacao['erros']) {
            return response()->json(['errors' => $validacao['erros']], 422);
        }
        $dados   = $validacao['dados'];
        $usuario = (string) session('user');

        try {
            // a mesma chave (a loja tocou de novo depois de a rede cair): o pedido que já nasceu, sem conferir o crédito de
            // novo (o próprio pedido já reservou o crédito e a conferência o recusaria)
            [$linha, $pedido, $novo] = $criador->repetido($vendor, $dados['chave'])
                ?? $this->comCredito((string) $vendor->company_uuid, (string) $vendor->uuid, fn () => $criador->criar($vendor, $this->coletaDaLoja($vendor), $dados, $usuario));
        } catch (PedidoRecusado $e) {
            return response()->json(['errors' => [$e->getMessage()]], $e->status);
        }

        return response()->json(['pedido' => PedidosDoPortal::resumo($pedido, $linha)], $novo ? 201 : 200);
    }

    protected function lojaDaSessao(): ?Vendor
    {
        return LojaDoUsuario::vendor(session('user'));
    }

    protected function coletaDaLoja(Vendor $vendor): ?Place
    {
        return LojaDoUsuario::coleta($vendor);
    }

    protected function comCredito(string $empresa, string $lojaUuid, Closure $criar)
    {
        return CreditoNoPedido::criar($empresa, $lojaUuid, $criar);
    }

    protected function semLoja()
    {
        return response()->json(['errors' => ['Este usuário não está ligado a nenhuma loja. Fale com a central.']], 403);
    }
}
