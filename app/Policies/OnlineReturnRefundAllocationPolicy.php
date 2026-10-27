<?php

namespace App\Policies;

use App\Models\OnlineReturnRefundAllocation;
use App\Models\ReturnExchangeCase;
use App\Models\User;
use App\Models\InventoryUserBranch;
use App\Support\ReturnExchange\ReturnExchangeCaseBranchAccess;
use Database\Seeders\RolePermissionSeeder;

class OnlineReturnRefundAllocationPolicy
{
    public function submit(User $user, ReturnExchangeCase $case): bool
    {
        if (! $user->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_ORDER_REFUND_ALLOCATION_SUBMIT)) {
            return false;
        }

        return app(ReturnExchangeCaseOnlineRefundPolicy::class)->submitOrderRefund($user, $case);
    }

    public function review(User $user, OnlineReturnRefundAllocation $allocation): bool
    {
        return $user->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_ORDER_REFUND_ALLOCATION_REVIEW)
            && ReturnExchangeCaseBranchAccess::hasCentralAccess($user);
    }
}
