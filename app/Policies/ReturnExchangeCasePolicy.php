<?php

namespace App\Policies;

use App\Models\ReturnExchangeCase;
use App\Models\User;
use App\Support\Inventory\InventoryBranchScope;
use App\Support\ReturnExchange\ReturnExchangeCaseBranchAccess;
use Database\Seeders\RolePermissionSeeder;

class ReturnExchangeCasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_VIEW);
    }

    public function view(User $user, ReturnExchangeCase $case): bool
    {
        if (! $user->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_VIEW)) {
            return false;
        }

        return ReturnExchangeCaseBranchAccess::canView($user, $case);
    }

    public function create(User $user): bool
    {
        return $user->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_CREATE);
    }

    public function receive(User $user, ReturnExchangeCase $case): bool
    {
        if (! $user->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_RECEIVE)) {
            return false;
        }

        if (ReturnExchangeCaseBranchAccess::hasCentralAccess($user)) {
            return true;
        }

        $branchIds = InventoryBranchScope::allowedBranchIds($user);
        if ($branchIds === null || $branchIds === []) {
            return false;
        }

        if ($case->branch_id === null) {
            return false;
        }

        return in_array((int) $case->branch_id, $branchIds, true);
    }

    public function inspect(User $user, ReturnExchangeCase $case): bool
    {
        return $this->receive($user, $case)
            && $user->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_INSPECT);
    }

    public function viewCentralQueue(User $user): bool
    {
        return ReturnExchangeCaseBranchAccess::hasCentralAccess($user);
    }
}
