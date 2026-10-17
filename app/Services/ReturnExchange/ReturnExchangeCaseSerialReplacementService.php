<?php

namespace App\Services\ReturnExchange;

use App\Enums\InventoryMovementType;
use App\Enums\InventorySerialStatus;
use App\Enums\ReturnExchangeCaseStatus;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventorySale;
use App\Models\InventorySaleSerial;
use App\Models\InventorySerial;
use App\Models\ReturnExchangeCase;
use App\Models\ReturnExchangeCaseReceipt;
use App\Models\ReturnExchangeCaseSerialReplacement;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Support\Inventory\InventorySerialNumber;
use App\Support\ReturnExchange\ReturnExchangeCaseBranchAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReturnExchangeCaseSerialReplacementService
{
    private const ONLINE_HELD_MESSAGE = 'Online fulfilment serial replacement is not available until Finance shipment reconciliation is implemented. Use branch POS replacement when applicable.';

    public function __construct(
        private readonly ReturnExchangeCaseRecordedTaxOutcomeVerifier $taxOutcome,
        private readonly ReturnExchangeCaseSerialReplacementEquivalence $equivalence,
        private readonly InventoryStockService $stock,
    ) {}

    /**
     * @param  array{
     *     return_exchange_case_receipt_id: int,
     *     replacement_serial_number: string,
     *     idempotency_key?: string|null,
     * }  $payload
     */
    public function perform(User $actor, ReturnExchangeCase $case, array $payload): ReturnExchangeCaseSerialReplacement
    {
        ReturnExchangeCaseBranchAccess::assertCanOperateOnCase($actor, $case);

        $idempotencyKey = isset($payload['idempotency_key']) && $payload['idempotency_key'] !== ''
            ? (string) $payload['idempotency_key']
            : null;

        if ($idempotencyKey !== null) {
            $existing = ReturnExchangeCaseSerialReplacement::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                return $existing->load(['returnedSerial', 'replacementSerial']);
            }
        }

        try {
            return DB::transaction(function () use ($actor, $case, $payload, $idempotencyKey): ReturnExchangeCaseSerialReplacement {
                if ($idempotencyKey !== null) {
                    $dup = ReturnExchangeCaseSerialReplacement::query()
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();
                    if ($dup !== null) {
                        return $dup;
                    }
                }

                $lockedCase = ReturnExchangeCase::query()
                    ->whereKey($case->id)
                    ->lockForUpdate()
                    ->with(['receipts', 'posRefundRequest'])
                    ->firstOrFail();

                $this->assertCaseEligible($lockedCase);

                $receiptId = (int) $payload['return_exchange_case_receipt_id'];
                $receipt = ReturnExchangeCaseReceipt::query()
                    ->whereKey($receiptId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $receipt->return_exchange_case_id !== (int) $lockedCase->id) {
                    throw ValidationException::withMessages([
                        'return_exchange_case_receipt_id' => 'Receipt does not belong to this case.',
                    ]);
                }

                $existingForReceipt = ReturnExchangeCaseSerialReplacement::query()
                    ->where('return_exchange_case_receipt_id', $receipt->id)
                    ->lockForUpdate()
                    ->first();
                if ($existingForReceipt !== null) {
                    throw ValidationException::withMessages([
                        'replacement' => 'A serial replacement is already recorded for this receipt.',
                    ]);
                }

                $this->assertReceiptEligible($receipt);

                $returnedSerial = InventorySerial::query()
                    ->lockForUpdate()
                    ->findOrFail($receipt->inventory_serial_id);

                $this->assertReturnedSerialEligible($lockedCase, $returnedSerial);

                $replacementNumber = InventorySerialNumber::normalize(trim((string) $payload['replacement_serial_number']));
                if ($replacementNumber === '') {
                    throw ValidationException::withMessages([
                        'replacement_serial_number' => 'Enter the replacement serial number.',
                    ]);
                }

                $replacementSerial = $this->stock->lockSerialByNumber($replacementNumber);
                $replacementSerial = InventorySerial::query()->lockForUpdate()->findOrFail($replacementSerial->id);

                $this->assertReplacementSerialEligible($lockedCase, $returnedSerial, $replacementSerial);

                $verifiedUnit = $this->equivalence->verifiedUnitValueForReturnedSerial($lockedCase, $returnedSerial);
                if ($verifiedUnit === null) {
                    throw ValidationException::withMessages([
                        'replacement' => 'Product or unit value could not be verified for this return. Finance review is required.',
                    ]);
                }

                if (! $this->equivalence->productsMatch($returnedSerial, $replacementSerial)) {
                    throw ValidationException::withMessages([
                        'replacement_serial_number' => 'Replacement serial must match the returned product and variant.',
                    ]);
                }

                $tax = $this->taxOutcome->verify($lockedCase);
                if (! $tax->verified) {
                    throw ValidationException::withMessages([
                        'tax_evidence' => $tax->blockerMessage ?? 'Recorded tax evidence is required before serial replacement.',
                    ]);
                }

                $fulfilmentSerialId = null;
                if ($lockedCase->hardware_fulfilment_id !== null) {
                    $fulfilmentSerialId = HardwareFulfilmentSerial::query()
                        ->where('hardware_fulfilment_id', $lockedCase->hardware_fulfilment_id)
                        ->where('inventory_serial_id', $returnedSerial->id)
                        ->value('id');
                }

                $sale = $this->linkedSale($lockedCase);
                $saleSerialCountBefore = $sale !== null
                    ? InventorySaleSerial::query()->where('sale_id', $sale->id)->count()
                    : 0;

                $branch = $returnedSerial->branch;
                if ($branch === null) {
                    throw ValidationException::withMessages([
                        'replacement_serial_number' => 'Returned serial has no branch.',
                    ]);
                }

                $fromStatus = $replacementSerial->status;
                $this->stock->markSerialSold($replacementSerial, $branch);
                $replacementSerial = $replacementSerial->fresh() ?? $replacementSerial;

                $this->stock->recordMovement(
                    type: InventoryMovementType::ReturnCaseSerialReplace,
                    product: $replacementSerial->product,
                    branch: $branch,
                    qty: -1,
                    actor: $actor,
                    variant: $replacementSerial->variant,
                    serial: $replacementSerial,
                    sale: $sale,
                    fromStatus: $fromStatus,
                    toStatus: InventorySerialStatus::Sold,
                    notes: 'Return case serial replacement '.$lockedCase->case_no,
                    returnExchangeCase: $lockedCase,
                );

                $returnedAfter = InventorySerial::query()->findOrFail($returnedSerial->id);
                if (! in_array($returnedAfter->status, [InventorySerialStatus::Returned, InventorySerialStatus::Damaged], true)) {
                    throw ValidationException::withMessages([
                        'replacement' => 'Returned serial status changed unexpectedly.',
                    ]);
                }

                if ($sale !== null) {
                    $saleSerialCountAfter = InventorySaleSerial::query()->where('sale_id', $sale->id)->count();
                    if ($saleSerialCountAfter !== $saleSerialCountBefore) {
                        throw ValidationException::withMessages([
                            'replacement' => 'Original sale serial assignment must not be modified.',
                        ]);
                    }
                }

                $record = ReturnExchangeCaseSerialReplacement::query()->create([
                    'return_exchange_case_id' => $lockedCase->id,
                    'return_exchange_case_receipt_id' => $receipt->id,
                    'returned_inventory_serial_id' => $returnedSerial->id,
                    'replacement_inventory_serial_id' => $replacementSerial->id,
                    'inventory_sale_id' => $sale?->id,
                    'hardware_fulfilment_serial_id' => $fulfilmentSerialId,
                    'verified_unit_value' => $verifiedUnit,
                    'idempotency_key' => $idempotencyKey,
                    'performed_by' => $actor->id,
                    'performed_at' => now(),
                ]);

                return $record->load(['returnedSerial', 'replacementSerial']);
            });
        } catch (UniqueConstraintViolationException) {
            if ($idempotencyKey !== null) {
                return ReturnExchangeCaseSerialReplacement::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->firstOrFail();
            }

            throw ValidationException::withMessages([
                'replacement' => 'This serial replacement was already recorded.',
            ]);
        }
    }

    private function assertCaseEligible(ReturnExchangeCase $case): void
    {
        if ($case->status !== ReturnExchangeCaseStatus::Inspected) {
            throw ValidationException::withMessages([
                'case' => 'Goods must be received and inspected before serial replacement.',
            ]);
        }

        if ($case->refund_request_id !== null || $case->posRefundRequest !== null) {
            throw ValidationException::withMessages([
                'replacement' => 'Serial replacement cannot be recorded when a refund request exists on this case.',
            ]);
        }

        if ($case->hardware_fulfilment_id !== null && $case->inventory_sale_id === null) {
            throw ValidationException::withMessages([
                'replacement' => self::ONLINE_HELD_MESSAGE,
            ]);
        }

        if ($case->inventory_sale_id === null) {
            throw ValidationException::withMessages([
                'replacement' => 'Serial replacement is only supported for POS-linked cases in this release.',
            ]);
        }
    }

    private function assertReceiptEligible(ReturnExchangeCaseReceipt $receipt): void
    {
        if ($receipt->inspected_at === null) {
            throw ValidationException::withMessages([
                'return_exchange_case_receipt_id' => 'Receipt must be inspected before replacement.',
            ]);
        }

        if ($receipt->repair_hold) {
            throw ValidationException::withMessages([
                'return_exchange_case_receipt_id' => 'Repair hold must be cleared before serial replacement.',
            ]);
        }

        if ($receipt->inventory_serial_id === null) {
            throw ValidationException::withMessages([
                'return_exchange_case_receipt_id' => 'Receipt must be linked to an inventory serial.',
            ]);
        }
    }

    private function assertReturnedSerialEligible(ReturnExchangeCase $case, InventorySerial $serial): void
    {
        if (! in_array($serial->status, [InventorySerialStatus::Returned, InventorySerialStatus::Damaged], true)) {
            throw ValidationException::withMessages([
                'replacement' => 'Returned serial must remain in returned or damaged quarantine.',
            ]);
        }

        if ($case->branch_id !== null && (int) $serial->branch_id !== (int) $case->branch_id) {
            throw ValidationException::withMessages([
                'replacement' => 'Returned serial is not at the case branch.',
            ]);
        }

        $this->assertSerialNotInOtherOpenCase($serial, $case);
    }

    private function assertReplacementSerialEligible(
        ReturnExchangeCase $case,
        InventorySerial $returned,
        InventorySerial $replacement,
    ): void {
        if ((int) $replacement->id === (int) $returned->id) {
            throw ValidationException::withMessages([
                'replacement_serial_number' => 'Replacement serial must differ from the returned unit.',
            ]);
        }

        if ($replacement->status !== InventorySerialStatus::Available) {
            throw ValidationException::withMessages([
                'replacement_serial_number' => 'Replacement serial must be available and unreserved.',
            ]);
        }

        if ($replacement->reserved_reservation_id !== null) {
            throw ValidationException::withMessages([
                'replacement_serial_number' => 'Replacement serial is reserved.',
            ]);
        }

        if ($case->branch_id !== null && (int) $replacement->branch_id !== (int) $case->branch_id) {
            throw ValidationException::withMessages([
                'replacement_serial_number' => 'Replacement serial is not at the case branch.',
            ]);
        }

        $conflictReceipt = ReturnExchangeCaseReceipt::query()
            ->where('inventory_serial_id', $replacement->id)
            ->whereHas('case', function ($query) use ($case): void {
                $query->where('id', '!=', $case->id)
                    ->where('status', '!=', ReturnExchangeCaseStatus::Closed->value);
            })
            ->exists();

        if ($conflictReceipt) {
            throw ValidationException::withMessages([
                'replacement_serial_number' => 'Replacement serial is linked to another open return case.',
            ]);
        }

        if (ReturnExchangeCaseSerialReplacement::query()->where('replacement_inventory_serial_id', $replacement->id)->exists()) {
            throw ValidationException::withMessages([
                'replacement_serial_number' => 'Replacement serial was already issued on another case.',
            ]);
        }
    }

    private function assertSerialNotInOtherOpenCase(InventorySerial $serial, ReturnExchangeCase $case): void
    {
        $conflict = ReturnExchangeCaseReceipt::query()
            ->where('inventory_serial_id', $serial->id)
            ->whereHas('case', function ($query) use ($case): void {
                $query->where('id', '!=', $case->id)
                    ->where('status', '!=', ReturnExchangeCaseStatus::Closed->value);
            })
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'replacement' => 'Returned serial is tied to another open return case.',
            ]);
        }
    }

    private function linkedSale(ReturnExchangeCase $case): ?InventorySale
    {
        if ($case->inventory_sale_id === null) {
            return null;
        }

        return InventorySale::query()->find($case->inventory_sale_id);
    }
}
