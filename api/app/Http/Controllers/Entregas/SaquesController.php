<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\MotoboyDaSessao;
use App\Support\Entregas\Saques\Saque;
use App\Support\Entregas\Saques\SaqueRecusado;
use App\Support\Entregas\Saques\Saques;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: saque Pix do motoboy pelo app (API v1, token dele; MotoboyDaSessao). Pedir: confere, reserva
 * no livro (Saques::pedir) e manda ao Asaas na hora (Saques::enviar); o mesmo uuid repetido devolve o saque, sem mandar de
 * novo. Acompanhar: o saque dele pelo uuid (o app consulta a cada 3 s por até 1 min; depois, o push avisa).
 */
class SaquesController extends Controller
{
    /** POST v1/entregas/motoboy/saques {uuid, valor} → {saque} (201 novo, 200 repetido, 422 recusa). */
    public function pedir(Request $request)
    {
        $motoboy = MotoboyDaSessao::motoboy($request);
        if (!$motoboy) {
            return response()->json(['errors' => ['Disponível só para motoboys.']], 403);
        }

        try {
            $pedido = Saques::pedir((string) session('company'), (string) $motoboy->uuid, $request->input('uuid'), $request->input('valor'));
        } catch (SaqueRecusado $e) {
            return response()->json(['errors' => [$e->getMessage()]], 422);
        }
        $saque = $pedido['novo'] ? Saques::enviar($pedido['saque']) : $pedido['saque'];

        return response()->json(['saque' => Saques::publico($saque)], $pedido['novo'] ? 201 : 200);
    }

    /** GET v1/entregas/motoboy/saques/{uuid} → {saque}; de outro motoboy ou inexistente: 404. */
    public function mostrar(Request $request, string $id)
    {
        $motoboy = MotoboyDaSessao::motoboy($request);
        if (!$motoboy) {
            return response()->json(['errors' => ['Disponível só para motoboys.']], 403);
        }
        $saque = DB::table(Saque::TABELA)
            ->where('company_uuid', (string) session('company'))
            ->where('driver_uuid', (string) $motoboy->uuid)
            ->where('uuid', strtolower($id))
            ->first();
        if (!$saque) {
            return response()->json(['errors' => ['Saque não encontrado.']], 404);
        }

        return response()->json(['saque' => Saques::publico($saque)]);
    }
}
