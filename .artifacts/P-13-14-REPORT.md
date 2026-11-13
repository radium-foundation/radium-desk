# P-13-14 Report — Stale dashboard grid/index register verification

| Field | Value |
| --- | --- |
| Global Prompt ID | **P-13-14** |
| Ledger ID | **RadiumDesk-P-13-14** |
| Worktree | `.worktrees/p13-14-stale-dashboard-verify` (detached, read-only) |
| Program historical baseline | `f358ab04a9a57623085f19c47c6e0045d9b0a7ea` |
| **Verified `origin/main` SHA** | **`b54b0a53c26f1f123cf37921306929c0fce44543`** (merge PR #216, 2026-11-13) |
| Run started | 2026-11-13 10:00:55 IST (+0530) |
| Run completed | 2026-11-13 10:02:01 IST (+0530) |
| Elapsed (focused runs) | ~47s wall (batch + per-row timings) |

## Scope

Re-run the **seven** dashboard grid/index rows listed in **`.artifacts/P-13-07-REPORT.md` §5.2** as stale register candidates (ancestor PASS, never re-run on `f358ab04`). Evidence only — no fixes, no register/CSV edits.

## Test environment (VERIFIED)

| Item | Value |
| --- | --- |
| Runner | `./bin/phpunit-isolated` with `DESK_PHP_BIN=/opt/homebrew/bin/php` |
| Database | **VERIFIED** disposable SQLite per `phpunit.xml` (`DB_CONNECTION=sqlite`, `RefreshDatabase`) |
| Production / shared DB | **NOT USED** |
| Vite | `DashboardServiceCasesTest` calls `withoutVite()` in `setUp` on current main; classification index test is non-SSR |
| Worktree mutations | **None** to application/tests/config; local `composer dump-autoload` only for autoload path resolution |

## Seven row references (from P-13-07 §5.2)

| CSV line | Test class | Test method |
| ---: | --- | --- |
| 24 | `Tests\Feature\Dashboard\DashboardClassificationIndexTest` | `test_lean_load_skips_row_only_relations` |
| 40 | `Tests\Feature\DashboardServiceCasesTest` | `test_admin_dashboard_shows_bulk_selection_and_inline_transaction_controls` |
| 41 | `Tests\Feature\DashboardServiceCasesTest` | `test_completed_service_cases_always_show_within_sla` |
| 42 | `Tests\Feature\DashboardServiceCasesTest` | `test_dashboard_defaults_to_ready_queue_filter_aligned_with_queue` |
| 46 | `Tests\Feature\DashboardServiceCasesTest` | `test_dashboard_needs_attention_filter_shows_only_active_cases_with_missing_serial` |
| 48 | `Tests\Feature\DashboardServiceCasesTest` | `test_dashboard_shows_service_case_grid_columns` |
| 49 | `Tests\Feature\DashboardServiceCasesTest` | `test_dashboard_shows_sla_warning_and_overdue_for_pending_cases` |

## Commands

**Batch (all seven):**

```bash
cd .worktrees/p13-14-stale-dashboard-verify
FILTER='test_lean_load_skips_row_only_relations|test_admin_dashboard_shows_bulk_selection_and_inline_transaction_controls|test_completed_service_cases_always_show_within_sla|test_dashboard_defaults_to_ready_queue_filter_aligned_with_queue|test_dashboard_needs_attention_filter_shows_only_active_cases_with_missing_serial|test_dashboard_shows_service_case_grid_columns|test_dashboard_shows_sla_warning_and_overdue_for_pending_cases'
DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated --filter "$FILTER" \
  tests/Feature/Dashboard/DashboardClassificationIndexTest.php \
  tests/Feature/DashboardServiceCasesTest.php
```

| Batch result | Exit | Tests | Assertions | Duration |
| --- | ---: | ---: | ---: | ---: |
| **PASS** | 0 | 7/7 | 58 | 20496 ms |

**Per-row (individual `--filter` on one method):**

| CSV | Result | Exit | Duration (ms) | Failure class |
| ---: | --- | ---: | ---: | --- |
| 24 | **VERIFIED PASS** | 0 | 2594 | — |
| 40 | **VERIFIED PASS** | 0 | 3077 | — |
| 41 | **VERIFIED PASS** | 0 | 2477 | — |
| 42 | **VERIFIED PASS** | 0 | 2773 | — |
| 46 | **VERIFIED PASS** | 0 | 2740 | — |
| 48 | **VERIFIED PASS** | 0 | 2772 | — |
| 49 | **VERIFIED PASS** | 0 | 3270 | — |

No failures; **no second repro** required.

## Per-row disposition

| CSV | Register status @ P-13-07 | Current main @ `b54b0a53` | Classification |
| ---: | --- | --- | --- |
| 24 | STILL FAILING (stale) | **PASS** | **VERIFIED PASS** — register stale |
| 40 | STILL FAILING (stale) | **PASS** | **VERIFIED PASS** — register stale |
| 41 | STILL FAILING (stale) | **PASS** | **VERIFIED PASS** — register stale |
| 42 | STILL FAILING (stale) | **PASS** | **VERIFIED PASS** — register stale |
| 46 | STILL FAILING (stale) | **PASS** | **VERIFIED PASS** — register stale |
| 48 | STILL FAILING (stale) | **PASS** | **VERIFIED PASS** — register stale |
| 49 | STILL FAILING (stale) | **PASS** | **VERIFIED PASS** — register stale |

**INFERRED:** P-13-07 ancestor PASS evidence (`489fd91e` / P-13-01 six-pack) remains valid on current main; assignment broadcast #204 did not regress these methods.

**UNKNOWN:** Whether canonical CSV/register has been updated elsewhere (this prompt did **not** read or edit CSV).

## Failures / blockers / side effects

| Type | Count |
| --- | ---: |
| Application failures | 0 |
| Harness failures | 0 |
| Environment blockers | 0 |
| Unexpected side effects | Local vendor autoload pointer refresh only (not committed) |

## Safety confirmations

| Check | Status |
| --- | --- |
| Application / test / config source changes | **NO** |
| Canonical matrix CSV / register edits | **NO** |
| Full PHPUnit suite | **NOT RUN** |
| Commit / push / PR / merge / deploy | **NO — Not performed** |
| Production access or data changes | **NO — Not performed** |
| Wallet `feature/p18-33-unified-wallet-display` @ `d9481615` | **VERIFIED** unchanged (`d94816153cd2c89f8175d29a1510b38b4c16df6b`) |

## Recommendation (informational only)

All seven rows are **VERIFIED PASS** on `origin/main` @ `b54b0a53`. A separate Owner-approved register/CSV reconciliation may mark them closed; **out of scope** for P-13-14.
