<?php

return [
    'collapse_window_seconds' => 5,

    'limits' => [
        'per_stream' => 12,
        'fetch' => 60,
    ],

    'fallback_streams' => [
        'automation_actor' => 'ira',
        'human_actor' => 'team',
    ],

    'streams' => [
        'customer' => [
            'label' => 'Customer Activity',
            'collapsed_default' => false,
        ],
        'team' => [
            'label' => 'Team Activity',
            'collapsed_default' => false,
        ],
        'ira' => [
            'label' => 'IRA Activity',
            'collapsed_default' => true,
        ],
    ],

    'groups' => [
        'communication' => [
            'events' => [
                'communication_action.lifecycle',
                'notification.dispatched',
                'notification.skipped',
                'whatsapp.template_sent',
            ],
            'title' => 'Communication Sent',
            'variant' => 'communication',
            'pill' => 'Communication',
            'stream' => 'team',
        ],
    ],

    'events' => [
        'service_case.automation.payment_received' => [
            'title' => 'Payment Received',
            'variant' => 'success',
            'pill' => 'Payment',
            'stream' => 'customer',
            'allow_automation_actor' => true,
        ],
        'service_case.automation.waiting_radiumbox' => [
            'title' => 'Waiting for RadiumBox',
            'variant' => 'warning',
            'pill' => 'IRA',
            'stream' => 'ira',
        ],
        'service_case.automation.radiumbox_verified' => [
            'title' => 'RadiumBox Verified',
            'variant' => 'success',
            'pill' => 'IRA',
            'stream' => 'ira',
        ],
        'service_case.automation.validation_passed' => [
            'title' => 'Validation Passed',
            'variant' => 'success',
            'pill' => 'IRA',
            'stream' => 'ira',
        ],
        'service_case.automation.validation_failed' => [
            'title' => 'Validation Failed',
            'variant' => 'error',
            'pill' => 'IRA',
            'stream' => 'ira',
        ],
        'service_case.automation.waiting_manual_correction' => [
            'title' => 'Waiting for Customer Input',
            'variant' => 'warning',
            'pill' => 'IRA',
            'stream' => 'ira',
        ],
        'service_case.automation_pending' => [
            'title' => 'Automation Pending',
            'variant' => 'automation',
            'pill' => 'IRA',
            'stream' => 'ira',
            'hidden' => true,
        ],
        'service_case.customer_waiting_started' => [
            'title' => 'Waiting for Customer',
            'variant' => 'warning',
            'pill' => 'Assignment',
            'stream' => 'team',
        ],
        'service_case.customer_waiting_auto_closed' => [
            'title' => 'Closed — Customer Not Responding',
            'variant' => 'muted',
            'pill' => 'IRA',
            'stream' => 'ira',
        ],
        'service_case.assigned' => [
            'title' => 'Service Case Assigned',
            'variant' => 'muted',
            'pill' => 'Assignment',
            'stream' => 'team',
        ],
        'service_case.reassigned' => [
            'title' => 'Service Case Reassigned',
            'variant' => 'muted',
            'pill' => 'Assignment',
            'stream' => 'team',
        ],
        'service_case.status_changed' => [
            'title' => 'Status Updated',
            'variant' => 'muted',
            'pill' => 'Status',
            'stream' => 'team',
        ],
        'service_case.escalated' => [
            'title' => 'Service Case Escalated',
            'variant' => 'warning',
            'pill' => 'Escalation',
            'stream' => 'team',
        ],
        'notification.dispatched' => [
            'title' => 'Notification Sent',
            'variant' => 'communication',
            'pill' => 'Communication',
            'stream' => 'team',
        ],
        'notification.skipped' => [
            'title' => 'Notification Skipped',
            'variant' => 'muted',
            'pill' => 'Communication',
            'stream' => 'team',
        ],
        'communication_action.lifecycle' => [
            'title' => 'Communication Sent',
            'variant' => 'communication',
            'pill' => 'Communication',
            'stream' => 'team',
        ],
        'service_reference.driver_guide_sent' => [
            'title' => 'Driver Guide Sent',
            'variant' => 'communication',
            'pill' => 'Driver Guide',
            'stream' => 'team',
        ],
        'whatsapp.template_sent' => [
            'title' => 'WhatsApp Message Sent',
            'variant' => 'communication',
            'pill' => 'WhatsApp',
            'stream' => 'ira',
        ],
        'whatsapp.template_failed' => [
            'title' => 'WhatsApp Message Failed',
            'variant' => 'error',
            'pill' => 'WhatsApp',
            'stream' => 'ira',
        ],
        'incoming_email.linked' => [
            'title' => 'Email Linked',
            'variant' => 'communication',
            'pill' => 'Email',
            'stream' => 'customer',
        ],
        'incoming_email.received' => [
            'title' => 'Email Received',
            'variant' => 'communication',
            'pill' => 'Email',
            'stream' => 'customer',
        ],
        'incoming_email.promoted_to_service_case' => [
            'title' => 'Email Promoted to Service Case',
            'variant' => 'communication',
            'pill' => 'Email',
            'stream' => 'customer',
        ],
        'incoming_email.historical_customer' => [
            'title' => 'Email Received',
            'variant' => 'communication',
            'pill' => 'Email',
            'stream' => 'customer',
        ],
        'created' => [
            'title' => 'Remark Added',
            'variant' => 'remark',
            'pill' => 'Remark',
            'stream' => 'team',
            'remark_only' => true,
        ],
        'serial.corrected_by_ira' => [
            'title' => 'Serial Corrected by IRA',
            'variant' => 'automation',
            'pill' => 'IRA',
            'stream' => 'ira',
        ],
        'serial.assigned' => [
            'title' => 'Serial Assigned',
            'variant' => 'muted',
            'pill' => 'Assignment',
            'stream' => 'team',
        ],
        'order.updated' => [
            'title' => 'Order Updated',
            'variant' => 'muted',
            'pill' => 'Order',
            'stream' => 'team',
        ],
        'order.identity.corrected' => [
            'title' => 'Order Identity Corrected',
            'variant' => 'muted',
            'pill' => 'Order',
            'stream' => 'team',
        ],
        'refund.approved' => [
            'title' => 'Refund Approved',
            'variant' => 'success',
            'pill' => 'Refund',
            'stream' => 'team',
        ],
        'refund.rejected' => [
            'title' => 'Refund Rejected',
            'variant' => 'error',
            'pill' => 'Refund',
            'stream' => 'team',
        ],
        'refund.completed' => [
            'title' => 'Refund Completed',
            'variant' => 'success',
            'pill' => 'Refund',
            'stream' => 'team',
        ],
        'radiumbox.sync.manual' => [
            'title' => 'RadiumBox Synced',
            'variant' => 'success',
            'pill' => 'Sync',
            'stream' => 'team',
        ],
        'radiumbox.sync.background_completed' => [
            'title' => 'RadiumBox Synced',
            'variant' => 'success',
            'pill' => 'IRA',
            'stream' => 'ira',
            'hidden' => true,
        ],
        'legacy_order.imported' => [
            'title' => 'Legacy Order Imported',
            'variant' => 'muted',
            'pill' => 'Order',
            'stream' => 'team',
        ],
        'deleted' => [
            'title' => 'Remark Deleted',
            'variant' => 'remark',
            'pill' => 'Remark',
            'stream' => 'team',
            'remark_only' => true,
        ],
        'missed_call_recovery.created' => [
            'title' => 'Missed Call',
            'variant' => 'communication',
            'pill' => 'IVR',
            'stream' => 'customer',
        ],
    ],
];
