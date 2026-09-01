<?php

namespace App\ReadModels\Finance;

use App\Models\StatutoryInvoice;
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
     * @return list<array<string, mixed>>
     */
    public function gstSummary(Request $request): array
    {
        $invoices = $this->filteredQuery($request)->with('items')->get();

        $byRate = [];
        foreach ($invoices as $invoice) {
            if ($invoice->status->value === 'cancelled') {
                continue;
            }
            foreach ($invoice->items as $item) {
                $rate = number_format((float) $item->gst_percentage, 2, '.', '');
                $byRate[$rate] ??= [
                    'gst_percentage' => $rate,
                    'taxable_value' => 0.0,
                    'tax_total' => 0.0,
                    'cgst' => 0.0,
                    'sgst' => 0.0,
                    'igst' => 0.0,
                    'unclassified_tax' => 0.0,
                ];
                $byRate[$rate]['taxable_value'] += (float) $item->taxable_value;
                $byRate[$rate]['tax_total'] += (float) $item->tax_total;
                if ($item->cgst !== null || $item->sgst !== null || $item->igst !== null) {
                    $byRate[$rate]['cgst'] += (float) ($item->cgst ?? 0);
                    $byRate[$rate]['sgst'] += (float) ($item->sgst ?? 0);
                    $byRate[$rate]['igst'] += (float) ($item->igst ?? 0);
                } else {
                    $byRate[$rate]['unclassified_tax'] += (float) $item->tax_total;
                }
            }
        }

        ksort($byRate);

        return array_values($byRate);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function salesByChannel(Request $request): array
    {
        $rows = $this->filteredQuery($request)
            ->selectRaw('channel, status, count(*) as invoice_count, coalesce(sum(invoice_value), 0) as invoice_value, coalesce(sum(tax_total), 0) as tax_total')
            ->groupBy('channel', 'status')
            ->orderBy('channel')
            ->get();

        return $rows->map(function ($row): array {
            $channel = $row->channel;
            $status = $row->status;

            return [
                'channel' => $channel instanceof \BackedEnum ? $channel->value : (string) $channel,
                'status' => $status instanceof \BackedEnum ? $status->value : (string) $status,
                'invoice_count' => (int) $row->invoice_count,
                'invoice_value' => (float) $row->invoice_value,
                'tax_total' => (float) $row->tax_total,
            ];
        })->all();
    }

    /**
     * @return list<string>
     */
    public function registerHeaders(): array
    {
        return [
            'invoice_number',
            'invoice_date',
            'source_channel',
            'customer',
            'buyer_gstin',
            'branch',
            'seller_gstin',
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
            'source_transaction_id',
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
            $invoice->issued_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '',
            $invoice->channel->value,
            (string) ($invoice->buyer_name ?? ''),
            (string) ($invoice->buyer_gstin ?? ''),
            (string) ($invoice->branch?->name ?? $invoice->branch?->code ?? ''),
            (string) ($invoice->seller_gstin ?? ''),
            $hsn,
            number_format((float) $invoice->taxable_value, 2, '.', ''),
            $invoice->cgst === null ? '' : number_format((float) $invoice->cgst, 2, '.', ''),
            $invoice->sgst === null ? '' : number_format((float) $invoice->sgst, 2, '.', ''),
            $invoice->igst === null ? '' : number_format((float) $invoice->igst, 2, '.', ''),
            number_format((float) $invoice->tax_total, 2, '.', ''),
            number_format((float) $invoice->invoice_value, 2, '.', ''),
            (string) ($invoice->payment_reference ?? ''),
            (string) ($invoice->payment_method ?? ''),
            $invoice->status->value,
            (string) $invoice->source_id,
        ];
    }

    private function filteredQuery(Request $request): Builder
    {
        return StatutoryInvoice::query()
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
                        ->orWhere('buyer_gstin', 'like', '%'.$search.'%');
                });
            });
    }
}
