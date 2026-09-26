<?php

namespace App\Reports\CaMonthly;

use App\Models\StatutoryInvoice;
use Illuminate\Support\Collection;

final class CaMonthlyReportInvoiceGroupBuilder
{
    public function __construct(
        private readonly CaMonthlyReportInvoiceExportBuilder $exportBuilder,
        private readonly CaMonthlyReportLineValuePolicy $lineValuePolicy,
    ) {}

    /**
     * @param  Collection<int, StatutoryInvoice>  $invoices
     * @return list<CaMonthlyReportInvoiceGroup>
     */
    public function buildGroups(Collection $invoices): array
    {
        if ($invoices->isEmpty()) {
            return [];
        }

        $exportRows = $this->exportBuilder->buildForInvoices($invoices);
        $groups = [];

        foreach ($invoices->values() as $index => $invoice) {
            $exportRow = $exportRows[$index] ?? null;
            if ($exportRow === null) {
                continue;
            }

            $exportableItems = $this->lineValuePolicy->filterExportable(
                $invoice->items->sortBy('line_no')->values()->all(),
            );

            $children = [];
            foreach ($exportableItems as $itemIndex => $item) {
                $detail = $exportRow->detailRows[$itemIndex] ?? null;
                if ($detail === null) {
                    continue;
                }

                $children[] = new CaMonthlyReportInvoiceChildRow(
                    productName: $detail[0],
                    quantity: $detail[1],
                    hsnSac: $detail[2],
                    taxableAmount: $detail[3],
                    shipping: $detail[4],
                    igst: $detail[5],
                    cgst: $detail[6],
                    sgst: $detail[7],
                    lineTotal: $detail[8],
                );
            }

            $taxAmount = round($exportRow->igst + $exportRow->cgst + $exportRow->sgst, 2);

            $groups[] = new CaMonthlyReportInvoiceGroup(
                invoiceId: $invoice->id,
                invoiceNumber: (string) $invoice->invoice_number,
                issuedDate: $exportRow->parentCells[1],
                buyerName: (string) ($invoice->buyer_name ?? ''),
                orderType: $exportRow->parentCells[5],
                taxableAmount: $exportRow->parentCells[12],
                shippingAmount: $exportRow->parentCells[13],
                taxAmount: $taxAmount !== 0.0 ? number_format($taxAmount, 2, '.', '') : '',
                totalAmount: $exportRow->parentCells[18],
                paymentChannel: $exportRow->parentCells[21],
                paymentMode: $exportRow->parentCells[22],
                paymentReference: $exportRow->parentCells[23],
                status: $exportRow->parentCells[3],
                documentType: $invoice->document_type->label(),
                expandable: $exportRow->expandable,
                children: $children,
                exportRows: [$exportRow->parentCells],
                singleLineRow: count($children) === 1 ? null : null,
                parentRow: $exportRow->parentCells,
            );
        }

        return $groups;
    }
}
