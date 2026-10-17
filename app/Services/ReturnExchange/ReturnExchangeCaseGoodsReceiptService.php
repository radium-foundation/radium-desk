<?php

namespace App\Services\ReturnExchange;

use App\Enums\InventoryMovementType;
use App\Enums\InventorySerialStatus;
use App\Enums\ReturnExchangeCaseStatus;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventorySale;
use App\Models\InventorySerial;
use App\Models\ReturnExchangeCase;
use App\Models\ReturnExchangeCaseLine;
use App\Models\ReturnExchangeCaseReceipt;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Support\Inventory\InventorySerialNumber;
use App\Support\ReturnExchange\ReturnExchangeCaseBranchAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReturnExchangeCaseGoodsReceiptService
{
    public function __construct(
        private readonly InventoryStockService $stock,
    ) {}

    /**
     * @param  array{
     *     return_exchange_case_line_id: int,
     *     serial_number_scanned?: string|null,
     *     idempotency_key?: string|null,
     *     qty_received?: int,
     * }  $payload
     */
    public function receive(ReturnExchangeCase $case, User $actor, array $payload): ReturnExchangeCaseReceipt
    {
        ReturnExchangeCaseBranchAccess::assertCanOperateOnCase($actor, $case);

        $idempotencyKey = isset($payload['idempotency_key']) && $payload['idempotency_key'] !== ''
            ? (string) $payload['idempotency_key']
            : null;

        if ($idempotencyKey !== null) {
            $existing = ReturnExchangeCaseReceipt::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                return $existing->load(['line', 'serial']);
            }
        }

        $lineId = (int) $payload['return_exchange_case_line_id'];
        $line = $case->lines()->whereKey($lineId)->first();
        if ($line === null) {
            throw ValidationException::withMessages([
                'return_exchange_case_line_id' => 'Line does not belong to this case.',
            ]);
        }

        if ($case->status === ReturnExchangeCaseStatus::Closed) {
            throw ValidationException::withMessages([
                'case' => 'This case is closed.',
            ]);
        }

        $serialNumber = isset($payload['serial_number_scanned'])
            ? trim((string) $payload['serial_number_scanned'])
            : null;

        try {
            return DB::transaction(function () use ($case, $actor, $line, $payload, $idempotencyKey, $serialNumber): ReturnExchangeCaseReceipt {
                $lockedCase = ReturnExchangeCase::query()->lockForUpdate()->findOrFail($case->id);

                if ($idempotencyKey !== null) {
                    $dup = ReturnExchangeCaseReceipt::query()
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();
                    if ($dup !== null) {
                        return $dup;
                    }
                }

                $existingLineReceipt = ReturnExchangeCaseReceipt::query()
                    ->where('return_exchange_case_id', $lockedCase->id)
                    ->where('return_exchange_case_line_id', $line->id)
                    ->lockForUpdate()
                    ->first();
                if ($existingLineReceipt !== null) {
                    return $existingLineReceipt;
                }

                $qty = max(1, (int) ($payload['qty_received'] ?? $line->qty));
                $inventorySerial = null;

                if ($serialNumber !== null && $serialNumber !== '') {
                    $inventorySerial = $this->receiveSerialForCase(
                        $lockedCase,
                        $line,
                        $serialNumber,
                        $actor,
                    );
                } elseif ($lockedCase->inventory_sale_id !== null && $line->serial_number !== null) {
                    $inventorySerial = $this->receiveSerializedUnit(
                        $lockedCase,
                        $line,
                        (string) $line->serial_number,
                        $actor,
                    );
                }

                $receipt = ReturnExchangeCaseReceipt::query()->create([
                    'return_exchange_case_id' => $lockedCase->id,
                    'return_exchange_case_line_id' => $line->id,
                    'idempotency_key' => $idempotencyKey,
                    'inventory_serial_id' => $inventorySerial?->id,
                    'qty_received' => $qty,
                    'serial_number_scanned' => $serialNumber ?? $line->serial_number,
                    'received_at' => now(),
                    'received_by' => $actor->id,
                ]);

                if ($lockedCase->status === ReturnExchangeCaseStatus::Submitted) {
                    $lockedCase->update(['status' => ReturnExchangeCaseStatus::Received]);
                }

                return $receipt->load(['line', 'serial']);
            });
        } catch (UniqueConstraintViolationException) {
            if ($idempotencyKey !== null) {
                return ReturnExchangeCaseReceipt::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->firstOrFail();
            }

            throw ValidationException::withMessages([
                'receipt' => 'This line or serial was already received on this case.',
            ]);
        }
    }

    /**
     * @param  array{
     *     product_match_passed: bool,
     *     device_operates_ok: bool,
     *     accessories_verified: bool,
     *     repair_hold?: bool,
     *     condition_code?: string|null,
     *     mark_damaged?: bool,
     *     internal_notes?: string|null,
     * }  $payload
     */
    public function inspect(
        ReturnExchangeCase $case,
        ReturnExchangeCaseReceipt $receipt,
        User $actor,
        array $payload,
    ): ReturnExchangeCaseReceipt {
        ReturnExchangeCaseBranchAccess::assertCanOperateOnCase($actor, $case);

        if ((int) $receipt->return_exchange_case_id !== (int) $case->id) {
            throw ValidationException::withMessages([
                'receipt' => 'Receipt does not belong to this case.',
            ]);
        }

        if ($receipt->inspected_at !== null) {
            return $receipt;
        }

        return DB::transaction(function () use ($case, $receipt, $actor, $payload): ReturnExchangeCaseReceipt {
            $locked = ReturnExchangeCaseReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            if ($locked->inspected_at !== null) {
                return $locked;
            }

            $markDamaged = (bool) ($payload['mark_damaged'] ?? false);
            $serialStatusAfter = null;

            if ($locked->inventory_serial_id !== null) {
                $serial = InventorySerial::query()->lockForUpdate()->findOrFail($locked->inventory_serial_id);
                $fromStatus = $serial->status;
                $toStatus = $markDamaged ? InventorySerialStatus::Damaged : InventorySerialStatus::Returned;

                if ($fromStatus === InventorySerialStatus::Available) {
                    throw ValidationException::withMessages([
                        'serial' => 'Serial is already sellable; inspection cannot proceed.',
                    ]);
                }

                if (! in_array($fromStatus, [InventorySerialStatus::Sold, InventorySerialStatus::Returned, InventorySerialStatus::Damaged], true)) {
                    throw ValidationException::withMessages([
                        'serial' => 'Serial is not in a receivable state.',
                    ]);
                }

                $serial->update(['status' => $toStatus]);
                $serialStatusAfter = $toStatus->value;

                $branch = $serial->branch;
                if ($branch !== null) {
                    $this->stock->recordMovement(
                        type: InventoryMovementType::ReturnCaseInspect,
                        product: $serial->product,
                        branch: $branch,
                        qty: 0,
                        actor: $actor,
                        variant: $serial->variant,
                        serial: $serial,
                        sale: $this->linkedSale($case),
                        fromStatus: $fromStatus,
                        toStatus: $toStatus,
                        notes: 'Return case inspection '.$case->case_no,
                        returnExchangeCase: $case,
                    );
                }
            }

            $locked->update([
                'product_match_passed' => (bool) $payload['product_match_passed'],
                'device_operates_ok' => (bool) $payload['device_operates_ok'],
                'accessories_verified' => (bool) $payload['accessories_verified'],
                'repair_hold' => (bool) ($payload['repair_hold'] ?? false),
                'condition_code' => $payload['condition_code'] ?? null,
                'serial_status_after_inspection' => $serialStatusAfter,
                'internal_notes' => $payload['internal_notes'] ?? null,
                'inspected_at' => now(),
                'inspected_by' => $actor->id,
            ]);

            $case->refresh();
            if ($case->status !== ReturnExchangeCaseStatus::Closed) {
                $case->update(['status' => ReturnExchangeCaseStatus::Inspected]);
            }

            return $locked->fresh(['line', 'serial']) ?? $locked;
        });
    }

    private function receiveSerialForCase(
        ReturnExchangeCase $case,
        ReturnExchangeCaseLine $line,
        string $serialNumber,
        User $actor,
    ): ?InventorySerial {
        if ($case->hardware_fulfilment_id !== null) {
            return $this->receiveFulfilmentSerial($case, $line, $serialNumber, $actor);
        }

        return $this->receiveSerializedUnit($case, $line, $serialNumber, $actor);
    }

    private function receiveFulfilmentSerial(
        ReturnExchangeCase $case,
        ReturnExchangeCaseLine $line,
        string $serialNumber,
        User $actor,
    ): ?InventorySerial {
        $normalized = InventorySerialNumber::normalize($serialNumber);
        $fulfilmentSerial = HardwareFulfilmentSerial::query()
            ->where('hardware_fulfilment_id', $case->hardware_fulfilment_id)
            ->where(function ($query) use ($normalized): void {
                $query->where('serial_number', $normalized)
                    ->orWhereHas('inventorySerial', fn ($q) => $q->where('serial_number', $normalized));
            })
            ->first();

        if ($fulfilmentSerial === null) {
            throw ValidationException::withMessages([
                'serial_number_scanned' => 'Serial is not allocated on the linked hardware fulfilment.',
            ]);
        }

        if ($fulfilmentSerial->inventory_serial_id === null) {
            return null;
        }

        $serial = InventorySerial::query()->lockForUpdate()->find($fulfilmentSerial->inventory_serial_id);
        if ($serial === null) {
            throw ValidationException::withMessages([
                'serial_number_scanned' => 'Fulfilment serial is not linked to inventory.',
            ]);
        }

        if (InventorySerialNumber::normalize((string) $serial->serial_number) !== $normalized) {
            throw ValidationException::withMessages([
                'serial_number_scanned' => 'Scanned serial does not match the fulfilment allocation.',
            ]);
        }

        return $this->receiveSerializedUnit($case, $line, $serialNumber, $actor, skipPosAssertion: true);
    }

    private function receiveSerializedUnit(
        ReturnExchangeCase $case,
        ReturnExchangeCaseLine $line,
        string $serialNumber,
        User $actor,
        bool $skipPosAssertion = false,
    ): InventorySerial {
        $normalized = InventorySerialNumber::normalize($serialNumber);
        $serial = $this->stock->lockSerialByNumber($normalized);

        $this->assertSerialNotInOtherOpenCase($serial, $case);

        if (! $skipPosAssertion && $case->inventory_sale_id !== null) {
            $this->assertSerialOnPosCase($case, $line, $serial, $normalized);
        }

        if ($case->branch_id !== null && (int) $serial->branch_id !== (int) $case->branch_id) {
            throw ValidationException::withMessages([
                'serial_number_scanned' => 'Serial is not at the case branch.',
            ]);
        }

        if ($serial->status !== InventorySerialStatus::Sold) {
            throw ValidationException::withMessages([
                'serial_number_scanned' => 'Serial must be in sold status to receive from a completed sale.',
            ]);
        }

        $fromStatus = $serial->status;
        $serial->update([
            'status' => InventorySerialStatus::Returned,
            'reserved_reservation_id' => null,
        ]);

        $branch = $serial->branch;
        if ($branch === null) {
            throw ValidationException::withMessages([
                'serial_number_scanned' => 'Serial has no branch.',
            ]);
        }

        $this->stock->recordMovement(
            type: InventoryMovementType::ReturnCaseReceive,
            product: $serial->product,
            branch: $branch,
            qty: 0,
            actor: $actor,
            variant: $serial->variant,
            serial: $serial,
            sale: $this->linkedSale($case),
            fromStatus: $fromStatus,
            toStatus: InventorySerialStatus::Returned,
            notes: 'Return case receipt '.$case->case_no,
            returnExchangeCase: $case,
        );

        return $serial->fresh() ?? $serial;
    }

    private function assertSerialOnPosCase(
        ReturnExchangeCase $case,
        ReturnExchangeCaseLine $line,
        InventorySerial $serial,
        string $normalized,
    ): void {
        $sale = InventorySale::query()
            ->with(['serials.serial'])
            ->find($case->inventory_sale_id);

        if ($sale === null || (int) $sale->id !== (int) $case->inventory_sale_id) {
            throw ValidationException::withMessages([
                'serial_number_scanned' => 'POS sale link is invalid.',
            ]);
        }

        $onSale = $sale->serials->contains(
            fn ($row): bool => (int) $row->serial_id === (int) $serial->id
                || InventorySerialNumber::normalize((string) $row->serial?->serial_number) === $normalized,
        );

        if (! $onSale) {
            throw ValidationException::withMessages([
                'serial_number_scanned' => 'Serial was not assigned on the linked POS sale.',
            ]);
        }

        if ($line->serial_number !== null
            && InventorySerialNumber::normalize((string) $line->serial_number) !== $normalized) {
            throw ValidationException::withMessages([
                'serial_number_scanned' => 'Scanned serial does not match the case line.',
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
                'serial_number_scanned' => 'Serial is already received on another open return case.',
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
