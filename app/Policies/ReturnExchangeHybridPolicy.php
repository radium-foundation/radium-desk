<?php

namespace App\Policies;

use App\Enums\ReturnExchangeHybridApprovalType;
use App\Models\ReturnExchangeCase;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

class ReturnExchangeHybridPolicy
{
    public function manage(User $user): bool
    {
        return $user->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_MANAGE);
    }

    public function planReplacement(User $user, ReturnExchangeCase $case): bool
    {
        return $user->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_HYBRID_PLAN_REPLACEMENT)
            && $user->can('view', $case);
    }

    public function recordApproval(User $user, ReturnExchangeCase $case, ReturnExchangeHybridApprovalType $type): bool
    {
        if (! $user->can('view', $case)) {
            return false;
        }

        return match ($type) {
            ReturnExchangeHybridApprovalType::BusinessCategory => $user->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_HYBRID_APPROVE_BUSINESS),
            ReturnExchangeHybridApprovalType::Goodwill => $user->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_HYBRID_APPROVE_GOODWILL),
            ReturnExchangeHybridApprovalType::PriceDifference => $user->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_HYBRID_APPROVE_PRICE),
            ReturnExchangeHybridApprovalType::Statutory => $user->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_HYBRID_APPROVE_STATUTORY),
            ReturnExchangeHybridApprovalType::Finance => $user->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_HYBRID_APPROVE_FINANCE),
            ReturnExchangeHybridApprovalType::FinalCompletion => $user->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_HYBRID_APPROVE_FINANCE),
        };
    }

    public function viewDemo(User $user): bool
    {
        return $user->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_MANAGE);
    }
}
