# Ledger claim — RadiumDesk-P-12-49 (WS-10 dashboard KPI / Ready queue overlay)

| Field | Value |
| --- | --- |
| Prompt | **P-12-49** | **CLAIMED** |
| Date | 2026-11-12 23:52 IST |
| Baseline | `origin/main` @ **`69886319`** (includes **#199** / P-12-48) |
| Matrix slice | WS-10 `OperationsModelFoundationTest` waiting-customer vs action-required KPI counts (2 methods) |
| Cluster | **VERIFIED APPLICATION DEFECT** — `DashboardSnapshot::warmQueueIncidents()` Ready overlay duplicated waiting-customer + hardware incidents into `action_required` counts |
| Worktree | `.worktrees/p12-49-ws10-app-defect` → `feature/p12-49-ws10-app-defect` |
| Wallet WIP | **`d94816153cd2c89f8175d29a1510b38b4c16df6b`** — **verify only, not modified** |
| Policy | No full suite; `bin/phpunit-isolated` focused only; no WS-04 OPM |
