# P-13-13 Report — Sidebar and settings labels cluster

| Field | Value |
| --- | --- |
| Global Prompt ID | **P-13-13** |
| Ledger ID | **RadiumDesk-P-13-13** |
| Branch | `feature/p13-13-sidebar-labels` |
| Worktree | `.worktrees/p13-13-sidebar-labels` |
| Baseline | `a7df720b606aea90e479d171c211d4857b61bdab` (`origin/main` post PR #214) |
| Program baseline | `f358ab04a9a57623085f19c47c6e0045d9b0a7ea` |
| Report started | 2026-11-13 09:43:00 IST |
| Report completed | 2026-11-13 09:52:00 IST |

## Assigned rows (8)

| CSV line | Test method | Status |
| --- | --- | --- |
| 18 | `CashBookPhase1Test::test_cash_book_appears_in_sidebar_for_employee` | **VERIFIED FIXED** (harness + stale label) |
| 70 | `HybridRealtimePhase3Test::test_system_settings_page_shows_phase_three_hybrid_features` | **VERIFIED FIXED** (harness + stale surface/copy) |
| 102 | `MonthlyAttendanceMatrixTest::test_workspace_nav_links_leave_and_hides_unimplemented_tabs` | **VERIFIED FIXED** (harness + assertion scope) |
| 123 | `PlatformIdentityTest::test_changelog_page_shows_empty_state_when_release_notes_missing` | **VERIFIED FIXED** (harness + stale version) |
| 176 | `SystemSettingsTest::test_superadmin_can_view_notification_settings` | **VERIFIED FIXED** (harness + stale copy) |
| 177 | `SystemSettingsTest::test_superadmin_can_view_settings` | **VERIFIED FIXED** (harness + stale copy) |
| 182 | `TodoControllerTest::test_layout_exposes_todo_modal_entry_points` | **VERIFIED FIXED** (harness + stale nav key) |
| 183 | `TodoControllerTest::test_sidebar_includes_todos_for_agent` | **VERIFIED FIXED** (harness + stale nav key) |

Shard source: `.artifacts/P-13-07-REPORT.md` § P-13-13.

## Findings

| Row(s) | Classification | Label | Summary |
| --- | --- | --- | --- |
| All 8 (initial) | **TEST_SETUP** | **VERIFIED** | `ViteManifestNotFoundException` on SSR layouts in isolated worktrees — fixed with `withoutVite()` in six test classes (same pattern as P-12-50 / P-13-02). |
| 18 | Stale assertion | **VERIFIED** | Root sidebar shows workspace destination **Finance** (`data-nav-key="finance.destination"`), not literal “Cash Book”; cash-book route unchanged. |
| 70 | Stale assertion | **VERIFIED** | Hybrid realtime toggles live on **Platform Configuration** (`admin.platform-configuration.index`), not operational settings; section title **Delivery Preferences** replaces legacy “Notification Delivery”. |
| 102 | Stale assertion | **VERIFIED** | `assertDontSee('Calendar')` matched **Holiday Calendar** in Control & Admin nav; workforce tab assertions scoped to `wm-workspace-nav` only. |
| 123 | Stale assertion | **VERIFIED** | `v4.0.2` now has CHANGELOG entry; test uses synthetic `v99.99.98` for empty-state message. |
| 176, 177 | Stale assertion | **VERIFIED** | Settings center nav uses **Application** / **Notifications** labels vs legacy “Assignment” / “System Settings” strings. |
| 182, 183 | Stale assertion | **VERIFIED** | To-dos entry moved to topbar modal (`data-todo-modal-open`); `data-nav-key="personal.todos"` no longer rendered — no UI change required. |

**Application defect (labels):** **None** — no Blade or permission changes; production copy already correct.

## Labels and behaviour

| Area | Preserved | Changed in product | Changed in tests only |
| --- | --- | --- | --- |
| Sidebar destinations | Finance → cash-book, Control & Admin, Home / Desk | — | Cash Book string → Finance + nav key |
| Hybrid / notifications admin UI | Platform Configuration advanced hybrid toggles; Delivery Preferences card | — | Route, role, section title expectations |
| Workforce workspace tabs | Leave, Attendance, no Calendar/Performance tabs in WM nav | — | Scoped DOM assertions |
| Changelog empty state | `ChangelogService::missingReleaseNotesMessage()` | — | Version fixture |
| Settings center nav | Application grouping, Notifications tab | — | assertSee strings |
| To-dos | Modal + topbar affordances | — | Dropped obsolete `data-nav-key` |

## Tests

**Cluster:**

```bash
cd .worktrees/p13-13-sidebar-labels
FILTER='test_cash_book_appears_in_sidebar_for_employee|test_system_settings_page_shows_phase_three_hybrid_features|test_workspace_nav_links_leave_and_hides_unimplemented_tabs|test_changelog_page_shows_empty_state_when_release_notes_missing|test_superadmin_can_view_notification_settings|test_superadmin_can_view_settings|test_layout_exposes_todo_modal_entry_points|test_sidebar_includes_todos_for_agent'
DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated --filter "$FILTER" \
  tests/Feature/CashBook/CashBookPhase1Test.php \
  tests/Feature/HybridRealtimePhase3Test.php \
  tests/Feature/MonthlyAttendanceMatrixTest.php \
  tests/Feature/PlatformIdentityTest.php \
  tests/Feature/SystemSettingsTest.php \
  tests/Feature/Todos/TodoControllerTest.php
```

| Run | Result | Duration |
| --- | --- | --- |
| Cluster | **8/8 PASS** | 11364ms |
| Six class regressions | **69/69 PASS** | 73834ms |

Full PHPUnit suite: **NOT RUN**.

## Git / CI

| Item | Status |
| --- | --- |
| Commit | `d3555ce4` |
| PR | [#215](https://github.com/radium-foundation/radium-desk/pull/215) |
| CI | **rip** + **release-rip** PASS ([rip](https://github.com/radium-foundation/radium-desk/actions/runs/43364838239)) |
| Merge SHA | **`749f96e1f674ebb14dcca66d99214b9e56e9ae3c`** |

## Risks / Owner

None — test-only. No navigation redesign.

## Production / deployment

**NO — Not performed.**

## Wallet preservation

`feature/p18-33-unified-wallet-display` @ **`d94816153cd2c89f8175d29a1510b38b4c16df6b`** — **VERIFIED** unchanged.

## Stage durations

| Stage | Duration |
| --- | --- |
| Worktree + repro | ~5 min |
| Harness + assertion updates | ~7 min |
| Validation + git | ~2 min |
