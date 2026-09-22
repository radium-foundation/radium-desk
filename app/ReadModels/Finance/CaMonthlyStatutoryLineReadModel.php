<?php

namespace App\ReadModels\Finance;

use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\StatutoryInvoice;
use App\Reports\CaMonthly\CaMonthlyReportDefinition;
use App\Reports\CaMonthly\CaMonthlyReportInvoiceExportBuilder;
use App\Reports\CaMonthly\CaMonthlyReportInvoiceExportRow;
use App\Reports\CaMonthly\CaMonthlyReportInvoiceGroup;
use App\Reports\CaMonthly\CaMonthlyReportInvoiceGroupBuilder;
use App\Reports\CaMonthly\CaMonthlyReportLineValuePolicy;
use App\Reports\CaMonthly\CaMonthlyReportOrderContextResolver;
use App\Reports\CaMonthly\CaMonthlyReportOrderType;
use App\Reports\CaMonthly\CaMonthlyReportOrderTypeResolver;
use App\Reports\CaMonthly\CaMonthlyReportPaymentEvidenceResolver;
use App\Reports\CaMonthly\CaMonthlyReportPreflight;
use App\Reports\CaMonthly\CaMonthlyReportWorkbookMeta;
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
        private readonly CaMonthlyReportOrderTypeResolver $orderTypeResolver,
        private readonly CaMonthlyReportInvoiceExportBuilder $invoiceExportBuilder,
        private readonly CaMonthlyReportInvoiceGroupBuilder $groupBuilder,
        private readonly CaMonthlyReportLineValuePolicy $lineValuePolicy,
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
        $groups = $this->groupBuilder->buildGroups($invoices);

        return $paginator->setCollection(collect($groups));
    }

    /**
     * @return list<list<string>>
     */
    public function exportRows(Request $request): array
    {
        $rows = [];
        foreach ($this->invoiceExportRows($request) as $exportRow) {
            $rows[] = $exportRow->parentCells;
        }

        return $rows;
    }

    public function countExportLines(Request $request): int
    {
        return (int) $this->filteredInvoiceQuery($request)->count();
    }

    /**
     * Stream invoice-level parent rows (CSV and flat exports).
     *
     * @param  callable(list<string>): void  $callback
     */
    public function streamExportRows(Request $request, callable $callback): int
    {
        $rowCount = 0;
        $chunkSize = max(1, (int) config('ca_monthly_report.invoice_chunk_size', 25));

        $this->filteredInvoiceQuery($request)
            ->with($this->invoiceRelations())
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $invoices) use ($callback, &$rowCount): void {
                foreach ($this->invoiceExportBuilder->buildForInvoices($invoices) as $exportRow) {
                    $callback($exportRow->parentCells);
                    $rowCount++;
                }
            });

        return $rowCount;
    }

    /**
     * Stream grouped invoice export rows (XLSX with expandable detail).
     *
     * @param  callable(CaMonthlyReportInvoiceExportRow): void  $callback
     */
    public function streamExportInvoiceGroups(Request $request, callable $callback): int
    {
        $rowCount = 0;
        $chunkSize = max(1, (int) config('ca_monthly_report.invoice_chunk_size', 25));

        $this->filteredInvoiceQuery($request)
            ->with($this->invoiceRelations())
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $invoices) use ($callback, &$rowCount): void {
                foreach ($this->invoiceExportBuilder->buildForInvoices($invoices) as $exportRow) {
                    $callback($exportRow);
                    $rowCount++;
                }
            });

        return $rowCount;
    }

    public function workbookMeta(Request $request): CaMonthlyReportWorkbookMeta
    {
        $period = ReportPeriod::fromRequest($request);

        return new CaMonthlyReportWorkbookMeta(
            periodFrom: $period->from ?? '',
            periodTo: $period->to ?? '',
            generatedAt: Carbon::now()
                ->timezone((string) config('app.timezone'))
                ->format('Y-m-d H:i:s T'),
        );
    }

    public function preflight(Request $request): CaMonthlyReportPreflight
    {
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
        $unclassifiedOrdertypeLineCount = 0;
        $exportableLineCount = 0;
        $excludedZeroValueLineCount = 0;

        $taxableTotal = 0.0;
        $shippingTotal = 0.0;
        $igstTotal = 0.0;
        $cgstTotal = 0.0;
        $sgstTotal = 0.0;
        $shortExcessTotal = 0.0;
        $totalAmountTotal = 0.0;

        $chunkSize = max(1, (int) config('ca_monthly_report.invoice_chunk_size', 25));

        $this->filteredInvoiceQuery($request)
            ->with($this->invoiceRelations())
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $invoices) use (
                &$invoiceIds,
                &$hardwareOrderIds,
                &$serviceOrderIds,
                &$bundledOrderIds,
                &$unclassifiedOrderIds,
                &$cancelledIncludedIds,
                &$creditNoteIds,
                &$missingOrderDateCount,
                &$missingHsnSac,
                &$missingGstinInvoiceIds,
                &$missingIrnInvoiceIds,
                &$missingAckInvoiceIds,
                &$missingStateInvoiceIds,
                &$discountLineCount,
                &$nonReconcilingLineCount,
                &$shippingInvoiceCount,
                &$unclassifiedOrdertypeLineCount,
                &$exportableLineCount,
                &$excludedZeroValueLineCount,
                &$taxableTotal,
                &$shippingTotal,
                &$igstTotal,
                &$cgstTotal,
                &$sgstTotal,
                &$shortExcessTotal,
                &$totalAmountTotal,
            ): void {
                $orderContexts = $this->orderContextResolver->resolveForInvoices($invoices);
                $orderTypes = $this->orderTypeResolver->resolveForInvoices($invoices);
                $exportRows = $this->invoiceExportBuilder->buildForInvoices($invoices);

                foreach ($invoices as $index => $invoice) {
                    $exportRow = $exportRows[$index] ?? null;
                    if ($exportRow === null) {
                        continue;
                    }

                    $invoiceIds[$invoice->id] = true;
                    $taxableTotal += $exportRow->taxableAmount;
                    $shippingTotal += $exportRow->shippingAmount;
                    $igstTotal += $exportRow->igst;
                    $cgstTotal += $exportRow->cgst;
                    $sgstTotal += $exportRow->sgst;
                    $shortExcessTotal += $exportRow->shortExcess;
                    $totalAmountTotal += $exportRow->invoiceTotal;

                    $calculated = round(
                        $exportRow->taxableAmount
                        + $exportRow->shippingAmount
                        + $exportRow->igst
                        + $exportRow->cgst
                        + $exportRow->sgst
                        + $exportRow->shortExcess,
                        2,
                    );
                    if (abs($calculated - $exportRow->invoiceTotal) > 0.01) {
                        $nonReconcilingLineCount++;
                    }

                    foreach ($invoice->items as $item) {
                        if ($this->lineValuePolicy->isExportable($item)) {
                            $exportableLineCount++;
                            if (round((float) $item->discount, 2) > 0) {
                                $discountLineCount++;
                            }
                            if ($this->nullableString($item->hsn_sac) === null) {
                                $missingHsnSac++;
                            }
                        } else {
                            $excludedZeroValueLineCount++;
                        }
                    }

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
                        $unclassifiedOrdertypeLineCount++;
                    }

                    $orderContext = $orderContexts[$invoice->id] ?? null;
                    if ($this->nullableString($orderContext?->orderDate) === null) {
                        $missingOrderDateCount++;
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
            });

        $periodCancelledInvoices = $this->filteredInvoiceQuery($request)
            ->where('status', StatutoryInvoiceStatus::Cancelled)
            ->with($this->invoiceRelations())
            ->get();

        $cancelledEvidenceSummary = $this->paymentEvidenceResolver->summarizeCancelledInvoices($periodCancelledInvoices);

        return new CaMonthlyReportPreflight(
            invoiceCount: count($invoiceIds),
            lineCount: $exportableLineCount,
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
            unresolvedEwayBillLineCount: $exportableLineCount,
            unresolvedShippingLineCount: count($invoiceIds) - $shippingInvoiceCount,
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
     * @return list<CaMonthlyReportInvoiceExportRow>
     */
    private function invoiceExportRows(Request $request): array
    {
        $invoices = $this->filteredInvoiceQuery($request)
            ->with($this->invoiceRelations())
            ->orderBy('statutory_invoices.'.CaMonthlyReportDefinition::AUTHORITATIVE_DATE_COLUMN)
            ->orderBy('statutory_invoices.id')
            ->get();

        return $this->invoiceExportBuilder->buildForInvoices($invoices);
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

    /**
     * @return list<string>
     */
    private function invoiceRelations(): array
    {
        return [
            'branch',
            'eInvoiceRecord',
            'items',
            'inventorySale.branch',
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
}
