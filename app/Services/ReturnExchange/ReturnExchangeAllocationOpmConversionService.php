<?php

namespace App\Services\ReturnExchange;

use App\Enums\AllocationOpmWalletFinalizationState;
use App\Enums\WalletRefundOpmConversionStatus;
use App\Models\OnlineReturnRefundAllocationLine;
use App\Models\OnlineReturnRefundAllocationLineExecution;
use App\Models\OnlineReturnRefundAllocationOpmConversion;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\WalletRefundOpmConversion\WalletRefundOpmConversionCashfreeVerifier;
use App\Services\WalletRefundOpmConversion\WalletRefundOpmSiteAdapterResolver;
use App\Support\Money\WalletMoney;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Wallet OPM spoke contract (reserve / release / finalize-reversal) is implemented on site adapters
 * and verified on RadiumBox in DeskWalletOpmConversionReservationService: finalize returns
 * idempotent_replay when the reservation is already finalized; release replays when status is released.
 */
final class ReturnExchangeAllocationOpmConversionService
{
    public function __construct(
        private readonly ReturnExchangeAllocationOpmConversionEligibilityService $eligibility,
        private readonly ReturnExchangeAllocationWalletCreditEvidenceVerifier $walletEvidence,
        private readonly WalletRefundOpmSiteAdapterResolver $adapterResolver,
        private readonly WalletRefundOpmConversionCashfreeVerifier $cashfreeVerifier,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array{amount: string, idempotency_key?: string|null}  $payload
     */
    public function submit(
        User $requester,
        OnlineReturnRefundAllocationLine $line,
        OnlineReturnRefundAllocationLineExecution $execution,
        array $payload,
    ): OnlineReturnRefundAllocationOpmConversion {
        $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));
        $amount = WalletMoney::normalize((string) ($payload['amount'] ?? '')) ?? '';

        if ($idempotencyKey !== '') {
            $existing = OnlineReturnRefundAllocationOpmConversion::query()
                ->where('submit_idempotency_key', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                $this->assertSubmitReplayAuthorized($requester, $line, $existing);

                return $existing;
            }
        }

