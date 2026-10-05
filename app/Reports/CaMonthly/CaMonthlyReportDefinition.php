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

    public const TEMPLATE_VERSION = '2026-10-05';

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
        'HSN/SAC',
        'Taxable Amount',
        'Shipping',
        'IGST',
        'CGST',
        'SGST',
        'Line Total',
    ];
}
