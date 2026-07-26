<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Feature flag
    |--------------------------------------------------------------------------
    |
    | When false, the dashboard falls back to the legacy My Activity feed.
    |
    */
    'enabled' => (bool) env('DASHBOARD_TEAM_ACTIVITY_ENABLED', true),

    'poll_interval_ms' => 30000,

    'user_idle_ms' => 5 * 60 * 1000,

    'history_limit' => 12,

    'max_expanded_agents' => 20,

    'ira_agent_id' => 0,

    'ira_display_name' => 'IRA',

    'ira_badge' => 'AI / Automation',

    /*
    |--------------------------------------------------------------------------
    | IRA virtual member (Team Activity)
    |--------------------------------------------------------------------------
    */
    'ira_event_allowlist' => [
        'service_case.automation.payment_received',
        'service_case.automation.waiting_radiumbox',
        'service_case.automation.radiumbox_verified',
        'service_case.automation.validation_passed',
        'service_case.automation.validation_failed',
        'service_case.automation.waiting_manual_correction',
        'service_case.automation_pending',
        'service_case.customer_waiting_auto_closed',
        'serial.corrected_by_ira',
    ],

    /*
    |--------------------------------------------------------------------------
    | IRA Today · N completion events
    |--------------------------------------------------------------------------
    |
    | One successfully processed incident = one KPI unit. Pipeline milestones
    | remain in ira_event_allowlist for Latest Activity / status only.
    |
    */
    'ira_event_count_allowlist' => [
        'service_case.automation.validation_passed',
    ],

    /*
    |--------------------------------------------------------------------------
    | Business audit events counted / shown in Team Activity
    |--------------------------------------------------------------------------
    */
    'event_allowlist' => [
        'service_case.assigned',
        'service_case.reassigned',
        'service_case.status_changed',
        'service_case.escalated',
        'service_case.customer_waiting_started',
        'notification.dispatched',
        'notification.skipped',
        'communication_action.lifecycle',
        'service_reference.driver_guide_sent',
        'whatsapp.template_sent',
        'whatsapp.template_failed',
        'incoming_email.linked',
        'incoming_email.received',
        'incoming_email.promoted_to_service_case',
        'created',
        'deleted',
        'serial.assigned',
        'serial.corrected_by_ira',
        'order.updated',
        'order.identity.corrected',
        'refund.approved',
        'refund.rejected',
        'refund.completed',
        'radiumbox.sync.manual',
        'legacy_order.imported',
        'missed_call_recovery.created',
        'approval_numbers.submitted',
        'approval_numbers.deleted',
        'user.availability_changed',
        'workforce.leave.approved',
        'service_case.automation.payment_received',
        'service_case.automation.waiting_radiumbox',
        'service_case.automation.radiumbox_verified',
        'service_case.automation.validation_passed',
        'service_case.automation.validation_failed',
        'service_case.automation.waiting_manual_correction',
        'service_case.customer_waiting_auto_closed',
    ],

    /*
    |--------------------------------------------------------------------------
    | Human operational KPI allowlist (Today · N activities only)
    |--------------------------------------------------------------------------
    |
    | One intentional business decision = one Today · N count.
    | Latest Activity and expanded history continue using event_allowlist above.
    |
    | Special filters in TeamActivityPanelService:
    | - created / deleted → Remark morph + origin=manual only
    | - whatsapp.template_sent → trigger_source=manual only
    | - incoming_email.promoted_to_service_case → manual promote (not auto-link)
    |
    */
    'event_count_allowlist' => [
        'service_case.assigned',
        'service_case.reassigned',
        'service_case.status_changed',
        'service_case.escalated',
        'whatsapp.template_sent',
        'incoming_email.promoted_to_service_case',
        'created',
        'deleted',
        'serial.assigned',
        'order.updated',
        'order.identity.corrected',
        'refund.approved',
        'refund.rejected',
        'refund.completed',
        'workforce.leave.approved',
        'approval_numbers.submitted',
        'approval_numbers.deleted',
    ],

    /*
    |--------------------------------------------------------------------------
    | Map audit event → current status overlay (Active only when none match)
    | Assignment / Remark / Status / Serial / Model must never map here.
    |--------------------------------------------------------------------------
    */
    'event_status_map' => [
        'service_case.customer_waiting_started' => 'waiting_customer',
        'notification.dispatched' => 'email',
        'communication_action.lifecycle' => 'email',
        'whatsapp.template_sent' => 'whatsapp',
        'whatsapp.template_failed' => 'whatsapp',
        'incoming_email.linked' => 'email',
        'incoming_email.received' => 'email',
        'incoming_email.promoted_to_service_case' => 'email',
        'missed_call_recovery.created' => 'on_ivr',
        'service_case.automation.payment_received' => 'ira',
        'service_case.automation.waiting_radiumbox' => 'ira',
        'service_case.automation.radiumbox_verified' => 'ira',
        'service_case.automation.validation_passed' => 'ira',
        'service_case.automation.validation_failed' => 'ira',
        'service_case.automation.waiting_manual_correction' => 'ira',
        'service_case.customer_waiting_auto_closed' => 'ira',
    ],

    /*
    |--------------------------------------------------------------------------
    | Supervisor-friendly activity labels (latest + history)
    |--------------------------------------------------------------------------
    */
    'activity_labels' => [
        'service_case.assigned' => 'Assigned',
        'service_case.reassigned' => 'Reassigned',
        'service_case.status_changed' => 'Status Changed',
        'service_case.escalated' => 'Escalated',
        'service_case.customer_waiting_started' => 'Customer Waiting',
        'notification.dispatched' => 'Email Sent',
        'notification.skipped' => 'Email Skipped',
        'communication_action.lifecycle' => 'Email Sent',
        'service_reference.driver_guide_sent' => 'Driver Guide Sent',
        'whatsapp.template_sent' => 'WhatsApp Sent',
        'whatsapp.template_failed' => 'WhatsApp Failed',
        'incoming_email.linked' => 'Email Linked',
        'incoming_email.received' => 'Email Received',
        'incoming_email.promoted_to_service_case' => 'Email Promoted',
        'created' => 'Remark Added',
        'deleted' => 'Remark Deleted',
        'serial.assigned' => 'Serial Updated',
        'serial.corrected_by_ira' => 'Serial Updated',
        'order.updated' => 'Model Updated',
        'order.identity.corrected' => 'Model Updated',
        'refund.approved' => 'Refund Approved',
        'refund.rejected' => 'Refund Rejected',
        'refund.completed' => 'Refund Completed',
        'radiumbox.sync.manual' => 'RadiumBox Synced',
        'legacy_order.imported' => 'Legacy Imported',
        'missed_call_recovery.created' => 'IVR Call',
        'approval_numbers.submitted' => 'Approval Saved',
        'approval_numbers.deleted' => 'Approval Deleted',
        'user.availability_changed' => 'Availability Changed',
        'workforce.leave.approved' => 'Leave Approved',
        'service_case.automation.payment_received' => 'IRA Payment Received',
        'service_case.automation.waiting_radiumbox' => 'IRA Waiting RadiumBox',
        'service_case.automation.radiumbox_verified' => 'IRA RadiumBox Verified',
        'service_case.automation.validation_passed' => 'IRA Validation Passed',
        'service_case.automation.validation_failed' => 'IRA Validation Failed',
        'service_case.automation.waiting_manual_correction' => 'IRA Waiting Input',
        'service_case.customer_waiting_auto_closed' => 'IRA Auto Closed',
    ],

    'statuses' => [
        'working' => ['label' => 'Active', 'tone' => 'muted'],
        'idle' => ['label' => 'Active', 'tone' => 'muted'],
        'waiting_customer' => ['label' => 'Waiting Customer', 'tone' => 'muted'],
        'on_ivr' => ['label' => 'On IVR', 'tone' => 'muted'],
        'email' => ['label' => 'Email', 'tone' => 'muted'],
        'whatsapp' => ['label' => 'WhatsApp', 'tone' => 'muted'],
        'remark' => ['label' => 'Remark', 'tone' => 'muted'],
        'assignment' => ['label' => 'Assignment', 'tone' => 'muted'],
        'status_changed' => ['label' => 'Status Changed', 'tone' => 'muted'],
        'serial_updated' => ['label' => 'Serial Updated', 'tone' => 'muted'],
        'model_updated' => ['label' => 'Model Updated', 'tone' => 'muted'],
        'refund' => ['label' => 'Refund', 'tone' => 'muted'],
        'approval' => ['label' => 'Approval', 'tone' => 'muted'],
        'auto_logout' => ['label' => 'Auto Logged Out', 'tone' => 'muted'],
        'logout' => ['label' => 'Off Duty', 'tone' => 'muted'],
        'login' => ['label' => 'Active', 'tone' => 'muted'],
        'break' => ['label' => 'Break', 'tone' => 'muted'],
        'leave' => ['label' => 'Leave', 'tone' => 'muted'],
        'off_duty' => ['label' => 'Off Duty', 'tone' => 'muted'],
        'not_started_shift' => ['label' => 'Not Started Shift', 'tone' => 'muted'],
        'ira' => ['label' => 'IRA', 'tone' => 'muted'],
        'unknown' => ['label' => 'Unknown', 'tone' => 'muted'],
    ],
];
