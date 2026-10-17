<?php

namespace App\Services\ReturnExchange;

use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\ReturnExchangeCase;
use App\Models\StatutoryInvoice;
use App\Services\ReturnExchange\Data\ResolvedOnlineHardwareReturnContext;
use App\Services\ReturnExchange\Data\ReturnedHardwareEligibleRefundAmount;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Derives the maximum refund request amount for returned online hardware from persisted
 * commerce line economics (not order payment_amount / display totals).
 */
final class ReturnExchangeCaseReturnedHardwareEligibleRefundService
{
    public function resolve(
        ReturnExchangeCase $case,
        ResolvedOnlineHardwareReturnContext $context,
        ?StatutoryInvoice $taxInvoice = null,
    ): ReturnedHardwareEligibleRefundAmount {
        $fulfilment = $context->fulfilment;
        if ($fulfilment === null) {
            throw ValidationException::withMessages([
                'hardware_fulfilment_id' => 'Hardware fulfilment is required to calculate a returned-hardware refund amount.',
            ]);
        }

        $fulfilment->loadMissing(['serials.commerceOrderItem', 'commerceOrder.items']);
        $commerceOrder = $fulfilment->commerceOrder;
        if ($commerceOrder === null) {
            throw ValidationException::withMessages([
                'order' => 'Commerce order for this fulfilment was not found.',
            ]);
        }

        $returnedQtyByItemId = $this->returnedQuantityByCommerceItemId($case, $fulfilment);
        if ($returnedQtyByItemId === []) {
            throw ValidationException::withMessages([
                'lines' => 'Returned hardware could not be mapped to commerce order lines.',
            ]);
        }

        $eligible = 0.0;
        foreach ($returnedQtyByItemId as $itemId => $returnedQty) {
            $item = $commerceOrder->items->firstWhere('id', $itemId);
            if ($item === null) {
                throw ValidationException::withMessages([
                    'lines' => 'Returned commerce line #'.$itemId.' is not on the fulfilment order.',
                ]);
            }

            if ($returnedQty > (int) $item->qty) {
                throw ValidationException::withMessages([
                    'lines' => 'Return quantity exceeds commerce line qty for SKU '.$item->sku.'.',
                ]);
            }

            $eligible += $this->lineAmountForReturnedQty($item, $returnedQty);
        }

        $eligible = round($eligible, 2);
        if ($eligible < 0.01) {
            throw ValidationException::withMessages([
                'amount' => 'Eligible returned-hardware refund amount could not be determined from order lines.',
            ]);
        }

        if ($taxInvoice !== null) {
            $eligible = min($eligible, $this->capFromStatutoryInvoiceItems($taxInvoice, $returnedQtyByItemId, $commerceOrder->items));
        }

        $orderPaymentCap = round((float) ($context->order->payment_amount ?? 0), 2);
        $isPartial = $orderPaymentCap > 0.01 && $eligible < ($orderPaymentCap - 0.001);

        return new ReturnedHardwareEligibleRefundAmount(
            amount: number_format($eligible, 2, '.', ''),
            isPartialRelativeToOrderPayment: $isPartial,
        );
    }

