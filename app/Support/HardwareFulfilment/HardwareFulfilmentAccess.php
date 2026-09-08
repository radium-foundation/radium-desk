<?php

namespace App\Support\HardwareFulfilment;

use App\Models\User;
use App\Support\Inventory\InventoryAccess;
use Database\Seeders\RolePermissionSeeder;

/**
 * P0-M6 is still UNKNOWN (permission vs a named operator).
 * This uses the existing Spatie role/permission mechanism. It does not hard-code a user id.
 */
final class HardwareFulfilmentAccess
{
    public static function allows(?User $user): bool
    {
        return InventoryAccess::allowsPermission(
            $user,
            RolePermissionSeeder::PERMISSION_HARDWARE_FULFILMENT_OPERATE,
        );
    }

    public static function allowsCountryCorrection(?User $user): bool
    {
        return InventoryAccess::allowsPermission(
            $user,
            RolePermissionSeeder::PERMISSION_HARDWARE_FULFILMENT_CORRECT_COUNTRY,
        );
    }
}
