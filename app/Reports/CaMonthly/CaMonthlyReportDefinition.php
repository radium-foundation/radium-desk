<?php

namespace App\Reports\CaMonthly;

/**
 * CA Monthly Report output contract and period configuration.
 *
 * Period filter uses statutory invoice issue date (Date of Invoice).
 */
final class CaMonthlyReportDefinition
{
    public const ID = 'statutory.ca_monthly';

    public const DISPLAY_NAME = 'CA Monthly Report';

    /**
     * Configurable authoritative date column for period filtering.
     */
    public const AUTHORITATIVE_DATE_COLUMN = 'issued_at';

    public const TITLE_ROW = 1;

    public const PERIOD_ROW = 2;

    public const HEADER_ROW = 3;

    public const DATA_START_ROW = 4;

    public const SHEET_NAME = 'CA Monthly Report';

    public const TEMPLATE_VERSION = '2026-09-27';

    /**
     * Invoice-level CA register columns (parent rows).
     *
     * @var list<string>
     */
    public const HEADERS = [
        'Branch',
        'Invoice Date',
        'Invoice No.',
        'Status',
        'Order ID',
        'Order Type',
        'Customer Name',
        'GSTIN',
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
        'Payment Channel',
        'Payment Method',
        'Payment Reference',
    ];

    /**
     * Expandable line-item detail columns (child rows).
     *
     * @var list<string>
     */
    public const DETAIL_HEADERS = [
        'Product / Service',
        'Quantity',
        'HSN/SAC',
        'Taxable Amount',
        'Shipping',
        'IGST',
        'CGST',
        'SGST',
        'Line Total',
    ];
}
