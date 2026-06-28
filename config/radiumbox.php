<?php

return [
    'enabled' => filter_var(env('RADIUMBOX_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    'base_url' => rtrim(env('RADIUMBOX_BASE_URL', 'https://admin.radiumbox.com'), '/'),

    'timeout_seconds' => (int) env('RADIUMBOX_TIMEOUT_SECONDS', 5),

    'connect_timeout_seconds' => (int) env('RADIUMBOX_CONNECT_TIMEOUT_SECONDS', 3),
];
