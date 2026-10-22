<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\Sistema\PrecosDasCentrais;
use App\Support\Entregas\Sistema\RelatorioDasCorridas;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: Corridas do sistema (console → Fleet-Ops → Financeiro → Corridas do sistema): todas as
 * corridas finalizadas de todas as organizações (centrais), com o preço por corrida de cada central, para o dono do
 * sistema cobrar. Só o administrador do sistema (users.type = 'admin', o primeiro usuário da instalação), pela
 * negarSeNaoDonoDoSistema: NÃO é a trava do Financeiro, que vai passar a ser o administrador da organização.
 * Rotas em int/v1/entregas/sistema/* (RouteServiceProvider). Regras em App\Support\Entregas\Sistema.
 * Spec: docs/superpowers/specs/2026-10-22-corridas-do-sistema-design.md.
 */
class CorridasDoSistemaController extends Controller
{
    public const NEGADO = 'Somente o administrador do sistema pode ver esta tela.';

    /** GET sistema/corridas?inicio=Y-m-d&fim=Y-m-d → {inicio, fim, total, centrais, corridas, lista_cortada}. */
    public function corridas(Request $request)
    {
        if ($negado = $this->negarSeNaoDonoDoSistema($request)) {
            return $negado;
        }

        $relatorio = RelatorioDasCorridas::montar($request->input('inicio'), $request->input('fim'));

        return is_string($relatorio) ? $this->erro($relatorio) : response()->json($relatorio);
    }

    /** GET sistema/precos → {centrais: [{company, nome, precos: [{id, valor, vale_a_partir_de}]}]}, todas as organizações. */
    public function precos(Request $request)
    {
        if ($negado = $this->negarSeNaoDonoDoSistema($request)) {
            return $negado;
        }

        return response()->json(['centrais' => PrecosDasCentrais::lista()]);
    }

    /** POST sistema/precos {company, valor, vale_a_partir_de} → {preco: {company, id, valor, vale_a_partir_de}}. */
    public function criarPreco(Request $request)
    {
        if ($negado = $this->negarSeNaoDonoDoSistema($request)) {
            return $negado;
        }

        $preco = PrecosDasCentrais::criar($request->all(), $this->usuario($request), $this->agora());

        return is_string($preco) ? $this->erro($preco) : response()->json(['preco' => $preco]);
    }

    /** DELETE sistema/precos/{id} → {apagado: true}; id que não existe (ou não é número): 404. */
    public function apagarPreco(Request $request, string $id)
    {
        if ($negado = $this->negarSeNaoDonoDoSistema($request)) {
            return $negado;
        }

        $numero = ctype_digit($id) ? (int) $id : 0;

        return PrecosDasCentrais::apagar($numero) ? response()->json(['apagado' => true]) : $this->erro('Preço não encontrado.', 404);
    }

    /** Agora em Brasília, sem fuso (o formato da tabela). */
    protected function agora(): string
    {
        return now()->format('Y-m-d H:i:s');
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

    /** Só o administrador do sistema (User::isAdmin(): type 'admin'); o "Administrator" de uma organização é 'user' e não passa. */
    protected function negarSeNaoDonoDoSistema(Request $request)
    {
        $usuario = Auth::getUserFromSession($request);

        if (!$usuario || !$usuario->isAdmin()) {
            return response()->json(['errors' => [static::NEGADO]], 403);
        }

        return null;
    }
}
