<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Collapse Window
    |--------------------------------------------------------------------------
    |
    | Repeated lifecycle events for the same entity within this window (seconds)
    | are merged into a single operator-friendly activity card.
    |
    */
    'collapse_window_seconds' => 5,

    /*
    |--------------------------------------------------------------------------
    | Event Groups
    |--------------------------------------------------------------------------
    |
    | Events sharing a group key are candidates for collapse when they occur
    | close together on the same auditable record.
    |
    */
    'groups' => [
        'communication' => [
            'events' => [
                'communication_action.lifecycle',
                'notification.dispatched',
                'notification.skipped',
                'whatsapp.template_sent',
            ],
            'title' => 'Communication Sent',
            'icon' => '💬',
            'variant' => 'communication',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Presentation
    |--------------------------------------------------------------------------
    |
    | Keys are audit log event names. Add new events here instead of editing
    | Blade templates. Supported fields:
    | - title: operator-facing headline
    | - icon: emoji icon shown beside the title
    | - source: source badge label (null = derive from payload)
    | - variant: success | warning | communication | automation | remark | error | muted
    | - hidden: when true, the event is omitted from the feed (audit preserved)
    |
    */
    'events' => [
        'service_case.automation.payment_received' => [
            'title' => 'Payment Received',
            'icon' => '💰',
            'source' => 'Automation',
            'variant' => 'success',
        ],
        'service_case.automation.waiting_radiumbox' => [
            'title' => 'Waiting for RadiumBox',
            'icon' => '⏳',
            'source' => 'Automation',
            'variant' => 'warning',
        ],
        'service_case.automation.radiumbox_verified' => [
            'title' => 'RadiumBox Verified',
            'icon' => '✅',
            'source' => 'Automation',
            'variant' => 'success',
        ],
        'service_case.automation.validation_passed' => [
            'title' => 'Validation Passed',
            'icon' => '✅',
            'source' => 'Automation',
            'variant' => 'success',
        ],
        'service_case.automation.validation_failed' => [
            'title' => 'Validation Failed',
            'icon' => '⚠️',
            'source' => 'Automation',
            'variant' => 'error',
        ],
        'service_case.automation.waiting_manual_correction' => [
            'title' => 'Waiting for Customer Input',
            'icon' => '⏳',
            'source' => 'Automation',
            'variant' => 'warning',
        ],
        'service_case.automation_pending' => [
            'title' => 'Automation Pending',
            'icon' => '🤖',
            'source' => 'Automation',
            'variant' => 'automation',
            'hidden' => true,
        ],
        'service_case.customer_waiting_started' => [
            'title' => 'Waiting for Customer',
            'icon' => '⏳',
            'source' => 'Automation',
            'variant' => 'warning',
        ],
        'service_case.customer_waiting_auto_closed' => [
            'title' => 'Closed — Customer Not Responding',
            'icon' => '🔒',
            'source' => 'Automation',
            'variant' => 'muted',
        ],
        'service_case.assigned' => [
            'title' => 'Service Case Assigned',
            'icon' => '👤',
            'source' => 'Manual',
            'variant' => 'muted',
        ],
        'service_case.reassigned' => [
            'title' => 'Service Case Reassigned',
            'icon' => '👤',
            'source' => 'Manual',
            'variant' => 'muted',
        ],
        'service_case.status_changed' => [
            'title' => 'Status Updated',
            'icon' => '🎫',
            'source' => 'Manual',
            'variant' => 'muted',
        ],
        'service_case.escalated' => [
            'title' => 'Service Case Escalated',
            'icon' => '🎫',
            'source' => 'Manual',
            'variant' => 'warning',
        ],
        'notification.dispatched' => [
            'title' => 'Notification Sent',
            'icon' => '🔔',
            'source' => null,
            'variant' => 'communication',
        ],
        'notification.skipped' => [
            'title' => 'Notification Skipped',
            'icon' => '🔔',
            'source' => 'Automation',
            'variant' => 'muted',
        ],
        'communication_action.lifecycle' => [
            'title' => 'Communication Sent',
            'icon' => '💬',
            'source' => null,
            'variant' => 'communication',
        ],
        'service_reference.driver_guide_sent' => [
            'title' => 'Driver Guide Sent',
            'icon' => '📘',
            'source' => 'Automation',
            'variant' => 'communication',
        ],
        'whatsapp.template_sent' => [
            'title' => 'WhatsApp Message Sent',
            'icon' => '💬',
            'source' => 'WhatsApp',
            'variant' => 'communication',
        ],
        'whatsapp.template_failed' => [
            'title' => 'WhatsApp Message Failed',
            'icon' => '💬',
            'source' => 'WhatsApp',
            'variant' => 'error',
        ],
        'incoming_email.linked' => [
            'title' => 'Email Linked',
            'icon' => '✉️',
            'source' => 'Email',
            'variant' => 'communication',
        ],
        'incoming_email.received' => [
            'title' => 'Email Received',
            'icon' => '✉️',
            'source' => 'Email',
            'variant' => 'communication',
        ],
        'incoming_email.promoted_to_service_case' => [
            'title' => 'Email Promoted to Service Case',
            'icon' => '✉️',
            'source' => 'Email',
            'variant' => 'communication',
        ],
        'created' => [
            'title' => 'Remark Added',
            'icon' => '📝',
            'source' => 'Manual',
            'variant' => 'remark',
            'remark_only' => true,
        ],
        'serial.corrected_by_ira' => [
            'title' => 'Serial Corrected by IRA',
            'icon' => '🤖',
            'source' => 'Automation',
            'variant' => 'automation',
        ],
        'serial.assigned' => [
            'title' => 'Serial Assigned',
            'icon' => '📦',
            'source' => 'Manual',
            'variant' => 'muted',
        ],
        'order.updated' => [
            'title' => 'Order Updated',
            'icon' => '📦',
            'source' => 'Manual',
            'variant' => 'muted',
        ],
        'order.identity.corrected' => [
            'title' => 'Order Identity Corrected',
            'icon' => '📦',
            'source' => 'Manual',
            'variant' => 'muted',
        ],
        'refund.approved' => [
            'title' => 'Refund Approved',
            'icon' => '💰',
            'source' => 'Manual',
            'variant' => 'success',
        ],
        'refund.rejected' => [
            'title' => 'Refund Rejected',
            'icon' => '💰',
            'source' => 'Manual',
            'variant' => 'error',
        ],
        'refund.completed' => [
            'title' => 'Refund Completed',
            'icon' => '💰',
            'source' => 'Manual',
            'variant' => 'success',
        ],
        'radiumbox.sync.manual' => [
            'title' => 'RadiumBox Synced',
            'icon' => '🔄',
            'source' => 'Manual',
            'variant' => 'success',
        ],
        'radiumbox.sync.background_completed' => [
            'title' => 'RadiumBox Synced',
            'icon' => '🔄',
            'source' => 'Automation',
            'variant' => 'success',
            'hidden' => true,
        ],
        'legacy_order.imported' => [
            'title' => 'Legacy Order Imported',
            'icon' => '📦',
            'source' => 'Manual',
            'variant' => 'muted',
        ],
        'deleted' => [
            'title' => 'Remark Deleted',
            'icon' => '📝',
            'source' => 'Manual',
            'variant' => 'remark',
            'remark_only' => true,
        ],
    ],
];
