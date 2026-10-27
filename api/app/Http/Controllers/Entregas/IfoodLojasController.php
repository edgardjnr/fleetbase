<?php

namespace App\Http\Controllers\Entregas;

use App\Support\Entregas\Ifood\VinculosIfood;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: vínculo da Loja com o iFood na tela Lojas (só administradores), pelo fluxo distribuído
 * (VinculosIfood):
 * - POST lojas/{id}/ifood/codigo: pede o código de vínculo ao iFood → {codigo, link, expira_em_segundos};
 * - POST lojas/{id}/ifood/vincular: {authorizationCode} troca o código de autorização pelos tokens → {loja} ou, com
 *   várias lojas na conta do iFood, {escolher: [{id, nome}]}; {merchant_id} conclui a escolha → {loja};
 * - DELETE lojas/{id}/ifood: desvincula → {loja}.
 * Com a integração desligada (ENTREGAS_IFOOD), o código e o vínculo respondem 409; desvincular funciona sempre (não
 * chama o iFood). A {loja} é a do LojasController::formatar, com o bloco `ifood` (sem tokens). Os erros e a validação
 * são os do VinculoIfoodNaTela, o mesmo da loja no portal (Integrações). Código e vínculo têm o limitador
 * entregas-ifood-vinculo (20 por minuto por usuário, no RouteServiceProvider).
 */
class IfoodLojasController extends LojasController
{
    use VinculoIfoodNaTela;

    public function codigo(Request $request, VinculosIfood $vinculos, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request) ?? $this->negarSeDesligada()) {
            return $erro;
        }

        return $this->codigoDoIfood($vinculos, $this->acharLoja($id)->uuid);
    }

    public function vincular(Request $request, VinculosIfood $vinculos, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request) ?? $this->negarSeDesligada()) {
            return $erro;
        }
        $vendor = $this->acharLoja($id);

        return $this->vincularNoIfood($request, $vinculos, (string) session('company'), $vendor->uuid, fn () => ['loja' => $this->formatar($vendor->refresh())]);
    }

    public function desvincular(Request $request, VinculosIfood $vinculos, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $vendor = $this->acharLoja($id);
        $vinculos->desvincular($vendor->uuid);

        return response()->json(['loja' => $this->formatar($vendor->refresh())]);
    }
}
