<?php

namespace App\Policies;

use App\Enums\OnlineReturnRefundAllocationStatus;
use App\Models\OnlineReturnRefundAllocationLine;
use App\Models\User;
use App\Support\ReturnExchange\ReturnExchangeCaseBranchAccess;
use Database\Seeders\RolePermissionSeeder;

class OnlineReturnRefundAllocationLinePolicy
{
    public function execute(User $user, OnlineReturnRefundAllocationLine $line): bool
    {
        if (! $user->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_ORDER_REFUND_ALLOCATION_EXECUTE)) {
            return false;
        }

        if (! ReturnExchangeCaseBranchAccess::hasCentralAccess($user)) {
            return false;
        }

        $line->loadMissing('allocation');
        $allocation = $line->allocation;
        if ($allocation === null) {
            return false;
        }

        return $allocation->status === OnlineReturnRefundAllocationStatus::Approved;
    }
}
