<?php

namespace App\Reports\CaMonthly;

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
        $allocationPaymentMethods = $this->paymentEvidenceResolver->allocationPaymentMethodsForInvoices($invoices);
        $allocationTotals = $this->paymentEvidenceResolver->allocationTotalsForInvoices($invoices);
        $hardwarePaymentMethods = $this->paymentEvidenceResolver->hardwarePaymentMethodsForInvoices($invoices);
        $supportOrderPaymentMethods = $this->paymentEvidenceResolver->supportOrderPaymentMethodsForInvoices($invoices);
        $supportOrders = $this->paymentChannelResolver->supportOrdersForInvoices($invoices);
        $hardwareEvidence = $this->paymentChannelResolver->hardwareEvidenceForInvoices($invoices);
        $commerceOrders = $this->branchResolver->commerceOrdersForInvoices($invoices);
        $branches = $this->branchResolver->resolveForInvoices($invoices, $commerceOrders);

        $rows = [];
        foreach ($invoices as $invoice) {
            $rows[] = $this->buildOne(
                $invoice,
                $orderContexts[$invoice->id] ?? new CaMonthlyReportOrderContext(null, null),
                (string) ($orderTypes[$invoice->id] ?? ''),
                $branches[$invoice->id] ?? '',
                $allocationPaymentMethods[$invoice->id] ?? null,
                $allocationTotals[$invoice->id] ?? 0.0,
                $commerceOrders[$invoice->id] ?? null,
                $hardwarePaymentMethods[$invoice->id] ?? null,
                $supportOrderPaymentMethods[$invoice->id] ?? null,
                $supportOrders[$invoice->id] ?? null,
                $hardwareEvidence[$invoice->id] ?? null,
            );
        }

        return $rows;
    }

    private function buildOne(
        StatutoryInvoice $invoice,
        CaMonthlyReportOrderContext $orderContext,
        string $orderType,
        string $branch,
        ?string $allocationPaymentMethod,
        float $allocationTotal,
        ?CommerceOrder $commerceOrder,
        ?string $hardwarePaymentMethod,
        ?string $supportOrderPaymentMethod,
        ?Order $supportOrder,
        ?HardwareFulfilmentPaymentEvidence $hardwareEvidenceRecord,
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

        $paymentMode = $this->paymentEvidenceResolver->resolvePaymentModeDisplay(
            $invoice,
            $allocationPaymentMethod,
            $commerceOrder,
            $hardwarePaymentMethod,
            $supportOrderPaymentMethod,
        );

        $paymentChannel = $this->paymentChannelResolver->resolvePaymentChannelDisplay(
            $invoice,
            $commerceOrder,
            $supportOrder,
            $hardwareEvidenceRecord,
            $allocationTotal,
            $invoice->inventorySale,
        );

        $paymentReference = $this->paymentChannelResolver->resolvePaymentReferenceDisplay(
            $invoice,
            $commerceOrder,
            $supportOrder,
            $hardwareEvidenceRecord,
            $invoice->inventorySale,
        );

        $taxableAmount = round((float) $invoice->taxable_value, 2);
        $igst = round((float) ($invoice->igst ?? 0), 2);
        $cgst = round((float) ($invoice->cgst ?? 0), 2);
        $sgst = round((float) ($invoice->sgst ?? 0), 2);
        $shortExcess = round((float) $invoice->rounding, 2);
        $invoiceTotal = round((float) $invoice->invoice_value, 2);

        $parentCells = [
            $branch,
            $this->formatDate($invoice->issued_at),
            (string) $invoice->invoice_number,
            CaMonthlyReportStatusDisplay::forInvoice($invoice),
            (string) ($orderContext->orderId ?? ''),
            $orderType,
            (string) ($invoice->buyer_name ?? ''),
            (string) ($invoice->buyer_gstin ?? ''),
            (string) ($this->resolveState($invoice) ?? ''),
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
            $paymentMode,
            $paymentReference,
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
            (string) $item->qty,
            (string) ($item->hsn_sac ?? ''),
            $this->money((float) $item->taxable_value),
            $shipping !== 0.0 ? $this->money($shipping) : '',
            $this->zeroBlankMoney($item->igst),
            $this->zeroBlankMoney($item->cgst),
            $this->zeroBlankMoney($item->sgst),
            $this->money((float) $item->line_total),
        ];
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

    private function resolveState(StatutoryInvoice $invoice): ?string
    {
        $structured = StatutoryBillingStructured::fromStored($invoice->billing_address_structured);

        return StatutoryBillingStructured::nullable($structured['state'] ?? null);
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
