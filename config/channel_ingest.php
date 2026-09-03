<?php

return [

    /*
    | Per-channel HMAC secrets. Empty means that channel cannot ingest.
    | Do not reuse DESK_ORDER_API_TOKEN, Cashfree, or BonVoice credentials.
    | Secrets are never logged.
    */
    'secrets' => [
        'rdservice_in' => env('CHANNEL_INGEST_SECRET_RDSERVICE_IN'),
        'radiumbox_com' => env('CHANNEL_INGEST_SECRET_RADIUMBOX_COM'),
        'rdservice_net' => env('CHANNEL_INGEST_SECRET_RDSERVICE_NET'),
        'radiumsign_com' => env('CHANNEL_INGEST_SECRET_RADIUMSIGN_COM'),
        'future' => env('CHANNEL_INGEST_SECRET_FUTURE'),
    ],

    /*
    | Unix timestamp skew allowed for HMAC replay protection (seconds).
    */
    'replay_window_seconds' => max(30, (int) env('CHANNEL_INGEST_REPLAY_WINDOW_SECONDS', 300)),

    /*
    | Guard: channel ingest must not mint statutory invoices until CA series
    | and cutover are approved. If this is enabled without those, ingest fails.
    */
    'auto_issue_invoice' => false,

    /*
    | Owner/CA cutover flag. Left false; this foundation does not mint.
    */
    'cutover_approved' => filter_var(env('CHANNEL_INGEST_CUTOVER_APPROVED', false), FILTER_VALIDATE_BOOLEAN),

];
