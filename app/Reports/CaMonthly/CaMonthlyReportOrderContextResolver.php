<?php

namespace App\Reports\CaMonthly;

use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\InventorySale;
use App\Models\Order;
use App\Models\ServiceOrder;
use App\Models\StatutoryInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class CaMonthlyReportOrderContextResolver
{
    /**
     * @param  iterable<int, StatutoryInvoice>  $invoices
     * @return array<int, CaMonthlyReportOrderContext>
     */
    public function resolveForInvoices(iterable $invoices): array
    {
        $invoiceList = [];
        foreach ($invoices as $invoice) {
            $invoiceList[$invoice->id] = $invoice;
        }

        if ($invoiceList === []) {
            return [];
        }

        $commerceKeys = [];
        $inventorySaleIds = [];
        $serviceOrderIds = [];
        $serviceOrderNumbers = [];
        $supportOrderIds = [];

        foreach ($invoiceList as $invoice) {
            $sourceType = $this->sourceTypeValue($invoice);

            if ($sourceType === StatutoryInvoiceSourceType::CommerceOrder->value) {
                $commerceKeys[] = [
                    'channel' => $invoice->channel->value,
                    'source_type' => $sourceType,
                    'source_id' => (string) $invoice->source_id,
                ];
            }

            if ($invoice->inventory_sale_id !== null) {
                $inventorySaleIds[] = (int) $invoice->inventory_sale_id;
            }

            if ($sourceType === StatutoryInvoiceSourceType::ServiceOrder->value) {
                $sourceId = trim((string) $invoice->source_id);
                if ($sourceId !== '' && ctype_digit($sourceId)) {
                    $serviceOrderIds[] = (int) $sourceId;
                } elseif ($sourceId !== '') {
                    $serviceOrderNumbers[] = $sourceId;
                }
            }

            if ($invoice->support_order_id !== null) {
                $supportOrderIds[] = (int) $invoice->support_order_id;
            } elseif ($sourceType === StatutoryInvoiceSourceType::SupportOrder->value) {
                $supportOrderIds[] = (int) $invoice->source_id;
            }
        }

        $commerceByKey = $this->loadCommerceOrders($commerceKeys);
        $salesById = InventorySale::query()
            ->whereIn('id', array_values(array_unique($inventorySaleIds)))
            ->get()
            ->keyBy('id');
        $serviceOrdersById = ServiceOrder::query()
            ->whereIn('id', array_values(array_unique($serviceOrderIds)))
            ->get()
            ->keyBy('id');
        $serviceOrdersByNumber = ServiceOrder::query()
            ->whereIn('order_number', array_values(array_unique($serviceOrderNumbers)))
            ->get()
            ->keyBy('order_number');
        $supportOrdersById = Order::query()
            ->whereIn('id', array_values(array_unique($supportOrderIds)))
            ->get()
            ->keyBy('id');

        $resolved = [];
        foreach ($invoiceList as $invoice) {
            $resolved[$invoice->id] = $this->resolveOne(
                $invoice,
                $commerceByKey,
                $salesById,
                $serviceOrdersById,
                $serviceOrdersByNumber,
                $supportOrdersById,
            );
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

        $query = CommerceOrder::query()->where(function ($outer) use ($keys): void {
            foreach ($keys as $key) {
                $outer->orWhere(function ($inner) use ($key): void {
                    $inner->where('channel', $key['channel'])
                        ->where('source_type', $key['source_type'])
                        ->where('source_id', $key['source_id']);
                });
            }
        });

        $indexed = [];
        foreach ($query->get() as $order) {
            $indexed[$this->commerceKey(
                $order->channel->value,
                $order->source_type,
                (string) $order->source_id,
            )] = $order;
        }

        return $indexed;
    }

    /**
     * @param  array<string, CommerceOrder>  $commerceByKey
     * @param  Collection<int, InventorySale>  $salesById
     * @param  Collection<int, ServiceOrder>  $serviceOrdersById
     * @param  Collection<string, ServiceOrder>  $serviceOrdersByNumber
     * @param  Collection<int, Order>  $supportOrdersById
     */
    private function resolveOne(
        StatutoryInvoice $invoice,
        array $commerceByKey,
        $salesById,
        $serviceOrdersById,
        $serviceOrdersByNumber,
        $supportOrdersById,
    ): CaMonthlyReportOrderContext {
        $orderDate = null;
        $orderId = $this->nullableString($invoice->source_order_id)
            ?? $this->nullableString($invoice->source_id);

        $sourceType = $this->sourceTypeValue($invoice);

        if ($sourceType === StatutoryInvoiceSourceType::CommerceOrder->value) {
            $commerce = $commerceByKey[$this->commerceKey(
                $invoice->channel->value,
                $sourceType,
                (string) $invoice->source_id,
            )] ?? null;
            if ($commerce !== null) {
                $orderDate = $this->formatDate($commerce->ordered_at ?? $commerce->paid_at ?? $commerce->received_at);
                $orderId = $this->nullableString($commerce->source_order_id)
                    ?? $this->nullableString($commerce->source_id)
                    ?? $orderId;
            }
        }

        if ($invoice->inventory_sale_id !== null) {
            $sale = $salesById->get((int) $invoice->inventory_sale_id);
            if ($sale !== null) {
                $orderDate = $this->formatDate($sale->completed_at ?? $sale->created_at);
                $orderId = $this->nullableString($sale->sale_no) ?? $orderId;
            }
        }

        if ($sourceType === StatutoryInvoiceSourceType::ServiceOrder->value) {
            $serviceOrder = $this->resolveServiceOrder($invoice, $serviceOrdersById, $serviceOrdersByNumber);
            if ($serviceOrder !== null) {
                $orderDate = $this->formatDate($serviceOrder->created_at);
                $orderId = $this->nullableString($serviceOrder->order_number) ?? $orderId;
            }
        }

        $supportId = $invoice->support_order_id ?? (
            $sourceType === StatutoryInvoiceSourceType::SupportOrder->value
                ? (int) $invoice->source_id
                : null
        );
        if ($supportId !== null) {
            $supportOrder = $supportOrdersById->get((int) $supportId);
            if ($supportOrder !== null) {
                $orderDate = $this->formatDate($supportOrder->created_at);
                $orderId = $this->nullableString($supportOrder->order_id) ?? $orderId;
            }
        }

        return new CaMonthlyReportOrderContext($orderDate, $orderId);
    }

    /**
     * @param  Collection<int, ServiceOrder>  $serviceOrdersById
     * @param  Collection<string, ServiceOrder>  $serviceOrdersByNumber
     */
    private function resolveServiceOrder(
        StatutoryInvoice $invoice,
        $serviceOrdersById,
        $serviceOrdersByNumber,
    ): ?ServiceOrder {
        $sourceId = trim((string) $invoice->source_id);
        if ($sourceId === '') {
            return null;
        }

        if (ctype_digit($sourceId)) {
            $byId = $serviceOrdersById->get((int) $sourceId);
            if ($byId !== null) {
                return $byId;
            }
        }

        return $serviceOrdersByNumber->get($sourceId);
    }

    private function sourceTypeValue(StatutoryInvoice $invoice): string
    {
        $sourceType = $invoice->source_type;

        if ($sourceType instanceof StatutoryInvoiceSourceType) {
            return $sourceType->value;
        }

        return (string) $sourceType;
    }

    private function commerceKey(string $channel, string $sourceType, string $sourceId): string
    {
        return $channel.'|'.$sourceType.'|'.$sourceId;
    }

    private function formatDate(mixed $value): ?string
    {
        if (! $value instanceof \DateTimeInterface) {
            return null;
        }

        return Carbon::instance($value)
            ->timezone((string) config('app.timezone'))
            ->format('Y-m-d');
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
