<?php

namespace App\Services\ReturnExchange;

use App\Enums\InventorySaleStatus;
use App\Enums\PosReturnRefundRequestStatus;
use App\Enums\ReturnExchangeCaseSourceKind;
use App\Enums\ReturnExchangeCaseStatus;
use App\Models\InventorySale;
use App\Models\InventorySaleSerial;
use App\Models\PosReturnRefundRequest;
use App\Models\ReturnExchangeCase;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PosReturnRefundRequestService
{
    public function __construct(
        private readonly PosReturnRefundTaxEvidenceVerifier $taxEvidence,
        private readonly PosReturnRefundReferenceService $references,
    ) {}

    /**
     * @param  array{requester_remarks?: string|null}  $payload
     */
    public function submit(User $actor, ReturnExchangeCase $case, array $payload = []): PosReturnRefundRequest
    {
        try {
            return DB::transaction(function () use ($actor, $case, $payload): PosReturnRefundRequest {
                $lockedCase = ReturnExchangeCase::query()
                    ->whereKey($case->id)
                    ->lockForUpdate()
                    ->with(['lines', 'receipts', 'inventorySale.lines', 'inventorySale.serials'])
                    ->firstOrFail();

                $existing = PosReturnRefundRequest::query()
                    ->where('return_exchange_case_id', $lockedCase->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    throw ValidationException::withMessages([
                        'refund_request' => 'A POS refund request already exists for this return case ('.$existing->reference_no.').',
                    ]);
                }

                $this->assertCaseReadyForSubmit($lockedCase);
                $sale = $this->resolveSale($lockedCase);
                $this->assertFullReturnCoverage($lockedCase, $sale);
                $payment = $this->resolvePaymentProvenance($sale);

                $tax = $this->taxEvidence->verify($lockedCase);
                if (! $tax->verified || $tax->invoice === null || $tax->evidenceType === null) {
                    throw ValidationException::withMessages([
                        'tax_evidence' => $tax->blockerMessage ?? 'Tax evidence could not be verified.',
                    ]);
                }

                return PosReturnRefundRequest::query()->create([
                    'return_exchange_case_id' => $lockedCase->id,
                    'inventory_sale_id' => $sale->id,
                    'reference_no' => $this->references->generate(),
                    'amount' => $payment['amount'],
                    'payment_method' => $payment['payment_method'],
                    'payment_reference' => $payment['payment_reference'],
                    'status' => PosReturnRefundRequestStatus::PendingFinanceReview,
                    'statutory_invoice_id' => $tax->invoice->id,
                    'tax_evidence_type' => $tax->evidenceType,
                    'requester_remarks' => $payload['requester_remarks'] ?? null,
                    'requested_by' => $actor->id,
                    'requested_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'refund_request' => 'A POS refund request already exists for this return case.',
            ]);
        }
    }

    public function approve(User $actor, PosReturnRefundRequest $request, ?string $reason = null): PosReturnRefundRequest
    {
        return $this->review($actor, $request, PosReturnRefundRequestStatus::Approved, $reason);
    }

    public function reject(User $actor, PosReturnRefundRequest $request, string $reason): PosReturnRefundRequest
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'review_reason' => 'A rejection reason is required.',
            ]);
        }

        return $this->review($actor, $request, PosReturnRefundRequestStatus::Rejected, $reason);
    }

    private function review(
        User $actor,
        PosReturnRefundRequest $request,
        PosReturnRefundRequestStatus $decision,
        ?string $reason,
    ): PosReturnRefundRequest {
        return DB::transaction(function () use ($actor, $request, $decision, $reason): PosReturnRefundRequest {
            $locked = PosReturnRefundRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isPendingReview()) {
                throw ValidationException::withMessages([
                    'refund_request' => 'Only requests pending Finance review can be decided.',
                ]);
            }

            $locked->update([
                'status' => $decision,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_reason' => $reason,
            ]);

            return $locked->fresh(['returnCase', 'inventorySale', 'requester', 'reviewer']) ?? $locked;
        });
    }

    private function assertCaseReadyForSubmit(ReturnExchangeCase $case): void
    {
        if ($case->source_kind !== ReturnExchangeCaseSourceKind::PosSale) {
            throw ValidationException::withMessages([
                'case' => 'POS refund requests are only supported for POS sale return cases.',
            ]);
        }

        if ($case->status !== ReturnExchangeCaseStatus::Inspected) {
            throw ValidationException::withMessages([
                'case' => 'Goods must be received and inspected before submitting a refund request.',
            ]);
        }

        if ($case->inventory_sale_id === null) {
            throw ValidationException::withMessages([
                'case' => 'POS sale is not linked on this case.',
            ]);
        }
    }

    private function resolveSale(ReturnExchangeCase $case): InventorySale
    {
        $sale = $case->inventorySale;

        if ($sale === null) {
            throw ValidationException::withMessages([
                'inventory_sale_id' => 'POS sale was not found.',
            ]);
        }

        if ($sale->status !== InventorySaleStatus::Completed) {
            throw ValidationException::withMessages([
                'inventory_sale_id' => 'POS sale must be in completed status for refund request.',
            ]);
        }

        return $sale;
    }

    /**
     * @return array{amount: string, payment_method: string, payment_reference: string|null}
     */
    private function resolvePaymentProvenance(InventorySale $sale): array
    {
        $amount = round((float) $sale->total, 2);
        if ($amount < 0.01) {
            throw ValidationException::withMessages([
                'amount' => 'POS sale total is not a positive refundable amount.',
            ]);
        }

        $method = trim((string) ($sale->payment_method ?? ''));
        if ($method === '') {
            throw ValidationException::withMessages([
                'payment_method' => 'POS sale payment method is not recorded.',
            ]);
        }

        $reference = $sale->payment_reference;
        $normalizedMethod = strtolower($method);

        if (! in_array($normalizedMethod, ['cash', 'card'], true)) {
            if ($reference === null || trim((string) $reference) === '') {
                if ($sale->upi_intent_id === null) {
                    throw ValidationException::withMessages([
                        'payment_reference' => 'POS payment reference or UPI intent is required to verify original payment for method ['.$method.'].',
                    ]);
                }
            }
        }

        return [
            'amount' => number_format($amount, 2, '.', ''),
            'payment_method' => $method,
            'payment_reference' => $reference !== null && trim((string) $reference) !== ''
                ? trim((string) $reference)
                : ($sale->upi_intent_id !== null ? 'upi_intent:'.$sale->upi_intent_id : null),
        ];
    }

    private function assertFullReturnCoverage(ReturnExchangeCase $case, InventorySale $sale): void
    {
        $sale->loadMissing(['lines', 'serials']);

        $expectedQtyBySaleLine = [];
        foreach ($sale->lines as $saleLine) {
            $expectedQtyBySaleLine[(int) $saleLine->id] = (int) $saleLine->qty;
        }

        $actualQtyBySaleLine = [];
        foreach ($case->lines as $line) {
            if ($line->inventory_sale_line_id === null) {
                throw ValidationException::withMessages([
                    'lines' => 'Return case line '.$line->line_no.' is not linked to a POS sale line (partial return not supported).',
                ]);
            }

            $saleLineId = (int) $line->inventory_sale_line_id;
            if (! array_key_exists($saleLineId, $expectedQtyBySaleLine)) {
                throw ValidationException::withMessages([
                    'lines' => 'Return case includes a sale line that does not belong to the POS sale.',
                ]);
            }

            $actualQtyBySaleLine[$saleLineId] = ($actualQtyBySaleLine[$saleLineId] ?? 0) + (int) $line->qty;
        }

        foreach ($expectedQtyBySaleLine as $saleLineId => $expectedQty) {
            $actual = $actualQtyBySaleLine[$saleLineId] ?? 0;
            if ($actual !== $expectedQty) {
                throw ValidationException::withMessages([
                    'lines' => 'Partial POS returns are not supported in this phase (sale line #'.$saleLineId.' expected qty '.$expectedQty.', case qty '.$actual.').',
                ]);
            }
        }

        foreach ($case->lines as $line) {
            $receipt = $case->receipts->firstWhere('return_exchange_case_line_id', $line->id);
            if ($receipt === null || $receipt->inspected_at === null) {
                throw ValidationException::withMessages([
                    'case' => 'All return lines must be received and inspected before refund request.',
                ]);
            }
        }

        if ($sale->serials->isNotEmpty()) {
            $inspectedSerialIds = $case->receipts
                ->filter(fn ($receipt): bool => $receipt->inspected_at !== null)
                ->pluck('inventory_serial_id')
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->all();

            foreach ($sale->serials as $saleSerial) {
                $serialId = (int) ($saleSerial->serial_id ?? 0);
                if ($serialId === 0) {
                    throw ValidationException::withMessages([
                        'serials' => 'POS sale serial linkage is incomplete.',
                    ]);
                }

                if (! in_array($serialId, $inspectedSerialIds, true)) {
                    throw ValidationException::withMessages([
                        'serials' => 'Every serialized unit on the POS sale must be received and inspected (partial return not supported).',
                    ]);
                }
            }
        }
    }
}
