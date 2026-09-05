<?php

return [

    /*
    | Legal series is a CA decision. Empty/null means statutory minting is
    | disabled. Do not invent a production prefix, FY reset, or GSTIN scope.
    */
    'series_code' => env('STATUTORY_INVOICE_SERIES_CODE'),

    'document_type' => env('STATUTORY_INVOICE_DOCUMENT_TYPE', 'tax_invoice'),

    /*
    | Legacy single-series GSTIN token only. Finance Hub invoices do not
    | read this as the seller GSTIN. Leave empty.
    */
    'gstin_scope' => env('STATUTORY_INVOICE_GSTIN_SCOPE'),

    /*
    | Optional financial year token (e.g. 2026-2027). Leave empty until CA
    | confirms FY reset. The numbering service will not invent an FY.
    */
    'financial_year' => env('STATUTORY_INVOICE_FINANCIAL_YEAR'),

    /*
    | Format tokens: {series} {seq} {seq:N} {gstin} {fy}.
    | Empty means the legacy single-series path is disabled. Finance Hub
    | issuance uses location_series below when enabled.
    */
    'number_format' => env('STATUTORY_INVOICE_NUMBER_FORMAT'),

    /*
    | Owner-finalized statutory location series.
    | Number = INV-{GST_STATE}{FY}{SERIAL}. Serial starts at 1 each FY.
    | FY 2026-27 Delhi serial 1 = INV-07671. Mumbai serial 1 = INV-27671.
    | Product issuer is branch-mapped. Service issuer is B2B/B2C + customer state.
    | Do not remint Admin INV*.
    */
    'location_series' => [
        'enabled' => true,
        'locations' => [
            'delhi' => [
                'gst_state_code' => '07',
                'branch_codes' => ['DELHI-RETAIL'],
                'gstin' => env('STATUTORY_INVOICE_DELHI_GSTIN'),
                'address' => env('STATUTORY_INVOICE_DELHI_ADDRESS'),
                'state' => env('STATUTORY_INVOICE_DELHI_STATE'),
            ],
            'mumbai' => [
                'gst_state_code' => '27',
                'branch_codes' => ['MUMBAI'],
                'gstin' => env('STATUTORY_INVOICE_MUMBAI_GSTIN'),
                'address' => env('STATUTORY_INVOICE_MUMBAI_ADDRESS'),
                'state' => env('STATUTORY_INVOICE_MUMBAI_STATE'),
            ],
        ],
    ],

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

    /*
    | Company-level legal seller name. GSTIN and registered address are
    | issuer-specific under location_series.locations.*. Payload seller
    | fields are never used as the issuer.
    */
    'legal_name' => env('STATUTORY_INVOICE_LEGAL_NAME'),

    'seller_address' => env('STATUTORY_INVOICE_SELLER_ADDRESS'),

    'seller_state' => env('STATUTORY_INVOICE_SELLER_STATE'),

    /*
    | Invoice commercial-date boundary. Pre-2026-09-01 orders must not be
    | issued or retrieved. Do not backfill historical orders.
    */
    'invoice_scope_starts_at' => env('STATUTORY_INVOICE_SCOPE_STARTS_AT', '2026-09-01 00:00:00'),

    /*
    | Worker must not mint or call an IRP. Left hardcoded false.
    */
    'worker_may_mint' => false,

    'einvoice' => [
        'provider' => env('STATUTORY_EINVOICE_PROVIDER', 'none'),
    ],

];
