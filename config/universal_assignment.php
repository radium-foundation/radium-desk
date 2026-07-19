<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Universal Assignment Engine
    |--------------------------------------------------------------------------
    |
    | Feature flags for gradual rollout. All default to safe production behavior.
    |
    */

    'remove_shift_admin_fallback' => env('UNIVERSAL_ASSIGNMENT_REMOVE_SHIFT_ADMIN_FALLBACK', false),

];
