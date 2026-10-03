<?php

namespace App\Support\Entregas;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;

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
        if (!$userUuid) {
            return null;
        }

        return Vendor::whereHas('vendorPersonnel', function ($query) use ($userUuid) {
            $query->where('status', 'active')->whereHas('contact', function ($contato) use ($userUuid) {
                $contato->where('user_uuid', $userUuid)->where('type', 'customer');
            });
        })->first();
    }

    /** Local de coleta da loja (o Place do Vendor). */
    public static function coleta(?Vendor $vendor): ?Place
    {
        if (!$vendor || !$vendor->place_uuid) {
            return null;
        }

        return Place::where('uuid', $vendor->place_uuid)->first();
    }

    public static function contato(?string $userUuid): ?Contact
    {
        return $userUuid ? Contact::where(['user_uuid' => $userUuid, 'type' => 'customer'])->first() : null;
    }
}
