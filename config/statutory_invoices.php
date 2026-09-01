<?php

return [

    /*
    | Legal series is a CA decision. Empty/null means statutory minting is
    | disabled. Do not invent a production prefix, FY reset, or GSTIN scope.
    */
    'series_code' => env('STATUTORY_INVOICE_SERIES_CODE'),

    'document_type' => env('STATUTORY_INVOICE_DOCUMENT_TYPE', 'tax_invoice'),

    /*
    | Optional GSTIN scope for the sequence key. Leave empty until CA confirms
    | per-GSTIN series. The numbering service will not invent a GSTIN.
    */
    'gstin_scope' => env('STATUTORY_INVOICE_GSTIN_SCOPE'),

    /*
    | Optional financial year token (e.g. 2026-2027). Leave empty until CA
    | confirms FY reset. The numbering service will not invent an FY.
    */
    'financial_year' => env('STATUTORY_INVOICE_FINANCIAL_YEAR'),

    /*
    | Format tokens: {series} {seq} {seq:N} {gstin} {fy}.
    | Empty means minting is disabled even if series_code is set.
    */
    'number_format' => env('STATUTORY_INVOICE_NUMBER_FORMAT'),

    /*
    | Guard: statutory invoices must not post revenue/tax journals while POS
    | and Cashfree already credit 4000 on collection. CA recognition policy
    | is required before this may be enabled. The engine refuses to post.
    */
    'post_finance_journals' => false,

    /*
    | Guard: do not invent invoice-on-payment vs invoice-on-dispatch.
    | POS complete never auto-mints a statutory invoice.
    */
    'auto_issue_on_pos_complete' => false,

];
