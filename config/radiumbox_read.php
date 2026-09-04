<?php

return [
    /*
    | Read-only RadiumBox repository API. Separate from config/radiumbox.php
    | (live HTTP Admin enrichment). Default OFF.
    */
    'enabled' => filter_var(env('RADIUMBOX_READ_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'connection' => env('DB_RADIUMBOX_READ_CONNECTION', 'radiumbox_read'),

    'per_page' => [
        'default' => 15,
        'max' => 50,
    ],
];
