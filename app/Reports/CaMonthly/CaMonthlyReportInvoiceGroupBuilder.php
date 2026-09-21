<?php

namespace App\Reports\CaMonthly;

use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use Illuminate\Support\Collection;

final class CaMonthlyReportInvoiceGroupBuilder
{
    public function __construct(
        private readonly CaMonthlyReportLineBuilder $lineBuilder,
        private readonly CaMonthlyReportPaymentEvidenceResolver $paymentEvidenceResolver,
    ) {}

    /**
     * @param  Collection<int, StatutoryInvoice>  $invoices
     * @param  array<int, CaMonthlyReportOrderContext>  $orderContexts
     * @param  array<int, ?string>  $orderTypes
     * @param  array<int, string>  $allocationPaymentMethods
     * @return list<CaMonthlyReportInvoiceGroup>
     */
    public function buildGroups(
        Collection $invoices,
        array $orderContexts,
        array $orderTypes,
        array $allocationPaymentMethods = [],
    ): array {
        $items = $invoices
            ->flatMap(fn (StatutoryInvoice $invoice): Collection => $invoice->items)
            ->values();

        $builtRows = $this->lineBuilder->buildRows($items, $orderContexts, $orderTypes, $allocationPaymentMethods);
        $rowIndex = 0;
        $groups = [];

        foreach ($invoices as $invoice) {
            $lineCount = $invoice->items->count();
            $invoiceRows = array_slice($builtRows, $rowIndex, $lineCount);
            $rowIndex += $lineCount;

            $paymentMode = $this->paymentEvidenceResolver->resolvePaymentModeDisplay(
                $invoice,
                $allocationPaymentMethods[$invoice->id] ?? null,
            );
            $shippingAmount = round((float) ($invoice->shipping_amount ?? 0), 2);
            $taxAmount = round(
                (float) ($invoice->igst ?? 0)
                + (float) ($invoice->cgst ?? 0)
                + (float) ($invoice->sgst ?? 0),
                2,
            );

            $children = [];
            foreach ($invoice->items->sortBy('line_no') as $item) {
                $children[] = $this->buildChildRow($item);
            }

            $groups[] = new CaMonthlyReportInvoiceGroup(
                invoiceId: $invoice->id,
                invoiceNumber: (string) $invoice->invoice_number,
                issuedDate: $this->formatDate($invoice->issued_at),
                buyerName: (string) ($invoice->buyer_name ?? ''),
                orderType: (string) ($orderTypes[$invoice->id] ?? ''),
                taxableAmount: $this->money((float) $invoice->taxable_value),
                shippingAmount: $shippingAmount !== 0.0 ? $this->money($shippingAmount) : '',
                taxAmount: $taxAmount !== 0.0 ? $this->money($taxAmount) : '',
                totalAmount: $this->money((float) $invoice->invoice_value),
                paymentMode: $paymentMode,
                status: $invoice->status->label(),
                documentType: $invoice->document_type->label(),
                expandable: $lineCount > 1,
                children: $children,
                exportRows: array_map(
                    fn (CaMonthlyReportLineRow $row): array => $row->cells,
                    $invoiceRows,
                ),
                singleLineRow: $lineCount === 1 ? ($invoiceRows[0] ?? null) : null,
            );
        }

        return $groups;
    }

    private function buildChildRow(StatutoryInvoiceItem $item): CaMonthlyReportInvoiceChildRow
    {
        return new CaMonthlyReportInvoiceChildRow(
            productName: (string) $item->description,
            quantity: (string) $item->qty,
            hsnSac: (string) ($item->hsn_sac ?? ''),
            taxableAmount: $this->money((float) $item->taxable_value),
            igst: $this->zeroBlankMoney($item->igst),
            cgst: $this->zeroBlankMoney($item->cgst),
            sgst: $this->zeroBlankMoney($item->sgst),
        );
    }

    private function formatDate(mixed $value): string
    {
        if (! $value instanceof \DateTimeInterface) {
            return '';
        }

        return $value->format('Y-m-d');
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
}
