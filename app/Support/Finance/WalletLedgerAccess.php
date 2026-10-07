<?php

namespace App\Support\Finance;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

/**
 * Customer 360 wallet visibility.
 *
 * FinanceAccess::allowsPermission also requires finance.view, which opens the
 * whole Finance module. This tab is a customer view, so finance.wallet.view is
 * enough. IncidentPolicy::view still requires incidents.view on the case.
 */
final class WalletLedgerAccess
{
    public static function allows(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->can(RolePermissionSeeder::PERMISSION_FINANCE_WALLET_VIEW);
    }
}
