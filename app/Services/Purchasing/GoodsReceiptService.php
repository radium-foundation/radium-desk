<?php

namespace App\Services\Purchasing;

use App\Enums\GoodsReceiptSerialValidationStatus;
use App\Enums\GoodsReceiptStatus;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\GoodsReceiptSerial;
use App\Models\InventorySerial;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Support\Inventory\InventorySerialNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoodsReceiptService
{
    public function __construct(
        private readonly PurchasingNumberService $numbers,
        private readonly PurchasingAuditService $audit,
        private readonly PurchaseOrderService $purchaseOrders,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function createDraft(PurchaseOrder $po, array $lines, User $actor, ?string $receiptDate = null, ?string $challanRef = null, ?string $notes = null): GoodsReceipt
    {
        if (! $po->status->canReceive()) {
            throw ValidationException::withMessages([
                'purchase_order_id' => 'Goods can only be received against sent or partially received purchase orders.',
            ]);
        }

        return DB::transaction(function () use ($po, $lines, $actor, $receiptDate, $challanRef, $notes): GoodsReceipt {
            $receipt = GoodsReceipt::query()->create([
                'receipt_number' => $this->numbers->allocateGoodsReceiptNumber(),
                'receipt_date' => $receiptDate ?? now()->toDateString(),
                'purchase_order_id' => $po->id,
                'vendor_id' => $po->vendor_id,
                'branch_id' => $po->branch_id,
                'status' => GoodsReceiptStatus::Draft,
                'supplier_challan_reference' => $challanRef,
                'notes' => $notes,
                'received_by_user_id' => $actor->id,
            ]);

            foreach ($lines as $line) {
                $this->addLine($receipt, $po, $line);
            }

            $this->audit->log($actor, 'goods_receipt.created', $receipt, null, $receipt->toArray());

            return $receipt->fresh(['items.product', 'items.purchaseOrderItem']);
        });
    }

    /**
     * @param  list<string>|string  $serials
     */
    public function captureSerials(GoodsReceiptItem $item, array|string $serials, User $actor): GoodsReceipt
    {
        $receipt = $item->goodsReceipt;
        if ($receipt->status !== GoodsReceiptStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => 'Serials can only be captured on draft receipts.',
            ]);
        }

        $product = $item->product;
        if (! $product->is_serialized) {
            throw ValidationException::withMessages([
                'product_id' => "{$product->sku} is not serialised.",
            ]);
        }

        $numbers = InventorySerialNumber::parseList($serials);
        if ($numbers === []) {
            throw ValidationException::withMessages([
                'serials' => 'Enter at least one serial number.',
            ]);
        }

        return DB::transaction(function () use ($item, $receipt, $numbers, $actor): GoodsReceipt {
            $item->serials()->delete();

            foreach ($numbers as $number) {
                $validation = $this->validateSerial($number, $item, $receipt);

                GoodsReceiptSerial::query()->create([
                    'goods_receipt_id' => $receipt->id,
                    'goods_receipt_item_id' => $item->id,
                    'purchase_order_id' => $receipt->purchase_order_id,
                    'vendor_id' => $receipt->vendor_id,
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'serial_number' => $number,
                    'validation_status' => $validation['status'],
                    'validation_message' => $validation['message'],
                ]);

                if ($validation['status'] === GoodsReceiptSerialValidationStatus::Valid) {
                    $this->audit->log($actor, 'serial.received', $receipt, null, [
                        'serial_number' => $number,
                        'product_id' => $item->product_id,
                        'goods_receipt_item_id' => $item->id,
                    ]);
                }
            }

            return $receipt->fresh(['items.serials', 'serials']);
        });
    }

    public function submitForConfirmation(GoodsReceipt $receipt, User $actor): GoodsReceipt
    {
        if ($receipt->status !== GoodsReceiptStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => 'Only draft receipts can be submitted for confirmation.',
            ]);
        }

        $receipt->load(['items.product', 'items.serials']);
        $this->assertReceiptQuantitiesValid($receipt);

        return DB::transaction(function () use ($receipt, $actor): GoodsReceipt {
            $receipt->update(['status' => GoodsReceiptStatus::PendingConfirmation]);
            $this->audit->log($actor, 'goods_receipt.submitted', $receipt, ['status' => GoodsReceiptStatus::Draft->value], ['status' => GoodsReceiptStatus::PendingConfirmation->value]);

            return $receipt->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function addLine(GoodsReceipt $receipt, PurchaseOrder $po, array $line): GoodsReceiptItem
    {
        $poItem = PurchaseOrderItem::query()
            ->where('purchase_order_id', $po->id)
            ->findOrFail((int) $line['purchase_order_item_id']);

        if ((int) $poItem->product_id !== (int) ($line['product_id'] ?? $poItem->product_id)) {
            throw ValidationException::withMessages([
                'lines' => 'Receipt line product does not match purchase order item.',
            ]);
        }

        $qtyReceived = (int) ($line['quantity_received'] ?? 0);
        $qtyDamaged = (int) ($line['quantity_damaged'] ?? 0);

        if ($qtyReceived < 1) {
            throw ValidationException::withMessages([
                'lines' => 'Received quantity must be at least 1.',
            ]);
        }

        $remaining = $poItem->quantityRemaining();
        if ($qtyReceived > $remaining) {
            throw ValidationException::withMessages([
                'lines' => "Cannot receive {$qtyReceived} units for {$poItem->sku}; only {$remaining} remaining on PO.",
            ]);
        }

        return GoodsReceiptItem::query()->create([
            'goods_receipt_id' => $receipt->id,
            'purchase_order_item_id' => $poItem->id,
            'product_id' => $poItem->product_id,
            'variant_id' => $poItem->variant_id,
            'quantity_received' => $qtyReceived,
            'quantity_damaged' => $qtyDamaged,
            'quantity_short' => max(0, $remaining - $qtyReceived),
        ]);
    }

    private function assertReceiptQuantitiesValid(GoodsReceipt $receipt): void
    {
        foreach ($receipt->items as $item) {
            if ($item->product->is_serialized) {
                $validSerialCount = $item->serials
                    ->where('validation_status', GoodsReceiptSerialValidationStatus::Valid)
                    ->count();

                if ($validSerialCount !== $item->quantity_received) {
                    throw ValidationException::withMessages([
                        'serials' => "Serialized product {$item->product->sku} requires exactly {$item->quantity_received} valid serial(s); found {$validSerialCount}.",
                    ]);
                }
            }
        }
    }

    /**
     * @return array{status: GoodsReceiptSerialValidationStatus, message: ?string}
     */
    private function validateSerial(string $number, GoodsReceiptItem $item, GoodsReceipt $receipt): array
    {
        if ($number === '') {
            return ['status' => GoodsReceiptSerialValidationStatus::Blank, 'message' => 'Serial is blank.'];
        }

        $duplicateInReceipt = GoodsReceiptSerial::query()
            ->where('goods_receipt_id', $receipt->id)
            ->where('serial_number', $number)
            ->exists();

        if ($duplicateInReceipt) {
            return ['status' => GoodsReceiptSerialValidationStatus::DuplicateInReceipt, 'message' => 'Duplicate serial in this receipt.'];
        }

        $existing = InventorySerial::query()->where('serial_number', $number)->first();
        if ($existing !== null) {
            if ($existing->status->isAssignable() === false && $existing->status->value !== 'available') {
                return ['status' => GoodsReceiptSerialValidationStatus::AssignedElsewhere, 'message' => 'Serial is already assigned or sold.'];
            }

            return ['status' => GoodsReceiptSerialValidationStatus::AlreadyExists, 'message' => 'Serial already exists in Desk inventory.'];
        }

        return ['status' => GoodsReceiptSerialValidationStatus::Valid, 'message' => null];
    }
}
