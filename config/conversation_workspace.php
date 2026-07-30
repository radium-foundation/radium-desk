<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Customer Conversation Workspace
    |--------------------------------------------------------------------------
    |
    | Lightweight guided conversation UI for first-time enquiry calls.
    | Renders as a conditional top section inside existing Customer360.
    |
    */
    'enabled' => filter_var(
        env('CONVERSATION_WORKSPACE_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN,
    ),

    /*
    | Auto-create an inquiry case when an unknown caller is answered,
    | so Customer360 can open into the Conversation Workspace.
    */
    'auto_create_inquiry_on_answer' => filter_var(
        env('CONVERSATION_WORKSPACE_AUTO_CREATE_INQUIRY', false),
        FILTER_VALIDATE_BOOLEAN,
    ),

    'dispositions' => [
        'qualified_lead' => 'Qualified Lead',
        'existing_customer' => 'Existing Customer',
        'information_only' => 'Information Only',
        'wrong_number' => 'Wrong Number',
        'not_interested' => 'Not Interested',
        'callback_required' => 'Callback Required',
    ],

    'next_actions' => [
        'follow_up' => 'Follow-up',
        'share_quote' => 'Share Quote',
        'share_coupon' => 'Share Coupon',
        'call_tomorrow' => 'Call Tomorrow',
        'waiting_customer' => 'Waiting Customer',
        'converted' => 'Converted',
        'not_interested' => 'Not Interested',
        'existing_order' => 'Existing Order',
        'other' => 'Other',
    ],
];
