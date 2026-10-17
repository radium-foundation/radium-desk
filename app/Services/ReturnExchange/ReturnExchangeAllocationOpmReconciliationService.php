<?php

namespace App\Services\ReturnExchange;

use App\Enums\AllocationOpmReservationState;
use App\Enums\AllocationOpmWalletFinalizationState;
use App\Enums\WalletRefundOpmConversionStatus;
use App\Models\OnlineReturnRefundAllocationOpmConversion;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\WalletRefundOpmConversion\WalletRefundOpmSiteAdapterResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Central Finance reconciliation for uncertain allocation OPM spoke outcomes.
 *
 * Recovery uses only the stable idempotency keys persisted before the original spoke call:
 * - reserve: site_reservation_idempotency_key (reserve:{public_id})
 * - finalize: wallet_finalization_idempotency_key (return-allocation-opm:{public_id})
 *
 * RadiumBox replays return the original reservation/reversal without a second debit when those keys match.
 */
final class ReturnExchangeAllocationOpmReconciliationService
{
    public function __construct(
        private readonly ReturnExchangeAllocationWalletCreditEvidenceVerifier $walletEvidence,
        private readonly WalletRefundOpmSiteAdapterResolver $adapterResolver,
        private readonly ReturnExchangeAllocationOpmSpokeFailureClassifier $spokeFailures,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function recoverReservation(
        User $actor,
        OnlineReturnRefundAllocationOpmConversion $conversion,
        string $notes,
    ): OnlineReturnRefundAllocationOpmConversion {
        $this->assertReconciliationActor($actor, $conversion);

        $conversionId = (int) $conversion->id;

        DB::transaction(function () use ($conversionId, $actor, $notes): void {
            $conversion = $this->lock($conversionId);
            $this->assertWalletEvidence($conversion);

            if ($conversion->failure_code !== 'reservation_ambiguous'
                && $conversion->site_reservation_state !== AllocationOpmReservationState::Ambiguous) {
                throw ValidationException::withMessages([
                    'conversion' => 'Reservation reconciliation is only available for ambiguous reservation outcomes.',
                ]);
            }

            if (trim((string) $conversion->site_reservation_idempotency_key) === '') {
                throw ValidationException::withMessages([
                    'conversion' => 'Reservation idempotency key is missing; cannot recover safely.',
                ]);
            }
        });

        $conversion = OnlineReturnRefundAllocationOpmConversion::query()->findOrFail($conversionId);
        $subject = $this->spokeSubject($conversion);

        try {
            $adapter = $this->adapterResolver->forOrderAndCreditSource(
                $subject->order()?->order_id,
                $conversion->wallet_credit_source,
            );
            $result = $adapter->reserve($conversion, $subject);
            $reservationId = trim((string) ($result['reservation_id'] ?? ''));
            if ($reservationId === '') {
                throw ValidationException::withMessages([
                    'conversion' => 'Site did not return a reservation id during recovery.',
                ]);
            }

            $conversion->update([
                'site_reservation_id' => $reservationId,
                'site_reservation_status' => $result['status'] ?? 'reserved',
                'site_reservation_may_exist' => true,
            ]);

            $confirm = $adapter->confirmReservation($conversion->fresh() ?? $conversion);
        } catch (ValidationException $exception) {
            $this->recordReconciliationFailure(
                $conversionId,
                $actor,
                'recover_reservation',
                $exception,
                $notes,
            );
        }

        return DB::transaction(function () use ($conversionId, $actor, $confirm, $result, $notes): OnlineReturnRefundAllocationOpmConversion {
            $conversion = $this->lock($conversionId);

            $conversion->update([
                'status' => WalletRefundOpmConversionStatus::Reserved,
                'site_reservation_state' => AllocationOpmReservationState::Confirmed,
                'site_reservation_status' => $confirm['status'] ?? 'reserved',
                'failure_code' => null,
                'failure_message' => null,
                'reconciliation_entered_at' => null,
                'last_reconciliation_at' => now(),
                'last_reconciliation_by_id' => $actor->id,
                'last_reconciliation_action' => 'recover_reservation',
                'last_reconciliation_outcome' => ($result['idempotent_replay'] ?? false) ? 'reservation_idempotent_replay' : 'reservation_recovered',
                'last_reconciliation_detail' => $notes,
            ]);

            $this->audit($conversion, 'return_allocation_opm_conversion.reservation_recovered', $actor, [
                'reservation_id' => $conversion->site_reservation_id,
                'idempotency_key' => $conversion->site_reservation_idempotency_key,
                'idempotent_replay' => $result['idempotent_replay'] ?? false,
                'notes' => $notes,
            ]);

            return $conversion;
        });
    }

    public function recoverFinalization(
        User $actor,
        OnlineReturnRefundAllocationOpmConversion $conversion,
        string $notes,
    ): OnlineReturnRefundAllocationOpmConversion {
        $this->assertReconciliationActor($actor, $conversion);

        $conversionId = (int) $conversion->id;

        DB::transaction(function () use ($conversionId, $actor): void {
            $conversion = $this->lock($conversionId);
            $this->assertWalletEvidence($conversion);

            if (! in_array($conversion->wallet_finalization_state, [
                AllocationOpmWalletFinalizationState::Ambiguous,
                AllocationOpmWalletFinalizationState::InFlight,
            ], true)) {
                throw ValidationException::withMessages([
                    'conversion' => 'Finalization reconciliation is only available for ambiguous or in-flight wallet outcomes.',
                ]);
            }

            if ($conversion->cashfree_verified_at === null) {
                throw ValidationException::withMessages([
                    'conversion' => 'Cashfree payout must be verified before wallet finalization can be reconciled.',
                ]);
            }

            if (trim((string) $conversion->wallet_finalization_idempotency_key) === '') {
                throw ValidationException::withMessages([
                    'conversion' => 'Finalization idempotency key is missing; cannot recover safely.',
                ]);
            }

            if (trim((string) $conversion->site_reservation_id) === '') {
                throw ValidationException::withMessages([
                    'conversion' => 'Site reservation id is required before wallet finalization reconciliation.',
                ]);
            }
        });

        $conversion = OnlineReturnRefundAllocationOpmConversion::query()->findOrFail($conversionId);
        $subject = $this->spokeSubject($conversion);

        try {
            $adapter = $this->adapterResolver->forOrderAndCreditSource(
                $subject->order()?->order_id,
                $conversion->wallet_credit_source,
            );
            $result = $adapter->finalizeWalletReversal($conversion, $subject);
        } catch (ValidationException $exception) {
            $this->recordReconciliationFailure(
                $conversionId,
                $actor,
                'recover_finalization',
                $exception,
                $notes,
            );
        }

        $reference = trim((string) ($result['wallet_reversal_reference'] ?? ''));
        if ($reference === '') {
            throw ValidationException::withMessages([
                'conversion' => 'Site did not return a wallet reversal reference during recovery.',
            ]);
        }

        return DB::transaction(function () use ($conversionId, $actor, $result, $reference, $notes): OnlineReturnRefundAllocationOpmConversion {
            $conversion = $this->lock($conversionId);

            $conversion->update([
                'wallet_reversal_reference' => $reference,
                'wallet_finalization_state' => AllocationOpmWalletFinalizationState::Confirmed,
                'wallet_finalization_confirmed_at' => now(),
                'wallet_finalization_idempotent_replay' => (bool) ($result['idempotent_replay'] ?? false),
                'status' => WalletRefundOpmConversionStatus::Completed,
                'failure_code' => null,
                'failure_message' => null,
                'last_reconciliation_at' => now(),
                'last_reconciliation_by_id' => $actor->id,
                'last_reconciliation_action' => 'recover_finalization',
                'last_reconciliation_outcome' => ($result['idempotent_replay'] ?? false) ? 'finalization_idempotent_replay' : 'finalization_recovered',
                'last_reconciliation_detail' => $notes,
            ]);

            $this->audit($conversion, 'return_allocation_opm_conversion.finalization_recovered', $actor, [
                'wallet_reversal_reference' => $reference,
                'idempotency_key' => $conversion->wallet_finalization_idempotency_key,
                'idempotent_replay' => $result['idempotent_replay'] ?? false,
                'notes' => $notes,
            ]);

            return $conversion;
        });
    }

    private function recordReconciliationFailure(
        int $conversionId,
        User $actor,
        string $action,
        ValidationException $exception,
        string $notes,
    ): void {
        $message = collect($exception->errors())->flatten()->first() ?? 'Reconciliation failed.';

        DB::transaction(function () use ($conversionId, $actor, $action, $message, $notes): void {
            $conversion = $this->lock($conversionId);

            $conversion->update([
                'status' => WalletRefundOpmConversionStatus::ReconciliationHold,
                'last_reconciliation_at' => now(),
                'last_reconciliation_by_id' => $actor->id,
                'last_reconciliation_action' => $action,
                'last_reconciliation_outcome' => 'reconciliation_failed',
                'last_reconciliation_detail' => $notes.' | '.$message,
                'failure_message' => $message,
            ]);

            $this->audit($conversion, 'return_allocation_opm_conversion.reconciliation_failed', $actor, [
                'action' => $action,
                'message' => $message,
                'notes' => $notes,
            ]);
        });

        throw ValidationException::withMessages(['conversion' => $message]);
    }

    private function assertReconciliationActor(User $actor, OnlineReturnRefundAllocationOpmConversion $conversion): void
    {
        if ($conversion->requester_id === $actor->id) {
            throw ValidationException::withMessages([
                'conversion' => 'The reconciliation operator must be a different user than the requester.',
            ]);
        }

        if ($conversion->approver_id === $actor->id) {
            throw ValidationException::withMessages([
                'conversion' => 'The reconciliation operator must be a different user than the approver.',
            ]);
        }
    }

    private function assertWalletEvidence(OnlineReturnRefundAllocationOpmConversion $conversion): void
    {
        $execution = $conversion->walletExecution()->first();
        $line = $conversion->line()->first();
        if ($execution === null || $line === null) {
            throw ValidationException::withMessages(['conversion' => 'Wallet execution linkage is missing.']);
        }

        $this->walletEvidence->assertConversionMatchesExecution($conversion, $line, $execution);
    }

    private function spokeSubject(OnlineReturnRefundAllocationOpmConversion $conversion): ReturnExchangeAllocationWalletOpmSpokeSubject
    {
        $conversion->loadMissing(['allocation.order']);
        $order = $conversion->allocation?->order;
        if ($order === null) {
            throw ValidationException::withMessages(['order' => 'Order is required for wallet OPM spoke calls.']);
        }

        return new ReturnExchangeAllocationWalletOpmSpokeSubject(
            (string) $conversion->desk_wallet_reference,
            $order,
        );
    }

    private function lock(int $id): OnlineReturnRefundAllocationOpmConversion
    {
        return OnlineReturnRefundAllocationOpmConversion::query()
            ->whereKey($id)
            ->lockForUpdate()
            ->firstOrFail();
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
                'status' => $conversion->status->value,
            ], $context),
        );
    }
}
