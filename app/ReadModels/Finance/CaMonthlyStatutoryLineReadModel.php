<?php

namespace App\ReadModels\Finance;

use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Reports\CaMonthly\CaMonthlyReportDefinition;
use App\Reports\CaMonthly\CaMonthlyReportInclusionPolicy;
use App\Reports\CaMonthly\CaMonthlyReportInvoiceGroup;
use App\Reports\CaMonthly\CaMonthlyReportInvoiceGroupBuilder;
use App\Reports\CaMonthly\CaMonthlyReportLineBuilder;
use App\Reports\CaMonthly\CaMonthlyReportLineRow;
use App\Reports\CaMonthly\CaMonthlyReportOrderContextResolver;
use App\Reports\CaMonthly\CaMonthlyReportOrderType;
use App\Reports\CaMonthly\CaMonthlyReportOrderTypeResolver;
use App\Reports\CaMonthly\CaMonthlyReportPaymentEvidenceResolver;
use App\Reports\CaMonthly\CaMonthlyReportPreflight;
use App\Support\Finance\ReportPeriod;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CaMonthlyStatutoryLineReadModel
{
    public function __construct(
        private readonly CaMonthlyReportOrderContextResolver $orderContextResolver,
        private readonly CaMonthlyReportOrderTypeResolver $orderTypeResolver,
        private readonly CaMonthlyReportLineBuilder $lineBuilder,
        private readonly CaMonthlyReportInvoiceGroupBuilder $groupBuilder,
        private readonly CaMonthlyReportInclusionPolicy $inclusionPolicy,
        private readonly CaMonthlyReportPaymentEvidenceResolver $paymentEvidenceResolver,
    ) {}

    /**
     * @return LengthAwarePaginator<int, CaMonthlyReportInvoiceGroup>
     */
    public function paginateInvoiceGroups(Request $request, int $perPage = 50): LengthAwarePaginator
    {
        $paginator = $this->filteredInvoiceQuery($request)
            ->with($this->invoiceRelations())
            ->orderBy('statutory_invoices.'.CaMonthlyReportDefinition::AUTHORITATIVE_DATE_COLUMN)
            ->orderBy('statutory_invoices.id')
            ->paginate($perPage)
            ->withQueryString();

        $invoices = collect($paginator->items());
        $groups = $this->buildGroups($invoices);

        return $paginator->setCollection(collect($groups));
    }

    /**
     * @return list<list<string>>
     */
    public function exportRows(Request $request): array
    {
        return $this->buildRowCells($this->includedItems($request));
    }

    public function preflight(Request $request): CaMonthlyReportPreflight
    {
        $includedItems = $this->includedItems($request);
        $periodInvoices = $this->periodInvoices($request);

        $invoiceIds = [];
        $hardwareOrderIds = [];
        $serviceOrderIds = [];
        $bundledOrderIds = [];
        $unclassifiedOrderIds = [];
        $cancelledIncludedIds = [];
        $creditNoteIds = [];
        $missingOrderDateCount = 0;
        $missingHsnSac = 0;
        $missingGstinInvoiceIds = [];
        $missingIrnInvoiceIds = [];
        $missingAckInvoiceIds = [];
        $missingStateInvoiceIds = [];
        $discountLineCount = 0;
        $nonReconcilingLineCount = 0;
        $shippingInvoiceCount = 0;

        $taxableTotal = 0.0;
        $shippingTotal = 0.0;
        $igstTotal = 0.0;
        $cgstTotal = 0.0;
        $sgstTotal = 0.0;
        $shortExcessTotal = 0.0;
        $totalAmountTotal = 0.0;

        $invoices = $includedItems
            ->map(fn (StatutoryInvoiceItem $item): ?StatutoryInvoice => $item->invoice)
            ->filter()
            ->unique('id')
            ->values();

        $orderContexts = $this->orderContextResolver->resolveForInvoices($invoices);
        $orderTypes = $this->orderTypeResolver->resolveForInvoices($invoices);
        $allocationPaymentMethods = $this->paymentEvidenceResolver->allocationPaymentMethodsForInvoices($invoices);
        $builtRows = $this->lineBuilder->buildRows(
            $includedItems,
            $orderContexts,
            $orderTypes,
            $allocationPaymentMethods,
        );

        foreach ($builtRows as $row) {
            $this->accumulateRowTotals($row, $taxableTotal, $shippingTotal, $igstTotal, $cgstTotal, $sgstTotal, $shortExcessTotal, $totalAmountTotal);

            if (! $row->reconciles) {
                $nonReconcilingLineCount++;
            }

            if ($row->lineDiscount > 0) {
                $discountLineCount++;
            }
        }

        foreach ($includedItems as $item) {
            $invoice = $item->invoice;
            if ($invoice === null) {
                continue;
            }

            $invoiceIds[$invoice->id] = true;

            if ($invoice->status === StatutoryInvoiceStatus::Cancelled) {
                $cancelledIncludedIds[$invoice->id] = true;
            }

            if ($invoice->document_type === StatutoryInvoiceDocumentType::CreditNote) {
                $creditNoteIds[$invoice->id] = true;
            }

            if (round((float) ($invoice->shipping_amount ?? 0), 2) > 0) {
                $shippingInvoiceCount++;
            }

            $orderType = $orderTypes[$invoice->id] ?? null;
            if ($orderType === CaMonthlyReportOrderType::HARDWARE) {
                $hardwareOrderIds[$invoice->id] = true;
            } elseif ($orderType === CaMonthlyReportOrderType::SERVICE) {
                $serviceOrderIds[$invoice->id] = true;
            } elseif ($orderType === CaMonthlyReportOrderType::BUNDLED) {
                $bundledOrderIds[$invoice->id] = true;
            } else {
                $unclassifiedOrderIds[$invoice->id] = true;
            }

            $orderContext = $orderContexts[$invoice->id] ?? null;
            if ($this->nullableString($orderContext?->orderDate) === null) {
                $missingOrderDateCount++;
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

            if ($this->nullableString($invoice->eInvoiceRecord?->ack_no) === null) {
                $missingAckInvoiceIds[$invoice->id] = true;
            }

            if ($this->resolveState($invoice) === null) {
                $missingStateInvoiceIds[$invoice->id] = true;
            }
        }

        $periodCancelledInvoices = $periodInvoices
            ->filter(fn (StatutoryInvoice $invoice): bool => $invoice->status === StatutoryInvoiceStatus::Cancelled)
            ->values();

        $cancelledEvidenceSummary = $this->paymentEvidenceResolver->summarizeCancelledInvoices($periodCancelledInvoices);

        $lineCount = $includedItems->count();
        $unclassifiedOrdertypeLineCount = 0;
        foreach ($includedItems as $item) {
            $invoice = $item->invoice;
            if ($invoice === null) {
                continue;
            }

            if (($orderTypes[$invoice->id] ?? null) === null) {
                $unclassifiedOrdertypeLineCount++;
            }
        }

        return new CaMonthlyReportPreflight(
            invoiceCount: count($invoiceIds),
            lineCount: $lineCount,
            hardwareOrderCount: count($hardwareOrderIds),
            serviceOrderCount: count($serviceOrderIds),
            bundledOrderCount: count($bundledOrderIds),
            unclassifiedOrderCount: count($unclassifiedOrderIds),
            cancelledIncludedCount: count($cancelledIncludedIds),
            cancelledExcludedCount: 0,
            cancelledIncludedViaPaymentReferenceCount: $cancelledEvidenceSummary['included_via_payment_reference'],
            cancelledIncludedViaPaymentMethodCount: $cancelledEvidenceSummary['included_via_payment_method'],
            cancelledIncludedViaPaymentAllocationCount: $cancelledEvidenceSummary['included_via_payment_allocation'],
            cancelledAmbiguousInvoiceValueOnlyCount: $cancelledEvidenceSummary['ambiguous_invoice_value_only'],
            creditNoteCount: count($creditNoteIds),
            missingOrderDateCount: $missingOrderDateCount,
            missingHsnSacLineCount: $missingHsnSac,
            missingBuyerGstinInvoiceCount: count($missingGstinInvoiceIds),
            missingIrnInvoiceCount: count($missingIrnInvoiceIds),
            missingAcknowledgementInvoiceCount: count($missingAckInvoiceIds),
            missingStateInvoiceCount: count($missingStateInvoiceIds),
            unresolvedEwayBillLineCount: $lineCount,
            unresolvedShippingLineCount: $lineCount - $shippingInvoiceCount,
            unclassifiedOrdertypeLineCount: $unclassifiedOrdertypeLineCount,
            discountLineCount: $discountLineCount,
            nonReconcilingLineCount: $nonReconcilingLineCount,
            taxableAmountTotal: $this->money($taxableTotal),
            shippingAmountTotal: $this->money($shippingTotal),
            igstTotal: $this->money($igstTotal),
            cgstTotal: $this->money($cgstTotal),
            sgstTotal: $this->money($sgstTotal),
            shortExcessTotal: $this->money($shortExcessTotal),
            totalAmountTotal: $this->money($totalAmountTotal),
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
     * @param  Collection<int, StatutoryInvoiceItem>  $items
     * @return list<list<string>>
     */
    private function buildRowCells(Collection $items): array
    {
        $invoices = $items
            ->map(fn (StatutoryInvoiceItem $item): ?StatutoryInvoice => $item->invoice)
            ->filter()
            ->unique('id')
            ->values();

        $orderContexts = $this->orderContextResolver->resolveForInvoices($invoices);
        $orderTypes = $this->orderTypeResolver->resolveForInvoices($invoices);
        $allocationPaymentMethods = $this->paymentEvidenceResolver->allocationPaymentMethodsForInvoices($invoices);

        return array_map(
            fn (CaMonthlyReportLineRow $row): array => $row->cells,
            $this->lineBuilder->buildRows($items, $orderContexts, $orderTypes, $allocationPaymentMethods),
        );
    }

    /**
     * @param  Collection<int, StatutoryInvoice>  $invoices
     * @return list<CaMonthlyReportInvoiceGroup>
     */
    private function buildGroups(Collection $invoices): array
    {
        if ($invoices->isEmpty()) {
            return [];
        }

        $orderContexts = $this->orderContextResolver->resolveForInvoices($invoices);
        $orderTypes = $this->orderTypeResolver->resolveForInvoices($invoices);
        $allocationPaymentMethods = $this->paymentEvidenceResolver->allocationPaymentMethodsForInvoices($invoices);

        return $this->groupBuilder->buildGroups(
            $invoices,
            $orderContexts,
            $orderTypes,
            $allocationPaymentMethods,
        );
    }

    /**
     * @return Collection<int, StatutoryInvoiceItem>
     */
    private function includedItems(Request $request): Collection
    {
        return $this->filteredLineQuery($request)
            ->with($this->lineRelations())
            ->orderBy('statutory_invoice_items.invoice_id')
            ->orderBy('statutory_invoice_items.line_no')
            ->get();
    }

    /**
     * @return Collection<int, StatutoryInvoice>
     */
    private function periodInvoices(Request $request): Collection
    {
        return StatutoryInvoice::query()
            ->tap(function (Builder $query) use ($request): void {
                ReportPeriod::fromRequest($request)->apply(
                    $query,
                    CaMonthlyReportDefinition::AUTHORITATIVE_DATE_COLUMN,
                );
            })
            ->get();
    }

    private function filteredInvoiceQuery(Request $request): Builder
    {
        return StatutoryInvoice::query()
            ->where(function (Builder $query): void {
                $query->where('document_type', StatutoryInvoiceDocumentType::CreditNote)
                    ->orWhereIn('status', [
                        StatutoryInvoiceStatus::Issued,
                        StatutoryInvoiceStatus::Cancelled,
                    ]);
            })
            ->tap(function (Builder $query) use ($request): void {
                ReportPeriod::fromRequest($request)->apply(
                    $query,
                    CaMonthlyReportDefinition::AUTHORITATIVE_DATE_COLUMN,
                );
            });
    }

    private function filteredLineQuery(Request $request): Builder
    {
        return StatutoryInvoiceItem::query()
            ->select('statutory_invoice_items.*')
            ->join('statutory_invoices', 'statutory_invoices.id', '=', 'statutory_invoice_items.invoice_id')
            ->where(function (Builder $query): void {
                $query->where('statutory_invoices.document_type', StatutoryInvoiceDocumentType::CreditNote)
                    ->orWhereIn('statutory_invoices.status', [
                        StatutoryInvoiceStatus::Issued,
                        StatutoryInvoiceStatus::Cancelled,
                    ]);
            })
            ->tap(function (Builder $query) use ($request): void {
                ReportPeriod::fromRequest($request)->apply(
                    $query,
                    'statutory_invoices.'.CaMonthlyReportDefinition::AUTHORITATIVE_DATE_COLUMN,
                );
            });
    }

    /**
     * @return list<string>
     */
    private function invoiceRelations(): array
    {
        return [
            'branch',
            'eInvoiceRecord',
            'items',
            'inventorySale',
        ];
    }

    /**
     * @return list<string>
     */
    private function lineRelations(): array
    {
        return [
            'invoice.branch',
            'invoice.eInvoiceRecord',
            'invoice.items',
            'invoice.inventorySale',
        ];
    }

    private function resolveState(StatutoryInvoice $invoice): ?string
    {
        $structured = StatutoryBillingStructured::fromStored($invoice->billing_address_structured);

        return StatutoryBillingStructured::nullable($structured['state'] ?? null);
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function accumulateRowTotals(
        CaMonthlyReportLineRow $row,
        float &$taxableTotal,
        float &$shippingTotal,
        float &$igstTotal,
        float &$cgstTotal,
        float &$sgstTotal,
        float &$shortExcessTotal,
        float &$totalAmountTotal,
    ): void {
        $taxableTotal += $row->taxableAmount;
        $shippingTotal += $row->shippingAmount;
        $igstTotal += $row->igst;
        $cgstTotal += $row->cgst;
        $sgstTotal += $row->sgst;
        $shortExcessTotal += $row->shortExcess;
        $totalAmountTotal += $row->totalAmount;
    }
}
