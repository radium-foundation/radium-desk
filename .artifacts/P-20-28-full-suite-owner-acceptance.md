# P-20-28 — Full-suite Owner acceptance (no rerun)

| Field | Value |
| --- | --- |
| Task | **P-20-28** |
| Owner | **PIYA** |
| Cursor ledger | `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef` |
| Authoritative log | `.artifacts/p20-24-full-suite-20261120T064034Z.log` |
| Run SHA | **`4114b5d2002aab86bebca80f49be3986ab420d40`** (superset of deploy lineage) |

## Decision

Owner authorizes **P-20-28 production deploy** **without** a new full PHPUnit run, accepting the **eight documented failures** from the P-20-24 run as release evidence.

## Eight failures accepted as documented (not waived as fixed)

1. `ChangelogServiceTest::test_newest_changelog_entry_is_not_necessarily_current` — env/manifest
2–3. `CentralWalletDeploymentDriftTest` (2) — deployment drift
4. `DashboardServiceCasesTest::test_dashboard_sla_alert_cards_filter_table` — **UNKNOWN** (historically PASS @ `27b03d19`)
5. `HardwareFulfilmentWorkQueueTest::test_rde318421_show_is_display_only_and_does_not_duplicate` — **UNKNOWN**
6. `InquiryLifecyclePhase1Test::test_customer_360_shows_case_and_enquiry_type_for_inquiry` — baseline (P-12-32)
7. `JulyLeaveReconcileCommandTest::test_reconcile_approves_pending_creates_missing_and_matches_matrix` — **OWNER EXCLUDED** (P-13-44)
8. `WalletRefundExecutionTest::test_already_closed_refund_cannot_be_recompleted` — **UNKNOWN**

**Suite summary:** 8582 tests, 8 failures, exit **INFERRED 1**, no JUnit.

## Compensating evidence (P-20-28)

- Focused GST bundle **68/68 PASS** (`phpunit-isolated`, Imagick), log `p20-28-focused-gst-bundle-*.log`
- MariaDB concurrency **7/7 PASS** (P-20-27)
- P-20-20 mint gate unchanged on deploy delta for hardware paths

**Not claimed:** production known-good from full suite.