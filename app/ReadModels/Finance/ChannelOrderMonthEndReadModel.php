<?php

namespace App\ReadModels\Finance;

use App\Enums\CommerceOrderStatus;
use App\Models\CommerceOrder;
use App\Support\Finance\ReportPeriod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ChannelOrderMonthEndReadModel
{
    /**
     * @return LengthAwarePaginator<int, CommerceOrder>
     */
    public function paginate(Request $request, int $perPage = 25): LengthAwarePaginator
    {
        return $this->filteredQuery($request)
            ->with('items')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return list<CommerceOrder>
     */
    public function exportOrders(Request $request): array
    {
        return $this->filteredQuery($request)
            ->with('items')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    public function eligibilitySummary(Request $request): array
    {
        $orders = $this->filteredQuery($request)->get();

        $received = $orders->count();
        $eligibleUninvoiced = $orders->filter(function (CommerceOrder $order): bool {
            return $order->invoice_eligible
                && $order->status !== CommerceOrderStatus::Invoiced
                && $order->statutory_invoice_id === null;
        })->count();
        $ineligible = $orders->filter(fn (CommerceOrder $order): bool => ! $order->invoice_eligible)->count();
        $invoiced = $orders->filter(
            fn (CommerceOrder $order): bool => $order->status === CommerceOrderStatus::Invoiced || $order->statutory_invoice_id !== null
        )->count();

        return [
            'received' => $received,
            'eligible_uninvoiced' => $eligibleUninvoiced,
            'ineligible' => $ineligible,
            'invoiced' => $invoiced,
        ];
    }

    /**
     * @return list<string>
     */
    public function orderHeaders(): array
    {
        return [
            'order_no',
            'received_at',
            'ordered_at',
            'paid_at',
            'source_channel',
            'source_type',
            'source_transaction_id',
            'source_order_id',
            'payment_status',
            'payment_mode',
            'payment_reference',
            'payment_provider',
            'customer',
            'buyer_gstin',
            'seller_gstin',
            'place_of_supply',
            'hsn_sac',
            'taxable_value',
            'tax_total',
            'order_value',
            'invoice_eligible',
            'status',
            'status_reason',
            'statutory_invoice_id',
        ];
    }

    /**
     * @return list<string>
     */
    public function orderRow(CommerceOrder $order): array
    {
        $hsn = $order->items->pluck('hsn_sac')->filter()->unique()->implode(', ');

        return [
            (string) $order->order_no,
            $this->timestamp($order->received_at),
            $this->timestamp($order->ordered_at),
            $this->timestamp($order->paid_at),
            $order->channel instanceof \BackedEnum ? $order->channel->value : (string) $order->channel,
            (string) $order->source_type,
            (string) $order->source_id,
            (string) ($order->source_order_id ?? ''),
            (string) $order->payment_status,
            (string) ($order->payment_method ?? ''),
            (string) ($order->payment_reference ?? ''),
            (string) ($order->payment_provider ?? ''),
            (string) ($order->customer_name ?? ''),
            (string) ($order->buyer_gstin ?? ''),
            (string) ($order->seller_gstin ?? ''),
            (string) ($order->place_of_supply_state ?? ''),
            $hsn,
            $this->nullableMoney($order->taxable_value),
            $this->nullableMoney($order->tax_total),
            $this->nullableMoney($order->order_value),
            $order->invoice_eligible ? 'yes' : 'no',
            $order->status instanceof \BackedEnum ? $order->status->value : (string) $order->status,
            (string) ($order->status_reason ?? ''),
            $order->statutory_invoice_id === null ? '' : (string) $order->statutory_invoice_id,
        ];
    }

    /**
     * @return list<list<string>>
     */
    public function orderRows(Request $request): array
    {
        return array_map(fn (CommerceOrder $order): array => $this->orderRow($order), $this->exportOrders($request));
    }

    /**
     * @return list<string>
     */
    public function collectionHeaders(): array
    {
        return [
            'document_kind',
            'document_number',
            'document_date',
            'source_channel',
            'source_transaction_id',
            'source_order_id',
            'payment_mode',
            'payment_reference',
            'amount',
            'status',
        ];
    }

    /**
     * @return list<list<string>>
     */
    public function collectionRows(Request $request): array
    {
        $rows = [];
        foreach ($this->exportOrders($request) as $order) {
            if (! $this->hasCollection($order)) {
                continue;
            }

            $rows[] = [
                'commerce_order',
                (string) $order->order_no,
                $this->timestamp($order->paid_at ?? $order->received_at),
                $order->channel instanceof \BackedEnum ? $order->channel->value : (string) $order->channel,
                (string) $order->source_id,
                (string) ($order->source_order_id ?? ''),
                (string) ($order->payment_method ?? ''),
                (string) ($order->payment_reference ?? ''),
                $this->nullableMoney($order->order_value),
                (string) $order->payment_status,
            ];
        }

        return $rows;
    }

    public function filteredQuery(Request $request): Builder
    {
        return CommerceOrder::query()
            ->tap(fn (Builder $q) => ReportPeriod::fromRequest($request)->apply($q, 'received_at'))
            ->when($request->filled('channel'), fn (Builder $q) => $q->where('channel', $request->string('channel')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), function (Builder $q) use ($request) {
                $search = $request->string('q')->trim()->toString();
                $q->where(function (Builder $inner) use ($search) {
                    $inner->where('order_no', 'like', '%'.$search.'%')
                        ->orWhere('source_id', 'like', '%'.$search.'%')
                        ->orWhere('source_order_id', 'like', '%'.$search.'%')
                        ->orWhere('customer_name', 'like', '%'.$search.'%')
                        ->orWhere('customer_phone', 'like', '%'.$search.'%')
                        ->orWhere('buyer_gstin', 'like', '%'.$search.'%')
                        ->orWhere('payment_reference', 'like', '%'.$search.'%');
                });
            });
    }

    private function hasCollection(CommerceOrder $order): bool
    {
        return $order->payment_status === 'paid'
            && (filled($order->payment_method) || filled($order->payment_reference));
    }

    private function timestamp(mixed $value): string
    {
        if (! $value instanceof \DateTimeInterface) {
            return '';
        }

        return $value->timezone((string) config('app.timezone'))->format('Y-m-d H:i');
    }

    private function nullableMoney(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return number_format((float) $value, 2, '.', '');
    }
}
