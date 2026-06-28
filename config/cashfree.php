<?php

return [
    'client_secret' => env('CASHFREE_CLIENT_SECRET'),
    'verify_signature' => filter_var(env('CASHFREE_VERIFY_SIGNATURE', false), FILTER_VALIDATE_BOOLEAN),
    'system_user_email' => env('CASHFREE_SYSTEM_USER_EMAIL', 'superadmin@radium.local'),
];
