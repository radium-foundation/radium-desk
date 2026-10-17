<?php

namespace App\Policies;

use App\Enums\AllocationOpmReservationState;
use App\Enums\AllocationOpmWalletFinalizationState;
use App\Enums\WalletRefundOpmConversionStatus;
use App\Models\OnlineReturnRefundAllocationOpmConversion;
use App\Models\User;
use App\Support\ReturnExchange\ReturnExchangeCaseBranchAccess;
use Database\Seeders\RolePermissionSeeder;

class OnlineReturnRefundAllocationOpmConversionPolicy
{
    public function approve(User $user, OnlineReturnRefundAllocationOpmConversion $conversion): bool
    {
        return $user->can(RolePermissionSeeder::PERMISSION_REFUNDS_WALLET_OPM_CONVERSION_APPROVE)
            && ReturnExchangeCaseBranchAccess::hasCentralAccess($user)
            && $conversion->status === WalletRefundOpmConversionStatus::PendingApproval;
    }

    public function reject(User $user, OnlineReturnRefundAllocationOpmConversion $conversion): bool
    {
        return $this->approve($user, $conversion);
    }

    public function recordPayout(User $user, OnlineReturnRefundAllocationOpmConversion $conversion): bool
    {
        return $user->can(RolePermissionSeeder::PERMISSION_REFUNDS_WALLET_OPM_CONVERSION_PAYOUT)
            && ReturnExchangeCaseBranchAccess::hasCentralAccess($user)
            && $conversion->status === WalletRefundOpmConversionStatus::Reserved;
    }

    public function verifyAndFinalize(User $user, OnlineReturnRefundAllocationOpmConversion $conversion): bool
    {
        return $user->can(RolePermissionSeeder::PERMISSION_REFUNDS_WALLET_OPM_CONVERSION_PAYOUT)
            && ReturnExchangeCaseBranchAccess::hasCentralAccess($user)
            && in_array($conversion->status, [
                WalletRefundOpmConversionStatus::PayoutRecorded,
                WalletRefundOpmConversionStatus::ReconciliationHold,
            ], true);
    }

    public function release(User $user, OnlineReturnRefundAllocationOpmConversion $conversion): bool
    {
        if ($conversion->failure_code === 'reservation_ambiguous'
            || $conversion->site_reservation_state === AllocationOpmReservationState::Ambiguous) {
            return false;
        }

        return $user->can(RolePermissionSeeder::PERMISSION_REFUNDS_WALLET_OPM_CONVERSION_RELEASE)
            && ReturnExchangeCaseBranchAccess::hasCentralAccess($user)
            && in_array($conversion->status, [
                WalletRefundOpmConversionStatus::Reserved,
                WalletRefundOpmConversionStatus::ReservationFailed,
            ], true);
    }

    public function reconcileReservation(User $user, OnlineReturnRefundAllocationOpmConversion $conversion): bool
    {
        return $user->can(RolePermissionSeeder::PERMISSION_REFUNDS_WALLET_OPM_CONVERSION_RECONCILE)
            && ReturnExchangeCaseBranchAccess::hasCentralAccess($user)
            && $conversion->failure_code === 'reservation_ambiguous'
            && $conversion->status === WalletRefundOpmConversionStatus::ReconciliationHold;
    }

    public function reconcileFinalization(User $user, OnlineReturnRefundAllocationOpmConversion $conversion): bool
    {
        return $user->can(RolePermissionSeeder::PERMISSION_REFUNDS_WALLET_OPM_CONVERSION_RECONCILE)
            && ReturnExchangeCaseBranchAccess::hasCentralAccess($user)
            && $conversion->status === WalletRefundOpmConversionStatus::ReconciliationHold
            && in_array($conversion->wallet_finalization_state, [
                AllocationOpmWalletFinalizationState::Ambiguous,
                AllocationOpmWalletFinalizationState::InFlight,
            ], true)
            && $conversion->cashfree_verified_at !== null;
    }
}
