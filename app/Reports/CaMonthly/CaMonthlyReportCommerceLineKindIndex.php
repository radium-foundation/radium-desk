<?php

namespace App\Reports\CaMonthly;

use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use Illuminate\Support\Collection;

/**
 * Maps statutory invoice lines to authoritative commerce shipping_line_kind values.
 */
final class CaMonthlyReportCommerceLineKindIndex
{
    /**
     * @param  iterable<int, StatutoryInvoice>  $invoices
     * @return array<int, array<int, ?string>>
     */
    public function lineKindsByInvoiceId(iterable $invoices): array
    {
        $invoiceList = [];
        $commerceKeys = [];

        foreach ($invoices as $invoice) {
            $invoiceList[$invoice->id] = $invoice;

            if ($this->sourceTypeValue($invoice) === StatutoryInvoiceSourceType::CommerceOrder->value) {
                $commerceKeys[] = [
                    'channel' => $invoice->channel->value,
                    'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
                    'source_id' => (string) $invoice->source_id,
                ];
            }
        }

        if ($invoiceList === []) {
            return [];
        }

        $commerceByKey = $this->loadCommerceOrders($commerceKeys);
        $resolved = [];

        foreach ($invoiceList as $invoice) {
            $items = $invoice->relationLoaded('items')
                ? $invoice->items
                : collect();

            $commerceItems = $this->commerceItemsForInvoice($invoice, $commerceByKey);
            $lineKinds = [];

            foreach ($items->sortBy('line_no') as $item) {
                $lineKinds[(int) $item->line_no] = $this->resolveLineKind($item, $commerceItems);
            }

            $resolved[$invoice->id] = $lineKinds;
        }

        return $resolved;
    }

    /**
     * @param  list<array{channel: string, source_type: string, source_id: string}>  $keys
     * @return array<string, CommerceOrder>
     */
    private function loadCommerceOrders(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $query = CommerceOrder::query()->with('items');
        $query->where(function ($builder) use ($keys): void {
            foreach ($keys as $key) {
                $builder->orWhere(function ($match) use ($key): void {
                    $match->where('channel', $key['channel'])
                        ->where('source_type', $key['source_type'])
                        ->where('source_id', $key['source_id']);
                });
            }
        });

        $indexed = [];
        foreach ($query->get() as $order) {
            $indexed[$this->commerceKey(
                (string) $order->channel->value,
                (string) $order->source_type,
                (string) $order->source_id,
            )] = $order;
        }

        return $indexed;
    }

    /**
     * @param  array<string, CommerceOrder>  $commerceByKey
     * @return Collection<int, CommerceOrderItem>
     */
    private function commerceItemsForInvoice(StatutoryInvoice $invoice, array $commerceByKey): Collection
    {
        if ($this->sourceTypeValue($invoice) !== StatutoryInvoiceSourceType::CommerceOrder->value) {
            return collect();
        }

        $order = $commerceByKey[$this->commerceKey(
            $invoice->channel->value,
            StatutoryInvoiceSourceType::CommerceOrder->value,
            (string) $invoice->source_id,
        )] ?? null;

        return $order?->items ?? collect();
    }

    /**
     * @param  Collection<int, CommerceOrderItem>  $commerceItems
     */
    private function resolveLineKind(StatutoryInvoiceItem $item, Collection $commerceItems): ?string
    {
        if ($commerceItems->isEmpty()) {
            return null;
        }

        $sku = $this->nullableString($item->sku);
        if ($sku !== null) {
            foreach ($commerceItems as $commerceItem) {
                if ($this->nullableString($commerceItem->sku) === $sku) {
                    return $this->nullableString($commerceItem->shipping_line_kind);
                }
            }
        }

        $sorted = $commerceItems->sortBy('id')->values();
        $index = max(0, (int) $item->line_no - 1);

        return $this->nullableString($sorted->get($index)?->shipping_line_kind);
    }

    private function commerceKey(string $channel, string $sourceType, string $sourceId): string
    {
        return $channel.'|'.$sourceType.'|'.$sourceId;
    }

    private function sourceTypeValue(StatutoryInvoice $invoice): string
    {
        $sourceType = $invoice->source_type;

        return $sourceType instanceof StatutoryInvoiceSourceType
            ? $sourceType->value
            : (string) $sourceType;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
