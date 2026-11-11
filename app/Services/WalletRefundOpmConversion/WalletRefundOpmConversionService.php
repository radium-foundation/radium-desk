<?php

namespace App\Services\WalletRefundOpmConversion;

use App\Enums\WalletRefundOpmConversionStatus;
use App\Models\RefundRequest;
use App\Models\User;
use App\Models\WalletRefundOpmConversion;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WalletRefundOpmConversionService
{
    public function __construct(
        private readonly WalletRefundOpmConversionEligibilityService $eligibility,
        private readonly WalletRefundCreditSourceResolver $creditSources,
        private readonly WalletRefundOpmSiteAdapterResolver $adapterResolver,
        private readonly WalletRefundOpmConversionCashfreeVerifier $cashfreeVerifier,
        private readonly WalletRefundOpmConversionNotificationService $notifications,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function submit(User $requester, RefundRequest $refund): WalletRefundOpmConversion
    {
        $this->eligibility->assertCanSubmit($refund);

        return DB::transaction(function () use ($requester, $refund): WalletRefundOpmConversion {
            RefundRequest::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();

            $creditSource = $this->creditSources->resolve($refund);

            $conversion = WalletRefundOpmConversion::query()->create([
                'public_id' => (string) Str::uuid(),
                'refund_request_id' => $refund->id,
                'status' => WalletRefundOpmConversionStatus::PendingApproval,
                'site_code' => $this->eligibility->siteCodeForRefund($refund),
                'wallet_credit_source' => $creditSource,
                'amount' => $this->eligibility->fullConversionAmount($refund),
                'currency' => 'INR',
                'requester_id' => $requester->id,
            ]);

            $this->audit($conversion, 'wallet_refund_opm_conversion.submitted', $requester);
            $this->notifications->notifyApproversOfSubmission($conversion);

            return $conversion;
        });
    }

    public function approve(User $approver, WalletRefundOpmConversion $conversion): WalletRefundOpmConversion
    {
        if ($conversion->requester_id === $approver->id) {
            throw ValidationException::withMessages([
                'conversion' => 'The approver must be a different user than the requester.',
            ]);
        }

        return DB::transaction(function () use ($approver, $conversion): WalletRefundOpmConversion {
            $conversion = $this->lockConversion($conversion);
            $this->assertStatus($conversion, WalletRefundOpmConversionStatus::PendingApproval);

            $refund = $conversion->refundRequest()->lockForUpdate()->firstOrFail();
            $this->eligibility->assertCanSubmit($refund, $conversion->id);

            $conversion->update([
                'status' => WalletRefundOpmConversionStatus::Approved,
                'approver_id' => $approver->id,
            ]);

            $this->audit($conversion, 'wallet_refund_opm_conversion.approved', $approver);

            return $this->reserveAfterApproval($conversion->fresh() ?? $conversion, $refund, $approver);
        });
    }

    public function reject(User $approver, WalletRefundOpmConversion $conversion, string $reason): WalletRefundOpmConversion
    {
        return DB::transaction(function () use ($approver, $conversion, $reason): WalletRefundOpmConversion {
            $conversion = $this->lockConversion($conversion);
            $this->assertStatus($conversion, WalletRefundOpmConversionStatus::PendingApproval);

            $conversion->update([
                'status' => WalletRefundOpmConversionStatus::Rejected,
                'approver_id' => $approver->id,
                'failure_code' => 'rejected',
                'failure_message' => $reason,
            ]);

            $this->audit($conversion, 'wallet_refund_opm_conversion.rejected', $approver, ['reason' => $reason]);

            return $conversion;
        });
    }

    public function recordPayout(
        User $operator,
        WalletRefundOpmConversion $conversion,
        string $cashfreeOrderId,
        string $cashfreeRefundId,
        string $cashfreeAmount,
    ): WalletRefundOpmConversion {
        if ($conversion->approver_id === $operator->id) {
            throw ValidationException::withMessages([
                'conversion' => 'The payout operator must be a different user than the approver.',
            ]);
        }

        if ($conversion->requester_id === $operator->id) {
            throw ValidationException::withMessages([
                'conversion' => 'The payout operator cannot be the same user as the requester.',
            ]);
        }

        $duplicate = WalletRefundOpmConversion::query()
            ->where('cashfree_refund_id', $cashfreeRefundId)
            ->where('id', '!=', $conversion->id)
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'cashfree_refund_id' => 'This Cashfree refund reference is already linked to another conversion.',
            ]);
        }

        return DB::transaction(function () use ($operator, $conversion, $cashfreeOrderId, $cashfreeRefundId, $cashfreeAmount): WalletRefundOpmConversion {
            $conversion = $this->lockConversion($conversion);
            $this->assertStatus($conversion, WalletRefundOpmConversionStatus::Reserved);

            if (bccomp($cashfreeAmount, (string) $conversion->amount, 2) !== 0) {
                throw ValidationException::withMessages([
                    'cashfree_refund_amount' => 'Cashfree payout amount must match the approved full conversion amount.',
                ]);
            }

            $conversion->update([
                'status' => WalletRefundOpmConversionStatus::PayoutRecorded,
                'payout_operator_id' => $operator->id,
                'cashfree_order_id' => $cashfreeOrderId,
                'cashfree_refund_id' => $cashfreeRefundId,
                'cashfree_refund_amount' => $cashfreeAmount,
                'wallet_finalization_idempotency_key' => 'wallet-opm-conversion:'.$conversion->public_id,
            ]);

            $this->audit($conversion, 'wallet_refund_opm_conversion.payout_recorded', $operator);

            return $this->verifyAndFinalize($operator, $conversion->fresh() ?? $conversion);
        });
    }

    public function verifyAndFinalize(User $actor, WalletRefundOpmConversion $conversion): WalletRefundOpmConversion
    {
        return DB::transaction(function () use ($actor, $conversion): WalletRefundOpmConversion {
            $conversion = $this->lockConversion($conversion);
            if ($conversion->status !== WalletRefundOpmConversionStatus::PayoutRecorded
                && $conversion->status !== WalletRefundOpmConversionStatus::ReconciliationHold) {
                $this->assertStatus($conversion, WalletRefundOpmConversionStatus::PayoutRecorded);
            }

            $refund = $conversion->refundRequest()->firstOrFail();
            $verify = $this->cashfreeVerifier->verify($conversion);

            $conversion->update([
                'cashfree_refund_status' => $verify['status'],
            ]);

            if (! ($verify['verified'] ?? false)) {
                return $this->enterReconciliation($conversion, $actor, $verify['message'] ?? 'Cashfree verification did not succeed.', $verify['status'] ?? 'ambiguous');
            }

            $conversion->update(['cashfree_verified_at' => now()]);

            try {
                $adapter = $this->adapterResolver->forRefund($refund);
                $adapter->finalizeWalletReversal($conversion, new RefundRequestWalletOpmSpokeSubject($refund));
            } catch (ValidationException $exception) {
                return $this->enterReconciliation(
                    $conversion,
                    $actor,
                    collect($exception->errors())->flatten()->first() ?? 'Wallet finalization failed.',
                    'wallet_finalization_failed',
                );
            }

            $conversion->update([
                'status' => WalletRefundOpmConversionStatus::Completed,
            ]);

            $this->audit($conversion, 'wallet_refund_opm_conversion.completed', $actor);

            return $conversion;
        });
    }

    public function authorizeRelease(User $actor, WalletRefundOpmConversion $conversion, string $reason): WalletRefundOpmConversion
    {
        return DB::transaction(function () use ($actor, $conversion, $reason): WalletRefundOpmConversion {
            $conversion = $this->lockConversion($conversion);
            if ($conversion->status !== WalletRefundOpmConversionStatus::Reserved
                && $conversion->status !== WalletRefundOpmConversionStatus::ReservationFailed) {
                throw ValidationException::withMessages([
                    'conversion' => 'Release is only allowed before payout is recorded.',
                ]);
            }

            if ($conversion->cashfree_refund_id !== null || $conversion->cashfree_verified_at !== null) {
                throw ValidationException::withMessages([
                    'conversion' => 'Cannot release reservation after Cashfree payout evidence exists.',
                ]);
            }

            $refund = $conversion->refundRequest()->firstOrFail();
            if ($conversion->site_reservation_id !== null && $conversion->status->holdsSiteReservation()) {
                $adapter = $this->adapterResolver->forRefund($refund);
                $adapter->releaseReservation($conversion, new RefundRequestWalletOpmSpokeSubject($refund));
            }

            $conversion->update([
                'status' => WalletRefundOpmConversionStatus::Cancelled,
                'failure_code' => 'released',
                'failure_message' => $reason,
            ]);

            $this->audit($conversion, 'wallet_refund_opm_conversion.released', $actor, ['reason' => $reason]);

            return $conversion;
        });
    }

    private function reserveAfterApproval(
        WalletRefundOpmConversion $conversion,
        RefundRequest $refund,
        User $actor,
    ): WalletRefundOpmConversion {
        $conversion->update(['status' => WalletRefundOpmConversionStatus::Reserving]);

        try {
            $adapter = $this->adapterResolver->forRefund($refund);
            $subject = new RefundRequestWalletOpmSpokeSubject($refund);
            $result = $adapter->reserve($conversion, $subject);
            $conversion->update([
                'site_reservation_id' => $result['reservation_id'],
                'site_reservation_status' => $result['status'],
            ]);
            $confirm = $adapter->confirmReservation($conversion->fresh() ?? $conversion);

            $conversion->update([
                'status' => WalletRefundOpmConversionStatus::Reserved,
                'site_reservation_status' => $confirm['status'],
            ]);

            $this->audit($conversion, 'wallet_refund_opm_conversion.reserved', $actor);

            return $conversion;
        } catch (ValidationException $exception) {
            $conversion->update([
                'status' => WalletRefundOpmConversionStatus::ReservationFailed,
                'failure_code' => 'reservation_failed',
                'failure_message' => collect($exception->errors())->flatten()->first(),
            ]);

            throw $exception;
        }
    }

    private function enterReconciliation(
        WalletRefundOpmConversion $conversion,
        User $actor,
        string $message,
        string $code,
    ): WalletRefundOpmConversion {
        $conversion->update([
            'status' => WalletRefundOpmConversionStatus::ReconciliationHold,
            'failure_code' => $code,
            'failure_message' => $message,
            'reconciliation_entered_at' => now(),
        ]);

        $this->audit($conversion, 'wallet_refund_opm_conversion.reconciliation_hold', $actor, [
            'code' => $code,
            'message' => $message,
        ]);

        return $conversion;
    }

    private function lockConversion(WalletRefundOpmConversion $conversion): WalletRefundOpmConversion
    {
        return WalletRefundOpmConversion::query()->lockForUpdate()->findOrFail($conversion->id);
    }

    private function assertStatus(WalletRefundOpmConversion $conversion, WalletRefundOpmConversionStatus $expected): void
    {
        if ($conversion->status !== $expected) {
            throw ValidationException::withMessages([
                'conversion' => 'Conversion is not in the expected state for this action.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function audit(WalletRefundOpmConversion $conversion, string $event, User $actor, array $meta = []): void
    {
        $refund = $conversion->refundRequest;
        if ($refund === null) {
            return;
        }

        $this->auditLogService->log(
            $actor->id,
            $event,
            $refund,
            null,
            array_merge([
                'conversion_public_id' => $conversion->public_id,
                'conversion_status' => $conversion->status?->value,
                'amount' => (string) $conversion->amount,
                'site_code' => $conversion->site_code,
            ], $meta),
        );
    }
}
