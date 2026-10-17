<?php

namespace App\Services\ReturnExchange;

use App\Enums\OnlineReturnRefundAllocationDestination;
use App\Enums\OnlineReturnRefundAllocationLineExecutionStatus;
use App\Enums\OnlineReturnRefundAllocationStatus;
use App\Enums\ReturnExchangeCaseStatus;
use App\Models\OnlineReturnRefundAllocation;
use App\Models\OnlineReturnRefundAllocationLine;
use App\Models\OnlineReturnRefundAllocationLineExecution;
use App\Models\ReturnExchangeCase;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ReturnExchange\Data\ResolvedOnlineHardwareReturnContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OnlineReturnRefundAllocationExecutionService
{
    public function __construct(
        private readonly ReturnExchangeCaseOnlineHardwareContext $hardwareContext,
        private readonly ReturnExchangeCaseRecordedTaxOutcomeVerifier $taxOutcome,
        private readonly ReturnExchangeCaseOnlineRefundAllocationCapResolver $caps,
        private readonly ReturnExchangeAllocationWalletCreditService $walletCredit,
    ) {}

    public function isExecutionEnabled(): bool
    {
        return (bool) config('return_exchange.online_refund_allocation.execution_enabled', false);
    }

    /**
     * @param  array{
     *     idempotency_key: string,
     *     execution_reference?: string|null,
     *     transaction_id?: string|null,
     * }  $payload
     */
    public function executeLine(
        User $actor,
        OnlineReturnRefundAllocation $allocation,
        OnlineReturnRefundAllocationLine $line,
        array $payload,
    ): OnlineReturnRefundAllocationLineExecution {
        if (! $this->isExecutionEnabled()) {
            throw ValidationException::withMessages([
                'execution' => 'Return-case allocation execution is disabled in this environment.',
            ]);
        }

        $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            throw ValidationException::withMessages([
                'idempotency_key' => 'An idempotency key is required.',
            ]);
        }

        $existing = OnlineReturnRefundAllocationLineExecution::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($actor, $allocation, $line, $payload, $idempotencyKey): OnlineReturnRefundAllocationLineExecution {
                $dup = OnlineReturnRefundAllocationLineExecution::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($dup !== null) {
                    return $dup;
                }

                $lockedLine = OnlineReturnRefundAllocationLine::query()
                    ->whereKey($line->id)
                    ->lockForUpdate()
                    ->with(['allocation.returnCase', 'allocation.order', 'successfulExecution'])
                    ->firstOrFail();

                if ((int) $lockedLine->online_return_refund_allocation_id !== (int) $allocation->id) {
                    throw ValidationException::withMessages([
                        'line' => 'Allocation line does not belong to this allocation.',
                    ]);
                }

                $alreadySucceeded = OnlineReturnRefundAllocationLineExecution::query()
                    ->where('online_return_refund_allocation_line_id', $lockedLine->id)
                    ->where('status', OnlineReturnRefundAllocationLineExecutionStatus::Succeeded->value)
                    ->lockForUpdate()
                    ->exists();
                if ($alreadySucceeded) {
                    throw ValidationException::withMessages([
                        'execution' => 'This allocation line was already executed successfully.',
                    ]);
                }

                $lockedAllocation = OnlineReturnRefundAllocation::query()
                    ->whereKey($allocation->id)
                    ->lockForUpdate()
                    ->with(['returnCase', 'order', 'lines.successfulExecution'])
                    ->firstOrFail();

                $this->assertAllocationExecutable($lockedAllocation);

                $case = $lockedAllocation->returnCase;
                if ($case === null) {
                    throw ValidationException::withMessages([
                        'case' => 'Return case linkage is missing.',
                    ]);
                }

                $context = $this->hardwareContext->resolve($case);
                $tax = $this->taxOutcome->verify($case);
                if (! $tax->verified || $tax->invoice === null) {
                    throw ValidationException::withMessages([
                        'tax_evidence' => $tax->blockerMessage ?? 'Recorded tax evidence is required before execution.',
                    ]);
                }

                $this->assertRemainingCaps($lockedAllocation, $lockedLine, $case, $context, $tax->invoice);

                $destination = $lockedLine->destination;

                if ($destination === OnlineReturnRefundAllocationDestination::Opm) {
                    return $this->recordHeld(
                        $lockedLine,
                        $actor,
                        $idempotencyKey,
                        'OPM conversion is not linked to return-case allocations in Desk. Record a Cashfree or manual payout separately.',
                    );
                }

                if ($destination === OnlineReturnRefundAllocationDestination::Cashfree) {
                    return $this->executeManual(
                        $lockedLine,
                        $actor,
                        $idempotencyKey,
                        $payload,
                        provider: 'cashfree_manual',
                        requireReference: true,
                    );
                }

                if (in_array($destination, [
                    OnlineReturnRefundAllocationDestination::BankTransfer,
                    OnlineReturnRefundAllocationDestination::Upi,
                ], true)) {
                    return $this->executeManual(
                        $lockedLine,
                        $actor,
                        $idempotencyKey,
                        $payload,
                        provider: 'manual',
                        requireReference: true,
                        fallbackLineReference: $lockedLine->execution_reference,
                    );
                }

                if ($destination === OnlineReturnRefundAllocationDestination::Wallet) {
                    return $this->executeWallet($lockedLine, $lockedAllocation, $actor, $idempotencyKey);
                }

                return $this->recordHeld(
                    $lockedLine,
                    $actor,
                    $idempotencyKey,
                    'Unsupported allocation destination.',
                );
            });
        } catch (UniqueConstraintViolationException) {
            return OnlineReturnRefundAllocationLineExecution::query()
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();
        }
    }

    private function assertAllocationExecutable(OnlineReturnRefundAllocation $allocation): void
    {
        if ($allocation->status !== OnlineReturnRefundAllocationStatus::Approved) {
            throw ValidationException::withMessages([
                'allocation' => 'Only Finance-approved allocations can be executed.',
            ]);
        }

        $case = $allocation->returnCase;
        if ($case === null) {
            throw ValidationException::withMessages([
                'case' => 'Return case is not linked.',
            ]);
        }

        if ($case->status !== ReturnExchangeCaseStatus::Inspected) {
            throw ValidationException::withMessages([
                'case' => 'Return case must remain in inspected state for allocation execution.',
            ]);
        }

        if ($case->refund_request_id !== null) {
            throw ValidationException::withMessages([
                'execution' => 'A generic refund request is already linked to this case.',
            ]);
        }
    }

    private function assertRemainingCaps(
        OnlineReturnRefundAllocation $allocation,
        OnlineReturnRefundAllocationLine $line,
        ReturnExchangeCase $case,
        ResolvedOnlineHardwareReturnContext $context,
        StatutoryInvoice $invoice,
    ): void {
        $caps = $this->caps->resolve($case, $context, $invoice);
        $executed = $this->sumSucceededAmount($allocation);
        $lineAmount = round((float) $line->amount, 2);
        $max = round((float) $caps->maxAllocatableAmount, 2);

        if ($executed + $lineAmount > $max + 0.001) {
            throw ValidationException::withMessages([
                'execution' => 'Executing this line would exceed the verified allocation cap.',
            ]);
        }

        if ($lineAmount > round((float) $allocation->requested_total, 2) + 0.001) {
            throw ValidationException::withMessages([
                'execution' => 'Line amount exceeds the approved allocation.',
            ]);
        }
    }

    private function sumSucceededAmount(OnlineReturnRefundAllocation $allocation): float
    {
        $allocation->loadMissing(['lines.successfulExecution']);

        $sum = 0.0;
        foreach ($allocation->lines as $allocationLine) {
            if ($allocationLine->successfulExecution !== null) {
                $sum += round((float) $allocationLine->amount, 2);
            }
        }

        return round($sum, 2);
    }

    /**
     * @param  array{execution_reference?: string|null, transaction_id?: string|null}  $payload
     */
    private function executeManual(
        OnlineReturnRefundAllocationLine $line,
        User $actor,
        string $idempotencyKey,
        array $payload,
        string $provider,
        bool $requireReference,
        ?string $fallbackLineReference = null,
    ): OnlineReturnRefundAllocationLineExecution {
        $reference = trim((string) ($payload['execution_reference'] ?? $fallbackLineReference ?? ''));
        $transactionId = trim((string) ($payload['transaction_id'] ?? ''));
        if ($requireReference && $reference === '' && $transactionId === '') {
            return OnlineReturnRefundAllocationLineExecution::query()->create([
                'online_return_refund_allocation_line_id' => $line->id,
                'status' => OnlineReturnRefundAllocationLineExecutionStatus::ManualPending,
                'executor_provider' => $provider,
                'idempotency_key' => $idempotencyKey,
                'failure_reason' => null,
                'held_reason' => null,
                'executed_by' => $actor->id,
                'executed_at' => now(),
                'metadata' => ['awaiting' => 'execution_reference_or_transaction_id'],
            ]);
        }

        if ($reference === '' && $transactionId === '') {
            throw ValidationException::withMessages([
                'execution_reference' => 'Enter payout reference or transaction ID to complete manual execution.',
            ]);
        }

        return OnlineReturnRefundAllocationLineExecution::query()->create([
            'online_return_refund_allocation_line_id' => $line->id,
            'status' => OnlineReturnRefundAllocationLineExecutionStatus::Succeeded,
            'executor_provider' => $provider,
            'idempotency_key' => $idempotencyKey,
            'execution_reference' => $reference !== '' ? $reference : null,
            'transaction_id' => $transactionId !== '' ? $transactionId : null,
            'executed_by' => $actor->id,
            'executed_at' => now(),
            'metadata' => ['manual' => true],
        ]);
    }

    private function executeWallet(
        OnlineReturnRefundAllocationLine $line,
        OnlineReturnRefundAllocation $allocation,
        User $actor,
        string $idempotencyKey,
    ): OnlineReturnRefundAllocationLineExecution {
        $order = $allocation->order;
        if ($order === null) {
            throw ValidationException::withMessages([
                'order' => 'Order is not linked on this allocation.',
            ]);
        }

        try {
            $result = $this->walletCredit->credit($allocation, $line, $order, $actor);
        } catch (ValidationException $exception) {
            return OnlineReturnRefundAllocationLineExecution::query()->create([
                'online_return_refund_allocation_line_id' => $line->id,
                'status' => OnlineReturnRefundAllocationLineExecutionStatus::Failed,
                'executor_provider' => 'wallet',
                'idempotency_key' => $idempotencyKey,
                'failure_reason' => collect($exception->errors())->flatten()->first(),
                'executed_by' => $actor->id,
                'executed_at' => now(),
            ]);
        }

        return OnlineReturnRefundAllocationLineExecution::query()->create([
            'online_return_refund_allocation_line_id' => $line->id,
            'status' => OnlineReturnRefundAllocationLineExecutionStatus::Succeeded,
            'executor_provider' => $result['provider'],
            'idempotency_key' => $idempotencyKey,
            'execution_reference' => $result['reference_number'],
            'transaction_id' => $result['transaction_id'],
            'executed_by' => $actor->id,
            'executed_at' => now(),
            'metadata' => $result['metadata'],
        ]);
    }

    private function recordHeld(
        OnlineReturnRefundAllocationLine $line,
        User $actor,
        string $idempotencyKey,
        string $reason,
    ): OnlineReturnRefundAllocationLineExecution {
        return OnlineReturnRefundAllocationLineExecution::query()->create([
            'online_return_refund_allocation_line_id' => $line->id,
            'status' => OnlineReturnRefundAllocationLineExecutionStatus::Held,
            'executor_provider' => 'held',
            'idempotency_key' => $idempotencyKey,
            'held_reason' => $reason,
            'executed_by' => $actor->id,
            'executed_at' => now(),
        ]);
    }
}
