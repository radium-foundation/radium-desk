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

    public const HEADER_ROW = 3;

    public const SHEET_NAME = 'Sheet1';

    public const TEMPLATE_VERSION = '2026-09-21';

    /**
     * Exact CA column labels in order (A–AA).
     *
     * @var list<string>
     */
    public const HEADERS = [
        'Branch',
        'Date_of_order',
        'Orderid',
        'Ordertype',
        'Date of Invoice',
        'Invoice No.',
        'Full Name',
        'GST',
        'STATE',
        'POS',
        'eWay Bill',
        'NAME OF PRODUCT',
        'Quantity',
        'SAC/HSN',
        'Taxable Amount',
        'Shipping',
        'IGST',
        'CGST',
        'SGST',
        'Short/Excess',
        'Total Amount',
        'IRN Number',
        'Acknowledgement',
        'Status',
        'Payment Mode',
        'Amount',
        'Document Type',
    ];
}
