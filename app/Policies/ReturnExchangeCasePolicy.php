<?php

namespace App\Policies;

use App\Models\ReturnExchangeCase;
use App\Models\User;
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

    public function viewCentralQueue(User $user): bool
    {
        return ReturnExchangeCaseBranchAccess::hasCentralAccess($user);
    }
}
