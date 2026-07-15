<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Missing Serial Automation
    |--------------------------------------------------------------------------
    |
    | Schedules customer outreach when a paid order still has no usable serial
    | after RadiumBox automatic recovery has been attempted.
    |
    | Request Serial Number is NOT sent merely because serial_number is empty.
    | All of the following must be true:
    |   1. Order is Cashfree-verified (successfully paid)
    |   2. first_delay_minutes (default 15) have elapsed since payment
    |   3. RadiumBox enrichment has been attempted at least once
    |   4. Serial is still unavailable and enrichment is still needed
    |   5. Customer has not already been contacted for this event
    |
    | Customer journey after the initial request:
    |   +24 h → Support Reminder (CustomerWaitingFollowup)
    |   6 PM  → Auto-close (existing customer_waiting_default lifecycle)
    |
    | See docs/missing-serial-automation.md for the full trigger and journey.
    |
    */
    'enabled' => env('MISSING_SERIAL_AUTOMATION_ENABLED', true),

    'first_delay_minutes' => (int) env('MISSING_SERIAL_FIRST_DELAY_MINUTES', 15),

    'reminder_delay_hours' => (int) env('MISSING_SERIAL_REMINDER_DELAY_HOURS', 24),

    'escalation_delay_hours' => (int) env('MISSING_SERIAL_ESCALATION_DELAY_HOURS', 72),

    'schedule_interval_minutes' => (int) env('MISSING_SERIAL_SCHEDULE_INTERVAL_MINUTES', 15),

    'batch_limit' => (int) env('MISSING_SERIAL_BATCH_LIMIT', 100),
];
