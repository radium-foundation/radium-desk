<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Platform cache write/read audit (temporary investigation)
    |--------------------------------------------------------------------------
    |
    | When true, every Platform snapshot writer/reader logs JSON lines to
    | storage/logs/platform-cache-audit.log. Disable after the investigation.
    */
    'cache_audit' => (bool) env('PLATFORM_CACHE_AUDIT', false),
];
