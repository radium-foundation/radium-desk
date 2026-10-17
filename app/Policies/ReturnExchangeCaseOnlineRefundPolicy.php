<?php

namespace App\Policies;

use App\Enums\ReturnExchangeCaseSourceKind;
use App\Models\InventoryUserBranch;
use App\Models\ReturnExchangeCase;
use App\Models\User;
use App\Support\ReturnExchange\ReturnExchangeCaseBranchAccess;
use Database\Seeders\RolePermissionSeeder;

class ReturnExchangeCaseOnlineRefundPolicy
{
    public function submitOrderRefund(User $user, ReturnExchangeCase $case): bool
    {
        if (! $user->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_ORDER_REFUND_SUBMIT)) {
            return false;
        }

        if (! in_array($case->source_kind, [
            ReturnExchangeCaseSourceKind::EcommerceOrder,
            ReturnExchangeCaseSourceKind::EcommerceFulfilment,
        ], true)) {
            return false;
        }

        if (! ReturnExchangeCaseBranchAccess::canView($user, $case)) {
            return false;
        }

        if ($case->branch_id === null) {
            return false;
        }

        $assignedBranchIds = InventoryUserBranch::query()
            ->where('user_id', $user->id)
            ->pluck('branch_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($assignedBranchIds === []) {
            return false;
        }

        return in_array((int) $case->branch_id, $assignedBranchIds, true);
    }
}
