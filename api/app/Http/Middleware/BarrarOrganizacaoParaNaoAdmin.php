<?php

namespace App\Http\Middleware;

use Closure;
use Fleetbase\Models\User;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: criar organização e entrar em organização só para o administrador do sistema (decisão do
 * Edgard, 2026-10-25).
 *
 * O console já esconde o "Criar ou entrar em organização" de quem não é admin (`currentUser.isAdmin`, o `is_admin` do
 * usuário = `type admin`), mas a API do core aceitava qualquer usuário logado em ROTAS (AuthController::createOrganization
 * cria a organização com ele como Administrator; joinOrganization o põe numa organização que o convidou).
 *
 * Nenhuma das duas faz parte da instalação nem do aceite de convite: o primeiro cadastro é o `onboard/create-account` e o
 * convite por e-mail é o `users/accept-company-invite`, rotas sem login. As duas daqui ficam no grupo `fleetbase.protected`
 * (só com login), e o único lugar do console que as chama é o modal "Criar ou entrar em organização".
 *
 * Fica no fim do grupo `fleetbase.protected` (RouteServiceProvider), depois do `auth:sanctum` e do
 * SetupFleetbaseSession, e lê o usuário como o próprio AuthController (Auth::getUserFromSession). Quem não é admin (ou,
 * por garantia, sem usuário) leva 403 com MENSAGEM; o resto do grupo passa direto.
 */
class BarrarOrganizacaoParaNaoAdmin
{
    /** As rotas do core (Route::fleetbaseAuthRoutes, prefixo int/v1/auth). */
    public const ROTAS = ['int/v1/auth/create-organization', 'int/v1/auth/join-organization'];

    public const MENSAGEM = 'Só o administrador do sistema cria ou entra em organização.';

    public function handle(Request $request, Closure $next)
    {
        // caminho decodificado, como o roteador do Laravel casa as rotas (o mesmo do ProtegerPortalLoja)
        $caminho = trim(rawurldecode($request->path()), '/');
        if (!in_array($caminho, static::ROTAS, true)) {
            return $next($request);
        }

        $usuario = Auth::getUserFromSession($request);
        if ($usuario instanceof User && $usuario->isAdmin()) {
            return $next($request);
        }

        Log::info('[entregas] organização: criar ou entrar barrado (não é admin)', [
            'caminho' => $caminho,
            'usuario' => $usuario instanceof User ? $usuario->public_id : null,
        ]);

        return response()->json(['errors' => [static::MENSAGEM]], 403);
    }
}
