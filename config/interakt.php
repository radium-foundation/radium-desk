<?php

return [
    'api_key' => env('INTERAKT_API_KEY'),
    'webhook_secret' => env('INTERAKT_WEBHOOK_SECRET'),
    'base_url' => rtrim((string) env('INTERAKT_BASE_URL', 'https://api.interakt.ai'), '/'),
    'verify_signature' => filter_var(env('INTERAKT_VERIFY_SIGNATURE', false), FILTER_VALIDATE_BOOLEAN),
    'timeout_seconds' => (int) env('INTERAKT_TIMEOUT_SECONDS', 15),
    'connect_timeout_seconds' => (int) env('INTERAKT_CONNECT_TIMEOUT_SECONDS', 5),
    'max_retries' => (int) env('INTERAKT_MAX_RETRIES', 3),
    'retry_delay_ms' => (int) env('INTERAKT_RETRY_DELAY_MS', 200),
    'app_url' => rtrim((string) env('INTERAKT_APP_URL', 'https://app.interakt.ai'), '/'),
    'conversation_url_template' => env('INTERAKT_CONVERSATION_URL_TEMPLATE'),
    'customer_profile_url_template' => env(
        'INTERAKT_CUSTOMER_PROFILE_URL_TEMPLATE',
        '{app_url}/contacts?search={phone}',
    ),
    'flow_token_ttl_hours' => (int) env('INTERAKT_FLOW_TOKEN_TTL_HOURS', 24),
    'flow_id' => env('INTERAKT_FLOW_ID'),
    'templates' => [
        'request_serial_number' => [
            'enabled' => filter_var(env('INTERAKT_TEMPLATE_REQUEST_SERIAL_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'name' => env('INTERAKT_TEMPLATE_REQUEST_SERIAL'),
            'language_code' => env('INTERAKT_TEMPLATE_REQUEST_SERIAL_LANGUAGE', 'en'),
            'language_code_is_default' => ! filled(env('INTERAKT_TEMPLATE_REQUEST_SERIAL_LANGUAGE')),
            'display_name' => env('INTERAKT_TEMPLATE_REQUEST_SERIAL_DISPLAY', 'Order Update'),
            'purpose' => 'Request Serial Number',
            'internal_note' => 'Requested serial number from customer via approved WhatsApp template.',
            // support_schedule (en): static header "Order Update"; body {{1}} = customer name; body {{2}} = order ID;
            // CTA "Book Support" dynamic URL /support/schedule/{{1}}?source=whatsapp with tracked token in {{1}}.
            // Rollback template: order_confirm_manual_schedule (header {{1}} = order ID; body {{1}}/{{2}} as above).
            // Values are supplied at dispatch time by WhatsAppChannel for RequestSerialNumber notifications.
        ],
        'request_correct_serial' => [
            'enabled' => filter_var(env('INTERAKT_TEMPLATE_REQUEST_CORRECT_SERIAL_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'name' => env('INTERAKT_TEMPLATE_REQUEST_CORRECT_SERIAL'),
            'language_code' => env('INTERAKT_TEMPLATE_REQUEST_CORRECT_SERIAL_LANGUAGE', 'en'),
            'language_code_is_default' => ! filled(env('INTERAKT_TEMPLATE_REQUEST_CORRECT_SERIAL_LANGUAGE')),
            'display_name' => env('INTERAKT_TEMPLATE_REQUEST_CORRECT_SERIAL_DISPLAY', 'Serial Confirmation'),
            'purpose' => 'Request Correct Serial',
            'internal_note' => 'Asked customer to confirm the correct device serial number via approved WhatsApp template.',
            // Same variable layout as request_serial_number: body {{1}} = customer name, {{2}} = order ID;
            // CTA button uses tracked schedule token. Values supplied by WhatsAppChannel at dispatch time.
        ],
        'repair_started' => [
            'enabled' => filter_var(env('INTERAKT_TEMPLATE_REPAIR_STARTED_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'name' => env('INTERAKT_TEMPLATE_REPAIR_STARTED'),
            'language_code' => env('INTERAKT_TEMPLATE_REPAIR_STARTED_LANGUAGE', 'en'),
            'language_code_is_default' => ! filled(env('INTERAKT_TEMPLATE_REPAIR_STARTED_LANGUAGE')),
            'display_name' => env('INTERAKT_TEMPLATE_REPAIR_STARTED_DISPLAY', 'Repair Started'),
            'purpose' => 'Repair Started',
        ],
        'repair_completed' => [
            'enabled' => filter_var(env('INTERAKT_TEMPLATE_REPAIR_COMPLETED_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'name' => env('INTERAKT_TEMPLATE_REPAIR_COMPLETED'),
            'language_code' => env('INTERAKT_TEMPLATE_REPAIR_COMPLETED_LANGUAGE', 'en'),
            'language_code_is_default' => ! filled(env('INTERAKT_TEMPLATE_REPAIR_COMPLETED_LANGUAGE')),
            'display_name' => env('INTERAKT_TEMPLATE_REPAIR_COMPLETED_DISPLAY', 'Repair Completed'),
            'purpose' => 'Repair Completed',
        ],
        'ready_for_dispatch' => [
            'enabled' => filter_var(env('INTERAKT_TEMPLATE_READY_FOR_DISPATCH_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'name' => env('INTERAKT_TEMPLATE_READY_FOR_DISPATCH'),
            'language_code' => env('INTERAKT_TEMPLATE_READY_FOR_DISPATCH_LANGUAGE', 'en'),
            'language_code_is_default' => ! filled(env('INTERAKT_TEMPLATE_READY_FOR_DISPATCH_LANGUAGE')),
            'display_name' => env('INTERAKT_TEMPLATE_READY_FOR_DISPATCH_DISPLAY', 'Ready for Dispatch'),
            'purpose' => 'Ready for Dispatch',
        ],
        'refund_update' => [
            'enabled' => filter_var(env('INTERAKT_TEMPLATE_REFUND_UPDATE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'name' => env('INTERAKT_TEMPLATE_REFUND_UPDATE'),
            'language_code' => env('INTERAKT_TEMPLATE_REFUND_UPDATE_LANGUAGE', 'en'),
            'language_code_is_default' => ! filled(env('INTERAKT_TEMPLATE_REFUND_UPDATE_LANGUAGE')),
            'display_name' => env('INTERAKT_TEMPLATE_REFUND_UPDATE_DISPLAY', 'Refund Update'),
            'purpose' => 'Refund Update',
        ],
        'amc_reminder' => [
            'enabled' => filter_var(env('INTERAKT_TEMPLATE_AMC_REMINDER_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'name' => env('INTERAKT_TEMPLATE_AMC_REMINDER'),
            'language_code' => env('INTERAKT_TEMPLATE_AMC_REMINDER_LANGUAGE', 'en'),
            'language_code_is_default' => ! filled(env('INTERAKT_TEMPLATE_AMC_REMINDER_LANGUAGE')),
            'display_name' => env('INTERAKT_TEMPLATE_AMC_REMINDER_DISPLAY', 'AMC Reminder'),
            'purpose' => 'AMC Reminder',
        ],
        'support_appointment_booked' => [
            'enabled' => filter_var(env('INTERAKT_TEMPLATE_SUPPORT_APPOINTMENT_BOOKED_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'name' => env('INTERAKT_TEMPLATE_SUPPORT_APPOINTMENT_BOOKED'),
            'language_code' => env('INTERAKT_TEMPLATE_SUPPORT_APPOINTMENT_BOOKED_LANGUAGE', 'en'),
            'language_code_is_default' => ! filled(env('INTERAKT_TEMPLATE_SUPPORT_APPOINTMENT_BOOKED_LANGUAGE')),
            'display_name' => env('INTERAKT_TEMPLATE_SUPPORT_APPOINTMENT_BOOKED_DISPLAY', 'Support Appointment Booked'),
            'purpose' => 'Support Appointment Booked',
            'internal_note' => 'Confirms support appointment booking with preferred date and time slot.',
        ],
        'customer_waiting_followup' => [
            'enabled' => filter_var(env('INTERAKT_TEMPLATE_CUSTOMER_WAITING_FOLLOWUP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'name' => env('INTERAKT_TEMPLATE_CUSTOMER_WAITING_FOLLOWUP', 'support_schedule_followup'),
            'language_code' => env('INTERAKT_TEMPLATE_CUSTOMER_WAITING_FOLLOWUP_LANGUAGE', 'en'),
            'language_code_is_default' => ! filled(env('INTERAKT_TEMPLATE_CUSTOMER_WAITING_FOLLOWUP_LANGUAGE')),
            'display_name' => env('INTERAKT_TEMPLATE_CUSTOMER_WAITING_FOLLOWUP_DISPLAY', 'Support Reminder'),
            'purpose' => 'Customer Waiting Follow-up',
            'internal_note' => 'Reminder that support is paused until the customer shares requested details.',
            // support_schedule_followup (en): static header "Support Reminder"; body {{1}} = customer name; body {{2}} = support request reference;
            // CTA "Book Support" dynamic URL /support/schedule/{{1}}?source=whatsapp with tracked token in {{1}}.
        ],
    ],
];
