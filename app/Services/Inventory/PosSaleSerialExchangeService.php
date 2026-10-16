<?php

namespace App\Services\Inventory;

use App\Enums\InventoryMovementType;
use App\Enums\InventorySaleStatus;
use App\Enums\InventorySerialStatus;
use App\Models\InventoryPosSerialExchange;
use App\Models\InventorySale;
use App\Models\InventorySaleSerial;
use App\Models\InventorySerial;
use App\Models\User;
use App\Support\Inventory\InventorySerialNumber;
use App\Support\Inventory\PosSaleStatutoryReversalGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PosSaleSerialExchangeService
{
    private const ATTEMPTS = 5;

    public function __construct(
        private readonly InventoryStockService $stock,
    ) {}

    public function exchange(
        InventorySale $sale,
        string $fromSerialNumber,
        string $toSerialNumber,
        User $actor,
        string $reason,
        string $idempotencyKey,
    ): InventoryPosSerialExchange {
        $fromSerialNumber = InventorySerialNumber::normalize($fromSerialNumber);
        $toSerialNumber = InventorySerialNumber::normalize($toSerialNumber);
        $reason = trim($reason);
        $idempotencyKey = trim($idempotencyKey);

        if ($fromSerialNumber === '' || $toSerialNumber === '') {
            throw ValidationException::withMessages([
                'from_serial' => 'Both the current and replacement serial numbers are required.',
            ]);
        }

        if ($fromSerialNumber === $toSerialNumber) {
            throw ValidationException::withMessages([
                'to_serial' => 'Replacement serial must differ from the serial on the sale.',
            ]);
        }

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required.',
            ]);
        }

        if ($idempotencyKey === '') {
            throw ValidationException::withMessages([
                'idempotency_key' => 'An idempotency key is required.',
            ]);
        }

        $existing = InventoryPosSerialExchange::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing !== null) {
            $this->assertIdempotentReplay($existing, $sale->id, $fromSerialNumber, $toSerialNumber);

            return $existing->load(['fromSerial', 'toSerial', 'actor']);
        }

        try {
            return DB::transaction(function () use (
                $sale,
                $fromSerialNumber,
                $toSerialNumber,
                $actor,
                $reason,
                $idempotencyKey,
            ): InventoryPosSerialExchange {
                $sale = InventorySale::query()->lockForUpdate()->findOrFail($sale->id);

                if ($sale->status !== InventorySaleStatus::Completed) {
                    throw ValidationException::withMessages([
                        'sale' => 'Only a completed sale can have serials exchanged.',
                    ]);
                }

                PosSaleStatutoryReversalGuard::assertSerialExchangeAllowed($sale);

                $sale->load(['lines.product', 'lines.variant', 'serials.serial', 'branch']);
                $branch = $sale->branch;
                if ($branch === null) {
                    throw ValidationException::withMessages([
                        'sale' => 'Sale branch could not be resolved.',
                    ]);
                }

                $assignment = $this->findSaleAssignment($sale, $fromSerialNumber);
                if ($assignment === null) {
                    throw ValidationException::withMessages([
                        'from_serial' => 'That serial is not assigned to this sale.',
                    ]);
                }

                $line = $sale->lines->firstWhere('id', $assignment->sale_line_id);
                if ($line === null || ! $line->product?->is_serialized) {
                    throw ValidationException::withMessages([
                        'from_serial' => 'Serial exchange applies only to serialized product lines.',
                    ]);
                }

                $lockOrder = [$fromSerialNumber, $toSerialNumber];
                sort($lockOrder, SORT_STRING);
                foreach ($lockOrder as $number) {
                    $this->stock->lockSerialByNumber($number);
                }

                $fromSerial = $this->stock->lockSerialById($assignment->serial_id);
                $toSerial = $this->stock->lockSerialByNumber($toSerialNumber);

                if (InventorySerialNumber::normalize($fromSerial->serial_number) !== $fromSerialNumber) {
                    throw ValidationException::withMessages([
                        'from_serial' => 'The sale serial record no longer matches the requested serial.',
                    ]);
                }

                if ($fromSerial->status !== InventorySerialStatus::Sold) {
                    throw ValidationException::withMessages([
                        'from_serial' => "Serial {$fromSerial->serial_number} is not sold on this sale.",
                    ]);
                }

                if ($toSerial->product_id !== $fromSerial->product_id) {
                    throw ValidationException::withMessages([
                        'to_serial' => 'Replacement serial must be the same product as the serial on the sale.',
                    ]);
                }

                if ((int) ($toSerial->variant_id ?? 0) !== (int) ($fromSerial->variant_id ?? 0)) {
                    throw ValidationException::withMessages([
                        'to_serial' => 'Replacement serial must be the same variant as the serial on the sale.',
                    ]);
                }

                $alreadyOnSale = $sale->serials->contains(
                    fn (InventorySaleSerial $row): bool => (int) $row->serial_id === (int) $toSerial->id,
                );
                if ($alreadyOnSale) {
                    throw ValidationException::withMessages([
                        'to_serial' => 'Replacement serial is already assigned to this sale.',
                    ]);
                }

                $this->stock->lockAvailableSerialsForSale(
                    $line->product,
                    $branch,
                    [$toSerialNumber],
                    $line->variant,
                );

                $toSerial = $this->stock->lockSerialByNumber($toSerialNumber);

                $exchange = InventoryPosSerialExchange::query()->create([
                    'idempotency_key' => $idempotencyKey,
                    'sale_id' => $sale->id,
                    'sale_serial_id' => $assignment->id,
                    'from_serial_id' => $fromSerial->id,
                    'to_serial_id' => $toSerial->id,
                    'reason' => $reason,
                    'actor_user_id' => $actor->id,
                ]);

                $movementNotes = $this->movementNotes($exchange, $fromSerial, $toSerial, $reason);

                $this->stock->restoreSerialFromSale($fromSerial, $branch);
                $this->stock->recordMovement(
                    type: InventoryMovementType::SerialExchange,
                    product: $fromSerial->product,
                    branch: $branch,
                    qty: 1,
                    actor: $actor,
                    variant: $fromSerial->variant,
                    serial: $fromSerial,
                    sale: $sale,
                    fromStatus: InventorySerialStatus::Sold,
                    toStatus: InventorySerialStatus::Available,
                    notes: $movementNotes.' · released from sale',
                );

                $replacementFromStatus = $toSerial->status;
                $this->stock->markSerialSold($toSerial, $branch);
                $this->stock->recordMovement(
                    type: InventoryMovementType::SerialExchange,
                    product: $toSerial->product,
                    branch: $branch,
                    qty: -1,
                    actor: $actor,
                    variant: $toSerial->variant,
                    serial: $toSerial,
                    sale: $sale,
                    fromStatus: $replacementFromStatus,
                    toStatus: InventorySerialStatus::Sold,
                    notes: $movementNotes.' · assigned to sale',
                );

                $assignment->update(['serial_id' => $toSerial->id]);

                return $exchange->load(['fromSerial', 'toSerial', 'actor']);
            }, self::ATTEMPTS);
        } catch (UniqueConstraintViolationException) {
            $existing = InventoryPosSerialExchange::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing === null) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'This exchange was submitted concurrently. Retry with the same idempotency key.',
                ]);
            }

            $this->assertIdempotentReplay($existing, $sale->id, $fromSerialNumber, $toSerialNumber);

            return $existing->load(['fromSerial', 'toSerial', 'actor']);
        }
    }

    private function findSaleAssignment(InventorySale $sale, string $fromSerialNumber): ?InventorySaleSerial
    {
        foreach ($sale->serials as $assignment) {
            $number = InventorySerialNumber::normalize((string) ($assignment->serial?->serial_number ?? ''));
            if ($number === $fromSerialNumber) {
                return $assignment;
            }
        }

        return null;
    }

    private function assertIdempotentReplay(
        InventoryPosSerialExchange $existing,
        int $saleId,
        string $fromSerialNumber,
        string $toSerialNumber,
    ): void {
        if ((int) $existing->sale_id !== $saleId) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'This idempotency key was already used for a different sale.',
            ]);
        }

        $existing->loadMissing(['fromSerial', 'toSerial']);
        $existingFrom = InventorySerialNumber::normalize((string) ($existing->fromSerial?->serial_number ?? ''));
        $existingTo = InventorySerialNumber::normalize((string) ($existing->toSerial?->serial_number ?? ''));
        if ($existingFrom !== $fromSerialNumber || $existingTo !== $toSerialNumber) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'This idempotency key was already used with different serial numbers.',
            ]);
        }
    }

    private function movementNotes(
        InventoryPosSerialExchange $exchange,
        InventorySerial $fromSerial,
        InventorySerial $toSerial,
        string $reason,
    ): string {
        return sprintf(
            'POS serial exchange #%d: %s → %s. %s',
            $exchange->id,
            $fromSerial->serial_number,
            $toSerial->serial_number,
            $reason,
        );
    }
}
