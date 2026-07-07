<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Unified Intake Primary Mode
    |--------------------------------------------------------------------------
    |
    | When enabled on the dashboard, global search is styled as the primary
    | intake path and "New Service Request" is shown as a secondary fallback.
    | Search logic and Quick Create remain unchanged.
    |
    */

    'primary' => (bool) env('UNIFIED_INTAKE_PRIMARY', false),

];
