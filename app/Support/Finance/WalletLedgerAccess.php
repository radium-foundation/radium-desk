<?php

namespace App\Support\Finance;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

final class WalletLedgerAccess
{
    public static function allows(?User $user): bool
    {
        return FinanceAccess::allowsPermission(
            $user,
            RolePermissionSeeder::PERMISSION_FINANCE_WALLET_VIEW,
        );
    }
}
