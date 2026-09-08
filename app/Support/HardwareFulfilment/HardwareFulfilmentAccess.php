<?php

namespace App\Support\HardwareFulfilment;

use App\Models\HardwareFulfilment;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Support\Inventory\InventoryAccess;
use App\Support\Inventory\InventoryBranchScope;
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

    public static function allowsLinkedInvoice(?User $user, StatutoryInvoice $invoice): bool
    {
        if (! self::allows($user)) {
            return false;
        }

        $fulfilment = HardwareFulfilment::query()
            ->with('fulfilmentBranch')
            ->where(function ($query) use ($invoice): void {
                $query->where('statutory_invoice_id', $invoice->id)
                    ->orWhereHas('commerceOrder', function ($order) use ($invoice): void {
                        $order->where('statutory_invoice_id', $invoice->id);
                    });
            })
            ->first();

        if ($fulfilment === null) {
            return false;
        }

        if ($fulfilment->fulfilment_branch_id === null) {
            return true;
        }

        $branch = $fulfilment->fulfilmentBranch;

        return $branch !== null && InventoryBranchScope::allows($user, $branch);
    }
}
