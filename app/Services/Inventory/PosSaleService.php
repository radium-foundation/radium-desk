<?php

namespace App\Services\Inventory;

use App\Enums\InventoryFinanceHandoffStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InventorySaleStatus;
use App\Enums\InventorySerialStatus;
use App\Events\Inventory\InventorySaleCompleted;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\InventoryProduct;
use App\Models\InventoryProductVariant;
use App\Models\InventoryReservation;
use App\Models\InventorySale;
use App\Models\User;
use App\Services\Finance\PosSaleJournalService;
use App\Services\StatutoryInvoice\StatutoryInvoiceAccountingPolicy;
use App\Support\Inventory\InventorySerialNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosSaleService
{
    private const COMPLETION_ATTEMPTS = 5;

    public function __construct(
        private readonly InventoryStockService $stock,
        private readonly PosSaleJournalService $journals,
        private readonly StatutoryInvoiceAccountingPolicy $statutoryAccounting,
    ) {}

    /**
     * Complete a retail sale in one transaction: customer, invoice, stock-out, serial assignment.
     *
     * @param  list<array{
     *     product_id: int,
     *     variant_id?: int|null,
     *     qty: int,
     *     serials?: list<string>|string|null,
     *     unit_price?: float|string|null,
     *     discount?: float|string|null
     * }>  $lines
     */
    public function completeSale(
        InventoryBranch $branch,
        array $customer,
        array $lines,
        string $paymentMethod,
        User $actor,
        float $headerDiscount = 0,
        ?string $paymentReference = null,
        ?string $notes = null,
        ?InventoryReservation $reservation = null,
        ?string $idempotencyKey = null,
    ): InventorySale {
        $this->statutoryAccounting->assertMustNotAutoIssueOnPosComplete();

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'Add at least one product line.',
            ]);
        }

        if (trim($paymentMethod) === '') {
            throw ValidationException::withMessages([
                'payment_method' => 'Select a payment method.',
            ]);
        }

        if ($headerDiscount < 0) {
            throw ValidationException::withMessages([
                'discount' => 'Discount cannot be negative.',
            ]);
        }

        $idempotencyKey = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? trim($idempotencyKey)
            : null;

        try {
            return DB::transaction(function () use (
                $branch,
                $customer,
                $lines,
                $paymentMethod,
                $actor,
                $headerDiscount,
                $paymentReference,
                $notes,
                $reservation,
                $idempotencyKey,
            ): InventorySale {
                if ($idempotencyKey !== null) {
                    // Do not lockForUpdate a missing unique key: InnoDB gap-locks the
                    // empty index and deadlocks the other counter's INSERT.
                    $existing = InventorySale::query()
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();
                    if ($existing !== null) {
                        return $existing->fresh(['lines.product', 'serials.serial', 'customer', 'branch']) ?? $existing;
                    }
                }

                $lockedBranch = InventoryBranch::query()->lockForUpdate()->findOrFail($branch->id);
                if (! $lockedBranch->is_active) {
                    throw ValidationException::withMessages([
                        'branch_id' => 'Branch is inactive.',
                    ]);
                }

                $allSerialNumbers = [];
                foreach ($lines as $line) {
                    foreach (InventorySerialNumber::parseList($line['serials'] ?? []) as $number) {
                        $allSerialNumbers[] = $number;
                    }
                }
                $allSerialNumbers = array_values(array_unique($allSerialNumbers));
                sort($allSerialNumbers, SORT_STRING);
                foreach ($allSerialNumbers as $number) {
                    $this->stock->lockSerialByNumber($number);
                }

                $inventoryCustomer = $this->findOrCreateCustomer($customer);

                $sale = InventorySale::query()->create([
                    'sale_no' => 'POS-TMP-'.strtoupper(bin2hex(random_bytes(6))),
                    'idempotency_key' => $idempotencyKey,
                    'branch_id' => $lockedBranch->id,
                    'customer_id' => $inventoryCustomer->id,
                    'status' => InventorySaleStatus::Completed,
                    'subtotal' => 0,
                    'discount' => $headerDiscount,
                    'tax' => 0,
                    'total' => 0,
                    'payment_method' => $paymentMethod,
                    'payment_reference' => $paymentReference,
                    'finance_handoff_status' => InventoryFinanceHandoffStatus::Pending,
                    'notes' => $notes,
                    'created_by' => $actor->id,
                    'completed_at' => now(),
                ]);

                $subtotal = 0.0;
                $tax = 0.0;
                $lineDiscountTotal = 0.0;

                foreach ($lines as $index => $line) {
                    $product = InventoryProduct::query()
                        ->with(['variants' => fn ($variants) => $variants->where('is_active', true)])
                        ->find($line['product_id'] ?? null);
                    if ($product === null || ! $product->is_active) {
                        throw ValidationException::withMessages([
                            "lines.{$index}.product_id" => 'Product is missing or inactive.',
                        ]);
                    }

                    $variant = null;
                    if (! empty($line['variant_id'])) {
                        $variant = InventoryProductVariant::query()->find($line['variant_id']);
                        if ($variant === null || $variant->product_id !== $product->id || ! $variant->is_active) {
                            throw ValidationException::withMessages([
                                "lines.{$index}.variant_id" => 'Variant is missing or inactive.',
                            ]);
                        }
                    } elseif ($product->variants->isNotEmpty()) {
                        throw ValidationException::withMessages([
                            "lines.{$index}.variant_id" => 'Select a variant. POS sells child SKUs separately from the parent product.',
                        ]);
                    }

                    $qty = (int) ($line['qty'] ?? 0);
                    if ($qty < 1) {
                        throw ValidationException::withMessages([
                            "lines.{$index}.qty" => 'Quantity must be at least 1.',
                        ]);
                    }

                    $unitPrice = $line['unit_price'] ?? $product->priceFor($variant);
                    $unitPrice = round((float) $unitPrice, 2);
                    $lineDiscount = round((float) ($line['discount'] ?? 0), 2);
                    if ($lineDiscount < 0) {
                        throw ValidationException::withMessages([
                            "lines.{$index}.discount" => 'Line discount cannot be negative.',
                        ]);
                    }

                    $lineSubtotal = round($unitPrice * $qty, 2);
                    $taxable = round($lineSubtotal - $lineDiscount, 2);
                    if ($taxable < 0) {
                        throw ValidationException::withMessages([
                            "lines.{$index}.discount" => 'Discount cannot exceed line subtotal.',
                        ]);
                    }

                    $gst = (float) $product->gst_percentage;
                    $lineTax = round($taxable * ($gst / 100), 2);
                    $lineTotal = round($taxable + $lineTax, 2);

                    $saleLine = $sale->lines()->create([
                        'product_id' => $product->id,
                        'variant_id' => $variant?->id,
                        'qty' => $qty,
                        'unit_price' => $unitPrice,
                        'gst_percentage' => $gst,
                        'discount' => $lineDiscount,
                        'tax' => $lineTax,
                        'line_total' => $lineTotal,
                    ]);

                    if ($product->is_serialized) {
                        $serialNumbers = InventorySerialNumber::parseList($line['serials'] ?? []);
                        if (count($serialNumbers) !== $qty) {
                            throw ValidationException::withMessages([
                                "lines.{$index}.serials" => "Provide exactly {$qty} available serial(s) for {$product->sku}.",
                            ]);
                        }

                        $serials = $this->stock->lockAvailableSerialsForSale(
                            $product,
                            $lockedBranch,
                            $serialNumbers,
                            $variant,
                            $reservation?->id,
                        );
                        foreach ($serials as $serial) {
                            $fromStatus = $serial->status;
                            $this->stock->markSerialSold($serial, $lockedBranch);
                            $sale->serials()->create([
                                'sale_line_id' => $saleLine->id,
                                'serial_id' => $serial->id,
                            ]);
                            $this->stock->recordMovement(
                                type: InventoryMovementType::Sale,
                                product: $product,
                                branch: $lockedBranch,
                                qty: -1,
                                actor: $actor,
                                variant: $variant,
                                serial: $serial,
                                sale: $sale,
                                fromStatus: $fromStatus,
                                toStatus: InventorySerialStatus::Sold,
                                notes: $notes,
                            );
                        }
                    } else {
                        if ($reservation !== null) {
                            $this->stock->consumeReservedQuantity($reservation, $product, $lockedBranch, $qty, $variant);
                        } else {
                            $this->stock->deductQuantity($product, $lockedBranch, $qty, $variant);
                        }
                        $this->stock->recordMovement(
                            type: InventoryMovementType::Sale,
                            product: $product,
                            branch: $lockedBranch,
                            qty: -$qty,
                            actor: $actor,
                            variant: $variant,
                            sale: $sale,
                            notes: $notes,
                        );
                    }

                    $subtotal += $lineSubtotal;
                    $tax += $lineTax;
                    $lineDiscountTotal += $lineDiscount;
                }

                $discount = round($headerDiscount + $lineDiscountTotal, 2);
                $total = round($subtotal - $discount + $tax, 2);
                if ($total < 0) {
                    throw ValidationException::withMessages([
                        'discount' => 'Discount cannot exceed sale total.',
                    ]);
                }

                $invoiceYear = (int) now()->format('Y');
                $next = $lockedBranch->invoice_sequence + 1;
                $lockedBranch->update(['invoice_sequence' => $next]);
                $invoiceNumber = sprintf('INV-%s-%d-%05d', $lockedBranch->code, $invoiceYear, $next);

                // Internal POS receipt only. Statutory GST invoices are minted by
                // StatutoryInvoiceService when numbering is configured. Never auto-issue here.

                $sale->update([
                    'sale_no' => sprintf('POS-%06d', $sale->id),
                    'invoice_number' => $invoiceNumber,
                    'subtotal' => $subtotal,
                    'discount' => $discount,
                    'tax' => $tax,
                    'total' => $total,
                ]);

                if ($reservation !== null) {
                    $reservation->update(['sale_id' => $sale->id]);
                    $this->stock->consumeReservation($reservation);
                }

                $this->journals->postForSale($sale, $actor, failClosed: true);

                InventorySaleCompleted::dispatch($sale->fresh() ?? $sale);

                return $sale->fresh(['lines.product', 'serials.serial', 'customer', 'branch']) ?? $sale;
            }, self::COMPLETION_ATTEMPTS);
        } catch (UniqueConstraintViolationException $exception) {
            if ($idempotencyKey !== null) {
                $existing = InventorySale::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing !== null) {
                    return $existing->fresh(['lines.product', 'serials.serial', 'customer', 'branch']) ?? $existing;
                }
            }

            throw $exception;
        }
    }

    public function cancelSale(InventorySale $sale, User $actor, string $reason): InventorySale
    {
        return $this->reverseSale($sale, $actor, $reason, InventorySaleStatus::Cancelled, InventoryMovementType::SaleCancel);
    }

    public function returnSale(InventorySale $sale, User $actor, string $reason): InventorySale
    {
        return $this->reverseSale($sale, $actor, $reason, InventorySaleStatus::Returned, InventoryMovementType::Return);
    }

    private function reverseSale(
        InventorySale $sale,
        User $actor,
        string $reason,
        InventorySaleStatus $toStatus,
        InventoryMovementType $movementType,
    ): InventorySale {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required.',
            ]);
        }

        return DB::transaction(function () use ($sale, $actor, $reason, $toStatus, $movementType): InventorySale {
            $sale = InventorySale::query()->lockForUpdate()->findOrFail($sale->id);

            if ($sale->status !== InventorySaleStatus::Completed) {
                throw ValidationException::withMessages([
                    'sale' => 'Only a completed sale can be cancelled or returned.',
                ]);
            }

            $sale->load(['lines.product', 'lines.variant', 'serials.serial', 'branch']);
            $branch = $sale->branch;

            $orderedAssignments = $sale->serials
                ->sortBy(fn ($assignment) => (string) ($assignment->serial?->serial_number ?? ''))
                ->values();

            foreach ($orderedAssignments as $assignment) {
                $serial = $this->stock->lockSerialById($assignment->serial_id);
                if ($serial->status !== InventorySerialStatus::Sold) {
                    throw ValidationException::withMessages([
                        'sale' => "Serial {$serial->serial_number} is no longer sold and cannot be reversed.",
                    ]);
                }

                $this->stock->restoreSerialFromSale($serial, $branch);
                $this->stock->recordMovement(
                    type: $movementType,
                    product: $serial->product,
                    branch: $branch,
                    qty: 1,
                    actor: $actor,
                    variant: $serial->variant,
                    serial: $serial,
                    sale: $sale,
                    fromStatus: InventorySerialStatus::Sold,
                    toStatus: InventorySerialStatus::Available,
                    notes: $reason,
                );
            }

            foreach ($sale->lines as $line) {
                if ($line->product->is_serialized) {
                    continue;
                }

                $this->stock->restoreQuantity($line->product, $branch, $line->qty, $line->variant);
                $this->stock->recordMovement(
                    type: $movementType,
                    product: $line->product,
                    branch: $branch,
                    qty: $line->qty,
                    actor: $actor,
                    variant: $line->variant,
                    sale: $sale,
                    notes: $reason,
                );
            }

            $this->journals->reverseForSale($sale, $actor, failClosed: true);

            $sale->update([
                'status' => $toStatus,
                'cancel_reason' => $reason,
                'cancelled_at' => now(),
            ]);

            return $sale->fresh(['lines.product', 'serials.serial', 'customer', 'branch']) ?? $sale;
        }, self::COMPLETION_ATTEMPTS);
    }

    /**
     * Authoritative POS total using the same line math as completeSale.
     *
     * @param  list<array{
     *     product_id: int,
     *     variant_id?: int|null,
     *     qty: int,
     *     unit_price?: float|string|null,
     *     discount?: float|string|null
     * }>  $lines
     * @return array{subtotal: float, discount: float, tax: float, total: float}
     */
    public function quoteTotals(array $lines, float $headerDiscount = 0): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'Add at least one product line.',
            ]);
        }

        if ($headerDiscount < 0) {
            throw ValidationException::withMessages([
                'discount' => 'Discount cannot be negative.',
            ]);
        }

        $subtotal = 0.0;
        $tax = 0.0;
        $lineDiscountTotal = 0.0;

        foreach ($lines as $index => $line) {
            $product = InventoryProduct::query()
                ->with(['variants' => fn ($variants) => $variants->where('is_active', true)])
                ->find($line['product_id'] ?? null);
            if ($product === null || ! $product->is_active) {
                throw ValidationException::withMessages([
                    "lines.{$index}.product_id" => 'Product is missing or inactive.',
                ]);
            }

            $variant = null;
            if (! empty($line['variant_id'])) {
                $variant = InventoryProductVariant::query()->find($line['variant_id']);
                if ($variant === null || $variant->product_id !== $product->id || ! $variant->is_active) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.variant_id" => 'Variant is missing or inactive.',
                    ]);
                }
            } elseif ($product->variants->isNotEmpty()) {
                throw ValidationException::withMessages([
                    "lines.{$index}.variant_id" => 'Select a variant. POS sells child SKUs separately from the parent product.',
                ]);
            }

            $qty = (int) ($line['qty'] ?? 0);
            if ($qty < 1) {
                throw ValidationException::withMessages([
                    "lines.{$index}.qty" => 'Quantity must be at least 1.',
                ]);
            }

            $unitPrice = $line['unit_price'] ?? $product->priceFor($variant);
            $unitPrice = round((float) $unitPrice, 2);
            $lineDiscount = round((float) ($line['discount'] ?? 0), 2);
            if ($lineDiscount < 0) {
                throw ValidationException::withMessages([
                    "lines.{$index}.discount" => 'Line discount cannot be negative.',
                ]);
            }

            $lineSubtotal = round($unitPrice * $qty, 2);
            $taxable = round($lineSubtotal - $lineDiscount, 2);
            if ($taxable < 0) {
                throw ValidationException::withMessages([
                    "lines.{$index}.discount" => 'Discount cannot exceed line subtotal.',
                ]);
            }

            $gst = (float) $product->gst_percentage;
            $lineTax = round($taxable * ($gst / 100), 2);
            $subtotal += $lineSubtotal;
            $tax += $lineTax;
            $lineDiscountTotal += $lineDiscount;
        }

        $discount = round($headerDiscount + $lineDiscountTotal, 2);
        $total = round($subtotal - $discount + $tax, 2);
        if ($total < 0) {
            throw ValidationException::withMessages([
                'discount' => 'Discount cannot exceed sale total.',
            ]);
        }

        return [
            'subtotal' => round($subtotal, 2),
            'discount' => $discount,
            'tax' => round($tax, 2),
            'total' => $total,
        ];
    }

    /**
     * @param  array{name?: string, phone?: string, email?: string|null, gstin?: string|null}  $customer
     */
    public function findOrCreateCustomer(array $customer): InventoryCustomer
    {
        $phone = preg_replace('/\s+/', '', (string) ($customer['phone'] ?? '')) ?? '';
        $name = trim((string) ($customer['name'] ?? ''));

        if ($phone === '' || $name === '') {
            throw ValidationException::withMessages([
                'customer_phone' => 'Customer name and phone are required.',
            ]);
        }

        $existing = InventoryCustomer::query()->where('phone', $phone)->first();
        if ($existing !== null) {
            $locked = InventoryCustomer::query()->lockForUpdate()->find($existing->id) ?? $existing;
            $locked->fill([
                'name' => $name,
                'email' => $customer['email'] ?? $locked->email,
                'gstin' => $customer['gstin'] ?? $locked->gstin,
            ])->save();

            return $locked;
        }

        try {
            return InventoryCustomer::query()->create([
                'name' => $name,
                'phone' => $phone,
                'email' => $customer['email'] ?? null,
                'gstin' => $customer['gstin'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $locked = InventoryCustomer::query()->where('phone', $phone)->lockForUpdate()->first();
            if ($locked !== null) {
                $locked->fill([
                    'name' => $name,
                    'email' => $customer['email'] ?? $locked->email,
                    'gstin' => $customer['gstin'] ?? $locked->gstin,
                ])->save();

                return $locked;
            }

            throw $exception;
        }
    }
}
