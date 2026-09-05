<?php

namespace App\ReadModels\Finance;

use App\Models\StatutoryInvoice;
use App\Support\Finance\ReportPeriod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class StatutoryInvoiceRegisterReadModel
{
    /**
     * @return LengthAwarePaginator<int, StatutoryInvoice>
     */
    public function paginate(Request $request, int $perPage = 50): LengthAwarePaginator
    {
        return $this->filteredQuery($request)
            ->with(['items', 'branch', 'inventorySale'])
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return list<StatutoryInvoice>
     */
    public function exportRows(Request $request): array
    {
        return $this->filteredQuery($request)
            ->with(['items', 'branch', 'inventorySale'])
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function registerHeaders(): array
    {
        return [
            'invoice_number',
            'document_type',
            'invoice_date',
            'source_channel',
            'source_type',
            'source_transaction_id',
            'source_order_id',
            'customer',
            'buyer_phone',
            'buyer_gstin',
            'place_of_supply',
            'branch',
            'seller_gstin',
            'seller_name',
            'hsn_sac',
            'taxable_value',
            'cgst',
            'sgst',
            'igst',
            'tax_total',
            'total',
            'payment_reference',
            'payment_mode',
            'status',
            'cancelled_at',
            'cancel_reason',
            'pos_internal_receipt',
        ];
    }

    /**
     * @return list<string>
     */
    public function registerRow(StatutoryInvoice $invoice): array
    {
        $hsn = $invoice->items->pluck('hsn_sac')->filter()->unique()->implode(', ');

        return [
            (string) $invoice->invoice_number,
            $invoice->document_type->value,
            $this->timestamp($invoice->issued_at),
            $invoice->channel->value,
            (string) $invoice->source_type,
            (string) $invoice->source_id,
            (string) ($invoice->source_order_id ?? ''),
            (string) ($invoice->buyer_name ?? ''),
            (string) ($invoice->buyer_phone ?? ''),
            (string) ($invoice->buyer_gstin ?? ''),
            (string) ($invoice->place_of_supply_state ?? ''),
            (string) ($invoice->branch?->name ?? $invoice->branch?->code ?? ''),
            (string) ($invoice->seller_gstin ?? ''),
            (string) ($invoice->seller_name ?? ''),
            $hsn,
            $this->money($invoice->taxable_value),
            $this->nullableMoney($invoice->cgst),
            $this->nullableMoney($invoice->sgst),
            $this->nullableMoney($invoice->igst),
            $this->money($invoice->tax_total),
            $this->money($invoice->invoice_value),
            (string) ($invoice->payment_reference ?? ''),
            (string) ($invoice->payment_method ?? ''),
            $invoice->status->value,
            $this->timestamp($invoice->cancelled_at),
            (string) ($invoice->cancel_reason ?? ''),
            (string) ($invoice->inventorySale?->invoice_number ?? ''),
        ];
    }

    public function filteredQuery(Request $request): Builder
    {
        return StatutoryInvoice::query()
            ->tap(fn (Builder $q) => ReportPeriod::fromRequest($request)->apply($q, 'issued_at'))
            ->when($request->filled('channel'), fn (Builder $q) => $q->where('channel', $request->string('channel')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), function (Builder $q) use ($request) {
                $search = $request->string('q')->trim()->toString();
                $q->where(function (Builder $inner) use ($search) {
                    $inner->where('invoice_number', 'like', '%'.$search.'%')
                        ->orWhere('source_id', 'like', '%'.$search.'%')
                        ->orWhere('source_order_id', 'like', '%'.$search.'%')
                        ->orWhere('buyer_name', 'like', '%'.$search.'%')
                        ->orWhere('buyer_phone', 'like', '%'.$search.'%')
                        ->orWhere('buyer_gstin', 'like', '%'.$search.'%')
                        ->orWhere('payment_reference', 'like', '%'.$search.'%');
                });
            });
    }

    private function timestamp(mixed $value): string
    {
        if (! $value instanceof \DateTimeInterface) {
            return '';
        }

        return $value->timezone((string) config('app.timezone'))->format('Y-m-d H:i');
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function nullableMoney(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return $this->money($value);
    }
}
