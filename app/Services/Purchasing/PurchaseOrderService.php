<?php

namespace App\Services\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventoryProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    public function __construct(
        private readonly PurchasingNumberService $numbers,
        private readonly PurchasingAuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $lines
     */
    public function createDraft(array $header, array $lines, User $actor): PurchaseOrder
    {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'Add at least one product line.',
            ]);
        }

        return DB::transaction(function () use ($header, $lines, $actor): PurchaseOrder {
            $vendor = Vendor::query()->findOrFail((int) $header['vendor_id']);
            $branch = InventoryBranch::query()->findOrFail((int) $header['branch_id']);

            $po = PurchaseOrder::query()->create([
                'po_number' => $this->numbers->allocatePurchaseOrderNumber(),
                'vendor_id' => $vendor->id,
                'branch_id' => $branch->id,
                'po_date' => $header['po_date'] ?? now()->toDateString(),
                'expected_delivery_date' => $header['expected_delivery_date'] ?? null,
                'status' => PurchaseOrderStatus::Draft,
                'notes' => filled($header['notes'] ?? null) ? trim((string) $header['notes']) : null,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);

            $this->syncLines($po, $lines);
            $this->recalculateTotals($po);
            $this->audit->log($actor, 'purchase_order.created', $po, null, $po->fresh(['items'])->toArray());

            return $po->fresh(['items.product', 'vendor', 'branch']);
        });
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $lines
     */
    public function updateDraft(PurchaseOrder $po, array $header, array $lines, User $actor): PurchaseOrder
    {
        if (! $po->status->canEdit()) {
            throw ValidationException::withMessages([
                'status' => 'Only draft purchase orders can be edited.',
            ]);
        }

        return DB::transaction(function () use ($po, $header, $lines, $actor): PurchaseOrder {
            $old = $po->toArray();
            $po->update([
                'vendor_id' => (int) $header['vendor_id'],
                'branch_id' => (int) $header['branch_id'],
                'po_date' => $header['po_date'] ?? $po->po_date,
                'expected_delivery_date' => $header['expected_delivery_date'] ?? null,
                'notes' => filled($header['notes'] ?? null) ? trim((string) $header['notes']) : null,
                'updated_by_user_id' => $actor->id,
            ]);

            $po->items()->delete();
            $this->syncLines($po, $lines);
            $this->recalculateTotals($po);
            $this->audit->log($actor, 'purchase_order.updated', $po, $old, $po->fresh(['items'])->toArray());

            return $po->fresh(['items.product', 'vendor', 'branch']);
        });
    }

    public function send(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        if ($po->status !== PurchaseOrderStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => 'Only draft purchase orders can be sent.',
            ]);
        }

        return DB::transaction(function () use ($po, $actor): PurchaseOrder {
            $oldStatus = $po->status->value;
            $po->update([
                'status' => PurchaseOrderStatus::Sent,
                'sent_at' => now(),
                'updated_by_user_id' => $actor->id,
            ]);
            $this->audit->log($actor, 'purchase_order.status_changed', $po, ['status' => $oldStatus], ['status' => PurchaseOrderStatus::Sent->value]);

            return $po->fresh();
        });
    }

    public function cancel(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        if (! $po->status->canCancel()) {
            throw ValidationException::withMessages([
                'status' => 'This purchase order cannot be cancelled.',
            ]);
        }

        return DB::transaction(function () use ($po, $actor): PurchaseOrder {
            $oldStatus = $po->status->value;
            $po->update([
                'status' => PurchaseOrderStatus::Cancelled,
                'cancelled_at' => now(),
                'updated_by_user_id' => $actor->id,
            ]);
            $this->audit->log($actor, 'purchase_order.status_changed', $po, ['status' => $oldStatus], ['status' => PurchaseOrderStatus::Cancelled->value]);

            return $po->fresh();
        });
    }

    public function refreshReceiptStatus(PurchaseOrder $po): PurchaseOrder
    {
        $po->load('items');
        $ordered = $po->items->sum('quantity_ordered');
        $received = $po->items->sum('quantity_received');

        if ($received <= 0) {
            return $po;
        }

        $newStatus = match (true) {
            $received >= $ordered => PurchaseOrderStatus::Received,
            default => PurchaseOrderStatus::PartiallyReceived,
        };

        if ($po->status === PurchaseOrderStatus::Sent || $po->status === PurchaseOrderStatus::PartiallyReceived || $po->status === PurchaseOrderStatus::Received) {
            $po->update(['status' => $newStatus]);
        }

        return $po->fresh();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function syncLines(PurchaseOrder $po, array $lines): void
    {
        foreach ($lines as $line) {
            $product = InventoryProduct::query()->findOrFail((int) $line['product_id']);
            $variant = filled($line['variant_id'] ?? null)
                ? InventoryProductVariant::query()->findOrFail((int) $line['variant_id'])
                : null;

            if ($variant !== null && $variant->product_id !== $product->id) {
                throw ValidationException::withMessages([
                    'lines' => "Variant does not belong to product {$product->sku}.",
                ]);
            }

            $qty = (int) ($line['quantity'] ?? $line['quantity_ordered'] ?? 0);
            if ($qty < 1) {
                throw ValidationException::withMessages([
                    'lines' => 'Line quantity must be at least 1.',
                ]);
            }

            $unitCost = (float) ($line['unit_cost'] ?? $product->unit_cost ?? 0);
            $taxRate = (float) ($line['tax_rate'] ?? $product->gst_percentage ?? 0);
            $discount = (float) ($line['discount_amount'] ?? 0);
            $taxable = max(0, ($qty * $unitCost) - $discount);
            $taxAmount = round($taxable * ($taxRate / 100), 2);
            $lineTotal = round($taxable + $taxAmount, 2);

            PurchaseOrderItem::query()->create([
                'purchase_order_id' => $po->id,
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'sku' => $variant?->sku ?? $product->sku,
                'quantity_ordered' => $qty,
                'unit_cost' => $unitCost,
                'tax_rate' => $taxRate,
                'discount_amount' => $discount,
                'line_total' => $lineTotal,
            ]);
        }
    }

    private function recalculateTotals(PurchaseOrder $po): void
    {
        $po->load('items');
        $subtotal = 0.0;
        $taxTotal = 0.0;
        $discountTotal = 0.0;

        foreach ($po->items as $item) {
            $discountTotal += (float) $item->discount_amount;
            $taxable = max(0, ($item->quantity_ordered * (float) $item->unit_cost) - (float) $item->discount_amount);
            $subtotal += $taxable;
            $taxTotal += round($taxable * ((float) $item->tax_rate / 100), 2);
        }

        $po->update([
            'subtotal' => round($subtotal, 2),
            'tax_total' => round($taxTotal, 2),
            'discount_total' => round($discountTotal, 2),
            'grand_total' => round($subtotal + $taxTotal, 2),
        ]);
    }
}
