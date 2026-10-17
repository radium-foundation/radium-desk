<?php

namespace App\Services\ReturnExchange;

use App\Enums\WalletRefundOpmConversionStatus;
use App\Models\OnlineReturnRefundAllocationLine;
use App\Models\OnlineReturnRefundAllocationLineExecution;
use App\Models\OnlineReturnRefundAllocationOpmConversion;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\WalletRefundOpmConversion\WalletRefundOpmConversionCashfreeVerifier;
use App\Services\WalletRefundOpmConversion\WalletRefundOpmSiteAdapterResolver;
use App\Support\Money\WalletMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ReturnExchangeAllocationOpmConversionService
{
    public function __construct(
        private readonly ReturnExchangeAllocationOpmConversionEligibilityService $eligibility,
        private readonly ReturnExchangeAllocationOpmCreditSourceResolver $creditSources,
        private readonly ReturnExchangeAllocationWalletCreditService $walletReferences,
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
        if ($idempotencyKey !== '') {
            $existing = OnlineReturnRefundAllocationOpmConversion::query()
                ->where('submit_idempotency_key', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $amount = WalletMoney::normalize((string) ($payload['amount'] ?? '')) ?? '';

        return DB::transaction(function () use ($requester, $line, $execution, $amount, $idempotencyKey): OnlineReturnRefundAllocationOpmConversion {
            $lockedExecution = OnlineReturnRefundAllocationLineExecution::query()
                ->whereKey($execution->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->eligibility->assertCanRequest($line, $lockedExecution, $amount);

            $line->loadMissing(['allocation.order', 'allocation.returnCase']);
            $allocation = $line->allocation;
            $order = $allocation?->order;
            if ($allocation === null || ! $order instanceof Order || $allocation->returnCase === null) {
                throw ValidationException::withMessages(['line' => 'Allocation linkage is incomplete.']);
            }

            $creditSource = $this->creditSources->resolveFromExecution($lockedExecution);
            $siteCode = $this->eligibility->siteCodeForExecution($lockedExecution, $order);

            $conversion = OnlineReturnRefundAllocationOpmConversion::query()->create([
                'public_id' => (string) Str::uuid(),
                'return_exchange_case_id' => $allocation->returnCase->id,
                'online_return_refund_allocation_id' => $allocation->id,
                'online_return_refund_allocation_line_id' => $line->id,
                'online_return_refund_allocation_line_execution_id' => $lockedExecution->id,
                'desk_wallet_reference' => $this->walletReferences->deskReference($allocation, $line),
                'wallet_executor_provider' => (string) $lockedExecution->executor_provider,
                'wallet_execution_reference' => $lockedExecution->execution_reference,
                'wallet_transaction_id' => $lockedExecution->transaction_id,
                'submit_idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
                'status' => WalletRefundOpmConversionStatus::PendingApproval,
                'site_code' => $siteCode,
                'wallet_credit_source' => $creditSource,
                'amount' => $amount,
                'currency' => 'INR',
                'requester_id' => $requester->id,
            ]);

            $this->audit($conversion, 'return_allocation_opm_conversion.submitted', $requester);

            return $conversion;
        });
    }

    public function approve(User $approver, OnlineReturnRefundAllocationOpmConversion $conversion): OnlineReturnRefundAllocationOpmConversion
    {
        if ($conversion->requester_id === $approver->id) {
            throw ValidationException::withMessages([
                'conversion' => 'The approver must be a different user than the requester.',
            ]);
        }

        return DB::transaction(function () use ($approver, $conversion): OnlineReturnRefundAllocationOpmConversion {
            $conversion = $this->lockConversion($conversion);
            $this->assertStatus($conversion, WalletRefundOpmConversionStatus::PendingApproval);

            $execution = $conversion->walletExecution()->lockForUpdate()->firstOrFail();
            $line = $conversion->line()->firstOrFail();
            $this->eligibility->assertCanRequest($line, $execution, (string) $conversion->amount, $conversion->id);

            $conversion->update([
                'status' => WalletRefundOpmConversionStatus::Approved,
                'approver_id' => $approver->id,
            ]);

            $this->audit($conversion, 'return_allocation_opm_conversion.approved', $approver);

            return $this->reserveAfterApproval($conversion->fresh() ?? $conversion, $approver);
        });
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

        return DB::transaction(function () use ($operator, $conversion, $cashfreeOrderId, $cashfreeRefundId, $cashfreeAmount): OnlineReturnRefundAllocationOpmConversion {
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

            return $this->verifyAndFinalize($operator, $conversion->fresh() ?? $conversion);
        });
    }

    public function verifyAndFinalize(User $actor, OnlineReturnRefundAllocationOpmConversion $conversion): OnlineReturnRefundAllocationOpmConversion
    {
        return DB::transaction(function () use ($actor, $conversion): OnlineReturnRefundAllocationOpmConversion {
            $conversion = $this->lockConversion($conversion);
            if ($conversion->status !== WalletRefundOpmConversionStatus::PayoutRecorded
                && $conversion->status !== WalletRefundOpmConversionStatus::ReconciliationHold) {
                $this->assertStatus($conversion, WalletRefundOpmConversionStatus::PayoutRecorded);
            }

            $verify = $this->cashfreeVerifier->verify($conversion);

            $conversion->update([
                'cashfree_refund_status' => $verify['status'],
            ]);

            if (! ($verify['verified'] ?? false)) {
                return $this->enterReconciliation($conversion, $actor, $verify['message'] ?? 'Cashfree verification did not succeed.', $verify['status'] ?? 'ambiguous');
            }

            $conversion->update(['cashfree_verified_at' => now()]);

            $subject = $this->spokeSubject($conversion);

            try {
                $adapter = $this->adapterResolver->forOrderAndCreditSource(
                    $subject->order()?->order_id,
                    $conversion->wallet_credit_source,
                );
                $result = $adapter->finalizeWalletReversal($conversion, $subject);
                $conversion->update([
                    'wallet_reversal_reference' => $result['wallet_reversal_reference'] ?? null,
                ]);
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

            $this->audit($conversion, 'return_allocation_opm_conversion.completed', $actor);

            return $conversion;
        });
    }

    public function authorizeRelease(User $actor, OnlineReturnRefundAllocationOpmConversion $conversion, string $reason): OnlineReturnRefundAllocationOpmConversion
    {
        return DB::transaction(function () use ($actor, $conversion, $reason): OnlineReturnRefundAllocationOpmConversion {
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

            $subject = $this->spokeSubject($conversion);
            if ($conversion->site_reservation_id !== null && $conversion->status->holdsSiteReservation()) {
                $adapter = $this->adapterResolver->forOrderAndCreditSource(
                    $subject->order()?->order_id,
                    $conversion->wallet_credit_source,
                );
                $adapter->releaseReservation($conversion, $subject);
            }

            $conversion->update([
                'status' => WalletRefundOpmConversionStatus::Cancelled,
                'failure_code' => 'released',
                'failure_message' => $reason,
            ]);

            $this->audit($conversion, 'return_allocation_opm_conversion.released', $actor, ['reason' => $reason]);

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
            ]);
            $confirm = $adapter->confirmReservation($conversion->fresh() ?? $conversion);

            $conversion->update([
                'status' => WalletRefundOpmConversionStatus::Reserved,
                'site_reservation_status' => $confirm['status'],
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

        return new ReturnExchangeAllocationWalletOpmSpokeSubject(
            (string) $conversion->desk_wallet_reference,
            $order,
        );
    }

    private function lockConversion(OnlineReturnRefundAllocationOpmConversion $conversion): OnlineReturnRefundAllocationOpmConversion
    {
        return OnlineReturnRefundAllocationOpmConversion::query()
            ->whereKey($conversion->id)
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
            ], $context),
        );
    }
}
