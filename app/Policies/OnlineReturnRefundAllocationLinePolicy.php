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

    public function requestOpmConversion(User $user, OnlineReturnRefundAllocationLine $line): bool
    {
        if (! $user->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_ORDER_REFUND_ALLOCATION_OPM_CONVERSION_REQUEST)) {
            return false;
        }

        $line->loadMissing(['allocation.returnCase', 'successfulExecution']);
        $allocation = $line->allocation;
        if ($allocation === null || $line->successfulExecution === null) {
            return false;
        }

        if ($allocation->status !== OnlineReturnRefundAllocationStatus::Approved) {
            return false;
        }

        $case = $allocation->returnCase;
        if ($case === null) {
            return false;
        }

        if (ReturnExchangeCaseBranchAccess::hasCentralAccess($user)) {
            return true;
        }

        return $user->can(RolePermissionSeeder::PERMISSION_RETURNS_CASES_ORDER_REFUND_ALLOCATION_SUBMIT)
            && ReturnExchangeCaseBranchAccess::canView($user, $case);
    }
}
