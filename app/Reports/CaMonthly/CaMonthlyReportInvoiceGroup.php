<?php

namespace App\Reports\CaMonthly;

final class CaMonthlyReportInvoiceGroup
{
    /**
     * @param  list<CaMonthlyReportInvoiceChildRow>  $children
     * @param  list<list<string>>  $exportRows
     * @param  list<string>  $parentRow
     */
    public function __construct(
        public readonly int $invoiceId,
        public readonly string $invoiceNumber,
        public readonly string $issuedDate,
        public readonly string $buyerName,
        public readonly string $orderType,
        public readonly string $taxableAmount,
        public readonly string $shippingAmount,
        public readonly string $taxAmount,
        public readonly string $totalAmount,
        public readonly string $paymentChannel,
        public readonly string $paymentMode,
        public readonly string $paymentReference,
        public readonly string $status,
        public readonly string $documentType,
        public readonly bool $expandable,
        public readonly array $children,
        public readonly array $exportRows,
        public readonly ?CaMonthlyReportLineRow $singleLineRow = null,
        public readonly array $parentRow = [],
    ) {}
}
