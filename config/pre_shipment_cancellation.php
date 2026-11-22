<?php

return [
    /*
    | Phase 2 State A+B hardware pre-shipment cancellation (desk-owned).
    | Default OFF — enable only via environment after Owner runbook.
    */
    'enabled' => (bool) env('PRE_SHIPMENT_CANCELLATION_ENABLED', false),
];
