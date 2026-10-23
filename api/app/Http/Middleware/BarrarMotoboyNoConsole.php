<?php

namespace App\Http\Middleware;

use Closure;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Entregas RestaurantePro: motoboy não entra no console nem no portal (decisão do Edgard, 2026-10-23).
 *
 * O motoboy usa só o app Entregas. Criado em Fleet-Ops → Motoristas, ele fica com `users.type = 'driver'` e o papel
 * "Driver", e o Fleetbase deixa esse login abrir o console. No caso do líder dos motoboys é pior: o papel "Líder de
 * motoboys" (permissão de passar pedido para outro motoboy) também valeria nas telas do console.
 *
 * Middleware global (roda antes das rotas, inclusive as do Composer), por isso identifica o usuário direto pelo token
 * Sanctum, como o ProtegerPortalLoja. Só age na API do console e do portal (`int/v1/*` e `customer-portal/int/v1/*`,
 * caminho decodificado): no login (ROTAS_DE_LOGIN, pelo `identity`, a mesma consulta do core) e em toda chamada com
 * token de motoboy, o que derruba quem já está logado. Resposta: 403 com MENSAGEM.
 *
 * Passa direto: o resto da API (inclusive `v1/*`, a do app do motoboy, e `~registry/...`), sem token, chave de API (sem
 * "|") e token inválido. O app do motoboy não usa `int/v1` (só as telas de `src/legacy`, fora de uso).
 *
 * Motoboy = type driver ou com cadastro de motoboy (Driver). Ficam de fora a loja (customer, que tem as regras do
 * ProtegerPortalLoja), o administrador do sistema (type admin) e o motoboy com o papel Administrator na empresa atual
 * dele: é assim que a central libera o console a alguém que também tem cadastro de motoboy.
 */
class BarrarMotoboyNoConsole
{
    public const PREFIXOS = ['int/v1/', 'customer-portal/int/v1/'];

    public const MENSAGEM = 'O console é só para a central. Use o app Entregas.';

    public const PAPEL_LIBERADO = 'Administrator';

    public function handle(Request $request, Closure $next)
    {
        // caminho decodificado, como o roteador do Laravel casa as rotas (o mesmo do ProtegerPortalLoja)
        $caminho = trim(rawurldecode($request->path()), '/');
        if (!$this->ehDoConsole($caminho)) {
            return $next($request);
        }

        if ($request->isMethod('POST') && in_array($caminho, ProtegerPortalLoja::ROTAS_DE_LOGIN, true)) {
            $doLogin = $this->usuarioDoLogin($request);
            if ($doLogin && static::semAcessoAoConsole($doLogin)) {
                return $this->negar($caminho, $doLogin);
            }
        }

        $usuario = $this->usuarioDoToken($request->bearerToken());
        if ($usuario && static::semAcessoAoConsole($usuario)) {
            return $this->negar($caminho, $usuario);
        }

        return $next($request);
    }

    /** Motoboy (type driver ou com cadastro de motoboy) sem o papel Administrator na empresa; loja e admin ficam de fora. */
    public static function semAcessoAoConsole(User $usuario): bool
    {
        if ($usuario->type === 'customer' || $usuario->isAdmin()) {
            return false;
        }

        if ($usuario->type !== 'driver' && !static::temCadastroDeMotoboy($usuario)) {
            return false;
        }

        return !static::ehAdministradorDaEmpresa($usuario);
    }

    /** Cadastro de motoboy ligado ao usuário (o Driver usa SoftDeletes: o excluído não conta). */
    protected static function temCadastroDeMotoboy(User $usuario): bool
    {
        return Driver::where('user_uuid', $usuario->uuid)->exists();
    }

    /** Papel Administrator no vínculo do usuário com a empresa atual dele (CompanyUser, Spatie HasRoles). */
    protected static function ehAdministradorDaEmpresa(User $usuario): bool
    {
        if (!$usuario->company_uuid) {
            return false;
        }

        $vinculo = CompanyUser::where('user_uuid', $usuario->uuid)->where('company_uuid', $usuario->company_uuid)->first();

        return $vinculo instanceof CompanyUser && $vinculo->hasRole(static::PAPEL_LIBERADO);
    }

    protected function ehDoConsole(string $caminho): bool
    {
        foreach (static::PREFIXOS as $prefixo) {
            if (str_starts_with($caminho, $prefixo)) {
                return true;
            }
        }

        return false;
    }

    /**
     * O usuário que o login vai autenticar: a consulta do AuthController do core (e do portal), por e-mail ou telefone,
     * sem filtro de tipo e com o first(), a mesma do ProtegerPortalLoja::loginDesativado.
     */
    protected function usuarioDoLogin(Request $request): ?User
    {
        $identidade = $request->input('identity');
        if (!is_string($identidade) || trim($identidade) === '') {
            return null;
        }

        $usuario = User::where(fn ($q) => $q->where('email', $identidade)->orWhere('phone', $identidade))->first();

        return $usuario instanceof User ? $usuario : null;
    }

    /** Dono de um token Sanctum (`id|token`); chave de API (`flb_live_…`, sem "|") ou token inválido → null. */
    protected function usuarioDoToken(?string $token): ?User
    {
        if (!$token || !str_contains($token, '|')) {
            return null;
        }

        $usuario = PersonalAccessToken::findToken($token)?->tokenable;

        return $usuario instanceof User ? $usuario : null;
    }

    protected function negar(string $caminho, User $usuario)
    {
        Log::info('[entregas] console: motoboy barrado', ['caminho' => $caminho, 'usuario' => $usuario->public_id]);

        return response()->json(['errors' => [static::MENSAGEM]], 403);
    }
}
