<?php

declare(strict_types=1);

/**
 * Explicit per-test execution budgets (P-15-19).
 *
 * A test that is not listed here has no additional budget. There is no
 * universal default. To allow a legitimate long test, add an entry with
 * `seconds` and a `reason`. Exceeding a listed budget fails the test.
 *
 * The six entries below are the methods P-15-18 measured above 60 seconds
 * inside a single test body during the interrupted full run at 157bd0a58.
 *
 * @return array<string, array{seconds: int, reason: string}>
 */
return [
    'Tests\\Feature\\CentralWallet\\DeskFirstWalletRefundRevokeSpokeRoutingTest::test_radiumbox_desk_first_revoke_posts_central_wallet_audit_retirement' => [
        'seconds' => 60,
        'reason' => 'P-15-18 measured 9396s between prepared and finished.',
    ],
    'Tests\\Feature\\CentralWallet\\DeskFirstWalletRefundMultiSpokeTest::test_radiumbox_client_forwards_desk_ledger_id_when_allowlisted' => [
        'seconds' => 60,
        'reason' => 'P-15-18 measured 3740s between prepared and finished.',
    ],
    'Tests\\Feature\\CentralWallet\\DeskFirstWalletRefundRevokeSpokeRoutingTest::test_radiumbox_audit_retirement_failure_leaves_refund_revocable_without_duplicate_cw_debit' => [
        'seconds' => 60,
        'reason' => 'P-15-18 measured 3269s between prepared and finished.',
    ],
    'Tests\\Feature\\CentralWallet\\CentralWalletReservationTest::test_commit_one_of_multiple_active_reservations_leaves_other_hold_intact' => [
        'seconds' => 60,
        'reason' => 'P-15-18 measured 598s between prepared and finished.',
    ],
    'Tests\\Feature\\Automation\\CustomerWaitingLifecycleTest::test_closing_incident_clears_active_waiting_state' => [
        'seconds' => 60,
        'reason' => 'P-15-18 measured 593s between prepared and finished.',
    ],
    'Tests\\Feature\\DashboardReverbMetricsConsistencyTest::test_live_reverb_metrics_for_matches_expected_variants' => [
        'seconds' => 60,
        'reason' => 'P-15-18 measured 314s between prepared and finished.',
    ],
];
