<?php

namespace App\Reports\CaMonthly;

use App\Enums\StatutoryInvoiceDocumentType;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilmentPaymentEvidence;
use App\Models\Order;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class CaMonthlyReportInvoiceExportBuilder
{
    public function __construct(
        private readonly CaMonthlyReportOrderContextResolver $orderContextResolver,
        private readonly CaMonthlyReportOrderTypeResolver $orderTypeResolver,
        private readonly CaMonthlyReportPaymentEvidenceResolver $paymentEvidenceResolver,
        private readonly CaMonthlyReportPaymentChannelResolver $paymentChannelResolver,
        private readonly CaMonthlyReportBranchResolver $branchResolver,
        private readonly CaMonthlyReportLineValuePolicy $lineValuePolicy,
    ) {}

    /**
     * @param  Collection<int, StatutoryInvoice>  $invoices
     * @return list<CaMonthlyReportInvoiceExportRow>
     */
    public function buildForInvoices(Collection $invoices): array
    {
        if ($invoices->isEmpty()) {
            return [];
        }

        $orderContexts = $this->orderContextResolver->resolveForInvoices($invoices);
        $orderTypes = $this->orderTypeResolver->resolveForInvoices($invoices);
        $allocationTotals = $this->paymentEvidenceResolver->allocationTotalsForInvoices($invoices);
        $allocationMethods = $this->paymentEvidenceResolver->allocationPaymentMethodsForInvoices($invoices);
        $supportOrders = $this->paymentChannelResolver->supportOrdersForInvoices($invoices);
        $hardwareEvidence = $this->paymentChannelResolver->hardwareEvidenceForInvoices($invoices);
        $commerceOrders = $this->branchResolver->commerceOrdersForInvoices($invoices);
        $branches = $this->branchResolver->resolveForInvoices($invoices, $commerceOrders);
        $creditNotesByInvoice = $this->creditNotesByOriginalInvoice($invoices);

        $rows = [];
        foreach ($invoices as $invoice) {
            $rows[] = $this->buildOne(
                $invoice,
                $orderContexts[$invoice->id] ?? new CaMonthlyReportOrderContext(null, null),
                (string) ($orderTypes[$invoice->id] ?? ''),
                $branches[$invoice->id] ?? '',
                $allocationTotals[$invoice->id] ?? 0.0,
                $allocationMethods[$invoice->id] ?? null,
                $commerceOrders[$invoice->id] ?? null,
                $supportOrders[$invoice->id] ?? null,
                $hardwareEvidence[$invoice->id] ?? null,
                $creditNotesByInvoice[$invoice->id] ?? collect(),
            );
        }

        return $rows;
    }

    private function buildOne(
        StatutoryInvoice $invoice,
        CaMonthlyReportOrderContext $orderContext,
        string $orderType,
        string $branch,
        float $allocationTotal,
        ?string $allocationPaymentMethod,
        ?CommerceOrder $commerceOrder,
        ?Order $supportOrder,
        ?HardwareFulfilmentPaymentEvidence $hardwareEvidenceRecord,
        Collection $linkedCreditNotes,
    ): CaMonthlyReportInvoiceExportRow {
        $invoice->loadMissing('inventorySale');
        $exportableItems = $this->lineValuePolicy->filterExportable(
            $invoice->items->sortBy('line_no')->values()->all(),
        );

        $detailRows = [];
        $firstLineNo = $exportableItems[0]->line_no ?? null;
        $shippingAmount = round((float) ($invoice->shipping_amount ?? 0), 2);

        foreach ($exportableItems as $item) {
            $detailRows[] = $this->buildDetailRow(
                $item,
                (int) $item->line_no === (int) $firstLineNo,
                $shippingAmount,
            );
        }

        $paymentChannel = $this->paymentChannelResolver->resolvePaymentChannelDisplay(
            $invoice,
            $commerceOrder,
            $supportOrder,
            $hardwareEvidenceRecord,
            $allocationTotal,
            $invoice->inventorySale,
            $allocationPaymentMethod,
        );

        $taxableAmount = round((float) $invoice->taxable_value, 2);
        $igst = round((float) ($invoice->igst ?? 0), 2);
        $cgst = round((float) ($invoice->cgst ?? 0), 2);
        $sgst = round((float) ($invoice->sgst ?? 0), 2);
        $totalGst = round($igst + $cgst + $sgst, 2);
        $shortExcess = round((float) $invoice->rounding, 2);
        $headerDiscount = round((float) ($invoice->discount ?? 0), 2);
        $invoiceTotal = round((float) $invoice->invoice_value, 2);
        $creditNoteSummary = $this->summarizeCreditNotes($linkedCreditNotes, $invoice);

        $parentCells = [
            $branch,
            $this->formatDate($invoice->issued_at),
            (string) $invoice->invoice_number,
            CaMonthlyReportStatusDisplay::forInvoice($invoice),
            (string) ($orderContext->orderId ?? ''),
            $orderType,
            (string) ($invoice->buyer_name ?? ''),
            (string) ($invoice->buyer_gstin ?? ''),
            (string) ($this->resolveState($invoice, $commerceOrder) ?? ''),
            (string) ($invoice->place_of_supply_state ?? ''),
            '',
            $this->combineHsnSac($exportableItems),
            $this->money($taxableAmount),
            $shippingAmount !== 0.0 ? $this->money($shippingAmount) : '',
            $this->zeroBlankMoney($invoice->igst),
            $this->zeroBlankMoney($invoice->cgst),
            $this->zeroBlankMoney($invoice->sgst),
            $shortExcess !== 0.0 ? $this->money($shortExcess) : '',
            $this->money($invoiceTotal),
            (string) ($invoice->eInvoiceRecord?->irn ?? ''),
            (string) ($invoice->eInvoiceRecord?->ack_no ?? ''),
            $paymentChannel,
            $totalGst !== 0.0 ? $this->money($totalGst) : '',
            CaMonthlyReportPaymentStatusDisplay::fromPaymentState(
                $this->paymentChannelResolver->isPaymentUnpaid(
                    $invoice,
                    $commerceOrder,
                    $supportOrder,
                    $hardwareEvidenceRecord,
                    $allocationTotal,
                    $invoice->inventorySale,
                ),
                $this->paymentChannelResolver->isPaymentPartial(
                    $invoice,
                    $commerceOrder,
                    $supportOrder,
                    $hardwareEvidenceRecord,
                    $allocationTotal,
                    $invoice->inventorySale,
                ),
                $paymentChannel,
            ),
            $creditNoteSummary['number'],
            $creditNoteSummary['status'],
            $this->productNameSummary($exportableItems),
        ];

        return new CaMonthlyReportInvoiceExportRow(
            parentCells: $parentCells,
            detailRows: $detailRows,
            expandable: count($detailRows) > 1,
            taxableAmount: $taxableAmount,
            shippingAmount: $shippingAmount,
            igst: $igst,
            cgst: $cgst,
            sgst: $sgst,
            shortExcess: $shortExcess,
            headerDiscount: $headerDiscount,
            invoiceTotal: $invoiceTotal,
        );
    }

    /**
     * @return list<string>
     */
    private function buildDetailRow(StatutoryInvoiceItem $item, bool $isFirstLine, float $invoiceShippingAmount): array
    {
        $shipping = $isFirstLine ? $invoiceShippingAmount : 0.0;

        return [
            (string) $item->description,
            (string) ($item->sku ?? ''),
            (string) $item->qty,
            $this->money((float) $item->unit_price),
            $this->zeroBlankMoney($item->discount),
            (string) ($item->hsn_sac ?? ''),
            $this->formatGstRate($item->gst_percentage),
            $this->money((float) $item->taxable_value),
            $shipping !== 0.0 ? $this->money($shipping) : '',
            $this->zeroBlankMoney($item->igst),
            $this->zeroBlankMoney($item->cgst),
            $this->zeroBlankMoney($item->sgst),
            $this->money((float) $item->line_total),
        ];
    }

    /**
     * @param  Collection<int, StatutoryInvoice>  $invoices
     * @return array<int, Collection<int, StatutoryInvoice>>
     */
    private function creditNotesByOriginalInvoice(Collection $invoices): array
    {
        $invoiceIds = $invoices->pluck('id')->all();
        if ($invoiceIds === []) {
            return [];
        }

        $creditNotes = StatutoryInvoice::query()
            ->whereIn('original_statutory_invoice_id', $invoiceIds)
            ->where('document_type', StatutoryInvoiceDocumentType::CreditNote)
            ->orderBy('id')
            ->get()
            ->groupBy('original_statutory_invoice_id');

        $byInvoice = [];
        foreach ($invoiceIds as $invoiceId) {
            $byInvoice[$invoiceId] = $creditNotes->get($invoiceId, collect());
        }

        return $byInvoice;
    }

    /**
     * @param  Collection<int, StatutoryInvoice>  $creditNotes
     * @return array{number: string, status: string}
     */
    private function summarizeCreditNotes(Collection $creditNotes, StatutoryInvoice $invoice): array
    {
        if ($invoice->document_type === StatutoryInvoiceDocumentType::CreditNote) {
            return [
                'number' => (string) $invoice->invoice_number,
                'status' => CaMonthlyReportStatusDisplay::forInvoice($invoice),
            ];
        }

        if ($creditNotes->isEmpty()) {
            return ['number' => '', 'status' => ''];
        }

        $numbers = $creditNotes
            ->map(fn (StatutoryInvoice $creditNote): string => (string) $creditNote->invoice_number)
            ->filter(fn (string $number): bool => $number !== '')
            ->values()
            ->all();

        $statuses = $creditNotes
            ->map(fn (StatutoryInvoice $creditNote): string => CaMonthlyReportStatusDisplay::forInvoice($creditNote))
            ->unique()
            ->values()
            ->all();

        return [
            'number' => implode(', ', $numbers),
            'status' => implode(', ', $statuses),
        ];
    }

    private function formatGstRate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $rate = round((float) $value, 2);

        return $rate === 0.0 ? '' : number_format($rate, 2, '.', '').'%';
    }

    /**
     * @param  list<StatutoryInvoiceItem>  $items
     */
    private function combineHsnSac(array $items): string
    {
        $codes = [];
        foreach ($items as $item) {
            $code = $this->nullableString($item->hsn_sac);
            if ($code !== null) {
                $codes[$code] = true;
            }
        }

        return implode(', ', array_keys($codes));
    }

    /**
     * Customer billing state. Precedence is the stored billing snapshot, then the
     * linked order's structured billing state, then the order's billing_state column.
     * Place of supply and GSTIN registration state are not used.
     */
    private function resolveState(StatutoryInvoice $invoice, ?CommerceOrder $commerceOrder): ?string
    {
        $fromInvoice = $this->stateFromStructured($invoice->billing_address_structured);
        if ($fromInvoice !== null) {
            return $fromInvoice;
        }

        if ($commerceOrder !== null) {
            $fromCommerceStructured = $this->stateFromStructured($commerceOrder->billing_address_structured);
            if ($fromCommerceStructured !== null) {
                return $fromCommerceStructured;
            }

            $billingState = $this->nullableString($commerceOrder->billing_state);
            if ($billingState !== null) {
                return $billingState;
            }
        }

        $sale = $invoice->inventorySale;
        if ($sale !== null) {
            return $this->stateFromStructured($sale->billing_address_structured);
        }

        return null;
    }

    private function stateFromStructured(mixed $structured): ?string
    {
        $parsed = StatutoryBillingStructured::fromStored($structured);

        return StatutoryBillingStructured::nullable($parsed['state'] ?? null);
    }

    /**
     * @param  list<StatutoryInvoiceItem>  $items
     */
    private function productNameSummary(array $items): string
    {
        $names = [];
        foreach ($items as $item) {
            $name = $this->nullableString($item->description);
            if ($name !== null) {
                $names[] = $name;
            }
        }

        return implode('; ', $names);
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

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function zeroBlankMoney(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $amount = round((float) $value, 2);

        return $amount === 0.0 ? '' : $this->money($amount);
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
