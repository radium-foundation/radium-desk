<?php

namespace App\ReadModels\Finance;

use App\Enums\StatutoryInvoiceStatus;
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
     * @return list<StatutoryInvoice>
     */
    public function cancelledInvoices(Request $request): array
    {
        return $this->filteredQuery($request)
            ->where('status', StatutoryInvoiceStatus::Cancelled->value)
            ->with(['items', 'branch', 'inventorySale'])
            ->orderByDesc('cancelled_at')
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
            if ($invoice->status === StatutoryInvoiceStatus::Cancelled) {
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
                    'has_classified_split' => false,
                ];
                $byRate[$rate]['taxable_value'] += (float) $item->taxable_value;
                $byRate[$rate]['tax_total'] += (float) $item->tax_total;
                if ($item->cgst !== null || $item->sgst !== null || $item->igst !== null) {
                    $byRate[$rate]['has_classified_split'] = true;
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
        return $this->rollUpSales($this->filteredQuery($request)->orderBy('id')->get()->all(), includeDate: false);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function salesByDateAndChannel(Request $request): array
    {
        return $this->rollUpSales(
            $this->filteredQuery($request)->orderBy('issued_at')->orderBy('id')->get()->all(),
            includeDate: true,
        );
    }

    /**
     * @return array<string, float|int>
     */
    public function monthEndSummary(Request $request): array
    {
        $invoices = $this->filteredQuery($request)->get();

        $issuedCount = 0;
        $issuedTaxable = 0.0;
        $issuedTax = 0.0;
        $issuedValue = 0.0;
        $cancelledCount = 0;
        $cancelledValue = 0.0;
        $unclassifiedTax = 0.0;
        $collectionCount = 0;
        $collectionValue = 0.0;

        foreach ($invoices as $invoice) {
            if ($invoice->status === StatutoryInvoiceStatus::Cancelled) {
                $cancelledCount++;
                $cancelledValue += (float) $invoice->invoice_value;

                continue;
            }

            $issuedCount++;
            $issuedTaxable += (float) $invoice->taxable_value;
            $issuedTax += (float) $invoice->tax_total;
            $issuedValue += (float) $invoice->invoice_value;
            if ($invoice->cgst === null && $invoice->sgst === null && $invoice->igst === null) {
                $unclassifiedTax += (float) $invoice->tax_total;
            }
            if ($this->hasCollection($invoice)) {
                $collectionCount++;
                $collectionValue += (float) $invoice->invoice_value;
            }
        }

        return [
            'issued_count' => $issuedCount,
            'issued_taxable_value' => $issuedTaxable,
            'issued_tax_total' => $issuedTax,
            'issued_invoice_value' => $issuedValue,
            'cancelled_count' => $cancelledCount,
            'cancelled_invoice_value' => $cancelledValue,
            'unclassified_tax_total' => $unclassifiedTax,
            'collection_count' => $collectionCount,
            'collection_invoice_value' => $collectionValue,
        ];
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

    /**
     * @return list<string>
     */
    public function lineHeaders(): array
    {
        return [
            'invoice_number',
            'invoice_date',
            'source_channel',
            'source_type',
            'source_transaction_id',
            'source_order_id',
            'status',
            'line_no',
            'sku',
            'description',
            'hsn_sac',
            'qty',
            'unit_price',
            'discount',
            'gst_percentage',
            'taxable_value',
            'cgst',
            'sgst',
            'igst',
            'tax_total',
            'line_total',
            'buyer_gstin',
            'seller_gstin',
        ];
    }

    /**
     * @return list<list<string>>
     */
    public function lineRows(Request $request): array
    {
        $rows = [];
        foreach ($this->exportRows($request) as $invoice) {
            foreach ($invoice->items as $item) {
                $rows[] = [
                    (string) $invoice->invoice_number,
                    $this->timestamp($invoice->issued_at),
                    $invoice->channel->value,
                    (string) $invoice->source_type,
                    (string) $invoice->source_id,
                    (string) ($invoice->source_order_id ?? ''),
                    $invoice->status->value,
                    (string) $item->line_no,
                    (string) ($item->sku ?? ''),
                    (string) $item->description,
                    (string) ($item->hsn_sac ?? ''),
                    (string) $item->qty,
                    $this->money($item->unit_price),
                    $this->money($item->discount),
                    $this->money($item->gst_percentage),
                    $this->money($item->taxable_value),
                    $this->nullableMoney($item->cgst),
                    $this->nullableMoney($item->sgst),
                    $this->nullableMoney($item->igst),
                    $this->money($item->tax_total),
                    $this->money($item->line_total),
                    (string) ($invoice->buyer_gstin ?? ''),
                    (string) ($invoice->seller_gstin ?? ''),
                ];
            }
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    public function gstHeaders(): array
    {
        return [
            'gst_percentage',
            'taxable_value',
            'cgst',
            'sgst',
            'igst',
            'unclassified_tax',
            'tax_total',
        ];
    }

    /**
     * @return list<list<string>>
     */
    public function gstRows(Request $request): array
    {
        return array_map(fn (array $row): array => [
            (string) $row['gst_percentage'],
            $this->money($row['taxable_value']),
            $row['has_classified_split'] ? $this->money($row['cgst']) : '',
            $row['has_classified_split'] ? $this->money($row['sgst']) : '',
            $row['has_classified_split'] ? $this->money($row['igst']) : '',
            $this->money($row['unclassified_tax']),
            $this->money($row['tax_total']),
        ], $this->gstSummary($request));
    }

    /**
     * @return list<string>
     */
    public function salesHeaders(): array
    {
        return [
            'invoice_date',
            'source_channel',
            'status',
            'invoice_count',
            'taxable_value',
            'tax_total',
            'invoice_value',
        ];
    }

    /**
     * @return list<list<string>>
     */
    public function salesRows(Request $request): array
    {
        return array_map(fn (array $row): array => [
            (string) $row['invoice_date'],
            (string) $row['channel'],
            (string) $row['status'],
            (string) $row['invoice_count'],
            $this->money($row['taxable_value']),
            $this->money($row['tax_total']),
            $this->money($row['invoice_value']),
        ], $this->salesByDateAndChannel($request));
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
        foreach ($this->exportRows($request) as $invoice) {
            if (! $this->hasCollection($invoice)) {
                continue;
            }

            $rows[] = [
                'statutory_invoice',
                (string) $invoice->invoice_number,
                $this->timestamp($invoice->issued_at),
                $invoice->channel->value,
                (string) $invoice->source_id,
                (string) ($invoice->source_order_id ?? ''),
                (string) ($invoice->payment_method ?? ''),
                (string) ($invoice->payment_reference ?? ''),
                $this->money($invoice->invoice_value),
                $invoice->status->value,
            ];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    public function summaryHeaders(): array
    {
        return ['metric', 'value'];
    }

    /**
     * @return list<list<string>>
     */
    public function summaryRows(array $summary): array
    {
        $rows = [];
        foreach ($summary as $metric => $value) {
            $rows[] = [
                (string) $metric,
                is_float($value) ? $this->money($value) : (string) $value,
            ];
        }

        return $rows;
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

    /**
     * @param  list<StatutoryInvoice>  $invoices
     * @return list<array<string, mixed>>
     */
    private function rollUpSales(array $invoices, bool $includeDate): array
    {
        $grouped = [];
        foreach ($invoices as $invoice) {
            $date = $includeDate
                ? ($invoice->issued_at?->timezone((string) config('app.timezone'))->format('Y-m-d') ?? '')
                : '';
            $channel = $invoice->channel->value;
            $status = $invoice->status->value;
            $key = $date.'|'.$channel.'|'.$status;
            $grouped[$key] ??= [
                'invoice_date' => $date,
                'channel' => $channel,
                'status' => $status,
                'invoice_count' => 0,
                'taxable_value' => 0.0,
                'tax_total' => 0.0,
                'invoice_value' => 0.0,
            ];
            $grouped[$key]['invoice_count']++;
            $grouped[$key]['taxable_value'] += (float) $invoice->taxable_value;
            $grouped[$key]['tax_total'] += (float) $invoice->tax_total;
            $grouped[$key]['invoice_value'] += (float) $invoice->invoice_value;
        }

        ksort($grouped);

        return array_values($grouped);
    }

    private function hasCollection(StatutoryInvoice $invoice): bool
    {
        return filled($invoice->payment_method) || filled($invoice->payment_reference);
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
