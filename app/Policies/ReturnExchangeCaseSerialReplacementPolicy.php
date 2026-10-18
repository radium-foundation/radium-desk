<?php

namespace App\Policies;

use App\Models\ReturnExchangeCase;
use App\Models\User;
use App\Models\InventoryUserBranch;
use App\Support\ReturnExchange\ReturnExchangeCaseBranchAccess;
use Database\Seeders\RolePermissionSeeder;

class ReturnExchangeCaseSerialReplacementPolicy
{
    public function perform(User $user, ReturnExchangeCase $case): bool
    {
        if (! $user->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_SERIAL_REPLACE)) {
            return false;
        }

        if ($case->branch_id === null) {
            return ReturnExchangeCaseBranchAccess::hasCentralAccess($user);
        }

        if (! ReturnExchangeCaseBranchAccess::canView($user, $case)) {
            return false;
        }

        if (ReturnExchangeCaseBranchAccess::hasCentralAccess($user)) {
            return true;
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
