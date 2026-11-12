# Ledger claim — RadiumDesk-P-12-47 (WS-10 Operations Control Center Vite harness)

| Field | Value |
| --- | --- |
| Prompt | **P-12-47** | **CLAIMED** |
| Date | 2026-11-12 IST |
| Baseline | `origin/main` @ **`801c6a19`** (includes **#196** + **#197**) |
| Matrix slice | WS-10 Operations Control Center SSR / Ira briefing (`ControlCenterFirstPaintTest`, `IraOperationsBrainTest::test_admin_dashboard_shows_ira_briefing_panel`) |
| Cluster | Operations Control Center **Vite test harness** (missing `withoutVite()`); stale reconcile for `OperationsDashboardTest` + `AutomationOperationsDashboardTest` @ `801c6a19` |
| Worktree | `.worktrees/p12-47-ws10-dashboard` → `feature/p12-47-ws10-dashboard` |
| Wallet WIP | **`d94816153cd2c89f8175d29a1510b38b4c16df6b`** — **verify only, not modified** |
| Policy | No full suite; `bin/phpunit-isolated` focused only; no WS-04 OPM |
