<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\Ifood\VinculosIfood;
use App\Support\Entregas\Integracao\ChavesDeIntegracao;
use App\Support\Entregas\Integracoes\CatalogoDeIntegracoes;
use App\Support\Entregas\Integracoes\IntegracaoDaLoja;
use App\Support\Entregas\Integracoes\IntegracaoIfood;
use App\Support\Entregas\LojaDoUsuario;
use Fleetbase\FleetOps\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: a loja conecta os próprios sistemas pelo portal (Integrações). Usuário de loja
 * (type=customer, ProtegerPortalLoja); a loja é sempre a da sessão, nunca vem por parâmetro, e a empresa é a da loja.
 *
 * - GET loja/integracoes: os cartões do CatalogoDeIntegracoes → {integracoes: [{id, nome, fluxo, disponivel, estado, …}]};
 * - iFood (fluxo `ifood`, o mesmo vínculo da tela Lojas, VinculoIfoodNaTela):
 *   POST loja/integracoes/ifood/codigo → {codigo, link, expira_em_segundos};
 *   POST loja/integracoes/ifood/vincular ({authorizationCode} ou {merchant_id}) → {integracao} ou {escolher: [{id, nome}]};
 *   DELETE loja/integracoes/ifood → {integracao};
 * - sistemas por chave (fluxo `chave`):
 *   POST loja/integracoes/{id}/chave → {chave, integracao} (a chave em texto só sai aqui; revoga a anterior);
 *   DELETE loja/integracoes/{id}/chave → {integracao} (revoga: o sistema para de mandar pedidos).
 * Desligada (ENTREGAS_IFOOD, ENTREGAS_INTEGRACAO), conectar responde 409; desconectar funciona sempre. Sistema
 * desconhecido ou de outro fluxo: 404. Erros sempre {"errors": ["…"]}. Cada ação da loja fica no log
 * `[entregas] integrações: …` com o id do sistema e da loja.
 */
class IntegracoesDaLojaController extends Controller
{
    use VinculoIfoodNaTela;

    public function index()
    {
        $vendor = $this->lojaDaSessao();

        return response()->json(['integracoes' => CatalogoDeIntegracoes::daLoja((string) $vendor->company_uuid, (string) $vendor->uuid)]);
    }

    public function ifoodCodigo(VinculosIfood $vinculos)
    {
        $vendor = $this->lojaDaSessao();
        if ($erro = $this->negarSeDesligada()) {
            return $erro;
        }

        return $this->codigoDoIfood($vinculos, (string) $vendor->uuid);
    }

    public function ifoodVincular(Request $request, VinculosIfood $vinculos)
    {
        $vendor = $this->lojaDaSessao();
        if ($erro = $this->negarSeDesligada()) {
            return $erro;
        }

        return $this->vincularNoIfood($request, $vinculos, (string) $vendor->company_uuid, (string) $vendor->uuid, function () use ($vendor) {
            $this->registrar('iFood conectado', 'ifood', $vendor);

            return ['integracao' => $this->cartao(new IntegracaoIfood(), $vendor)];
        });
    }

    public function ifoodDesvincular(VinculosIfood $vinculos)
    {
        $vendor = $this->lojaDaSessao();
        $vinculos->desvincular((string) $vendor->uuid);
        $this->registrar('iFood desconectado', 'ifood', $vendor);

        return response()->json(['integracao' => $this->cartao(new IntegracaoIfood(), $vendor)]);
    }

    public function gerarChave(string $id)
    {
        $vendor     = $this->lojaDaSessao();
        $integracao = $this->integracaoPorChave($id);
        if (!$integracao) {
            return response()->json(['errors' => ['Integração não encontrada.']], 404);
        }
        if (!$integracao->ligada()) {
            return response()->json(['errors' => ['Esta integração não está disponível agora. Fale com a central.']], 409);
        }

        $chave = ChavesDeIntegracao::gerar((string) $vendor->company_uuid, (string) $vendor->uuid);
        $this->registrar('chave gerada', $integracao->id(), $vendor);

        return response()->json(['chave' => $chave, 'integracao' => $this->cartao($integracao, $vendor)]);
    }

    public function revogarChave(string $id)
    {
        $vendor     = $this->lojaDaSessao();
        $integracao = $this->integracaoPorChave($id);
        if (!$integracao) {
            return response()->json(['errors' => ['Integração não encontrada.']], 404);
        }

        ChavesDeIntegracao::revogar((string) $vendor->company_uuid, (string) $vendor->uuid);
        $this->registrar('chave revogada', $integracao->id(), $vendor);

        return response()->json(['integracao' => $this->cartao($integracao, $vendor)]);
    }

    protected function integracaoPorChave(string $id): ?IntegracaoDaLoja
    {
        $integracao = CatalogoDeIntegracoes::porId($id);

        return $integracao && $integracao->fluxo() === 'chave' ? $integracao : null;
    }

    protected function cartao(IntegracaoDaLoja $integracao, Vendor $vendor): array
    {
        return CatalogoDeIntegracoes::cartao($integracao, (string) $vendor->company_uuid, (string) $vendor->uuid);
    }

    protected function registrar(string $acao, string $sistema, Vendor $vendor): void
    {
        Log::info('[entregas] integrações: ' . $acao . ' pela loja', ['sistema' => $sistema, 'vendor' => $vendor->uuid]);
    }

    protected function lojaDaSessao(): Vendor
    {
        $vendor = LojaDoUsuario::vendor(session('user'));
        abort_if(!$vendor, 404, 'Usuário sem loja.');

        return $vendor;
    }
}
