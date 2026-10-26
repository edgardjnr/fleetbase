<?php

namespace App\Support\Entregas\Saques;

use Fleetbase\Models\CompanyUser;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: quem configura o saque Pix no console. O administrador do sistema (type admin) ou quem tem o
 * papel Administrator na organização da sessão (a mesma regra do BarrarMotoboyNoConsole para liberar o console). O resto
 * do Financeiro continua só com o administrador do sistema (pendência do negarSeNaoAdmin, ver o CLAUDE.md do Financeiro).
 */
final class AdminDaCentral
{
    public const PAPEL = 'Administrator';

    /** O usuário da sessão, se ele pode configurar o saque da organização da sessão; null se não pode. */
    public static function usuario(Request $request): ?object
    {
        $usuario = Auth::getUserFromSession($request);
        if (!$usuario) {
            return null;
        }
        if (method_exists($usuario, 'isAdmin') && $usuario->isAdmin()) {
            return $usuario;
        }
        $empresa = session('company');
        if (!$empresa) {
            return null;
        }
        // percorre os vínculos da empresa (no Fleetbase é um só; o teste monta mais de um para provar que o papel é o que vale)
        foreach (CompanyUser::where('user_uuid', $usuario->uuid)->where('company_uuid', $empresa)->get() as $vinculo) {
            if ($vinculo->hasRole(static::PAPEL)) {
                return $usuario;
            }
        }

        return null;
    }
}
