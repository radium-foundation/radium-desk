<?php

namespace App\Services\Inventory;

use App\Enums\InterBranchEwayBillStatus;
use App\Enums\InterBranchTransactionStatus;
use App\Enums\InventoryTransferStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\InterBranchTransaction;
use App\Models\InterBranchTransactionLine;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventoryProductVariant;
use App\Models\InventoryReservation;
use App\Models\InventorySerial;
use App\Models\InventoryTransfer;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\StatutoryInvoice\BuyerGstin;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceLineDraft;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceMintRequest;
use App\Services\StatutoryInvoice\StatutoryBillingIssuer;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use App\Services\StatutoryInvoice\StatutoryFinancialYear;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Services\StatutoryInvoice\StatutoryLocationSeries;
use App\Support\Finance\GstStateCodes;
use App\Support\Inventory\InventorySerialNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class InterBranchTransferService
{
    public function __construct(
        private readonly InventoryStockService $stock,
        private readonly StatutoryInvoiceService $invoices,
        private readonly StatutoryDocumentService $documents,
        private readonly StatutoryBillingIssuer $issuer,
        private readonly StatutoryLocationSeries $locations,
    ) {}

    /**
     * @param  list<array{product_id:int,variant_id?:int|null,qty:int,serials?:list<string>|string|null}>  $lines
     */
    public function issue(
        InventoryBranch $from,
        InventoryBranch $to,
        array $lines,
        User $actor,
        string $idempotencyKey,
        ?string $notes = null,
    ): InterBranchTransaction {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            throw ValidationException::withMessages([
                'idempotency_key' => 'An idempotency key is required.',
            ]);
        }

        $existing = InterBranchTransaction::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing !== null) {
            return $existing->load([
                'fromBranch',
                'toBranch',
                'lines.product',
                'lines.serial',
                'statutoryInvoice',
                'inventoryTransfer',
            ]);
        }

        if ($from->id === $to->id) {
            throw ValidationException::withMessages([
                'to_branch_id' => 'Source and destination branches must be different.',
            ]);
        }

        $destinationGstin = BuyerGstin::normalize($to->gstin);
        if ($destinationGstin === null || ! BuyerGstin::isValid($destinationGstin)) {
            throw ValidationException::withMessages([
                'to_branch_id' => 'Destination branch must have a valid GSTIN for inter-branch invoicing.',
            ]);
        }

        $normalizedLines = $this->normalizeLines($lines, $from);
        $reservationLines = $this->reservationPayloadFromNormalized($normalizedLines);

        $transaction = DB::transaction(function () use (
            $from,
            $to,
            $normalizedLines,
            $reservationLines,
            $actor,
            $idempotencyKey,
            $notes,
            $destinationGstin,
        ): InterBranchTransaction {
            $duplicate = InterBranchTransaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($duplicate !== null) {
                return $duplicate;
            }

            $this->assertSerialsNotInActiveTransfer($normalizedLines);

            $transaction = InterBranchTransaction::query()->create([
                'transaction_no' => 'IBT-TMP-'.strtoupper(bin2hex(random_bytes(6))),
                'idempotency_key' => $idempotencyKey,
                'from_branch_id' => $from->id,
                'to_branch_id' => $to->id,
                'status' => InterBranchTransactionStatus::Issued,
                'destination_gstin' => $destinationGstin,
                'notes' => $notes,
                'created_by' => $actor->id,
                'issued_at' => now(),
                'eway_bill_status' => InterBranchEwayBillStatus::NotApplicable,
            ]);
            $transaction->update(['transaction_no' => sprintf('IBT-%06d', $transaction->id)]);

            $transfer = InventoryTransfer::query()->create([
                'transfer_no' => 'TRF-TMP-'.strtoupper(bin2hex(random_bytes(6))),
                'from_branch_id' => $from->id,
                'to_branch_id' => $to->id,
                'status' => InventoryTransferStatus::Draft,
                'notes' => 'Inter-branch '.$transaction->transaction_no,
                'created_by' => $actor->id,
            ]);
            $transfer->update(['transfer_no' => sprintf('TRF-%06d', $transfer->id)]);

            foreach ($normalizedLines as $line) {
                if ($line['product']->is_serialized) {
                    foreach ($line['serials'] as $serial) {
                        InterBranchTransactionLine::query()->create([
                            'inter_branch_transaction_id' => $transaction->id,
                            'product_id' => $line['product']->id,
                            'variant_id' => $line['variant']?->id,
                            'serial_id' => $serial->id,
                            'qty' => 1,
                            'unit_price' => $line['unit_price'],
                            'gst_percentage' => $line['gst_percentage'],
                        ]);
                    }
                } else {
                    InterBranchTransactionLine::query()->create([
                        'inter_branch_transaction_id' => $transaction->id,
                        'product_id' => $line['product']->id,
                        'variant_id' => $line['variant']?->id,
                        'serial_id' => null,
                        'qty' => $line['qty'],
                        'unit_price' => $line['unit_price'],
                        'gst_percentage' => $line['gst_percentage'],
                    ]);
                }
            }

            $reservation = $this->stock->reserveForCart(
                $from,
                $reservationLines,
                $actor,
                'Inter-branch hold '.$transaction->transaction_no,
            );

            $invoice = $this->invoices->mint(
                $this->mintRequest($transaction, $from, $to, $normalizedLines, $transfer),
                $actor,
            );

            $transaction->update([
                'statutory_invoice_id' => $invoice->id,
                'inventory_transfer_id' => $transfer->id,
                'inventory_reservation_id' => $reservation->id,
            ]);

            return $transaction->fresh([
                'fromBranch',
                'toBranch',
                'lines.product',
                'lines.serial',
                'statutoryInvoice.items',
                'inventoryTransfer',
                'inventoryReservation',
            ]) ?? $transaction;
        }, 5);

        $this->generateDocumentSafely($transaction->statutoryInvoice);
        $this->queueEinvoiceSafely($transaction->statutoryInvoice);

        return $transaction;
    }

    /**
     * @param  array{transporter?:?string,transport_reference?:?string,dispatch_date?:?string,eway_bill_reference?:?string,eway_bill_notes?:?string}  $dispatch
     */
    public function dispatch(InterBranchTransaction $transaction, User $actor, array $dispatch = []): InterBranchTransaction
    {
        return DB::transaction(function () use ($transaction, $actor, $dispatch): InterBranchTransaction {
            $locked = InterBranchTransaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->with(['lines.product', 'lines.serial', 'inventoryTransfer', 'inventoryReservation'])
                ->firstOrFail();

            if ($locked->status !== InterBranchTransactionStatus::Issued) {
                if (in_array($locked->status, [InterBranchTransactionStatus::Dispatched, InterBranchTransactionStatus::InTransit, InterBranchTransactionStatus::Received, InterBranchTransactionStatus::Completed], true)) {
                    return $locked->load(['fromBranch', 'toBranch', 'statutoryInvoice', 'inventoryTransfer']);
                }

                throw ValidationException::withMessages([
                    'status' => 'Only issued inter-branch transfers can be dispatched.',
                ]);
            }

            $transfer = $locked->inventoryTransfer;
            $reservation = $locked->inventoryReservation;
            if ($transfer === null || $reservation === null) {
                throw ValidationException::withMessages([
                    'transfer' => 'Inter-branch transfer is missing its inventory transfer or reservation linkage.',
                ]);
            }

            $from = $locked->fromBranch;
            $to = $locked->toBranch;

            foreach ($locked->lines as $line) {
                if ($line->serial_id !== null) {
                    $serial = $this->stock->lockSerialById($line->serial_id);
                    $this->stock->dispatchReservedSerialForInterBranch(
                        $serial,
                        $reservation,
                        $from,
                        $to,
                        $transfer,
                        $actor,
                    );
                    $transfer->lines()->create([
                        'product_id' => $line->product_id,
                        'variant_id' => $line->variant_id,
                        'serial_id' => $line->serial_id,
                        'qty' => 1,
                    ]);
                }
            }

            $quantityLines = $locked->lines->whereNull('serial_id')->groupBy(fn ($line) => $line->product_id.'-'.($line->variant_id ?? 0));
            foreach ($quantityLines as $group) {
                /** @var InterBranchTransactionLine $first */
                $first = $group->first();
                $qty = (int) $group->sum('qty');
                $this->stock->dispatchReservedQuantityForInterBranch(
                    $reservation,
                    $first->product,
                    $from,
                    $to,
                    $qty,
                    $first->variant,
                    $transfer,
                    $actor,
                );
                $transfer->lines()->create([
                    'product_id' => $first->product_id,
                    'variant_id' => $first->variant_id,
                    'serial_id' => null,
                    'qty' => $qty,
                ]);
            }

            $this->stock->consumeReservation($reservation);

            $ewayReference = trim((string) ($dispatch['eway_bill_reference'] ?? ''));
            $locked->update([
                'status' => InterBranchTransactionStatus::InTransit,
                'dispatched_at' => now(),
                'transporter' => $dispatch['transporter'] ?? null,
                'transport_reference' => $dispatch['transport_reference'] ?? null,
                'dispatch_date' => $dispatch['dispatch_date'] ?? now()->toDateString(),
                'eway_bill_reference' => $ewayReference !== '' ? $ewayReference : null,
                'eway_bill_status' => $ewayReference !== ''
                    ? InterBranchEwayBillStatus::ReferenceEntered
                    : InterBranchEwayBillStatus::NotApplicable,
                'eway_bill_notes' => $dispatch['eway_bill_notes'] ?? null,
            ]);

            $transfer->update([
                'status' => InventoryTransferStatus::InTransit,
                'completed_at' => null,
            ]);

            return $locked->fresh([
                'fromBranch',
                'toBranch',
                'lines.product',
                'lines.serial',
                'statutoryInvoice',
                'inventoryTransfer.lines',
            ]) ?? $locked;
        }, 5);
    }

    public function receive(InterBranchTransaction $transaction, User $actor): InterBranchTransaction
    {
        return DB::transaction(function () use ($transaction, $actor): InterBranchTransaction {
            $locked = InterBranchTransaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->with(['lines.product', 'lines.serial', 'inventoryTransfer'])
                ->firstOrFail();

            if (! $locked->status->canReceive()) {
                if (in_array($locked->status, [InterBranchTransactionStatus::Received, InterBranchTransactionStatus::Completed], true)) {
                    return $locked->load(['fromBranch', 'toBranch', 'statutoryInvoice', 'inventoryTransfer']);
                }

                throw ValidationException::withMessages([
                    'status' => 'Only dispatched or in-transit transfers can be received.',
                ]);
            }

            $transfer = $locked->inventoryTransfer;
            if ($transfer === null) {
                throw ValidationException::withMessages([
                    'transfer' => 'Inter-branch transfer is missing its inventory transfer linkage.',
                ]);
            }

            $from = $locked->fromBranch;
            $to = $locked->toBranch;

            foreach ($locked->lines as $line) {
                if ($line->serial_id !== null) {
                    $serial = $this->stock->lockSerialById($line->serial_id);
                    $this->stock->receiveInterBranchSerial(
                        $serial,
                        $from,
                        $to,
                        $transfer,
                        $actor,
                    );
                }
            }

            $quantityLines = $locked->lines->whereNull('serial_id')->groupBy(fn ($line) => $line->product_id.'-'.($line->variant_id ?? 0));
            foreach ($quantityLines as $group) {
                /** @var InterBranchTransactionLine $first */
                $first = $group->first();
                $qty = (int) $group->sum('qty');
                $this->stock->receiveInterBranchQuantity(
                    $first->product,
                    $from,
                    $to,
                    $qty,
                    $first->variant,
                    $transfer,
                    $actor,
                );
            }

            $now = now();
            $locked->update([
                'status' => InterBranchTransactionStatus::Completed,
                'received_at' => $now,
                'completed_at' => $now,
            ]);

            $transfer->update([
                'status' => InventoryTransferStatus::Completed,
                'completed_at' => $now,
            ]);

            return $locked->fresh([
                'fromBranch',
                'toBranch',
                'lines.product',
                'lines.serial',
                'statutoryInvoice',
                'inventoryTransfer.lines',
            ]) ?? $locked;
        }, 5);
    }

    public function cancel(InterBranchTransaction $transaction, User $actor, string $reason): InterBranchTransaction
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'cancel_reason' => 'A cancellation reason is required.',
            ]);
        }

        return DB::transaction(function () use ($transaction, $actor, $reason): InterBranchTransaction {
            $locked = InterBranchTransaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->with(['inventoryTransfer', 'inventoryReservation'])
                ->firstOrFail();

            if (! $locked->status->canCancel()) {
                throw ValidationException::withMessages([
                    'status' => 'This inter-branch transfer cannot be cancelled in its current state.',
                ]);
            }

            if ($locked->status === InterBranchTransactionStatus::Issued && $locked->inventoryReservation !== null) {
                $this->stock->releaseReservation($locked->inventoryReservation, $actor, 'Inter-branch cancelled: '.$reason);
            }

            if (in_array($locked->status, [InterBranchTransactionStatus::Dispatched, InterBranchTransactionStatus::InTransit], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Dispatched stock must be received back before cancellation. Contact inventory admin.',
                ]);
            }

            $invoice = $locked->statutoryInvoice;
            if ($invoice !== null && $invoice->status !== StatutoryInvoiceStatus::Cancelled) {
                $this->invoices->cancel($invoice, $actor, $reason);
            }

            $locked->inventoryTransfer?->update(['status' => InventoryTransferStatus::Cancelled]);
            $locked->update([
                'status' => InterBranchTransactionStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason,
            ]);

            return $locked->fresh([
                'fromBranch',
                'toBranch',
                'lines.product',
                'lines.serial',
                'statutoryInvoice',
                'inventoryTransfer',
            ]) ?? $locked;
        }, 5);
    }

    /**
     * @param  list<array{product_id:int,variant_id?:int|null,qty:int,serials?:list<string>|string|null}>  $lines
     * @return list<array{product:InventoryProduct,variant:?InventoryProductVariant,qty:int,unit_price:float,gst_percentage:float,serials:list<InventorySerial>}>
     */
    private function normalizeLines(array $lines, InventoryBranch $from): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'Add at least one product line.',
            ]);
        }

        $normalized = [];
        foreach ($lines as $index => $line) {
            $product = InventoryProduct::query()->find($line['product_id'] ?? null);
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
            }

            $qty = (int) ($line['qty'] ?? 0);
            if ($qty < 1) {
                throw ValidationException::withMessages([
                    "lines.{$index}.qty" => 'Quantity must be at least 1.',
                ]);
            }

            $serials = [];
            if ($product->is_serialized) {
                $numbers = InventorySerialNumber::parseList($line['serials'] ?? []);
                if (count($numbers) !== $qty) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.serials" => "Provide exactly {$qty} serial(s) for {$product->sku}.",
                    ]);
                }

                foreach ($numbers as $number) {
                    $serial = $this->stock->lockSerialByNumber($number);
                    if ($serial->branch_id !== $from->id) {
                        throw ValidationException::withMessages([
                            "lines.{$index}.serials" => "Serial {$number} is not at {$from->code}.",
                        ]);
                    }
                    if (! $serial->status->isAssignable()) {
                        throw ValidationException::withMessages([
                            "lines.{$index}.serials" => "Serial {$number} is {$serial->status->label()} and cannot be transferred.",
                        ]);
                    }
                    $serials[] = $serial;
                }
            } else {
                $this->stock->assertQuantityAvailable($product, $from, $qty, $variant);
            }

            $normalized[] = [
                'product' => $product,
                'variant' => $variant,
                'qty' => $qty,
                'unit_price' => (float) $product->unit_price,
                'gst_percentage' => (float) $product->gst_percentage,
                'serials' => $serials,
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array{product:InventoryProduct,variant:?InventoryProductVariant,qty:int,unit_price:float,gst_percentage:float,serials:list<InventorySerial>}>  $normalizedLines
     * @return list<array<string, mixed>>
     */
    private function reservationPayloadFromNormalized(array $normalizedLines): array
    {
        $payload = [];
        foreach ($normalizedLines as $line) {
            $entry = [
                'product_id' => $line['product']->id,
                'variant_id' => $line['variant']?->id,
                'qty' => $line['qty'],
            ];
            if ($line['product']->is_serialized) {
                $entry['serials'] = array_map(
                    fn (InventorySerial $serial): string => $serial->serial_number,
                    $line['serials'],
                );
            }
            $payload[] = $entry;
        }

        return $payload;
    }

    /**
     * @param  list<array{product:InventoryProduct,variant:?InventoryProductVariant,qty:int,unit_price:float,gst_percentage:float,serials:list<InventorySerial>}>  $normalizedLines
     */
    private function mintRequest(
        InterBranchTransaction $transaction,
        InventoryBranch $from,
        InventoryBranch $to,
        array $normalizedLines,
        InventoryTransfer $transfer,
    ): StatutoryInvoiceMintRequest {
        $invoiceLines = [];
        $aggregated = [];
        foreach ($normalizedLines as $line) {
            $key = $line['product']->id.'|'.($line['variant']?->id ?? 0);
            if (! isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'product' => $line['product'],
                    'variant' => $line['variant'],
                    'qty' => 0,
                    'unit_price' => $line['unit_price'],
                    'gst_percentage' => $line['gst_percentage'],
                ];
            }
            $aggregated[$key]['qty'] += $line['qty'];
        }

        foreach ($aggregated as $row) {
            $taxable = round($row['unit_price'] * $row['qty'], 2);
            $taxTotal = round($taxable * ($row['gst_percentage'] / 100), 2);
            $invoiceLines[] = new StatutoryInvoiceLineDraft(
                description: $row['variant']?->name ?? $row['product']->name,
                qty: $row['qty'],
                unitPrice: $row['unit_price'],
                gstPercentage: $row['gst_percentage'],
                taxTotal: $taxTotal,
                lineTotal: round($taxable + $taxTotal, 2),
                taxableValue: $taxable,
                sku: $row['variant']?->sku ?? $row['product']->sku,
                hsnSac: $row['product']->hsn_code,
                uqc: $row['product']->uqc,
            );
        }

        $destinationGstin = BuyerGstin::normalize($to->gstin);
        $toLocation = $this->locations->requireFromBranchCode($to->code);
        $locationConfig = $this->locations->locations()[$toLocation];
        $placeState = $locationConfig['state'] !== ''
            ? $locationConfig['state']
            : GstStateCodes::nameForCode(BuyerGstin::stateCode($destinationGstin ?? '') ?? '');

        return new StatutoryInvoiceMintRequest(
            channel: StatutoryInvoiceChannel::DeskInventory,
            sourceType: StatutoryInvoiceSourceType::InterBranchTransfer,
            sourceId: (string) $transaction->id,
            lines: $invoiceLines,
            sourceOrderId: $transaction->transaction_no,
            branchId: $from->id,
            buyerName: $to->name,
            buyerGstin: $destinationGstin,
            placeOfSupplyState: $placeState,
            placeOfSupplySource: 'inter_branch_destination_branch',
            numberingLocation: $this->issuer->requireForProductBranch($from->code),
            financialYearToken: StatutoryFinancialYear::containing(now())->token(),
        );
    }

    /**
     * @param  list<array{product:InventoryProduct,variant:?InventoryProductVariant,qty:int,unit_price:float,gst_percentage:float,serials:list<InventorySerial>}>  $normalizedLines
     */
    private function assertSerialsNotInActiveTransfer(array $normalizedLines): void
    {
        $serialIds = collect($normalizedLines)
            ->flatMap(fn (array $line): array => array_map(fn (InventorySerial $serial): int => $serial->id, $line['serials']))
            ->unique()
            ->values();

        if ($serialIds->isEmpty()) {
            return;
        }

        $active = InterBranchTransactionLine::query()
            ->whereIn('serial_id', $serialIds)
            ->whereHas('transaction', function ($query): void {
                $query->whereIn('status', array_map(
                    fn (InterBranchTransactionStatus $status): string => $status->value,
                    InterBranchTransactionStatus::activeStockHolding(),
                ));
            })
            ->exists();

        if ($active) {
            throw ValidationException::withMessages([
                'serials' => 'One or more serials are already part of an active inter-branch transfer.',
            ]);
        }
    }

    private function generateDocumentSafely(?StatutoryInvoice $invoice): void
    {
        if ($invoice === null) {
            return;
        }

        try {
            $this->documents->generate($invoice);
        } catch (Throwable $exception) {
            Log::warning('inter_branch_transfer.document_generation_failed', [
                'invoice_id' => $invoice->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function queueEinvoiceSafely(?StatutoryInvoice $invoice): void
    {
        if ($invoice === null) {
            return;
        }

        try {
            $this->invoices->queueEinvoiceIfEligible($invoice);
        } catch (Throwable $exception) {
            Log::warning('inter_branch_transfer.einvoice_queue_failed', [
                'invoice_id' => $invoice->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
