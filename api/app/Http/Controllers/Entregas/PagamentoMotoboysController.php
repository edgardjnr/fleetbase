<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\CalculoEntregas;
use Fleetbase\Models\Company;
use Fleetbase\Models\Setting;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Entregas RestaurantePro: tela "Pagamento e cobrança" (Fleet-Ops → Recursos), só administradores.
 * O cálculo (faixas, km da rota, loja de cada pedido) fica em App\Support\Entregas\CalculoEntregas.
 */
class PagamentoMotoboysController extends Controller
{
    public function relatorio(Request $request, CalculoEntregas $calculo)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }

        $request->validate([
            'inicio'  => ['required', 'date_format:Y-m-d'],
            'fim'     => ['required', 'date_format:Y-m-d', 'after_or_equal:inicio'],
            'motoboy' => ['nullable', 'string'],
        ]);

        $companyUuid = session('company');
        $fuso        = Company::where('uuid', $companyUuid)->value('timezone') ?: 'America/Sao_Paulo';
        $inicio      = Carbon::createFromFormat('Y-m-d', $request->input('inicio'), $fuso)->startOfDay()->utc();
        $fim         = Carbon::createFromFormat('Y-m-d', $request->input('fim'), $fuso)->endOfDay()->utc();

        $pedidos = $calculo->pedidosConcluidos($companyUuid, $inicio, $fim, function ($query) use ($request) {
            if ($request->filled('motoboy')) {
                $query->whereHas('driverAssigned', fn ($driver) => $driver->where('public_id', $request->input('motoboy')));
            }
        });

        [$entregas, $pendentes] = $calculo->entregas($pedidos, $fuso);

        $motoboys    = $calculo->agrupar($entregas, 'motoboy', ['motoboy', 'motoboy_nome'], 'valor_motoboy')->sortByDesc('valor')->values();
        $lojas       = $calculo->agrupar($entregas, 'loja', ['loja', 'loja_nome'], 'valor_loja')->sortByDesc('valor')->values();
        $totalPagar  = round($motoboys->sum('valor'), 2);
        $totalCobrar = round($lojas->sum('valor'), 2);

        return response()->json([
            'inicio'    => $request->input('inicio'),
            'fim'       => $request->input('fim'),
            'fuso'      => $fuso,
            'faixas'    => $calculo->faixas(),
            'pendentes' => $pendentes,
            'totais'    => [
                'entregas' => count($entregas),
                'km'       => round(collect($entregas)->sum(fn ($item) => $item['km'] ?? 0), 2),
                'valor'    => $totalPagar,
                'cobrar'   => $totalCobrar,
                'margem'   => round($totalCobrar - $totalPagar, 2),
            ],
            'motoboys'  => $motoboys,
            'lojas'     => $lojas,
            'entregas'  => $entregas,
        ]);
    }

    public function salvarFaixas(Request $request, CalculoEntregas $calculo)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }

        $request->validate([
            'faixas'           => ['present', 'array', 'max:' . CalculoEntregas::MAX_FAIXAS],
            'faixas.*.ate_km'  => ['required', 'numeric', 'gt:0', 'max:1000'],
            'faixas.*.motoboy' => ['required', 'numeric', 'min:0', 'max:100000'],
            'faixas.*.loja'    => ['required', 'numeric', 'min:0', 'max:100000'],
        ]);

        $faixas  = $calculo->normalizarFaixas($request->input('faixas', []));
        $limites = array_column($faixas, 'ate_km');
        if (count($limites) !== count(array_unique($limites))) {
            return response()->json(['errors' => ['Há duas faixas com o mesmo "até km".']], 422);
        }

        Setting::configureCompany(CalculoEntregas::CHAVE_FAIXAS, $faixas);

        return response()->json(['faixas' => $faixas]);
    }

    protected function negarSeNaoAdmin(Request $request)
    {
        $usuario = Auth::getUserFromSession($request);

        if (!$usuario || $usuario->isNotAdmin()) {
            return response()->json(['errors' => ['Somente administradores podem ver o pagamento dos motoboys e a cobrança das lojas.']], 403);
        }

        return null;
    }
}
