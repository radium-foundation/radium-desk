<?php

namespace App\Policies;

use App\Models\PosReturnRefundRequest;
use App\Models\ReturnExchangeCase;
use App\Models\User;
use App\Models\InventoryUserBranch;
use App\Support\ReturnExchange\ReturnExchangeCaseBranchAccess;
use Database\Seeders\RolePermissionSeeder;

class PosReturnRefundRequestPolicy
{
    public function submit(User $user, ReturnExchangeCase $case): bool
    {
        if (! $user->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_POS_REFUND_SUBMIT)) {
            return false;
        }

        if ($case->branch_id === null) {
            return false;
        }

        if (! ReturnExchangeCaseBranchAccess::canView($user, $case)) {
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

    public function review(User $user, PosReturnRefundRequest $request): bool
    {
        return $user->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_POS_REFUND_REVIEW)
            && ReturnExchangeCaseBranchAccess::hasCentralAccess($user);
    }
}
