# P-13-02 — RefundRequest dashboard status-count failure

| Field | Value |
| --- | --- |
| **Global prompt** | P-13-02 |
| **Ledger ID** | RadiumDesk-P-13-02 |
| **Baseline** | `origin/main` @ `489fd91e` (#202) |
| **Worktree / branch** | `.worktrees/p13-02-refund-dashboard` → `feature/p13-02-refund-dashboard` |
| **Classification** | **TEST_SETUP** (primary); **HARNESS** adjunct (`withoutVite()`) |
| **Wallet preservation** | `feature/p18-33-unified-wallet-display` @ `d9481615` — read-only verify; **no changes** |
| **Full suite** | **NO** |
| **Production** | **NO** |

## Root cause

1. **App/product (context, not a defect):** Support agents use `OperationsRoleService::usesSupportQueues()`; `dashboard/partials/kpi-strip.blade.php` renders **agent action cards** and **excludes** the legacy KPI strip Refunds card when `$usesSupportQueues` is true (`! $usesSupportQueues` guard on line 62). `AgentDashboardRedesignTest` permanently expects agents **not** to see `dashboard-kpi-label">Refunds<`. `DashboardService::statsFor()` still aggregates `pending_refunds` for users with `refunds.view`.
2. **Test:** `RefundRequestTest::test_dashboard_shows_refund_status_counts` still used `assertSee('Refunds')` on agent dashboard HTML — stale after agent dashboard redesign (pre-existing per P-06-05 / P-12-32 matrix).
3. **Harness (local only):** Without `public/build/manifest.json` or `withoutVite()`, dashboard GET can 500 with `ViteManifestNotFoundException`; CI/with manifest surfaced the assertion failure instead.

**Not root cause:** Refund SQL aggregation (`DashboardKpiAggregator::refundStatusCounts()`), SSR module loading, or wallet work.

## Changes

| File | Change |
| --- | --- |
| `tests/Feature/RefundRequestTest.php` | `withoutVite()` in `setUp()`; agent test asserts `statsFor()` `pending_refunds === 1` + `assertDontSee` legacy Refunds KPI label |
| `docs/cursor-prompt-ledger.md` | Claim **RadiumDesk-P-13-02** |

## Tests (focused)

```bash
cd .worktrees/p13-02-refund-dashboard
composer dump-autoload -o   # when vendor not present
DESK_PHP_BIN=/opt/homebrew/bin/php bin/phpunit-isolated \
  tests/Feature/RefundRequestTest.php \
  tests/Feature/AgentDashboardRedesignTest.php \
  tests/Feature/DashboardLiveMetricCountsTest.php \
  tests/Feature/OperationsModelFoundationTest.php \
  --filter 'RefundRequestTest|test_dashboard_shows_refund_status|test_admin_dashboard_shows_all_refund|test_agent_dashboard|pending_refund'
```

**Result:** 25 tests, 140 assertions, **PASS** (~44s).

## Git / PR

| Item | SHA / link |
| --- | --- |
| Commit | `e4e5f267` |
| PR | [#203](https://github.com/radium-foundation/radium-desk/pull/203) |
| Merge | `7fae635b759eb5ca03a934a776ab91b7b8c7457c` |
| CI | `rip` + `release-rip` **PASS** (runs 43292809570 / 43292809652) |

## Matrix row outcomes (CSV **not** edited)

| Matrix test id | WS | Prior status | Outcome @ P-13-02 |
| --- | --- | --- | --- |
| `Tests.Feature.RefundRequestTest::test_dashboard_shows_refund_status_counts` | WS-10 | STILL FAILING | **VERIFIED FIXED** @ `e4e5f267` / merge `7fae635b` |

## Timestamps (IST)

| Phase | Start | End | Duration |
| --- | --- | --- | --- |
| Investigation + worktree | 2026-11-13 01:07 | 2026-11-13 01:10 | ~3m |
| Fix + local PHPUnit | 2026-11-13 01:10 | 2026-11-13 01:12 | ~2m |
| PR + CI + merge | 2026-11-13 01:12 | 2026-11-13 01:13 | ~1m |

## Remaining risks

- Other refund dashboard tests still rely on admin KPI HTML (`test_admin_dashboard_shows_all_refund_status_counts`) — correct for admin queues.
- Agent refund visibility in UI is via Finance nav / embedded refunds workspace, not KPI strip; live count endpoint `pending_refunds` unchanged.
