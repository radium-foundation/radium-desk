<?php

/**
 * Refund Management workflow configuration.
 *
 * Profiles seed deduction defaults only — Ops may override every value.
 * Do not hardcode cancellation amounts in application services.
 */
return [

    'gst_rate' => (float) env('REFUND_GST_RATE', 0.18),

    'customer_preferred_methods' => [
        'wallet' => 'Wallet',
        'opm' => 'OPM (Original Payment Method)',
    ],

    'refund_methods' => [
        'wallet' => 'Wallet',
        'cashfree' => 'Cashfree',
        'bank_transfer' => 'Bank Transfer',
        'upi' => 'UPI',
        'other' => 'Other',
    ],

    'difference_reasons' => [
        'cancellation_charges' => 'Cancellation Charges',
        'engineer_visit' => 'Engineer Visit',
        'partial_refund' => 'Partial Refund',
        'goodwill' => 'Goodwill',
        'other' => 'Other',
    ],

    'profiles' => [
        'full_refund' => [
            'label' => 'Full Refund',
            'cancellation_charges' => 0,
            'gst_on_cancellation' => 0,
            'other_deduction' => 0,
            'apply_gst_rate' => false,
        ],
        'standard_cancellation' => [
            'label' => 'Standard Cancellation',
            'cancellation_charges' => (float) env('REFUND_STANDARD_CANCELLATION_CHARGES', 100),
            'gst_on_cancellation' => null,
            'other_deduction' => 0,
            'apply_gst_rate' => true,
        ],
        'engineer_visit' => [
            'label' => 'Engineer Visit',
            'cancellation_charges' => (float) env('REFUND_ENGINEER_VISIT_CHARGES', 0),
            'gst_on_cancellation' => null,
            'other_deduction' => (float) env('REFUND_ENGINEER_VISIT_OTHER_DEDUCTION', 0),
            'apply_gst_rate' => true,
        ],
        'custom' => [
            'label' => 'Custom',
            'cancellation_charges' => 0,
            'gst_on_cancellation' => 0,
            'other_deduction' => 0,
            'apply_gst_rate' => false,
        ],
    ],

];
