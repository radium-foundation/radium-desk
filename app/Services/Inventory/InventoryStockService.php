<?php

namespace App\Services\Inventory;

use App\Enums\InventoryAdjustmentReason;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\InventorySerialCondition;
use App\Enums\InventorySerialStatus;
use App\Enums\InventoryTransferStatus;
use App\Models\InventoryAdjustment;
use App\Models\InventoryBranch;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryProductVariant;
use App\Models\InventoryReservation;
use App\Models\InventorySale;
use App\Models\InventorySerial;
use App\Models\InventoryStockBalance;
use App\Models\InventoryTransfer;
use App\Models\User;
use App\Support\Inventory\InventorySerialNumber;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryStockService
{
    private const LOCK_ATTEMPTS = 5;

    /**
     * @param  list<string>|string  $serials
     * @return list<InventorySerial>
     */
    public function stockInSerialized(
        InventoryProduct $product,
        InventoryBranch $branch,
        array|string $serials,
        User $actor,
        ?InventoryProductVariant $variant = null,
        ?string $batchCode = null,
        ?string $notes = null,
    ): array {
        $this->assertProductSerialized($product);
        $this->assertActive($product, $branch, $variant);

        $numbers = InventorySerialNumber::parseList($serials);
        if ($numbers === []) {
            throw ValidationException::withMessages([
                'serials' => 'Enter at least one serial number.',
            ]);
        }

        if ($product->tracks_batch && ($batchCode === null || trim($batchCode) === '')) {
            throw ValidationException::withMessages([
                'batch_code' => 'Batch/lot is required for this product.',
            ]);
        }

        return DB::transaction(function () use ($product, $branch, $numbers, $actor, $variant, $batchCode, $notes): array {
            $created = [];

            foreach ($numbers as $number) {
                try {
                    $serial = InventorySerial::query()->create([
                        'product_id' => $product->id,
                        'variant_id' => $variant?->id,
                        'serial_number' => $number,
                        'branch_id' => $branch->id,
                        'status' => InventorySerialStatus::Available,
                        'batch_code' => $batchCode !== null ? trim($batchCode) : null,
                    ]);
                } catch (UniqueConstraintViolationException|QueryException $exception) {
                    if (! $exception instanceof UniqueConstraintViolationException
                        && ! str_contains(strtolower($exception->getMessage()), 'unique')) {
                        throw $exception;
                    }

                    throw ValidationException::withMessages([
                        'serials' => "Serial {$number} already exists in inventory.",
                    ]);
                }

                $this->adjustBalance($product, $variant, $branch, availableDelta: 1);
                $this->recordMovement(
                    type: InventoryMovementType::StockIn,
                    product: $product,
                    branch: $branch,
                    qty: 1,
                    actor: $actor,
                    variant: $variant,
                    serial: $serial,
                    toStatus: InventorySerialStatus::Available,
                    notes: $notes,
                );

                $created[] = $serial;
            }

            return $created;
        });
    }

    public function stockInQuantity(
        InventoryProduct $product,
        InventoryBranch $branch,
        int $qty,
        User $actor,
        ?InventoryProductVariant $variant = null,
        ?string $notes = null,
    ): InventoryStockBalance {
        $this->assertProductQuantity($product);
        $this->assertActive($product, $branch, $variant);

        if ($qty < 1) {
            throw ValidationException::withMessages([
                'qty' => 'Quantity must be at least 1.',
            ]);
        }

        return DB::transaction(function () use ($product, $branch, $qty, $actor, $variant, $notes): InventoryStockBalance {
            $balance = $this->adjustBalance($product, $variant, $branch, availableDelta: $qty);
            $this->recordMovement(
                type: InventoryMovementType::StockIn,
                product: $product,
                branch: $branch,
                qty: $qty,
                actor: $actor,
                variant: $variant,
                notes: $notes,
            );

            return $balance;
        });
    }

    public function receiveOpeningSerialized(
        InventoryProduct $product,
        InventoryBranch $branch,
        string $serialNumber,
        User $actor,
        InventorySerialCondition $condition,
        InventorySerialStatus $status,
        ?InventoryProductVariant $variant = null,
        ?string $unitCost = null,
        ?string $notes = null,
        ?DateTimeInterface $occurredAt = null,
        ?int $openingImportBatchId = null,
    ): InventorySerial {
        $this->assertProductSerialized($product);
        $this->assertActive($product, $branch, $variant);

        if (! in_array($status, [InventorySerialStatus::Available, InventorySerialStatus::Damaged], true)) {
            throw ValidationException::withMessages([
                'stock_status' => 'Opening serials may only be Available or Damaged.',
            ]);
        }

        $number = InventorySerialNumber::normalize($serialNumber);
        if ($number === '') {
            throw ValidationException::withMessages([
                'serials' => 'Serial number is required for serialized opening stock.',
            ]);
        }

        return DB::transaction(function () use (
            $product,
            $branch,
            $number,
            $actor,
            $condition,
            $status,
            $variant,
            $unitCost,
            $notes,
            $occurredAt,
            $openingImportBatchId,
        ): InventorySerial {
            try {
                $serial = InventorySerial::query()->create([
                    'product_id' => $product->id,
                    'variant_id' => $variant?->id,
                    'serial_number' => $number,
                    'branch_id' => $branch->id,
                    'status' => $status,
                    'condition' => $condition,
                    'unit_cost' => $unitCost,
                ]);
            } catch (UniqueConstraintViolationException|QueryException $exception) {
                if (! $exception instanceof UniqueConstraintViolationException
                    && ! str_contains(strtolower($exception->getMessage()), 'unique')) {
                    throw $exception;
                }

                throw ValidationException::withMessages([
                    'serials' => "Serial {$number} already exists in inventory.",
                ]);
            }

            if ($status === InventorySerialStatus::Available) {
                $this->adjustBalance($product, $variant, $branch, availableDelta: 1);
            }

            $this->recordMovement(
                type: InventoryMovementType::Opening,
                product: $product,
                branch: $branch,
                qty: 1,
                actor: $actor,
                variant: $variant,
                serial: $serial,
                toStatus: $status,
                notes: $notes,
                occurredAt: $occurredAt,
                openingImportBatchId: $openingImportBatchId,
            );

            return $serial;
        });
    }

    public function receiveOpeningQuantity(
        InventoryProduct $product,
        InventoryBranch $branch,
        int $qty,
        User $actor,
        ?InventoryProductVariant $variant = null,
        ?string $notes = null,
        ?DateTimeInterface $occurredAt = null,
        ?int $openingImportBatchId = null,
    ): InventoryStockBalance {
        $this->assertProductQuantity($product);
        $this->assertActive($product, $branch, $variant);

        if ($qty < 1) {
            throw ValidationException::withMessages([
                'qty' => 'Quantity must be at least 1.',
            ]);
        }

        return DB::transaction(function () use (
            $product,
            $branch,
            $qty,
            $actor,
            $variant,
            $notes,
            $occurredAt,
            $openingImportBatchId,
        ): InventoryStockBalance {
            $balance = $this->adjustBalance($product, $variant, $branch, availableDelta: $qty);
            $this->recordMovement(
                type: InventoryMovementType::Opening,
                product: $product,
                branch: $branch,
                qty: $qty,
                actor: $actor,
                variant: $variant,
                notes: $notes,
                occurredAt: $occurredAt,
                openingImportBatchId: $openingImportBatchId,
            );

            return $balance;
        });
    }

    /**
     * Relocates the same serial row. Does not clone serials across branches.
     *
     * @param  list<string>|string  $serials
     */
    public function transferSerials(
        InventoryBranch $from,
        InventoryBranch $to,
        array|string $serials,
        User $actor,
        ?string $notes = null,
    ): InventoryTransfer {
        if ($from->id === $to->id) {
            throw ValidationException::withMessages([
                'to_branch_id' => 'Source and destination branches must be different.',
            ]);
        }

        $this->assertActive(branch: $from);
        $this->assertActive(branch: $to);

        $numbers = InventorySerialNumber::parseList($serials);
        if ($numbers === []) {
            throw ValidationException::withMessages([
                'serials' => 'Enter at least one serial number to transfer.',
            ]);
        }
        sort($numbers, SORT_STRING);

        return DB::transaction(function () use ($from, $to, $numbers, $actor, $notes): InventoryTransfer {
            $transfer = InventoryTransfer::query()->create([
                'transfer_no' => 'TRF-TMP-'.strtoupper(bin2hex(random_bytes(6))),
                'from_branch_id' => $from->id,
                'to_branch_id' => $to->id,
                'status' => InventoryTransferStatus::Completed,
                'notes' => $notes,
                'created_by' => $actor->id,
                'completed_at' => now(),
            ]);
            $transfer->update(['transfer_no' => sprintf('TRF-%06d', $transfer->id)]);

            foreach ($numbers as $number) {
                $serial = $this->lockSerialByNumber($number);

                if ($serial->branch_id !== $from->id) {
                    throw ValidationException::withMessages([
                        'serials' => "Serial {$number} is not at {$from->code}.",
                    ]);
                }

                if ($serial->status !== InventorySerialStatus::Available) {
                    throw ValidationException::withMessages([
                        'serials' => "Serial {$number} is {$serial->status->label()} and cannot be transferred.",
                    ]);
                }

                $product = $serial->product;
                $variant = $serial->variant;

                $this->adjustBalance($product, $variant, $from, availableDelta: -1);
                $serial->update([
                    'branch_id' => $to->id,
                    'status' => InventorySerialStatus::Available,
                ]);
                $this->adjustBalance($product, $variant, $to, availableDelta: 1);

                $transfer->lines()->create([
                    'product_id' => $product->id,
                    'variant_id' => $variant?->id,
                    'serial_id' => $serial->id,
                    'qty' => 1,
                ]);

                $this->recordMovement(
                    type: InventoryMovementType::TransferOut,
                    product: $product,
                    branch: $from,
                    qty: -1,
                    actor: $actor,
                    variant: $variant,
                    serial: $serial,
                    fromBranch: $from,
                    toBranch: $to,
                    transfer: $transfer,
                    fromStatus: InventorySerialStatus::Available,
                    toStatus: InventorySerialStatus::Available,
                    notes: $notes,
                );
                $this->recordMovement(
                    type: InventoryMovementType::TransferIn,
                    product: $product,
                    branch: $to,
                    qty: 1,
                    actor: $actor,
                    variant: $variant,
                    serial: $serial,
                    fromBranch: $from,
                    toBranch: $to,
                    transfer: $transfer,
                    fromStatus: InventorySerialStatus::Available,
                    toStatus: InventorySerialStatus::Available,
                    notes: $notes,
                );
            }

            return $transfer->fresh(['lines', 'fromBranch', 'toBranch']) ?? $transfer;
        }, self::LOCK_ATTEMPTS);
    }

    public function transferQuantity(
        InventoryProduct $product,
        InventoryBranch $from,
        InventoryBranch $to,
        int $qty,
        User $actor,
        ?InventoryProductVariant $variant = null,
        ?string $notes = null,
    ): InventoryTransfer {
        $this->assertProductQuantity($product);
        $this->assertActive($product, $from, $variant);
        $this->assertActive(branch: $to);

        if ($from->id === $to->id) {
            throw ValidationException::withMessages([
                'to_branch_id' => 'Source and destination branches must be different.',
            ]);
        }

        if ($qty < 1) {
            throw ValidationException::withMessages([
                'qty' => 'Quantity must be at least 1.',
            ]);
        }

        return DB::transaction(function () use ($product, $from, $to, $qty, $actor, $variant, $notes): InventoryTransfer {
            $this->adjustBalance($product, $variant, $from, availableDelta: -$qty);
            $this->adjustBalance($product, $variant, $to, availableDelta: $qty);

            $transfer = InventoryTransfer::query()->create([
                'transfer_no' => 'TRF-TMP-'.strtoupper(bin2hex(random_bytes(6))),
                'from_branch_id' => $from->id,
                'to_branch_id' => $to->id,
                'status' => InventoryTransferStatus::Completed,
                'notes' => $notes,
                'created_by' => $actor->id,
                'completed_at' => now(),
            ]);
            $transfer->update(['transfer_no' => sprintf('TRF-%06d', $transfer->id)]);
            $transfer->lines()->create([
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'serial_id' => null,
                'qty' => $qty,
            ]);

            $this->recordMovement(
                type: InventoryMovementType::TransferOut,
                product: $product,
                branch: $from,
                qty: -$qty,
                actor: $actor,
                variant: $variant,
                fromBranch: $from,
                toBranch: $to,
                transfer: $transfer,
                notes: $notes,
            );
            $this->recordMovement(
                type: InventoryMovementType::TransferIn,
                product: $product,
                branch: $to,
                qty: $qty,
                actor: $actor,
                variant: $variant,
                fromBranch: $from,
                toBranch: $to,
                transfer: $transfer,
                notes: $notes,
            );

            return $transfer->fresh(['lines']) ?? $transfer;
        }, self::LOCK_ATTEMPTS);
    }

    /**
     * @param  list<string>|string  $serials
     */
    public function reserveSerials(
        InventoryBranch $branch,
        array|string $serials,
        User $actor,
        ?string $notes = null,
    ): InventoryReservation {
        $numbers = InventorySerialNumber::parseList($serials);
        if ($numbers === []) {
            throw ValidationException::withMessages([
                'serials' => 'Enter at least one serial number to reserve.',
            ]);
        }
        sort($numbers, SORT_STRING);

        return DB::transaction(function () use ($branch, $numbers, $actor, $notes): InventoryReservation {
            $reservation = InventoryReservation::query()->create([
                'reservation_no' => 'RSV-TMP-'.strtoupper(bin2hex(random_bytes(6))),
                'branch_id' => $branch->id,
                'status' => InventoryReservationStatus::Active,
                'notes' => $notes,
                'created_by' => $actor->id,
            ]);
            $reservation->update(['reservation_no' => sprintf('RSV-%06d', $reservation->id)]);

            foreach ($numbers as $number) {
                $serial = $this->lockSerialByNumber($number);
                $this->assertSerialAvailableAt($serial, $branch, $number);

                $serial->update([
                    'status' => InventorySerialStatus::Reserved,
                    'reserved_reservation_id' => $reservation->id,
                ]);
                $this->adjustBalance($serial->product, $serial->variant, $branch, availableDelta: -1, reservedDelta: 1);

                $reservation->lines()->create([
                    'product_id' => $serial->product_id,
                    'variant_id' => $serial->variant_id,
                    'serial_id' => $serial->id,
                    'qty' => 1,
                ]);

                $this->recordMovement(
                    type: InventoryMovementType::Reserve,
                    product: $serial->product,
                    branch: $branch,
                    qty: 0,
                    actor: $actor,
                    variant: $serial->variant,
                    serial: $serial,
                    reservation: $reservation,
                    fromStatus: InventorySerialStatus::Available,
                    toStatus: InventorySerialStatus::Reserved,
                    notes: $notes,
                );
            }

            return $reservation->fresh(['lines']) ?? $reservation;
        }, self::LOCK_ATTEMPTS);
    }

    public function releaseReservation(InventoryReservation $reservation, User $actor, ?string $notes = null): InventoryReservation
    {
        return DB::transaction(function () use ($reservation, $actor, $notes): InventoryReservation {
            $reservation = InventoryReservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if ($reservation->status !== InventoryReservationStatus::Active) {
                throw ValidationException::withMessages([
                    'reservation' => 'Only an active reservation can be released.',
                ]);
            }

            $reservation->load('lines.serial.product', 'lines.serial.variant', 'branch');

            foreach ($reservation->lines as $line) {
                $serial = $line->serial;
                if ($serial === null) {
                    continue;
                }

                $serial = $this->lockSerialById($serial->id);
                if ($serial->status !== InventorySerialStatus::Reserved) {
                    continue;
                }

                $serial->update([
                    'status' => InventorySerialStatus::Available,
                    'reserved_reservation_id' => null,
                ]);
                $this->adjustBalance($serial->product, $serial->variant, $reservation->branch, availableDelta: 1, reservedDelta: -1);

                $this->recordMovement(
                    type: InventoryMovementType::Unreserve,
                    product: $serial->product,
                    branch: $reservation->branch,
                    qty: 0,
                    actor: $actor,
                    variant: $serial->variant,
                    serial: $serial,
                    reservation: $reservation,
                    fromStatus: InventorySerialStatus::Reserved,
                    toStatus: InventorySerialStatus::Available,
                    notes: $notes,
                );
            }

            $reservation->update([
                'status' => InventoryReservationStatus::Released,
                'released_at' => now(),
            ]);

            return $reservation->fresh(['lines']) ?? $reservation;
        });
    }

    public function consumeReservation(InventoryReservation $reservation): void
    {
        $reservation->update([
            'status' => InventoryReservationStatus::Consumed,
            'consumed_at' => now(),
        ]);
    }

    public function adjustSerialStatus(
        InventorySerial $serial,
        InventorySerialStatus $toStatus,
        InventoryAdjustmentReason $reason,
        User $actor,
        ?string $notes = null,
    ): InventoryAdjustment {
        if (in_array($toStatus, [InventorySerialStatus::Sold, InventorySerialStatus::Reserved, InventorySerialStatus::InTransit], true)) {
            throw ValidationException::withMessages([
                'status' => 'Sold, reserved, and in-transit statuses cannot be set by adjustment. Use POS, reservation, or transfer.',
            ]);
        }

        return DB::transaction(function () use ($serial, $toStatus, $reason, $actor, $notes): InventoryAdjustment {
            $serial = $this->lockSerialById($serial->id);
            $fromStatus = $serial->status;

            if ($fromStatus === $toStatus) {
                throw ValidationException::withMessages([
                    'status' => 'Serial is already in that status.',
                ]);
            }

            $adjustment = InventoryAdjustment::query()->create([
                'adjustment_no' => 'ADJ-TMP-'.strtoupper(bin2hex(random_bytes(6))),
                'branch_id' => $serial->branch_id,
                'reason' => $reason,
                'notes' => $notes,
                'created_by' => $actor->id,
            ]);
            $adjustment->update(['adjustment_no' => sprintf('ADJ-%06d', $adjustment->id)]);

            $availableDelta = 0;
            $reservedDelta = 0;

            if ($fromStatus === InventorySerialStatus::Available) {
                $availableDelta -= 1;
            }
            if ($fromStatus === InventorySerialStatus::Reserved) {
                $reservedDelta -= 1;
            }
            if ($toStatus === InventorySerialStatus::Available) {
                $availableDelta += 1;
            }
            if ($toStatus === InventorySerialStatus::Reserved) {
                $reservedDelta += 1;
            }

            $serial->update([
                'status' => $toStatus,
                'reserved_reservation_id' => $toStatus === InventorySerialStatus::Reserved
                    ? $serial->reserved_reservation_id
                    : null,
            ]);

            $this->adjustBalance($serial->product, $serial->variant, $serial->branch, $availableDelta, $reservedDelta);

            $adjustment->lines()->create([
                'product_id' => $serial->product_id,
                'variant_id' => $serial->variant_id,
                'serial_id' => $serial->id,
                'qty_delta' => $availableDelta,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
            ]);

            $this->recordMovement(
                type: InventoryMovementType::Adjustment,
                product: $serial->product,
                branch: $serial->branch,
                qty: $availableDelta,
                actor: $actor,
                variant: $serial->variant,
                serial: $serial,
                adjustment: $adjustment,
                fromStatus: $fromStatus,
                toStatus: $toStatus,
                notes: $notes,
            );

            return $adjustment->fresh(['lines']) ?? $adjustment;
        });
    }

    public function adjustQuantity(
        InventoryProduct $product,
        InventoryBranch $branch,
        int $qtyDelta,
        InventoryAdjustmentReason $reason,
        User $actor,
        ?InventoryProductVariant $variant = null,
        ?string $notes = null,
    ): InventoryAdjustment {
        $this->assertProductQuantity($product);
        $this->assertActive($product, $branch, $variant);

        if ($qtyDelta === 0) {
            throw ValidationException::withMessages([
                'qty_delta' => 'Adjustment quantity cannot be zero.',
            ]);
        }

        return DB::transaction(function () use ($product, $branch, $qtyDelta, $reason, $actor, $variant, $notes): InventoryAdjustment {
            $this->adjustBalance($product, $variant, $branch, availableDelta: $qtyDelta);

            $adjustment = InventoryAdjustment::query()->create([
                'adjustment_no' => 'ADJ-TMP-'.strtoupper(bin2hex(random_bytes(6))),
                'branch_id' => $branch->id,
                'reason' => $reason,
                'notes' => $notes,
                'created_by' => $actor->id,
            ]);
            $adjustment->update(['adjustment_no' => sprintf('ADJ-%06d', $adjustment->id)]);
            $adjustment->lines()->create([
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'serial_id' => null,
                'qty_delta' => $qtyDelta,
            ]);

            $this->recordMovement(
                type: InventoryMovementType::Adjustment,
                product: $product,
                branch: $branch,
                qty: $qtyDelta,
                actor: $actor,
                variant: $variant,
                adjustment: $adjustment,
                notes: $notes,
            );

            return $adjustment->fresh(['lines']) ?? $adjustment;
        });
    }

    /**
     * @param  list<string>  $serialNumbers
     * @return list<InventorySerial>
     */
    public function lockAvailableSerialsForSale(
        InventoryProduct $product,
        InventoryBranch $branch,
        array $serialNumbers,
        ?InventoryProductVariant $variant = null,
        ?int $reservationId = null,
    ): array {
        $this->assertProductSerialized($product);
        $locked = [];
        $ordered = $serialNumbers;
        sort($ordered, SORT_STRING);

        foreach ($ordered as $number) {
            $serial = $this->lockSerialByNumber($number);

            if ($serial->product_id !== $product->id) {
                throw ValidationException::withMessages([
                    'serials' => "Serial {$number} belongs to a different product.",
                ]);
            }

            if ((int) ($serial->variant_id ?? 0) !== (int) ($variant?->id ?? 0)) {
                throw ValidationException::withMessages([
                    'serials' => "Serial {$number} belongs to a different variant.",
                ]);
            }

            $this->assertSerialAvailableAt($serial, $branch, $number, $reservationId);
            $locked[] = $serial;
        }

        return $locked;
    }

    public function markSerialSold(InventorySerial $serial, InventoryBranch $branch): void
    {
        $from = $serial->status;
        $availableDelta = $from === InventorySerialStatus::Available ? -1 : 0;
        $reservedDelta = $from === InventorySerialStatus::Reserved ? -1 : 0;

        $serial->update([
            'status' => InventorySerialStatus::Sold,
            'reserved_reservation_id' => null,
        ]);

        $this->adjustBalance($serial->product, $serial->variant, $branch, $availableDelta, $reservedDelta);
    }

    public function restoreSerialFromSale(InventorySerial $serial, InventoryBranch $branch): void
    {
        $serial->update([
            'status' => InventorySerialStatus::Available,
            'reserved_reservation_id' => null,
        ]);
        $this->adjustBalance($serial->product, $serial->variant, $branch, availableDelta: 1);
    }

    public function deductQuantity(
        InventoryProduct $product,
        InventoryBranch $branch,
        int $qty,
        ?InventoryProductVariant $variant = null,
    ): void {
        $this->assertProductQuantity($product);
        $this->adjustBalance($product, $variant, $branch, availableDelta: -$qty);
    }

    public function restoreQuantity(
        InventoryProduct $product,
        InventoryBranch $branch,
        int $qty,
        ?InventoryProductVariant $variant = null,
    ): void {
        $this->assertProductQuantity($product);
        $this->adjustBalance($product, $variant, $branch, availableDelta: $qty);
    }

    public function recordMovement(
        InventoryMovementType $type,
        InventoryProduct $product,
        InventoryBranch $branch,
        int $qty,
        User $actor,
        ?InventoryProductVariant $variant = null,
        ?InventorySerial $serial = null,
        ?InventoryBranch $fromBranch = null,
        ?InventoryBranch $toBranch = null,
        ?InventorySale $sale = null,
        ?InventoryTransfer $transfer = null,
        ?InventoryReservation $reservation = null,
        ?InventoryAdjustment $adjustment = null,
        ?InventorySerialStatus $fromStatus = null,
        ?InventorySerialStatus $toStatus = null,
        ?string $notes = null,
        ?DateTimeInterface $occurredAt = null,
        ?int $openingImportBatchId = null,
    ): InventoryMovement {
        return InventoryMovement::query()->create([
            'occurred_at' => $occurredAt ?? now(),
            'type' => $type,
            'product_id' => $product->id,
            'variant_id' => $variant?->id ?? $serial?->variant_id,
            'serial_id' => $serial?->id,
            'branch_id' => $branch->id,
            'from_branch_id' => $fromBranch?->id,
            'to_branch_id' => $toBranch?->id,
            'qty' => $qty,
            'sale_id' => $sale?->id,
            'transfer_id' => $transfer?->id,
            'reservation_id' => $reservation?->id,
            'adjustment_id' => $adjustment?->id,
            'opening_import_batch_id' => $openingImportBatchId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'notes' => $notes,
            'actor_user_id' => $actor->id,
        ]);
    }

    public function lockSerialByNumber(string $serialNumber): InventorySerial
    {
        $number = InventorySerialNumber::normalize($serialNumber);
        $serial = InventorySerial::query()
            ->where('serial_number', $number)
            ->lockForUpdate()
            ->first();

        if ($serial === null) {
            throw ValidationException::withMessages([
                'serials' => "Serial {$number} was not found in inventory.",
            ]);
        }

        $serial->load(['product', 'variant', 'branch']);

        return $serial;
    }

    public function lockSerialById(int $id): InventorySerial
    {
        $serial = InventorySerial::query()->lockForUpdate()->find($id);
        if ($serial === null) {
            throw ValidationException::withMessages([
                'serials' => 'Serial was not found in inventory.',
            ]);
        }

        $serial->load(['product', 'variant', 'branch']);

        return $serial;
    }

    private function assertSerialAvailableAt(
        InventorySerial $serial,
        InventoryBranch $branch,
        string $number,
        ?int $reservationId = null,
    ): void {
        if ($serial->branch_id !== $branch->id) {
            throw ValidationException::withMessages([
                'serials' => "Serial {$number} is not available at {$branch->code}.",
            ]);
        }

        if ($serial->status === InventorySerialStatus::Available) {
            return;
        }

        if (
            $serial->status === InventorySerialStatus::Reserved
            && $reservationId !== null
            && (int) $serial->reserved_reservation_id === $reservationId
        ) {
            return;
        }

        throw ValidationException::withMessages([
            'serials' => "Serial {$number} is {$serial->status->label()} and cannot be assigned.",
        ]);
    }

    private function adjustBalance(
        InventoryProduct $product,
        ?InventoryProductVariant $variant,
        InventoryBranch $branch,
        int $availableDelta = 0,
        int $reservedDelta = 0,
    ): InventoryStockBalance {
        $key = InventoryStockBalance::keyFor($product->id, $variant?->id, $branch->id);

        $balance = InventoryStockBalance::query()
            ->where('balance_key', $key)
            ->lockForUpdate()
            ->first();

        if ($balance === null) {
            $balance = InventoryStockBalance::query()->create([
                'balance_key' => $key,
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'branch_id' => $branch->id,
                'available_qty' => 0,
                'reserved_qty' => 0,
            ]);
            $balance = InventoryStockBalance::query()
                ->where('id', $balance->id)
                ->lockForUpdate()
                ->firstOrFail();
        }

        $available = $balance->available_qty + $availableDelta;
        $reserved = $balance->reserved_qty + $reservedDelta;

        if ($available < 0 || $reserved < 0) {
            throw ValidationException::withMessages([
                'qty' => "Insufficient stock for {$product->sku} at {$branch->code}.",
            ]);
        }

        $balance->update([
            'available_qty' => $available,
            'reserved_qty' => $reserved,
        ]);

        return $balance;
    }

    private function assertProductSerialized(InventoryProduct $product): void
    {
        if (! $product->is_serialized) {
            throw ValidationException::withMessages([
                'product_id' => "{$product->sku} is quantity-tracked, not serialised.",
            ]);
        }
    }

    private function assertProductQuantity(InventoryProduct $product): void
    {
        if ($product->is_serialized) {
            throw ValidationException::withMessages([
                'product_id' => "{$product->sku} is serialised. Use serial stock-in.",
            ]);
        }
    }

    private function assertActive(
        ?InventoryProduct $product = null,
        ?InventoryBranch $branch = null,
        ?InventoryProductVariant $variant = null,
    ): void {
        if ($product !== null && ! $product->is_active) {
            throw ValidationException::withMessages([
                'product_id' => 'Product is inactive.',
            ]);
        }

        if ($branch !== null && ! $branch->is_active) {
            throw ValidationException::withMessages([
                'branch_id' => 'Branch is inactive.',
            ]);
        }

        if ($variant !== null && ! $variant->is_active) {
            throw ValidationException::withMessages([
                'variant_id' => 'Variant is inactive.',
            ]);
        }

        if ($variant !== null && $product !== null && $variant->product_id !== $product->id) {
            throw ValidationException::withMessages([
                'variant_id' => 'Variant does not belong to this product.',
            ]);
        }
    }
}
