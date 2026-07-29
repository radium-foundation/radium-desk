<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Commercial State (BR-04)
    |--------------------------------------------------------------------------
    |
    | Single commercial posture for cases: Open / Case Closed /
    | Refund Initiated / Refund Completed. When enabled, Customer 360 and
    | dashboard consume CommercialStateResolver; commercial actions are gated.
    |
    */

    'enabled' => (bool) env('COMMERCIAL_STATE_ENABLED', true),

];
