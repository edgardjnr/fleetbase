<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\Saques\ValidacaoDeSaque;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: POST v1/entregas/asaas/validar-saque, a validação de saque do Asaas (sem token de usuário: a
 * central vem do cabeçalho asaas-access-token). Responde {status: APPROVED} ou {status: REFUSED, refuseReason}.
 */
class ValidacaoAsaasController extends Controller
{
    public function validar(Request $request)
    {
        [$status, $corpo] = ValidacaoDeSaque::decidir($request->header('asaas-access-token'), $request->all());

        return response()->json($corpo, $status);
    }
}
