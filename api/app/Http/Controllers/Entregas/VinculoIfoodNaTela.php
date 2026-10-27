<?php

namespace App\Http\Controllers\Entregas;

use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\ErroDeVinculo;
use App\Support\Entregas\Ifood\ErroIfood;
use App\Support\Entregas\Ifood\VinculosIfood;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Entregas RestaurantePro: o vínculo de uma loja com o iFood pela tela, comum à central (tela Lojas,
 * IfoodLojasController) e à própria loja (portal → Integrações, IntegracoesDaLojaController). Quem usa confere antes quem
 * pode mexer em qual loja; aqui ficam o pedido do código, a troca do código de autorização (ou a escolha da loja) e os
 * erros do iFood, sempre no formato {"errors": ["…"]}: 422 (validação, código ausente ou recusado), 409 (desligada),
 * 502 (iFood fora do ar).
 */
trait VinculoIfoodNaTela
{
    protected function negarSeDesligada()
    {
        return ClienteIfood::ligada() ? null : response()->json(['errors' => ['Integração iFood desligada.']], 409);
    }

    /** O código de vínculo: {codigo, link, expira_em_segundos}. */
    protected function codigoDoIfood(VinculosIfood $vinculos, string $vendorUuid)
    {
        try {
            return response()->json($vinculos->iniciar($vendorUuid));
        } catch (ErroIfood $e) {
            return $this->erroDoIfood($e, 'código de vínculo');
        }
    }

    /**
     * {authorizationCode} troca o código de autorização pelos tokens; {merchant_id} conclui a escolha entre as lojas da
     * conta. Com várias lojas na conta, responde {escolher: [{id, nome}]}; vinculada, a resposta de $vinculada().
     */
    protected function vincularNoIfood(Request $request, VinculosIfood $vinculos, string $companyUuid, string $vendorUuid, Closure $vinculada)
    {
        // Validator::make, não $request->validate: o erro sai no formato da casa ({"errors": ["…"]}, 422), que as telas
        // mostram, e não no padrão do Laravel ({message, errors: {campo: […]}}); mensagens em pt-BR (o Laravel daqui só
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
                $resultado = $vinculos->escolher($companyUuid, $vendorUuid, $merchant);
            } elseif ($codigo !== '') {
                $resultado = $vinculos->concluir($companyUuid, $vendorUuid, $codigo);
            } else {
                return response()->json(['errors' => ['Cole o código de autorização que o iFood mostrou no Portal do Parceiro.']], 422);
            }
        } catch (ErroDeVinculo $e) {
            return response()->json(['errors' => [$e->getMessage()]], 422);
        } catch (ErroIfood $e) {
            return $this->erroDoIfood($e, 'vínculo');
        }

        if ($resultado['situacao'] === 'escolher') {
            return response()->json(['escolher' => $resultado['lojas']]);
        }

        return response()->json($vinculada());
    }

    /**
     * Erro do iFood no código ou no vínculo: 400/401/403 = recusa (422, com a mensagem da operação que falhou: o pedido do
     * código, a troca do código de autorização ou a lista de lojas da conta); o resto (rede, 408, 429, 5xx, resposta
     * incompleta) = 502. O log leva só a etapa, a operação e o status.
     */
    protected function erroDoIfood(ErroIfood $e, string $etapa)
    {
        $motivo = static::motivoDoIfood($e);
        Log::warning('[entregas] ifood: vínculo falhou', ['etapa' => $etapa, 'operacao' => $e->operacao, 'status' => $e->status, 'motivo' => $motivo]);

        if (in_array($e->status, [400, 401, 403], true)) {
            $mensagem = match ($e->operacao) {
                // credenciais do aplicativo (IFOOD_CLIENT_ID/IFOOD_CLIENT_SECRET) erradas ou app sem o fluxo distribuído
                'userCode'  => 'O iFood recusou o pedido do código de vínculo. Confira as credenciais do aplicativo iFood no servidor.',
                // a troca deu certo, mas a lista de lojas da conta foi recusada: os tokens saíram do cache (VinculosIfood::concluir)
                'merchants' => 'O iFood recusou a consulta das lojas da conta que autorizou. Gere um código de vínculo novo e autorize de novo no Portal do Parceiro.',
                // troca do código: código errado, já usado, vencido ou de outro aplicativo
                default     => 'O iFood recusou o código. Confira o código de autorização ou gere um código de vínculo novo.',
            };

            // o motivo vai para a tela: quem vincula vê por que o iFood recusou sem abrir o log
            return response()->json(['errors' => [$mensagem . ' (iFood ' . $e->status . ($motivo !== '' ? ': ' . $motivo : '') . ')']], 422);
        }

        return response()->json(['errors' => ['O iFood não respondeu agora. Tente de novo em instantes.']], 502);
    }

    /**
     * O motivo do erro no corpo do iFood ({"error": {"code", "message"}} no /oauth/token; {"errorType", "code",
     * "description"} ou {"message"} nas outras), até 200 caracteres, com sequências de 6+ dígitos mascaradas. O corpo de
     * erro não traz token nem o código enviado; "" quando não há corpo JSON.
     */
    public static function motivoDoIfood(ErroIfood $e): string
    {
        $corpo  = $e->corpoJson() ?? [];
        $fontes = [is_array($corpo['error'] ?? null) ? $corpo['error'] : ['code' => $corpo['error'] ?? null], $corpo];
        $partes = [];
        foreach ($fontes as $fonte) {
            foreach (['errorType', 'code', 'message', 'description', 'error_description'] as $campo) {
                $valor = $fonte[$campo] ?? null;
                if (is_scalar($valor) && trim((string) $valor) !== '' && !in_array(trim((string) $valor), $partes, true)) {
                    $partes[] = trim((string) $valor);
                }
            }
        }

        return mb_substr(preg_replace('/\d{6,}/', '***', implode(' · ', $partes)), 0, 200);
    }
}
