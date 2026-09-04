<?php

return [
    /*
    |--------------------------------------------------------------------------
    | UPI unpaid-intent expiry
    |--------------------------------------------------------------------------
    |
    | Owner policy. The design suggested 60 minutes; this value is configurable
    | so the duration can change without a code change. Pending intents are
    | expired lazily when they are opened or verified. Old rows are not deleted.
    |
    */
    'upi_intent_expires_minutes' => (int) env('POS_UPI_INTENT_EXPIRES_MINUTES', 60),
];
