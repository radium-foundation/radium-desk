<?php

namespace App\ReadModels\Finance;

use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Reports\CaMonthly\CaMonthlyReportDefinition;
use App\Reports\CaMonthly\CaMonthlyReportOrderContextResolver;
use App\Reports\CaMonthly\CaMonthlyReportPreflight;
use App\Support\Finance\ReportPeriod;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CaMonthlyStatutoryLineReadModel
{
    public function __construct(
        private readonly CaMonthlyReportOrderContextResolver $orderContextResolver,
    ) {}

    /**
     * @return LengthAwarePaginator<int, StatutoryInvoiceItem>
     */
    public function paginate(Request $request, int $perPage = 50): LengthAwarePaginator
    {
        return $this->filteredLineQuery($request)
            ->with([
                'invoice.branch',
                'invoice.eInvoiceRecord',
                'invoice.inventorySale',
            ])
            ->orderBy('statutory_invoice_items.invoice_id')
            ->orderBy('statutory_invoice_items.line_no')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return list<list<string>>
     */
    public function exportRows(Request $request): array
    {
        $items = $this->filteredLineQuery($request)
            ->with([
                'invoice.branch',
                'invoice.eInvoiceRecord',
                'invoice.inventorySale',
            ])
            ->orderBy('statutory_invoice_items.invoice_id')
            ->orderBy('statutory_invoice_items.line_no')
            ->get();

        return $this->mapItemsToRows($items);
    }

    public function preflight(Request $request): CaMonthlyReportPreflight
    {
        $items = $this->filteredLineQuery($request)
            ->with(['invoice.eInvoiceRecord'])
            ->get();

        $invoiceIds = [];
        $cancelledInvoiceIds = [];
        $missingHsnSac = 0;
        $missingGstinInvoiceIds = [];
        $missingIrnInvoiceIds = [];
        $missingStateInvoiceIds = [];

        foreach ($items as $item) {
            $invoice = $item->invoice;
            if ($invoice === null) {
                continue;
            }

            $invoiceIds[$invoice->id] = true;

            if ($invoice->status->value === 'cancelled') {
                $cancelledInvoiceIds[$invoice->id] = true;
            }

            if ($this->nullableString($item->hsn_sac) === null) {
                $missingHsnSac++;
            }

            if ($this->nullableString($invoice->buyer_gstin) === null) {
                $missingGstinInvoiceIds[$invoice->id] = true;
            }

            if ($this->nullableString($invoice->eInvoiceRecord?->irn) === null) {
                $missingIrnInvoiceIds[$invoice->id] = true;
            }

            if ($this->resolveState($invoice) === null) {
                $missingStateInvoiceIds[$invoice->id] = true;
            }
        }

        $lineCount = $items->count();

        return new CaMonthlyReportPreflight(
            invoiceCount: count($invoiceIds),
            lineCount: $lineCount,
            cancelledInvoiceCount: count($cancelledInvoiceIds),
            missingHsnSacLineCount: $missingHsnSac,
            missingBuyerGstinInvoiceCount: count($missingGstinInvoiceIds),
            missingIrnInvoiceCount: count($missingIrnInvoiceIds),
            missingStateInvoiceCount: count($missingStateInvoiceIds),
            unresolvedEwayBillLineCount: $lineCount,
            unresolvedShippingLineCount: $lineCount,
            unresolvedOrdertypeLineCount: $lineCount,
            unresolvedShortExcessLineCount: $lineCount,
            unresolvedAmountLineCount: $lineCount,
        );
    }

    /**
     * @return list<string>
     */
    public function headers(): array
    {
        return CaMonthlyReportDefinition::HEADERS;
    }

    /**
     * @return list<string>
     */
    public function previewRow(StatutoryInvoiceItem $item): array
    {
        return $this->mapItemsToRows(collect([$item]))[0] ?? array_fill(0, count(CaMonthlyReportDefinition::HEADERS), '');
    }

    /**
     * @param  Collection<int, StatutoryInvoiceItem>  $items
     * @return list<list<string>>
     */
    private function mapItemsToRows($items): array
    {
        $invoices = $items
            ->map(fn (StatutoryInvoiceItem $item): ?StatutoryInvoice => $item->invoice)
            ->filter()
            ->unique('id')
            ->values();

        $orderContexts = $this->orderContextResolver->resolveForInvoices($invoices);

        $rows = [];
        foreach ($items as $item) {
            $invoice = $item->invoice;
            if ($invoice === null) {
                continue;
            }

            $orderContext = $orderContexts[$invoice->id] ?? null;

            $rows[] = [
                (string) ($invoice->branch?->name ?? $invoice->branch?->code ?? ''),
                (string) ($orderContext?->orderDate ?? ''),
                (string) ($orderContext?->orderId ?? ''),
                '',
                $this->formatDate($invoice->issued_at),
                (string) $invoice->invoice_number,
                (string) ($invoice->buyer_name ?? ''),
                (string) ($invoice->buyer_gstin ?? ''),
                (string) ($this->resolveState($invoice) ?? ''),
                (string) ($invoice->place_of_supply_state ?? ''),
                '',
                (string) $item->description,
                (string) $item->qty,
                (string) ($item->hsn_sac ?? ''),
                $this->money($item->taxable_value),
                '',
                $this->nullableMoney($item->igst),
                $this->nullableMoney($item->cgst),
                $this->nullableMoney($item->sgst),
                '',
                $this->money($item->line_total),
                (string) ($invoice->eInvoiceRecord?->irn ?? ''),
                (string) ($invoice->eInvoiceRecord?->ack_no ?? ''),
                $invoice->status->label(),
                (string) ($invoice->payment_method ?? ''),
                '',
            ];
        }

        return $rows;
    }

    private function filteredLineQuery(Request $request): Builder
    {
        return StatutoryInvoiceItem::query()
            ->select('statutory_invoice_items.*')
            ->join('statutory_invoices', 'statutory_invoices.id', '=', 'statutory_invoice_items.invoice_id')
            ->tap(function (Builder $query) use ($request): void {
                ReportPeriod::fromRequest($request)->apply(
                    $query,
                    'statutory_invoices.'.CaMonthlyReportDefinition::AUTHORITATIVE_DATE_COLUMN,
                );
            });
    }

    private function resolveState(StatutoryInvoice $invoice): ?string
    {
        $structured = StatutoryBillingStructured::fromStored($invoice->billing_address_structured);
        $state = StatutoryBillingStructured::nullable($structured['state'] ?? null);
        if ($state !== null) {
            return $state;
        }

        return null;
    }

    private function formatDate(mixed $value): string
    {
        if (! $value instanceof \DateTimeInterface) {
            return '';
        }

        return Carbon::instance($value)
            ->timezone((string) config('app.timezone'))
            ->format('Y-m-d');
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

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
