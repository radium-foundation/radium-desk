<?php

use Illuminate\Support\Carbon;

return [

    /*
    |--------------------------------------------------------------------------
    | Sunday sandwich (Sat/Mon + short notice)
    |--------------------------------------------------------------------------
    */
    'sunday_sandwich' => [
        'minimum_notice_calendar_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Manual policy exceptions (per employee per calendar month)
    |--------------------------------------------------------------------------
    */
    'manual_exception' => [
        'max_approved_per_month' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Coverage groups (resolve by email — never display names)
    |--------------------------------------------------------------------------
    |
    | Triad: all three must not be on approved/pending leave on the same day.
    | Admin pair: Dileep and Avinash must not both be on leave the same day.
    |
    | Abhinav and Kanchan emails are Owner-configured (not verified in repo).
    |
    */
    'coverage' => [
        'triad_emails' => array_values(array_filter(array_map(
            static fn (string $email): string => strtolower(trim($email)),
            explode(',', (string) env(
                'WORKFORCE_LEAVE_COVERAGE_TRIAD_EMAILS',
                'shubhanshi@radiumbox.com',
            )),
        ))),
        'admin_pair_emails' => array_values(array_filter(array_map(
            static fn (string $email): string => strtolower(trim($email)),
            explode(',', (string) env(
                'WORKFORCE_LEAVE_ADMIN_PAIR_LEAVE_EMAILS',
                'dileep@radiumbox.com,avinash@radiumbox.com',
            )),
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Phased auto-approval
    |--------------------------------------------------------------------------
    |
    | Disabled by default: no leave-balance ledger exists (P-19-10 VERIFIED).
    | When enabled, only structural checks run — not entitlement/balance.
    |
    */
    'auto_approval' => [
        'enabled' => (bool) env('WORKFORCE_LEAVE_AUTO_APPROVAL_ENABLED', false),
        'phase' => (string) env('WORKFORCE_LEAVE_AUTO_APPROVAL_PHASE', 'pilot_1'),
        'pilot_1' => [
            'max_span_working_days' => 1,
            'allowed_weekdays' => [
                Carbon::TUESDAY,
                Carbon::WEDNESDAY,
                Carbon::THURSDAY,
                Carbon::FRIDAY,
            ],
        ],
        'pilot_2' => [
            'enabled' => (bool) env('WORKFORCE_LEAVE_AUTO_APPROVAL_PHASE_2_ENABLED', false),
            'max_span_working_days' => 3,
            'allowed_weekdays' => [
                Carbon::TUESDAY,
                Carbon::WEDNESDAY,
                Carbon::THURSDAY,
                Carbon::FRIDAY,
            ],
        ],
        'leave_balance_ledger_available' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Jyotsana (context only — no schedule mutation in P-19-15)
    |--------------------------------------------------------------------------
    */
    'jyotsana' => [
        'role_context' => 'off_hours_whatsapp_support',
        'reference_email' => strtolower(trim((string) env('WORKFORCE_LEAVE_JYOTSANA_EMAIL', ''))),
    ],

];
