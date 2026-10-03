<?php

namespace App\Support\Entregas;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;

/**
 * Entregas RestaurantePro: a loja (Vendor) de um usuário do portal.
 *
 * Usuário da loja = Contact type=customer ligado ao Vendor por VendorPersonnel ativo
 * (mesma regra do PortalAccountResolver do customer-portal-api).
 */
class LojaDoUsuario
{
    public static function vendor(?string $userUuid): ?Vendor
    {
        return $userUuid ? static::lojas($userUuid)->first() : null;
    }

    /** Quantas lojas o usuário tem (o portal da loja só aceita uma: com mais, juntaria os pedidos delas). */
    public static function totalDeLojas(?string $userUuid): int
    {
        return $userUuid ? static::lojas($userUuid)->count() : 0;
    }

    /** Local de coleta da loja (o Place do Vendor); usa o `place` já carregado pelo `Vendor::$with`. */
    public static function coleta(?Vendor $vendor): ?Place
    {
        if (!$vendor || !$vendor->place_uuid) {
            return null;
        }

        return $vendor->relationLoaded('place') ? $vendor->place : Place::where('uuid', $vendor->place_uuid)->first();
    }

    public static function contato(?string $userUuid): ?Contact
    {
        return $userUuid ? Contact::where(['user_uuid' => $userUuid, 'type' => 'customer'])->first() : null;
    }

    /** Lojas com vínculo ativo do contato de cliente do usuário (mesma regra do PortalAccountResolver). */
    protected static function lojas(string $userUuid): Builder
    {
        return Vendor::whereHas('vendorPersonnel', function ($query) use ($userUuid) {
            $query->where('status', 'active')->whereHas('contact', function ($contato) use ($userUuid) {
                $contato->where('user_uuid', $userUuid)->where('type', 'customer');
            });
        });
    }
}
