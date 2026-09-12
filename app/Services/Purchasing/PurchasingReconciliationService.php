<?php

namespace App\Services\Purchasing;

use App\Enums\GoodsReceiptSerialValidationStatus;
use App\Enums\GoodsReceiptStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InventorySerialStatus;
use App\Models\GoodsReceipt;
use App\Models\InventorySerial;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchasingReconciliationService
{
    public function __construct(
        private readonly PurchasingAuditService $audit,
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly InventoryStockService $stock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildSummary(GoodsReceipt $receipt): array
    {
        $receipt->load(['items.product', 'items.serials', 'purchaseOrder', 'supplierInvoices', 'vendor']);

        $ordered = $receipt->purchaseOrder->items->sum('quantity_ordered');
        $received = $receipt->items->sum('quantity_received');
        $serialCount = $receipt->serials->count();
        $invalidSerials = $receipt->serials->filter(fn ($s) => $s->validation_status !== GoodsReceiptSerialValidationStatus::Valid)->count();
        $duplicateSerials = $receipt->serials->where('validation_status', GoodsReceiptSerialValidationStatus::DuplicateInReceipt)->count();

        $invoice = $receipt->supplierInvoices->sortByDesc('id')->first();

        return [
            'ordered' => $ordered,
            'received' => $received,
            'serial_count' => $serialCount,
            'invalid_serials' => $invalidSerials,
            'duplicate_serials' => $duplicateSerials,
            'supplier_invoice_present' => $invoice !== null,
            'invoice_amount' => $invoice?->invoice_amount,
            'payment_status' => $invoice?->payment_status?->label(),
        ];
    }

    public function completeReceiving(GoodsReceipt $receipt, User $actor, ?string $idempotencyKey = null): GoodsReceipt
    {
        if ($receipt->status !== GoodsReceiptStatus::PendingConfirmation) {
            throw ValidationException::withMessages([
                'status' => 'Only receipts pending confirmation can be completed.',
            ]);
        }

        $idempotencyKey ??= 'gr-complete-'.$receipt->id;

        if ($receipt->status === GoodsReceiptStatus::Completed && $receipt->completion_idempotency_key === $idempotencyKey) {
            return $receipt;
        }

        $summary = $this->buildSummary($receipt);
        if ($summary['invalid_serials'] > 0) {
            throw ValidationException::withMessages([
                'serials' => 'Cannot complete receiving while invalid serials remain.',
            ]);
        }

        return DB::transaction(function () use ($receipt, $actor, $idempotencyKey): GoodsReceipt {
            $locked = GoodsReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === GoodsReceiptStatus::Completed) {
                if ($locked->completion_idempotency_key === $idempotencyKey) {
                    return $locked;
                }

                throw ValidationException::withMessages([
                    'status' => 'This receipt has already been completed.',
                ]);
            }

            $locked->load(['items.product', 'items.serials', 'purchaseOrder', 'branch', 'vendor']);

            foreach ($locked->items as $item) {
                if ($item->product->is_serialized) {
                    $this->releaseSerializedStock($locked, $item, $actor);
                } else {
                    $this->stock->stockInQuantity(
                        $item->product,
                        $locked->branch,
                        $item->quantity_received,
                        $actor,
                        $item->variant,
                        notes: "Goods receipt {$locked->receipt_number}",
                    );
                }

                $poItem = $item->purchaseOrderItem;
                $poItem->increment('quantity_received', $item->quantity_received);
            }

            $locked->update([
                'status' => GoodsReceiptStatus::Completed,
                'completed_at' => now(),
                'completed_by_user_id' => $actor->id,
                'completion_idempotency_key' => $idempotencyKey,
            ]);

            $this->purchaseOrders->refreshReceiptStatus($locked->purchaseOrder);
            $this->audit->log($actor, 'receiving.completed', $locked, null, [
                'receipt_number' => $locked->receipt_number,
                'purchase_order_id' => $locked->purchase_order_id,
            ]);

            return $locked->fresh(['items', 'serials']);
        });
    }

    private function releaseSerializedStock(GoodsReceipt $receipt, $item, User $actor): void
    {
        foreach ($item->serials as $grSerial) {
            if ($grSerial->validation_status !== GoodsReceiptSerialValidationStatus::Valid) {
                throw ValidationException::withMessages([
                    'serials' => "Serial {$grSerial->serial_number} is not valid for release.",
                ]);
            }

            try {
                $serial = InventorySerial::query()->create([
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'serial_number' => $grSerial->serial_number,
                    'branch_id' => $receipt->branch_id,
                    'status' => InventorySerialStatus::Available,
                    'unit_cost' => $item->purchaseOrderItem->unit_cost,
                    'vendor_id' => $receipt->vendor_id,
                    'purchase_order_id' => $receipt->purchase_order_id,
                    'goods_receipt_id' => $receipt->id,
                ]);
            } catch (UniqueConstraintViolationException|QueryException $exception) {
                if (! $exception instanceof UniqueConstraintViolationException
                    && ! str_contains(strtolower($exception->getMessage()), 'unique')) {
                    throw $exception;
                }

                throw ValidationException::withMessages([
                    'serials' => "Serial {$grSerial->serial_number} already exists in inventory.",
                ]);
            }

            $this->stock->recordMovement(
                type: InventoryMovementType::PurchaseReceipt,
                product: $item->product,
                branch: $receipt->branch,
                qty: 1,
                actor: $actor,
                variant: $item->variant,
                serial: $serial,
                toStatus: InventorySerialStatus::Available,
                notes: "Goods receipt {$receipt->receipt_number}",
                goodsReceiptId: $receipt->id,
            );

            $grSerial->update(['inventory_serial_id' => $serial->id]);
            $this->audit->log($actor, 'inventory.availability_transition', $serial, null, [
                'status' => InventorySerialStatus::Available->value,
                'goods_receipt_id' => $receipt->id,
            ]);
        }

        // Adjust balance for serialized items
        $balanceKey = \App\Models\InventoryStockBalance::keyFor($item->product_id, $item->variant_id, $receipt->branch_id);
        $balance = \App\Models\InventoryStockBalance::query()->firstOrCreate(
            ['balance_key' => $balanceKey],
            [
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'branch_id' => $receipt->branch_id,
                'available_qty' => 0,
                'reserved_qty' => 0,
            ],
        );
        $balance->increment('available_qty', $item->quantity_received);
    }
}