        try {
            return $this->createConversion($requester, $line, $execution, $amount, $idempotencyKey);
        } catch (UniqueConstraintViolationException $exception) {
            if ($idempotencyKey === '') {
                throw $exception;
            }

            $existing = OnlineReturnRefundAllocationOpmConversion::query()
                ->where('submit_idempotency_key', $idempotencyKey)
                ->firstOrFail();
            $this->assertSubmitReplayAuthorized($requester, $line, $existing);

            return $existing;
        }
    }

    public function approve(User $approver, OnlineReturnRefundAllocationOpmConversion $conversion): OnlineReturnRefundAllocationOpmConversion
    {
        if ($conversion->requester_id === $approver->id) {
            throw ValidationException::withMessages([
                'conversion' => 'The approver must be a different user than the requester.',
            ]);
        }

        DB::transaction(function () use ($approver, $conversion): void {
            $conversion = $this->lockConversion($conversion);
            $this->assertStatus($conversion, WalletRefundOpmConversionStatus::PendingApproval);

            $execution = $conversion->walletExecution()->lockForUpdate()->firstOrFail();
            $line = $conversion->line()->firstOrFail();
            $this->walletEvidence->assertConversionMatchesExecution($conversion, $line, $execution);
            $this->eligibility->assertCanRequest($line, $execution, (string) $conversion->amount, $conversion->id);

            $conversion->update([
                'status' => WalletRefundOpmConversionStatus::Approved,
                'approver_id' => $approver->id,
            ]);

            $this->audit($conversion, 'return_allocation_opm_conversion.approved', $approver);
        });

        return $this->reserveAfterApproval($conversion->fresh() ?? $conversion, $approver);
    }

    public function reject(User $approver, OnlineReturnRefundAllocationOpmConversion $conversion, string $reason): OnlineReturnRefundAllocationOpmConversion
    {
        return DB::transaction(function () use ($approver, $conversion, $reason): OnlineReturnRefundAllocationOpmConversion {
            $conversion = $this->lockConversion($conversion);
            $this->assertStatus($conversion, WalletRefundOpmConversionStatus::PendingApproval);

            $conversion->update([
                'status' => WalletRefundOpmConversionStatus::Rejected,
                'approver_id' => $approver->id,
                'failure_code' => 'rejected',
                'failure_message' => $reason,
            ]);

            $this->audit($conversion, 'return_allocation_opm_conversion.rejected', $approver, ['reason' => $reason]);

            return $conversion;
        });
    }

    public function recordPayout(
        User $operator,
        OnlineReturnRefundAllocationOpmConversion $conversion,
        string $cashfreeOrderId,
        string $cashfreeRefundId,
        string $cashfreeAmount,
    ): OnlineReturnRefundAllocationOpmConversion {
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

        $duplicate = OnlineReturnRefundAllocationOpmConversion::query()
            ->where('cashfree_refund_id', $cashfreeRefundId)
            ->where('id', '!=', $conversion->id)
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'cashfree_refund_id' => 'This Cashfree refund reference is already linked to another conversion.',
            ]);
        }

        DB::transaction(function () use ($operator, $conversion, $cashfreeOrderId, $cashfreeRefundId, $cashfreeAmount): void {
            $conversion = $this->lockConversion($conversion);
            $this->assertStatus($conversion, WalletRefundOpmConversionStatus::Reserved);

            if (bccomp($cashfreeAmount, (string) $conversion->amount, 2) !== 0) {
                throw ValidationException::withMessages([
                    'cashfree_refund_amount' => 'Cashfree payout amount must match the approved conversion amount.',
                ]);
            }

            $conversion->update([
                'status' => WalletRefundOpmConversionStatus::PayoutRecorded,
                'payout_operator_id' => $operator->id,
                'cashfree_order_id' => $cashfreeOrderId,
                'cashfree_refund_id' => $cashfreeRefundId,
                'cashfree_refund_amount' => $cashfreeAmount,
                'wallet_finalization_idempotency_key' => 'return-allocation-opm:'.$conversion->public_id,
            ]);

            $this->audit($conversion, 'return_allocation_opm_conversion.payout_recorded', $operator);
        });

        return $this->verifyAndFinalize($operator, $conversion->fresh() ?? $conversion);
    }

    public function verifyAndFinalize(User $actor, OnlineReturnRefundAllocationOpmConversion $conversion): OnlineReturnRefundAllocationOpmConversion
    {
        $conversionId = (int) $conversion->id;

        $cashfreeReady = DB::transaction(function () use ($actor, $conversion): bool {
            $conversion = $this->lockConversion($conversion);
            if ($conversion->status !== WalletRefundOpmConversionStatus::PayoutRecorded
                && $conversion->status !== WalletRefundOpmConversionStatus::ReconciliationHold) {
                $this->assertStatus($conversion, WalletRefundOpmConversionStatus::PayoutRecorded);
            }

            if ($this->walletFinalizationIsConfirmed($conversion)) {
                $this->markCompleted($conversion, $actor);

                return false;
            }

            $verify = $this->cashfreeVerifier->verify($conversion);

            $conversion->update([
                'cashfree_refund_status' => $verify['status'],
            ]);

            if (! ($verify['verified'] ?? false)) {
                $this->enterReconciliation($conversion, $actor, $verify['message'] ?? 'Cashfree verification did not succeed.', $verify['status'] ?? 'ambiguous');

                return false;
            }

            $conversion->update(['cashfree_verified_at' => now()]);

            return true;
        });

        if (! $cashfreeReady) {
            return OnlineReturnRefundAllocationOpmConversion::query()->findOrFail($conversionId);
        }

        return $this->performWalletFinalization($actor, $conversionId);
    }

    public function authorizeRelease(User $actor, OnlineReturnRefundAllocationOpmConversion $conversion, string $reason): OnlineReturnRefundAllocationOpmConversion
    {
        $conversionId = (int) $conversion->id;

        DB::transaction(function () use ($conversionId): void {
            $conversion = $this->lockConversionById($conversionId);
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

            if ($conversion->site_reservation_released_at !== null) {
                throw ValidationException::withMessages([
                    'conversion' => 'This reservation was already released.',
                ]);
            }
        });

        $conversion = OnlineReturnRefundAllocationOpmConversion::query()->findOrFail($conversionId);

        if ($this->siteReservationNeedsRelease($conversion)) {
            DB::transaction(function () use ($actor, $conversionId): void {
                $locked = $this->lockConversionById($conversionId);
                $this->releaseSiteReservation($locked, $actor);
            });
            $conversion = $conversion->fresh() ?? $conversion;
            if ($conversion->site_reservation_release_outcome === 'release_failed') {
                throw ValidationException::withMessages([
                    'conversion' => $conversion->site_reservation_release_error ?? 'Site reservation release failed.',
                ]);
            }
        }

        return DB::transaction(function () use ($actor, $conversionId, $reason): OnlineReturnRefundAllocationOpmConversion {
            $conversion = $this->lockConversionById($conversionId);

            if ($this->siteReservationNeedsRelease($conversion)) {
                throw ValidationException::withMessages([
                    'conversion' => 'Site reservation must be released before cancellation can complete.',
                ]);
            }

            if ($conversion->site_reservation_release_outcome === null && trim((string) $conversion->site_reservation_id) === '') {
                $conversion->update(['site_reservation_release_outcome' => 'not_required']);
            }

            $conversion->update([
                'status' => WalletRefundOpmConversionStatus::Cancelled,
                'failure_code' => 'released',
                'failure_message' => $reason,
                'site_reservation_may_exist' => false,
            ]);

            $this->audit($conversion, 'return_allocation_opm_conversion.released', $actor, ['reason' => $reason]);

            return $conversion;
        });
    }

    private function createConversion(
        User $requester,
        OnlineReturnRefundAllocationLine $line,
        OnlineReturnRefundAllocationLineExecution $execution,
        string $amount,
        string $idempotencyKey,
    ): OnlineReturnRefundAllocationOpmConversion {
        return DB::transaction(function () use ($requester, $line, $execution, $amount, $idempotencyKey): OnlineReturnRefundAllocationOpmConversion {
            if ($idempotencyKey !== '') {
                $dup = OnlineReturnRefundAllocationOpmConversion::query()
                    ->where('submit_idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($dup !== null) {
                    $this->assertSubmitReplayAuthorized($requester, $line, $dup);

                    return $dup;
                }
            }

            $lockedExecution = OnlineReturnRefundAllocationLineExecution::query()
                ->whereKey($execution->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->eligibility->assertCanRequest($line, $lockedExecution, $amount);
            $snapshot = $this->walletEvidence->verifyAndSnapshot($line, $lockedExecution);

            $line->loadMissing(['allocation.returnCase']);
            $allocation = $line->allocation;
            if ($allocation === null || $allocation->returnCase === null) {
                throw ValidationException::withMessages(['line' => 'Allocation linkage is incomplete.']);
            }

            if (bccomp($amount, $snapshot['bound_wallet_credit_amount'], 2) > 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Conversion amount cannot exceed the wallet credit amount.',
                ]);
            }

            $conversion = OnlineReturnRefundAllocationOpmConversion::query()->create([
                'public_id' => (string) Str::uuid(),
                'return_exchange_case_id' => $allocation->returnCase->id,
                'online_return_refund_allocation_id' => $allocation->id,
                'online_return_refund_allocation_line_id' => $line->id,
                'online_return_refund_allocation_line_execution_id' => $lockedExecution->id,
                'desk_wallet_reference' => $snapshot['desk_wallet_reference'],
                'bound_order_id' => $snapshot['bound_order_id'],
                'bound_wallet_credit_amount' => $snapshot['bound_wallet_credit_amount'],
                'wallet_executor_provider' => $snapshot['wallet_executor_provider'],
                'wallet_execution_reference' => $snapshot['wallet_execution_reference'],
                'wallet_transaction_id' => $snapshot['wallet_transaction_id'],
                'submit_idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
                'status' => WalletRefundOpmConversionStatus::PendingApproval,
                'site_code' => $snapshot['site_code'],
                'wallet_credit_source' => $snapshot['wallet_credit_source'],
                'amount' => $amount,
                'currency' => 'INR',
                'requester_id' => $requester->id,
                'wallet_finalization_state' => AllocationOpmWalletFinalizationState::NotSent,
            ]);

            $this->audit($conversion, 'return_allocation_opm_conversion.submitted', $requester);

            return $conversion;
        });
    }

    private function performWalletFinalization(User $actor, int $conversionId): OnlineReturnRefundAllocationOpmConversion
    {
        $prepared = DB::transaction(function () use ($conversionId, $actor): array {
            $conversion = $this->lockConversionById($conversionId);

            if ($this->walletFinalizationIsConfirmed($conversion)) {
                return ['skip_http' => true, 'conversion' => $conversion];
            }

            $state = $conversion->wallet_finalization_state;
            if ($state === AllocationOpmWalletFinalizationState::Ambiguous) {
                $this->enterReconciliation(
                    $conversion,
                    $actor,
                    'Wallet finalization outcome is ambiguous. Manual reconciliation is required before any retry.',
                    'wallet_finalization_ambiguous',
                );

                return ['skip_http' => true, 'conversion' => $conversion->fresh() ?? $conversion];
            }

            if ($state === AllocationOpmWalletFinalizationState::InFlight) {
                $this->enterReconciliation(
                    $conversion,
                    $actor,
                    'A wallet finalization attempt is already in flight. Reconcile with the site before retrying.',
                    'wallet_finalization_in_flight',
                );

                return ['skip_http' => true, 'conversion' => $conversion->fresh() ?? $conversion];
            }

            $conversion->update([
                'wallet_finalization_state' => AllocationOpmWalletFinalizationState::InFlight,
                'wallet_finalization_attempted_at' => now(),
            ]);

            return ['skip_http' => false, 'conversion' => $conversion->fresh() ?? $conversion];
        });

        /** @var OnlineReturnRefundAllocationOpmConversion $conversion */
        $conversion = $prepared['conversion'];

        if ($prepared['skip_http']) {
            if ($this->walletFinalizationIsConfirmed($conversion)) {
                return DB::transaction(function () use ($actor, $conversion): OnlineReturnRefundAllocationOpmConversion {
                    $conversion = $this->lockConversion($conversion);
                    $this->markCompleted($conversion, $actor);

                    return $conversion;
                });
            }

            return $conversion;
        }

        $subject = $this->spokeSubject($conversion);

        try {
            $adapter = $this->adapterResolver->forOrderAndCreditSource(
                $subject->order()?->order_id,
                $conversion->wallet_credit_source,
            );
            $result = $adapter->finalizeWalletReversal($conversion, $subject);
        } catch (ValidationException $exception) {
            return DB::transaction(function () use ($actor, $conversion, $exception): OnlineReturnRefundAllocationOpmConversion {
                $conversion = $this->lockConversion($conversion);
                $message = collect($exception->errors())->flatten()->first() ?? 'Wallet finalization failed.';
                if ($this->isAmbiguousSpokeFailure($message)) {
                    $conversion->update([
                        'wallet_finalization_state' => AllocationOpmWalletFinalizationState::Ambiguous,
                        'wallet_finalization_last_error' => $message,
                    ]);

                    return $this->enterReconciliation($conversion, $actor, $message, 'wallet_finalization_ambiguous');
                }

                $conversion->update([
                    'wallet_finalization_state' => AllocationOpmWalletFinalizationState::Failed,
                    'wallet_finalization_last_error' => $message,
                ]);

                return $this->enterReconciliation($conversion, $actor, $message, 'wallet_finalization_failed');
            });
        }

        return DB::transaction(function () use ($actor, $conversion, $result): OnlineReturnRefundAllocationOpmConversion {
            $conversion = $this->lockConversion($conversion);
            $replay = (bool) ($result['idempotent_replay'] ?? false);
            $reference = trim((string) ($result['wallet_reversal_reference'] ?? ''));
            if ($reference === '') {
                $conversion->update([
                    'wallet_finalization_state' => AllocationOpmWalletFinalizationState::Ambiguous,
                    'wallet_finalization_last_error' => 'Wallet finalization returned without a reversal reference.',
                ]);

                return $this->enterReconciliation(
                    $conversion,
                    $actor,
                    'Wallet finalization returned without a reversal reference.',
                    'wallet_finalization_ambiguous',
                );
            }

            $conversion->update([
                'wallet_reversal_reference' => $reference,
                'wallet_finalization_state' => AllocationOpmWalletFinalizationState::Confirmed,
                'wallet_finalization_confirmed_at' => now(),
                'wallet_finalization_idempotent_replay' => $replay,
            ]);

            $this->markCompleted($conversion, $actor);

            return $conversion;
        });
    }

    private function reserveAfterApproval(
        OnlineReturnRefundAllocationOpmConversion $conversion,
        User $actor,
    ): OnlineReturnRefundAllocationOpmConversion {
        $conversion->update(['status' => WalletRefundOpmConversionStatus::Reserving]);

        $subject = $this->spokeSubject($conversion);

        try {
            $adapter = $this->adapterResolver->forOrderAndCreditSource(
                $subject->order()?->order_id,
                $conversion->wallet_credit_source,
            );
            $result = $adapter->reserve($conversion, $subject);
            $conversion->update([
                'site_reservation_id' => $result['reservation_id'],
                'site_reservation_status' => $result['status'],
                'site_reservation_may_exist' => true,
            ]);
            $confirm = $adapter->confirmReservation($conversion->fresh() ?? $conversion);

            $conversion->update([
                'status' => WalletRefundOpmConversionStatus::Reserved,
                'site_reservation_status' => $confirm['status'],
                'site_reservation_may_exist' => true,
            ]);

            $this->audit($conversion, 'return_allocation_opm_conversion.reserved', $actor);
        } catch (ValidationException $exception) {
            $conversion->update([
                'status' => WalletRefundOpmConversionStatus::ReservationFailed,
                'failure_code' => 'reservation_failed',
                'failure_message' => collect($exception->errors())->flatten()->first(),
            ]);
            $this->audit($conversion, 'return_allocation_opm_conversion.reservation_failed', $actor);
        } catch (\Throwable $exception) {
            $conversion->update([
                'status' => WalletRefundOpmConversionStatus::ReservationFailed,
                'failure_code' => 'reservation_failed',
                'failure_message' => $exception->getMessage(),
            ]);
            $this->audit($conversion, 'return_allocation_opm_conversion.reservation_failed', $actor);
        }

        return $conversion->fresh() ?? $conversion;
    }

    private function releaseSiteReservation(OnlineReturnRefundAllocationOpmConversion $conversion, User $actor): void
    {
        $subject = $this->spokeSubject($conversion);

        try {
            $adapter = $this->adapterResolver->forOrderAndCreditSource(
                $subject->order()?->order_id,
                $conversion->wallet_credit_source,
            );
            $result = $adapter->releaseReservation($conversion, $subject);
            $conversion->update([
                'site_reservation_released_at' => now(),
                'site_reservation_release_outcome' => ($result['idempotent_replay'] ?? false) ? 'release_replay' : 'released',
                'site_reservation_release_error' => null,
                'site_reservation_may_exist' => false,
            ]);
            $this->audit($conversion, 'return_allocation_opm_conversion.reservation_released', $actor, $result);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?? 'Site reservation release failed.';
            $conversion->update([
                'site_reservation_release_outcome' => 'release_failed',
                'site_reservation_release_error' => $message,
            ]);
            $this->audit($conversion, 'return_allocation_opm_conversion.reservation_release_failed', $actor, ['message' => $message]);
        }
    }

    private function siteReservationNeedsRelease(OnlineReturnRefundAllocationOpmConversion $conversion): bool
    {
        if ($conversion->site_reservation_released_at !== null) {
            return false;
        }

        $reservationId = trim((string) $conversion->site_reservation_id);

        return $reservationId !== '' && ($conversion->site_reservation_may_exist || $conversion->site_reservation_id !== null);
    }

    private function walletFinalizationIsConfirmed(OnlineReturnRefundAllocationOpmConversion $conversion): bool
    {
        if ($conversion->wallet_finalization_state === AllocationOpmWalletFinalizationState::Confirmed) {
            return true;
        }

        return trim((string) $conversion->wallet_reversal_reference) !== '';
    }

    private function markCompleted(OnlineReturnRefundAllocationOpmConversion $conversion, User $actor): void
    {
        if ($conversion->status === WalletRefundOpmConversionStatus::Completed) {
            return;
        }

        $conversion->update([
            'status' => WalletRefundOpmConversionStatus::Completed,
            'wallet_finalization_state' => AllocationOpmWalletFinalizationState::Confirmed,
        ]);

        $this->audit($conversion, 'return_allocation_opm_conversion.completed', $actor);
    }

    private function isAmbiguousSpokeFailure(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'unreachable')
            || str_contains($normalized, 'connection')
            || str_contains($normalized, 'timeout');
    }

    private function assertSubmitReplayAuthorized(
        User $requester,
        OnlineReturnRefundAllocationLine $line,
        OnlineReturnRefundAllocationOpmConversion $existing,
    ): void {
        if ((int) $existing->online_return_refund_allocation_line_id !== (int) $line->id) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'This idempotency key is already used for a different allocation line.',
            ]);
        }

        if (! $requester->can('requestOpmConversion', $line)) {
            throw ValidationException::withMessages([
                'conversion' => 'You are not authorized to access this conversion request.',
            ]);
        }
    }

    private function enterReconciliation(
        OnlineReturnRefundAllocationOpmConversion $conversion,
        User $actor,
        string $message,
        string $statusCode,
    ): OnlineReturnRefundAllocationOpmConversion {
        $conversion->update([
            'status' => WalletRefundOpmConversionStatus::ReconciliationHold,
            'failure_code' => $statusCode,
            'failure_message' => $message,
            'reconciliation_entered_at' => $conversion->reconciliation_entered_at ?? now(),
        ]);

        $this->audit($conversion, 'return_allocation_opm_conversion.reconciliation_hold', $actor, [
            'status' => $statusCode,
            'message' => $message,
        ]);

        return $conversion;
    }

    private function spokeSubject(OnlineReturnRefundAllocationOpmConversion $conversion): ReturnExchangeAllocationWalletOpmSpokeSubject
    {
        $conversion->loadMissing(['allocation.order']);
        $order = $conversion->allocation?->order;
        if ($order === null) {
            throw ValidationException::withMessages([
                'order' => 'Order is required for wallet OPM spoke calls.',
            ]);
        }

        if ($conversion->bound_order_id !== null
            && trim((string) $order->order_id) !== trim((string) $conversion->bound_order_id)) {
            throw ValidationException::withMessages([
                'order' => 'Order binding does not match the conversion record.',
            ]);
        }

        return new ReturnExchangeAllocationWalletOpmSpokeSubject(
            (string) $conversion->desk_wallet_reference,
            $order,
        );
    }

    private function lockConversion(OnlineReturnRefundAllocationOpmConversion $conversion): OnlineReturnRefundAllocationOpmConversion
    {
        return $this->lockConversionById((int) $conversion->id);
    }

    private function lockConversionById(int $id): OnlineReturnRefundAllocationOpmConversion
    {
        return OnlineReturnRefundAllocationOpmConversion::query()
            ->whereKey($id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertStatus(OnlineReturnRefundAllocationOpmConversion $conversion, WalletRefundOpmConversionStatus $expected): void
    {
        if ($conversion->status !== $expected) {
            throw ValidationException::withMessages([
                'conversion' => 'Conversion is not in the expected state ('.$expected->value.').',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function audit(
        OnlineReturnRefundAllocationOpmConversion $conversion,
        string $event,
        User $actor,
        array $context = [],
    ): void {
        $this->auditLogService->log(
            $actor->id,
            $event,
            $conversion,
            null,
            array_merge([
                'public_id' => $conversion->public_id,
                'return_exchange_case_id' => $conversion->return_exchange_case_id,
                'allocation_line_id' => $conversion->online_return_refund_allocation_line_id,
                'wallet_execution_id' => $conversion->online_return_refund_allocation_line_execution_id,
                'desk_wallet_reference' => $conversion->desk_wallet_reference,
                'amount' => (string) $conversion->amount,
                'status' => $conversion->status->value,
                'site_reservation_id' => $conversion->site_reservation_id,
                'wallet_finalization_state' => $conversion->wallet_finalization_state?->value,
            ], $context),
        );
    }
}