    /**
     * @return array<int, int>
     */
    private function returnedQuantityByCommerceItemId(ReturnExchangeCase $case, HardwareFulfilment $fulfilment): array
    {
        $case->loadMissing(['lines', 'receipts']);

        $qtyByItem = [];
        $serials = $fulfilment->serials;

        if ($serials->isNotEmpty()) {
            $inspectedReceipts = $case->receipts->filter(fn ($receipt): bool => $receipt->inspected_at !== null);

            foreach ($inspectedReceipts as $receipt) {
                $serialId = $receipt->inventory_serial_id;
                if ($serialId !== null) {
                    $allocation = $serials->first(
                        fn ($row): bool => (int) $row->inventory_serial_id === (int) $serialId,
                    );

                    if ($allocation === null || $allocation->commerce_order_item_id === null) {
                        throw ValidationException::withMessages([
                            'serials' => 'Receipt serial #'.$serialId.' is not allocated on this fulfilment.',
                        ]);
                    }

                    $itemId = (int) $allocation->commerce_order_item_id;
                    $qtyByItem[$itemId] = ($qtyByItem[$itemId] ?? 0) + max(1, (int) $receipt->qty_received);

                    continue;
                }

                if ($serials->count() === 1 && $inspectedReceipts->count() === 1) {
                    $allocation = $serials->first();
                    if ($allocation?->commerce_order_item_id === null) {
                        throw ValidationException::withMessages([
                            'serials' => 'Fulfilment serial row is not linked to a commerce order line.',
                        ]);
                    }

                    $itemId = (int) $allocation->commerce_order_item_id;
                    $qtyByItem[$itemId] = ($qtyByItem[$itemId] ?? 0) + max(1, (int) $receipt->qty_received);

                    continue;
                }

                throw ValidationException::withMessages([
                    'serials' => 'Serialized fulfilment returns require inventory serial linkage on receipt.',
                ]);
            }

            return $qtyByItem;
        }

        $caseQty = (int) $case->lines->sum('qty');
        if ($caseQty < 1) {
            return [];
        }

        $hardwareItems = $fulfilment->commerceOrder?->items
            ?? CommerceOrderItem::query()->where('commerce_order_id', $fulfilment->commerce_order_id)->get();

        $productLines = $hardwareItems->filter(
            fn (CommerceOrderItem $item): bool => $item->requires_shipping !== false
                && ($item->shipping_line_kind === null || $item->shipping_line_kind === 'product'),
        );

        if ($productLines->count() === 1) {
            $itemId = (int) $productLines->first()->id;
            $qtyByItem[$itemId] = $caseQty;

            return $qtyByItem;
        }

        if ($productLines->count() > 1) {
            throw ValidationException::withMessages([
                'lines' => 'Non-serialized fulfilment with multiple product lines requires serialized receipts to allocate refund amount.',
            ]);
        }

        throw ValidationException::withMessages([
            'lines' => 'No shippable commerce product lines found for this fulfilment.',
        ]);
    }

    private function lineAmountForReturnedQty(CommerceOrderItem $item, int $returnedQty): float
    {
        $lineQty = max(1, (int) $item->qty);
        $lineTotal = round((float) $item->line_total, 2);

        return round(($lineTotal / $lineQty) * $returnedQty, 2);
    }

    /**
     * @param  array<int, int>  $returnedQtyByItemId
     * @param  Collection<int, CommerceOrderItem>  $items
     */
    private function capFromStatutoryInvoiceItems(
        StatutoryInvoice $invoice,
        array $returnedQtyByItemId,
        Collection $items,
    ): float {
        $invoice->loadMissing('items');
        if ($invoice->items->isEmpty()) {
            return PHP_FLOAT_MAX;
        }

        $cap = 0.0;
        foreach ($returnedQtyByItemId as $itemId => $returnedQty) {
            $commerceItem = $items->firstWhere('id', $itemId);
            if ($commerceItem === null) {
                continue;
            }

            $matches = $invoice->items->filter(function ($invoiceItem) use ($commerceItem): bool {
                $sku = trim((string) ($commerceItem->sku ?? ''));
                $catalog = trim((string) ($commerceItem->catalog_sku ?? ''));
                $invoiceSku = trim((string) ($invoiceItem->sku ?? $invoiceItem->description ?? ''));

                return $sku !== '' && strcasecmp($invoiceSku, $sku) === 0
                    || ($catalog !== '' && strcasecmp($invoiceSku, $catalog) === 0);
            });

            if ($matches->count() !== 1) {
                throw ValidationException::withMessages([
                    'tax_evidence' => 'Statutory invoice line mapping is ambiguous for SKU '.$commerceItem->sku.'. Finance review is required.',
                ]);
            }

            $invoiceLine = $matches->first();
            $lineQty = max(1, (int) ($invoiceLine->qty ?? 1));
            $lineTotal = round((float) $invoiceLine->line_total, 2);
            $cap += round(($lineTotal / $lineQty) * $returnedQty, 2);
        }

        return round($cap, 2);
    }
}
