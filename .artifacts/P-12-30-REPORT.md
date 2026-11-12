# P-12-30 remediation report (Radium Desk)

| Field | Value |
| --- | --- |
| **Prompt / ledger** | RadiumDesk-P-12-30 |
| **Baseline** | `origin/main` @ **`627fb38c`** (PR **#174** merged) |
| **Worktree** | `.worktrees/p12-30-ws-08-markup` |
| **Branch** | `feature/p12-30-ws-08-markup` |
| **Workstream** | **WS-08** — markup-assertion-mismatch cluster (~6) |
| **Policy** | Focused PHPUnit only (`php -n`); no full suite; no deploy/prod |

---

## Verify

| Check | Result |
| --- | --- |
| `origin/main` @ `627fb38c` | **MATCH** |
| PR **#174** merged | **VERIFIED** |
| Wallet `feature/p18-33-unified-wallet-display` @ primary | **`d9481615`** — **unchanged** |
| P-12-09 matrix / `failures-errors.jsonl` | Read via `.worktrees/p12-03-full-suite-prep` artifacts |
| P-12-22 / P-12-26 / P-12-29 overlap | No identity/OrderTransaction changes; dashboard deferred-row pattern aligned with live/search-rows contract |

---

## WS-08 matrix rows (P-12-05 sample_tests)

| # | Test | Subsystem |
| --- | --- | --- |
| 1 | `BackfillReadyQueueCommandTest::test_skips_expired_grace_for_process_automation_pending` | other |
| 2 | `TeamTelegramWorkAssistantTest::test_daily_briefing_sent` | other |
| 3 | `WorkspaceActionDialogTest::test_assign_action_requires_remark_and_assignee` | dashboard/c360 |
| 4 | `WorkspaceDashboardAssignTest::test_live_dashboard_rows_exclude_actions_column_for_admin` | dashboard/c360 |
| 5 | `HardwareInclusiveGstInvoiceTest::test_qty_ten_serial_count_mismatch_still_fails_before_gst` | workspace/hardware |
| 6 | `StatutoryInvoicePdfCustomerFacingSkuTest::test_product_pos_integration_pdf_omits_sku_but_preserves_financial_columns` | pdf/invoices |

---

## Root causes (per failure)

| Test | Classification | Root cause |
| --- | --- | --- |
| Backfill grace | **App defect** | Expired `automation_pending_until` skip ran **after** enrichment dispatch; grace cases were **processed** instead of skipped with `awaiting_grace_processor`. |
| TeamTelegram briefing | **Not reproduced** on `627fb38c` | **PASSED** locally (baseline stale / already fixed upstream). |
| Workspace assign timeline | **App defect** | `shouldRemoveFromAdminReadyQueue` rebuilt `WorkspaceRefreshEffects` without `targetSelectors`, dropping `#activity-timeline` refresh HTML. |
| Dashboard row markup | **Stale harness** | Ready Queue rows load via **`dashboard.service-cases.search-rows`** / lazy shell; `dashboard.live` `rows.0.html` empty in test harness. |
| Hardware GST serial mismatch | **Not reproduced** on `627fb38c` | **PASSED** locally. |
| Statutory POS PDF integration | **Env + harness** | Full `StatutoryDocumentService::generate()` requires **Imagick** for brand assets; local **SKIPPED**; CI with Imagick runs assertions. |

---

## Preserved / Extended / Changed / Removed

| Category | Items |
| --- | --- |
| **Preserved** | Wallet WIP `d9481615`; customer-facing dashboard row blade; statutory PDF layout for Desk Service PO header |
| **Extended** | Backfill grace gate before enrichment; assign refresh keeps timeline targets when row removed from Ready Queue |
| **Changed** | `BackfillReadyQueueCommand::resolveDecision`; `WorkspaceAssignActionService::buildSuccessResponse`; `WorkspaceDashboardAssignTest` row fetch contract; statutory integration Imagick guard + `generate()` before PDF read |
| **Removed** | Duplicate late-only grace skip (logic consolidated earlier in decision chain) |

---

## Focused tests (`PHP_INI_SCAN_DIR=` + `/opt/homebrew/bin/php -n`)

| Suite | Result | Exit | Notes |
| --- | --- | ---: | --- |
| `BackfillReadyQueueCommandTest::test_skips_expired_grace…` | **PASS** | 0 | |
| `TeamTelegramWorkAssistantTest::test_daily_briefing_sent` | **PASS** | 0 | |
| `WorkspaceActionDialogTest::test_assign_action_requires_remark_and_assignee` | **PASS** | 0 | |
| `WorkspaceDashboardAssignTest` (4 methods) | **PASS** | 0 | |
| `HardwareInclusiveGstInvoiceTest::test_qty_ten_serial_count_mismatch…` | **PASS** | 0 | |
| `StatutoryInvoicePdfCustomerFacingSkuTest::test_product_pos_integration…` | **SKIPPED** | 0 | No Imagick locally |
| `WorkspaceAssignActionTest::test_assign_action_returns_service_case_refresh_payload` | **PASS** | 0 | Regression for timeline refresh |

Log: `.artifacts/phpunit-ws08-final.log`

| Check | Status |
| --- | --- |
| Full PHPUnit suite | **NOT RUN** |
| Deploy / production | **NO** |
| Pint (`vendor/bin/pint --dirty`) | **PASS** |

---

## Matrix impact (estimate)

| Metric | Value |
| --- | --- |
| Post–P-12-29 estimate | **~213** remaining stable failures |
| WS-08 addressed (proven locally) | **−2** app defects + **−2** harness updates; **−2** already green on main |
| Overall remaining (estimate) | **~209** (not re-measured on full suite) |

---

## Wallet preservation

| Check | Value |
| --- | --- |
| Primary clone `feature/p18-33-unified-wallet-display` | **`d9481615`** — **unchanged** |
| Wallet / payment paths in diff | **0** |

---

## Recommended next workstream

**WS-10** assertion-data-mismatch remainder (~108) or **WS-11** tails (`TeamTelegramWorkAssistantTest::test_slot_reminder_sends_once`, PDF PHP errors) with focused runs.
