<?php

namespace App\Support\RadiumBoxRead;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

final class RadiumBoxReadAccess
{
    public static function allows(?User $user): bool
    {
        return $user?->can(RolePermissionSeeder::PERMISSION_RADIUMBOX_READ) === true;
    }
}
