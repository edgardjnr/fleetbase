<?php

namespace App\Support\Entregas;

use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: quem é o líder dos motoboys, que vê a aba Mapa do app (pedidos em andamento e motoboys no mapa)
 * e passa um pedido para outro motoboy (LiderController; desenho:
 * docs/superpowers/specs/2026-10-06-app-pedidos-e-mapa-do-lider-design.md).
 *
 * Líder = o motoboy da sessão (token do app, MotoboyDaSessao; nunca a chave flb_live_ do APK, que autentica como o admin
 * que a criou) que é administrador ou tem, na empresa da sessão, a permissão do Fleet-Ops "fleet-ops assign-driver-for
 * order" ou um curinga dela ("fleet-ops * order", "fleet-ops *"). A central dá a permissão em Admin → IAM → Usuários →
 * Motoristas, pelo papel "Líder de motoboys" (ver CLAUDE.md, "App do motoboy").
 *
 * Por que não o Fleetbase\Support\Auth::can: ele chama o hasPermissionTo do Spatie, que só vê as permissões diretas e as
 * dos papéis; a permissão que chega por uma política (do usuário ou do papel) fica de fora. Aqui vale a regra do
 * AuthorizationGuard do console: CompanyUser::getAllPermissions (diretas, papéis, políticas e políticas dos papéis), do
 * vínculo do usuário com a empresa da sessão. Vínculo desativado (IAM → Desativar usuário) não é líder.
 */
class LiderDosMotoboys
{
    public const PERMISSOES = ['fleet-ops assign-driver-for order', 'fleet-ops * order', 'fleet-ops *'];

    public static function daSessao(Request $request): bool
    {
        if (!MotoboyDaSessao::motoboy($request)) {
            return false;
        }

        $usuario = User::where('uuid', (string) session('user'))->first();

        return static::ehLider($usuario, (string) session('company'));
    }

    public static function ehLider(?User $usuario, string $empresaUuid): bool
    {
        if (!$usuario || $empresaUuid === '') {
            return false;
        }

        $vinculo = CompanyUser::where('user_uuid', $usuario->uuid)->where('company_uuid', $empresaUuid)->first();
        if ($vinculo && $vinculo->status === 'inactive') {
            return false;
        }

        if ($usuario->isAdmin()) {
            return true;
        }

        if (!$vinculo) {
            return false;
        }

        $nomes = [];
        foreach ($vinculo->getAllPermissions() as $permissao) {
            $nomes[] = (string) $permissao->name;
        }

        return array_intersect(static::PERMISSOES, $nomes) !== [];
    }
}
