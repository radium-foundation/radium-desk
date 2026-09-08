<?php

namespace App\Support\HardwareFulfilment;

use App\Models\HardwareFulfilment;
use App\Models\InventoryBranch;
use App\Models\Order;
use App\Models\User;
use App\Support\Inventory\InventoryBranchScope;

/**
 * Read-only dashboard/360 deep-link to an existing Hardware Fulfilment.
 * Does not ingest, create, or mutate fulfilment records.
 */
final class HardwareFulfilmentNavigation
{
    public static function resolveForOrder(?Order $order): ?HardwareFulfilment
    {
        if ($order === null || $order->id === null) {
            return null;
        }

        $bySupportOrder = HardwareFulfilment::query()
            ->where('support_order_id', (int) $order->id)
            ->orderBy('id')
            ->first();

        if ($bySupportOrder !== null) {
            return $bySupportOrder;
        }

        $sourceId = trim((string) $order->order_id);
        if ($sourceId === '') {
            return null;
        }

        return HardwareFulfilment::query()
            ->where('source_id', $sourceId)
            ->orderBy('id')
            ->first();
    }

    public static function urlFor(?User $user, ?Order $order): ?string
    {
        if (! HardwareFulfilmentAccess::allows($user)) {
            return null;
        }

        $fulfilment = self::resolveForOrder($order);
        if ($fulfilment === null || ! self::userCanOpen($user, $fulfilment)) {
            return null;
        }

        return route('inventory.hardware-fulfilments.show', $fulfilment);
    }

    public static function userCanOpen(?User $user, HardwareFulfilment $fulfilment): bool
    {
        if ($fulfilment->fulfilment_branch_id === null) {
            return true;
        }

        $branch = InventoryBranch::query()->find($fulfilment->fulfilment_branch_id);
        if ($branch === null) {
            return false;
        }

        return InventoryBranchScope::allows($user, $branch);
    }
}
