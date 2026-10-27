<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\LojaDoUsuario;
use App\Support\Entregas\PrePago\CarteiraDaLoja;
use App\Support\Entregas\PrePago\PrePago;
use App\Support\Entregas\PrePago\RecargaRecusada;
use App\Support\Entregas\PrePago\Recargas;
use App\Support\Entregas\Saques\CofreIndisponivel;
use Fleetbase\FleetOps\Models\Vendor;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: a "Minha carteira" da loja pré-paga no portal (int/v1/entregas/loja/carteira*). A loja vem
 * sempre da sessão (LojaDoUsuario); a recarga de outra loja ou de outra empresa responde 404, como a inexistente. O
 * portal nunca fala com o Asaas: recebe só o QR code (copia e cola e imagem) da recarga aguardando.
 */
class CarteiraDaLojaController extends Controller
{
    public const NAO_ENCONTRADA = 'Recarga não encontrada.';

    /** GET loja/carteira → {carteira, em_andamento, recargas}: o crédito, o Pix aguardando (com o QR) e as últimas recargas. */
    public function index()
    {
        $vendor  = $this->lojaDaSessao();
        $empresa = (string) session('company');
        $carteira = CarteiraDaLoja::resumo($empresa, (string) $vendor->uuid);
        if ($carteira['modelo'] !== PrePago::PRE) {
            return response()->json(['carteira' => $carteira, 'em_andamento' => null, 'recargas' => []]);
        }
        $andamento = Recargas::emAndamento($empresa, (string) $vendor->uuid);

        return response()->json([
            'carteira'     => $carteira,
            'em_andamento' => $andamento ? Recargas::publico(Recargas::conferir($andamento, true)) : null,
            'recargas'     => array_map(fn ($r) => Recargas::publico($r, false), Recargas::daLoja($empresa, (string) $vendor->uuid)),
        ]);
    }

    /** POST loja/carteira/recargas {valor} → 201 {recarga, carteira} com o QR code; recusa → 422. */
    public function pedir(Request $request)
    {
        $vendor = $this->lojaDaSessao();
        try {
            $recarga = Recargas::pedir((string) session('company'), (string) $vendor->uuid, (string) $vendor->name, $request->input('valor'), (string) session('user'));
        } catch (RecargaRecusada $e) {
            return $this->erro($e->getMessage(), 422);
        } catch (CofreIndisponivel $e) {
            return $this->erro('Recarga por Pix indisponível no momento. Fale com a central.', 503);
        }
        if ($recarga->situacao === PrePago::FALHOU) {
            return $this->erro((string) ($recarga->motivo_falha ?: Recargas::MOTIVO_SEM_PIX), 502);
        }

        return response()->json(['recarga' => Recargas::publico($recarga), 'carteira' => CarteiraDaLoja::resumo((string) session('company'), (string) $vendor->uuid)], 201);
    }

    /** GET loja/carteira/recargas/{uuid} → {recarga, carteira}: o portal pergunta enquanto o QR está na tela. */
    public function mostrar(string $id)
    {
        $vendor  = $this->lojaDaSessao();
        $recarga = $this->recargaDaLoja($vendor, $id);
        if (!$recarga) {
            return $this->erro(static::NAO_ENCONTRADA, 404);
        }

        return response()->json([
            'recarga'  => Recargas::publico(Recargas::conferir($recarga, true)),
            'carteira' => CarteiraDaLoja::resumo((string) session('company'), (string) $vendor->uuid),
        ]);
    }

    /** DELETE loja/carteira/recargas/{uuid}: cancela o Pix aguardando (a cobrança é apagada no Asaas). */
    public function cancelar(string $id)
    {
        $vendor  = $this->lojaDaSessao();
        $recarga = $this->recargaDaLoja($vendor, $id);
        if (!$recarga) {
            return $this->erro(static::NAO_ENCONTRADA, 404);
        }
        try {
            $recarga = Recargas::cancelar($recarga, (string) session('user'));
        } catch (RecargaRecusada $e) {
            return $this->erro($e->getMessage(), 422);
        }

        return response()->json([
            'recarga'  => Recargas::publico($recarga),
            'carteira' => CarteiraDaLoja::resumo((string) session('company'), (string) $vendor->uuid),
        ]);
    }

    /** A recarga pelo uuid, só se é da loja da sessão (outra loja, outra empresa ou uuid malformado: null). */
    protected function recargaDaLoja(Vendor $vendor, string $id): ?object
    {
        $id = strtolower(trim($id));
        if (!preg_match(Recargas::UUID, $id)) {
            return null;
        }
        $recarga = Recargas::porUuid((string) session('company'), $id);

        return $recarga && (string) $recarga->loja_uuid === (string) $vendor->uuid ? $recarga : null;
    }

    protected function lojaDaSessao(): Vendor
    {
        $vendor = LojaDoUsuario::vendor(session('user'));
        abort_if(!$vendor, 404, 'Usuário sem loja.');

        return $vendor;
    }

    protected function erro(string $mensagem, int $status)
    {
        return response()->json(['errors' => [$mensagem]], $status);
    }
}
