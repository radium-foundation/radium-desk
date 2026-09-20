<?php

namespace App\Services\Refunds;

use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundRevocationAttemptStatus;
use App\Enums\RefundRevokeCustomerOutcome;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\RefundRevocationAttempt;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\BusinessHoldService;
use App\Services\Commercial\CommercialServiceRestorationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefundRevokeService
{
    public function __construct(
        private readonly WalletRefundReversalResolver $walletReversalResolver,
        private readonly CommercialServiceRestorationService $commercialRestorationService,
        private readonly BusinessHoldService $businessHoldService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function canRevoke(RefundRequest $refund): bool
    {
        if ($refund->status?->isRevoked() ?? false) {
            return false;
        }

        if (! ($refund->status?->isEligibleForRevoke() ?? false)) {
            return false;
        }

        return $refund->approved_refund_method === ApprovedRefundMethod::Wallet;
    }

    /**
     * @param  array{
     *     customer_outcome: string,
     *     revoke_reason: string,
     * }  $data
     */
    public function revoke(
        RefundRequest $refund,
        User $actor,
        array $data,
        Request $request,
    ): RefundRequest {
        $refund = $refund->fresh(['order', 'revoker', 'executor', 'reviewer']) ?? $refund;

        if ($refund->status?->isRevoked() ?? false) {
            return $refund;
        }

        $outcome = RefundRevokeCustomerOutcome::tryFrom((string) ($data['customer_outcome'] ?? ''));
        if (! $outcome instanceof RefundRevokeCustomerOutcome) {
            throw ValidationException::withMessages([
                'customer_outcome' => 'Select what the customer wants after revoking this refund.',
            ]);
        }

        if (! $outcome->isImplemented()) {
            throw ValidationException::withMessages([
                'customer_outcome' => 'Original-payment-method refund requires a separate implementation gate.',
            ]);
        }

        $reason = trim((string) ($data['revoke_reason'] ?? ''));
        if ($reason === '') {
            throw ValidationException::withMessages([
                'revoke_reason' => 'A revoke reason is required.',
            ]);
        }

        $idempotencyKey = $this->idempotencyKey($refund);

        $attempt = $this->prepareAttempt($refund, $actor, $outcome, $reason, $idempotencyKey);

        $lockedRefund = RefundRequest::query()->findOrFail($refund->id);
        if ($lockedRefund->status?->isRevoked() ?? false) {
            return $lockedRefund->fresh(['order', 'revoker', 'executor', 'reviewer']) ?? $lockedRefund;
        }

        if (! $attempt->hasWalletReversal()) {
            $this->performWalletReversal($lockedRefund, $attempt, $idempotencyKey);
            $attempt = $attempt->fresh() ?? $attempt;
        }

        return $this->finalizeDeskRevoke($lockedRefund, $attempt, $actor, $outcome, $reason, $idempotencyKey, $request);
    }

    private function prepareAttempt(
        RefundRequest $refund,
        User $actor,
        RefundRevokeCustomerOutcome $outcome,
        string $reason,
        string $idempotencyKey,
    ): RefundRevocationAttempt {
        return DB::transaction(function () use ($refund, $actor, $outcome, $reason, $idempotencyKey): RefundRevocationAttempt {
            /** @var RefundRequest $locked */
            $locked = RefundRequest::query()->lockForUpdate()->findOrFail($refund->id);
            $this->assertRevokeEligible($locked);

            $attempt = RefundRevocationAttempt::query()
                ->where('refund_request_id', $locked->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($attempt instanceof RefundRevocationAttempt && $attempt->isCompleted()) {
                return $attempt;
            }

            if (! $attempt instanceof RefundRevocationAttempt) {
                $attempt = RefundRevocationAttempt::query()->create([
                    'refund_request_id' => $locked->id,
                    'idempotency_key' => $idempotencyKey,
                    'customer_outcome' => $outcome,
                    'revoke_reason' => $reason,
                    'status' => RefundRevocationAttemptStatus::Pending,
                    'actor_user_id' => $actor->id,
                ]);
            }

            return $attempt;
        });
    }

    private function performWalletReversal(
        RefundRequest $refund,
        RefundRevocationAttempt $attempt,
        string $idempotencyKey,
    ): void {
        try {
            $reversal = $this->walletReversalResolver->reverse($refund, $idempotencyKey);
        } catch (ValidationException $exception) {
            $attempt->update([
                'status' => RefundRevocationAttemptStatus::Failed,
                'error_message' => collect($exception->errors())->flatten()->first(),
            ]);

            throw $exception;
        }

        $attempt->update([
            'status' => RefundRevocationAttemptStatus::WalletReversed,
            'wallet_reversal_reference' => $reversal['wallet_reversal_reference'],
            'wallet_reversal_transaction_id' => $reversal['wallet_reversal_transaction_id'],
            'metadata' => [
                'wallet_balance' => $reversal['balance'] ?? null,
            ],
            'error_message' => null,
        ]);
    }

    private function finalizeDeskRevoke(
        RefundRequest $refund,
        RefundRevocationAttempt $attempt,
        User $actor,
        RefundRevokeCustomerOutcome $outcome,
        string $reason,
        string $idempotencyKey,
        Request $request,
    ): RefundRequest {
        return DB::transaction(function () use ($refund, $attempt, $actor, $outcome, $reason, $idempotencyKey, $request): RefundRequest {
            /** @var RefundRequest $locked */
            $locked = RefundRequest::query()->lockForUpdate()->findOrFail($refund->id);
            $locked->loadMissing('order');

            if ($locked->status?->isRevoked() ?? false) {
                return $locked->fresh(['order', 'revoker', 'executor', 'reviewer']) ?? $locked;
            }

            $attempt = RefundRevocationAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($attempt->isCompleted()) {
                return $locked->fresh(['order', 'revoker', 'executor', 'reviewer']) ?? $locked;
            }

            if (! $attempt->hasWalletReversal()) {
                throw ValidationException::withMessages([
                    'refund' => 'Wallet reversal must succeed before the refund can be revoked.',
                ]);
            }

            $reversalReference = $attempt->wallet_reversal_reference;
            $reversalTransactionId = $attempt->wallet_reversal_transaction_id;

            if ($reversalReference === null || $reversalTransactionId === null) {
                throw ValidationException::withMessages([
                    'refund' => 'Wallet reversal reference is missing after reversal.',
                ]);
            }

            $order = $locked->order;
            if (! $order instanceof Order) {
                throw ValidationException::withMessages([
                    'refund' => 'Refund order is required before commercial service can be restored.',
                ]);
            }

            $oldValues = $this->revokeSnapshot($locked);

            $locked->update([
                'status' => RefundStatus::Revoked,
                'revoked_at' => now(),
                'revoked_by' => $actor->id,
                'revoke_reason' => $reason,
                'revoke_customer_outcome' => $outcome,
                'revoke_wallet_reversal_reference' => $reversalReference,
                'revoke_wallet_reversal_transaction_id' => $reversalTransactionId,
            ]);

            $fresh = $locked->fresh(['order']) ?? $locked;

            try {
                $restoration = $this->commercialRestorationService->restoreAfterRevoke(
                    order: $order,
                    refund: $fresh,
                    actor: $actor,
                    walletReversalReference: $reversalReference,
                    revokeReason: $reason,
                );
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages([
                    'refund' => 'Wallet reversal succeeded but commercial restoration failed: '
                        .collect($exception->errors())->flatten()->first()
                        .' Retry revoke to complete Desk state without duplicating wallet debit.',
                ]);
            }

            $attempt->update([
                'status' => RefundRevocationAttemptStatus::Completed,
                'commercial_service_restoration_id' => $restoration->id,
            ]);

            $fresh->loadMissing('incident');
            if ($fresh->incident !== null) {
                $this->businessHoldService->clearRefundHoldForRefund(
                    refund: $fresh,
                    actor: $actor,
                    source: 'refund_revoked',
                );
            }

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'refund.revoked',
                auditable: $fresh,
                oldValues: $oldValues,
                newValues: array_merge($this->revokeSnapshot($fresh), [
                    'customer_outcome' => $outcome->value,
                    'wallet_reversal_reference' => $reversalReference,
                    'wallet_reversal_transaction_id' => $reversalTransactionId,
                    'commercial_service_restoration_id' => $restoration->id,
                    'idempotency_key' => $idempotencyKey,
                ]),
                request: $request,
            );

            return $fresh->fresh(['order', 'revoker', 'executor', 'reviewer']) ?? $fresh;
        });
    }

    private function assertRevokeEligible(RefundRequest $refund): void
    {
        if ($refund->status?->isRevoked() ?? false) {
            throw ValidationException::withMessages([
                'refund' => 'This refund has already been revoked.',
            ]);
        }

        if (! ($refund->status?->isEligibleForRevoke() ?? false)) {
            throw ValidationException::withMessages([
                'refund' => 'Only a completed wallet refund can be revoked.',
            ]);
        }

        if ($refund->approved_refund_method !== ApprovedRefundMethod::Wallet) {
            throw ValidationException::withMessages([
                'refund' => 'Refund revoke currently applies only to wallet refunds.',
            ]);
        }
    }

    private function idempotencyKey(RefundRequest $refund): string
    {
        return 'refund-revoke:'.$refund->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function revokeSnapshot(RefundRequest $refund): array
    {
        return [
            'id' => $refund->id,
            'reference_no' => $refund->reference_no,
            'status' => $refund->status?->value,
            'approved_refund_method' => $refund->approved_refund_method?->value,
            'refund_amount' => (string) ($refund->refund_amount ?? $refund->amount),
            'execution_reference_no' => $refund->execution_reference_no,
            'execution_transaction_id' => $refund->execution_transaction_id,
            'revoked_at' => $refund->revoked_at?->toIso8601String(),
            'revoked_by' => $refund->revoked_by,
            'revoke_reason' => $refund->revoke_reason,
            'revoke_customer_outcome' => $refund->revoke_customer_outcome?->value,
            'revoke_wallet_reversal_reference' => $refund->revoke_wallet_reversal_reference,
            'revoke_wallet_reversal_transaction_id' => $refund->revoke_wallet_reversal_transaction_id,
        ];
    }
}
