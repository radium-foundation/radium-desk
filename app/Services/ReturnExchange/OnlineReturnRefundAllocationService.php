<?php

namespace App\Services\ReturnExchange;

use App\Enums\OnlineReturnRefundAllocationDestination;
use App\Enums\OnlineReturnRefundAllocationStatus;
use App\Enums\ReturnExchangeCaseSourceKind;
use App\Enums\ReturnExchangeCaseStatus;
use App\Models\OnlineReturnRefundAllocation;
use App\Models\ReturnExchangeCase;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OnlineReturnRefundAllocationService
{
    public function __construct(
        private readonly ReturnExchangeCaseOnlineHardwareContext $hardwareContext,
        private readonly ReturnExchangeCaseRecordedTaxOutcomeVerifier $taxOutcome,
        private readonly ReturnExchangeCaseOnlineRefundAllocationCapResolver $caps,
        private readonly OnlineReturnRefundAllocationReferenceService $references,
    ) {}

    /**
     * @param  array{
     *     lines: list<array{destination: string, amount: string|float, execution_reference?: string|null}>,
     *     requester_remarks?: string|null,
     *     idempotency_key?: string|null,
     * }  $payload
     */
    public function submit(User $actor, ReturnExchangeCase $case, array $payload): OnlineReturnRefundAllocation
    {
        $idempotencyKey = isset($payload['idempotency_key']) && $payload['idempotency_key'] !== ''
            ? (string) $payload['idempotency_key']
            : null;

        if ($idempotencyKey !== null) {
            $existing = OnlineReturnRefundAllocation::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                return $existing->load('lines');
            }
        }

        try {
            return DB::transaction(function () use ($actor, $case, $payload, $idempotencyKey): OnlineReturnRefundAllocation {
                if ($idempotencyKey !== null) {
                    $dup = OnlineReturnRefundAllocation::query()
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();
                    if ($dup !== null) {
                        return $dup->load('lines');
                    }
                }

                $lockedCase = ReturnExchangeCase::query()
                    ->whereKey($case->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertCaseEligibleForSubmit($lockedCase);

                $existing = OnlineReturnRefundAllocation::query()
                    ->where('return_exchange_case_id', $lockedCase->id)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    throw ValidationException::withMessages([
                        'allocation' => 'An online refund allocation already exists for this case ('.$existing->reference_no.').',
                    ]);
                }

                $context = $this->hardwareContext->resolve($lockedCase);
                $tax = $this->taxOutcome->verify($lockedCase);
                if (! $tax->verified || $tax->invoice === null) {
                    throw ValidationException::withMessages([
                        'tax_evidence' => $tax->blockerMessage ?? 'Recorded tax evidence is required before Finance allocation.',
                    ]);
                }

                $capSnapshot = $this->caps->resolve($lockedCase, $context, $tax->invoice);
                $normalizedLines = $this->normalizeLines($payload['lines'] ?? []);
                $requestedTotal = round(array_sum(array_map(fn (array $line): float => $line['amount'], $normalizedLines)), 2);
                $maxAllocatable = round((float) $capSnapshot->maxAllocatableAmount, 2);

                if ($requestedTotal < 0.01) {
                    throw ValidationException::withMessages([
                        'lines' => 'Add at least one allocation line with a positive amount.',
                    ]);
                }

                if ($requestedTotal > $maxAllocatable + 0.001) {
                    throw ValidationException::withMessages([
                        'lines' => 'Requested allocation total exceeds the verified cap (₹'
                            .number_format($maxAllocatable, 2).' max for returned hardware and order remainder).',
                    ]);
                }

                $allocation = OnlineReturnRefundAllocation::query()->create([
                    'return_exchange_case_id' => $lockedCase->id,
                    'order_id' => $context->order->id,
                    'hardware_fulfilment_id' => $context->fulfilment?->id,
                    'reference_no' => $this->references->generate(),
                    'status' => OnlineReturnRefundAllocationStatus::PendingFinanceReview,
                    'returned_line_cap' => $capSnapshot->returnedLineCap,
                    'order_paid_total' => $capSnapshot->orderPaidTotal,
                    'prior_refunds_total' => $capSnapshot->priorRefundsTotal,
                    'max_allocatable_amount' => $capSnapshot->maxAllocatableAmount,
                    'requested_total' => number_format($requestedTotal, 2, '.', ''),
                    'currency' => $capSnapshot->currency,
                    'requester_remarks' => $payload['requester_remarks'] ?? null,
                    'requested_by' => $actor->id,
                    'requested_at' => now(),
                    'idempotency_key' => $idempotencyKey,
                ]);

                foreach ($normalizedLines as $index => $line) {
                    $allocation->lines()->create([
                        'line_no' => $index + 1,
                        'destination' => $line['destination'],
                        'amount' => number_format($line['amount'], 2, '.', ''),
                        'execution_reference' => $line['execution_reference'],
                    ]);
                }

                return $allocation->fresh(['lines', 'requester']) ?? $allocation;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'allocation' => 'An online refund allocation already exists for this case.',
            ]);
        }
    }

    public function approve(User $actor, OnlineReturnRefundAllocation $allocation, ?string $reason = null): OnlineReturnRefundAllocation
    {
        return $this->review($actor, $allocation, OnlineReturnRefundAllocationStatus::Approved, $reason);
    }

    public function reject(User $actor, OnlineReturnRefundAllocation $allocation, string $reason): OnlineReturnRefundAllocation
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'review_reason' => 'A rejection reason is required.',
            ]);
        }

        return $this->review($actor, $allocation, OnlineReturnRefundAllocationStatus::Rejected, $reason);
    }

    private function review(
        User $actor,
        OnlineReturnRefundAllocation $allocation,
        OnlineReturnRefundAllocationStatus $decision,
        ?string $reason,
    ): OnlineReturnRefundAllocation {
        return DB::transaction(function () use ($actor, $allocation, $decision, $reason): OnlineReturnRefundAllocation {
            $locked = OnlineReturnRefundAllocation::query()
                ->whereKey($allocation->id)
                ->lockForUpdate()
                ->with(['lines', 'returnCase'])
                ->firstOrFail();

            if (! $locked->status->isPendingReview()) {
                throw ValidationException::withMessages([
                    'allocation' => 'Only allocations pending Finance review can be decided.',
                ]);
            }

            $locked->update([
                'status' => $decision,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_reason' => $reason,
            ]);

            return $locked->fresh(['lines', 'returnCase', 'requester', 'reviewer']) ?? $locked;
        });
    }

    private function assertCaseEligibleForSubmit(ReturnExchangeCase $case): void
    {
        if (! in_array($case->source_kind, [
            ReturnExchangeCaseSourceKind::EcommerceOrder,
            ReturnExchangeCaseSourceKind::EcommerceFulfilment,
        ], true)) {
            throw ValidationException::withMessages([
                'case' => 'Online refund allocation is only supported for ecommerce return cases.',
            ]);
        }

        if ($case->status !== ReturnExchangeCaseStatus::Inspected) {
            throw ValidationException::withMessages([
                'case' => 'Goods must be received and inspected before Finance allocation.',
            ]);
        }

        if ($case->refund_request_id !== null) {
            throw ValidationException::withMessages([
                'allocation' => 'This case already has a linked refund request.',
            ]);
        }
    }

    /**
     * @param  list<array{destination?: string, amount?: string|float, execution_reference?: string|null}>  $lines
     * @return list<array{destination: OnlineReturnRefundAllocationDestination, amount: float, execution_reference: ?string}>
     */
    private function normalizeLines(array $lines): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'Provide at least one allocation line.',
            ]);
        }

        $normalized = [];
        foreach ($lines as $index => $line) {
            $destinationRaw = trim((string) ($line['destination'] ?? ''));
            $destination = OnlineReturnRefundAllocationDestination::tryFrom($destinationRaw);
            if ($destination === null) {
                throw ValidationException::withMessages([
                    "lines.{$index}.destination" => 'Select a supported refund destination.',
                ]);
            }

            $amount = round((float) ($line['amount'] ?? 0), 2);
            if ($amount < 0.01) {
                throw ValidationException::withMessages([
                    "lines.{$index}.amount" => 'Amount must be at least ₹0.01.',
                ]);
            }

            $reference = isset($line['execution_reference']) ? trim((string) $line['execution_reference']) : null;
            if ($reference === '') {
                $reference = null;
            }

            if (in_array($destination, [
                OnlineReturnRefundAllocationDestination::BankTransfer,
                OnlineReturnRefundAllocationDestination::Upi,
            ], true) && $reference === null) {
                throw ValidationException::withMessages([
                    "lines.{$index}.execution_reference" => 'Manual bank/UPI allocations require an execution reference for audit.',
                ]);
            }

            $normalized[] = [
                'destination' => $destination,
                'amount' => $amount,
                'execution_reference' => $reference,
            ];
        }

        return $normalized;
    }
}
