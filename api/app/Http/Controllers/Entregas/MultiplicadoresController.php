<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\Financeiro\Multiplicadores;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: multiplicadores por período do Financeiro (console → Fleet-Ops → Financeiro →
 * Multiplicadores), só para administradores. Regras em App\Support\Entregas\Financeiro\Multiplicadores. Rotas em
 * int/v1/entregas/financeiro/multiplicadores (RouteServiceProvider); o id é o uuid do período.
 */
class MultiplicadoresController extends Controller
{
    /** GET financeiro/multiplicadores → {multiplicadores: [...], agora: ISO} (a hora do servidor, para a situação). */
    public function lista(Request $request)
    {
        if ($negado = $this->negarSeNaoAdmin($request)) {
            return $negado;
        }

        return response()->json([
            'multiplicadores' => Multiplicadores::lista($this->empresa(), $this->agora()),
            'agora'           => Multiplicadores::iso($this->agora()),
        ]);
    }

    /** POST financeiro/multiplicadores {nome, mult_motoboy, mult_loja, inicio, fim} → {multiplicador}. */
    public function criar(Request $request)
    {
        if ($negado = $this->negarSeNaoAdmin($request)) {
            return $negado;
        }

        return $this->resposta(Multiplicadores::criar($this->empresa(), $request->all(), $this->usuario($request), $this->agora()));
    }

    /** PUT financeiro/multiplicadores/{id}: agendado muda tudo; em andamento, só o fim. */
    public function alterar(Request $request, string $id)
    {
        if ($negado = $this->negarSeNaoAdmin($request)) {
            return $negado;
        }

        return $this->resposta(Multiplicadores::alterar($this->empresa(), $id, $request->all(), $this->agora()));
    }

    /** POST financeiro/multiplicadores/{id}/encerrar: fim = agora (só em andamento). */
    public function encerrar(Request $request, string $id)
    {
        if ($negado = $this->negarSeNaoAdmin($request)) {
            return $negado;
        }

        return $this->resposta(Multiplicadores::encerrar($this->empresa(), $id, $this->usuario($request), $this->agora()));
    }

    /** DELETE financeiro/multiplicadores/{id}: só agendado → {apagado: true}. */
    public function apagar(Request $request, string $id)
    {
        if ($negado = $this->negarSeNaoAdmin($request)) {
            return $negado;
        }

        $resultado = Multiplicadores::apagar($this->empresa(), $id, $this->agora());
        if ($resultado === null) {
            return $this->erro('Período não encontrado.', 404);
        }

        return is_string($resultado) ? $this->erro($resultado) : response()->json(['apagado' => true]);
    }

    protected function resposta(array|string|null $resultado)
    {
        if ($resultado === null) {
            return $this->erro('Período não encontrado.', 404);
        }

        return is_string($resultado) ? $this->erro($resultado) : response()->json(['multiplicador' => $resultado]);
    }

    /** Agora em Brasília, sem fuso (o formato da tabela). */
    protected function agora(): string
    {
        return now()->format('Y-m-d H:i:s');
    }

    protected function empresa(): string
    {
        return (string) session('company');
    }

    protected function usuario(Request $request): ?string
    {
        $usuario = Auth::getUserFromSession($request);

        return $usuario?->uuid ? (string) $usuario->uuid : null;
    }

    protected function erro(string $mensagem, int $status = 422)
    {
        return response()->json(['errors' => [$mensagem]], $status);
    }

    protected function negarSeNaoAdmin(Request $request)
    {
        $usuario = Auth::getUserFromSession($request);

        if (!$usuario || $usuario->isNotAdmin()) {
            return response()->json(['errors' => ['Somente administradores podem mexer no financeiro.']], 403);
        }

        return null;
    }
}
