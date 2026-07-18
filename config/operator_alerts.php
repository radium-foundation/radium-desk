<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Operator Alert System
    |--------------------------------------------------------------------------
    |
    | Phase 0 foundation. When enabled is false (default), OperatorAlertDispatcher
    | is a no-op so existing notification behaviour is unchanged.
    |
    */

    'enabled' => filter_var(env('OPERATOR_ALERTS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'desktop_enabled' => filter_var(env('OPERATOR_ALERTS_DESKTOP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    'sound_enabled' => filter_var(env('OPERATOR_ALERTS_SOUND_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

];
