<?php

return [
    'webhook_token' => env('BONVOICE_WEBHOOK_TOKEN'),
    'account_id' => env('BONVOICE_ACCOUNT_ID'),
    'verify_signature' => filter_var(env('BONVOICE_VERIFY_SIGNATURE', false), FILTER_VALIDATE_BOOLEAN),
];
