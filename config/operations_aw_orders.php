<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AW- order daily Telegram summary (superadmin only)
    |--------------------------------------------------------------------------
    |
    | Previous IST calendar day AW- service-reference order counts. Default off.
    | Does not send when disabled — no idempotency receipt is written.
    |
    */

    'daily_telegram' => [
        'enabled' => (bool) env('AW_ORDER_DAILY_TELEGRAM_ENABLED', false),
        'schedule_time' => env('AW_ORDER_DAILY_TELEGRAM_TIME', '08:00'),
        'report_timezone' => env('AW_ORDER_DAILY_TELEGRAM_TIMEZONE', 'Asia/Kolkata'),
    ],

];
