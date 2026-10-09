<?php

namespace App\Reports\CaMonthly;

/**
 * Sales Report (statutory CA monthly register) output contract and period configuration.
 *
 * Period filter uses statutory invoice issue date (Date of Invoice).
 */
final class CaMonthlyReportDefinition
{
    public const ID = 'statutory.ca_monthly';

    public const DISPLAY_NAME = 'Sales Report';

    /** @deprecated User-facing legacy label retained for route/internal identifiers only. */
    public const LEGACY_DISPLAY_NAME = 'CA Monthly Report';

    /**
     * Configurable authoritative date column for period filtering.
     */
    public const AUTHORITATIVE_DATE_COLUMN = 'issued_at';

    public const TITLE_ROW = 1;

    public const PERIOD_ROW = 2;

    public const HEADER_ROW = 3;

    public const DATA_START_ROW = 4;

    public const SHEET_NAME = 'Sales Report';

    public const TEMPLATE_VERSION = '2026-10-09-order-quantity';

    /**
     * Invoice-level CA register columns (parent rows).
     *
     * @var list<string>
     */
    public const HEADERS = [
        'Branch Name',
        'Invoice Date',
        'Invoice No.',
        'Status',
        'Order ID',
        'Order Type',
        'Customer Name',
        'GSTIN',
        'GSTIN Format Status',
        'State',
        'Place of Supply',
        'eWay Bill',
        'HSN/SAC',
        'Taxable Amount',
        'Shipping',
        'IGST',
        'CGST',
        'SGST',
        'Short/Excess',
        'Invoice Total',
        'IRN Number',
        'Acknowledgement',
        'E-Invoice / IRN Generation Status',
        'E-Invoice / IRN Response Code',
        'E-Invoice / IRN Response Reason',
        'Payment Method',
        'Total GST',
        'Payment Status',
        'Credit Note Number',
        'Credit Note Status',
        'Product Name',
        'Total Quantity',
    ];

    /**
     * Expandable line-item detail columns (child rows).
     *
     * @var list<string>
     */
    public const DETAIL_HEADERS = [
        'Product Name',
        'Product Code / SKU',
        'Quantity',
        'Unit Price',
        'Discount',
        'HSN/SAC',
        'GST Rate',
        'Taxable Amount',
        'Shipping',
        'IGST',
        'CGST',
        'SGST',
        'Line Total',
    ];

    public const REFUND_REVIEW_SHEET_NAME = 'Refund & CN Review';

    /**
     * Refund exception review columns (second workbook sheet).
     *
     * @var list<string>
     */
    public const REFUND_REVIEW_HEADERS = [
        'Refund ID',
        'Refund Reference',
        'Order Reference',
        'Invoice Number',
        'Refund Date',
        'Refund Method',
        'Refund Amount',
        'Invoice Amount',
        'Cumulative Refund Amount',
        'Invoice Status',
        'Customer Type',
        'IRN Date',
        'IRN Age at Refund (hours)',
        'Credit Note Number',
        'Credit Note Status',
        'Credit Note Amount',
        'Exception / Review Status',
    ];

    public const REVIEW_STATUS_REFUND_BEFORE_INVOICE = 'CA / Finance Review — refund-before-invoice timeline anomaly';

    public const REVIEW_STATUS_POTENTIAL_CN = 'CA / Finance Review — potential statutory Credit Note treatment';
}
