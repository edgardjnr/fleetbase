<?php

namespace App\Http\Controllers\Entregas;

use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\ErroDeVinculo;
use App\Support\Entregas\Ifood\ErroIfood;
use App\Support\Entregas\Ifood\VinculosIfood;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Entregas RestaurantePro: vínculo da Loja com o iFood na tela Lojas (só administradores), pelo fluxo distribuído
 * (VinculosIfood):
 * - POST lojas/{id}/ifood/codigo: pede o código de vínculo ao iFood → {codigo, link, expira_em_segundos};
 * - POST lojas/{id}/ifood/vincular: {authorizationCode} troca o código de autorização pelos tokens → {loja} ou, com
 *   várias lojas na conta do iFood, {escolher: [{id, nome}]}; {merchant_id} conclui a escolha → {loja};
 * - DELETE lojas/{id}/ifood: desvincula → {loja}.
 * Com a integração desligada (ENTREGAS_IFOOD), o código e o vínculo respondem 409; desvincular funciona sempre (não
 * chama o iFood). A {loja} é a do LojasController::formatar, com o bloco `ifood` (sem tokens). Todo erro sai como
 * {"errors": ["…"]}: 422 (validação, código ausente ou recusado), 409 (desligada), 502 (iFood fora do ar). Código e
 * vínculo têm o limitador entregas-ifood-vinculo (20 por minuto por usuário, no RouteServiceProvider).
 */
class IfoodLojasController extends LojasController
{
    public function codigo(Request $request, VinculosIfood $vinculos, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request) ?? $this->negarSeDesligada()) {
            return $erro;
        }
        $vendor = $this->acharLoja($id);

        try {
            return response()->json($vinculos->iniciar($vendor->uuid));
        } catch (ErroIfood $e) {
            return $this->erroDoIfood($e, 'código de vínculo');
        }
    }

    public function vincular(Request $request, VinculosIfood $vinculos, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request) ?? $this->negarSeDesligada()) {
            return $erro;
        }
        $vendor = $this->acharLoja($id);
        // Validator::make, não $request->validate: o erro sai no formato da casa ({"errors": ["…"]}, 422), que a tela Lojas
        // mostra, e não no padrão do Laravel ({message, errors: {campo: […]}}); mensagens em pt-BR (o Laravel daqui só
        // tem as em inglês)
        $codigoInvalido   = 'O código de autorização precisa ser um texto de até 500 caracteres.';
        $merchantInvalido = 'A loja do iFood escolhida é inválida. Gere um código de vínculo novo.';
        $validador        = Validator::make($request->all(), [
            'authorizationCode' => ['nullable', 'string', 'max:500'],
            'merchant_id'       => ['nullable', 'string', 'max:64'],
        ], [
            'authorizationCode.string' => $codigoInvalido,
            'authorizationCode.max'    => $codigoInvalido,
            'merchant_id.string'       => $merchantInvalido,
            'merchant_id.max'          => $merchantInvalido,
        ]);
        if ($validador->fails()) {
            return response()->json(['errors' => $validador->errors()->all()], 422);
        }
        $dados    = $validador->validated();
        $merchant = trim((string) ($dados['merchant_id'] ?? ''));
        $codigo   = trim((string) ($dados['authorizationCode'] ?? ''));

        try {
            if ($merchant !== '') {
                $resultado = $vinculos->escolher(session('company'), $vendor->uuid, $merchant);
            } elseif ($codigo !== '') {
                $resultado = $vinculos->concluir(session('company'), $vendor->uuid, $codigo);
            } else {
                return response()->json(['errors' => ['Cole o código de autorização que o iFood mostrou ao dono da loja.']], 422);
            }
        } catch (ErroDeVinculo $e) {
            return response()->json(['errors' => [$e->getMessage()]], 422);
        } catch (ErroIfood $e) {
            return $this->erroDoIfood($e, 'vínculo');
        }

        if ($resultado['situacao'] === 'escolher') {
            return response()->json(['escolher' => $resultado['lojas']]);
        }

        return response()->json(['loja' => $this->formatar($vendor->refresh())]);
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

    protected function negarSeDesligada()
    {
        return ClienteIfood::ligada() ? null : response()->json(['errors' => ['Integração iFood desligada.']], 409);
    }

    /**
     * Erro do iFood no código ou no vínculo: 400/401/403 = recusa (422, com a mensagem da operação que falhou: o pedido do
     * código, a troca do código de autorização ou a lista de lojas da conta); o resto (rede, 408, 429, 5xx, resposta
     * incompleta) = 502. O log leva só a etapa, a operação e o status.
     */
    protected function erroDoIfood(ErroIfood $e, string $etapa)
    {
        Log::warning('[entregas] ifood: vínculo falhou', ['etapa' => $etapa, 'operacao' => $e->operacao, 'status' => $e->status]);

        if (in_array($e->status, [400, 401, 403], true)) {
            $mensagem = match ($e->operacao) {
                // credenciais do aplicativo (IFOOD_CLIENT_ID/IFOOD_CLIENT_SECRET) erradas ou app sem o fluxo distribuído
                'userCode'  => 'O iFood recusou o pedido do código de vínculo. Confira as credenciais do aplicativo iFood no servidor.',
                // a troca deu certo, mas a lista de lojas da conta foi recusada: os tokens saíram do cache (VinculosIfood::concluir)
                'merchants' => 'O iFood recusou a consulta das lojas da conta que autorizou. Gere um código de vínculo novo e peça ao dono da loja que autorize de novo.',
                // troca do código: código errado, já usado, vencido ou de outro aplicativo
                default     => 'O iFood recusou o código. Confira o código de autorização ou gere um código de vínculo novo.',
            };

            return response()->json(['errors' => [$mensagem]], 422);
        }

        return response()->json(['errors' => ['O iFood não respondeu agora. Tente de novo em instantes.']], 502);
    }
}
