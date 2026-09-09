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

    /**
     * Persisted Label/Manifest download. Does not grant shipment mutation.
     *
     * Admin / hardware_team keep {@see self::allows()} (operate).
     * Support Agent may download because Customer 360 already uses `orders.view`;
     * they do not receive `hardware.fulfilment.operate`.
     */
    public static function allowsDocumentDownload(?User $user): bool
    {
        if (self::allows($user)) {
            return true;
        }

        return self::allowsSupportAgentDocumentDownload($user);
    }

    public static function allowsSupportAgentDocumentDownload(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->hasRole(RolePermissionSeeder::ROLE_AGENT)
            && $user->can('orders.view');
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
