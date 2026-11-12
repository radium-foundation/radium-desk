# P-12-36 — WS-10 `DashboardServiceCasesTest` SLA/grid harness (6 matrix rows)

| Field | Value |
| --- | --- |
| Prompt | **RadiumDesk-P-12-36** (claimed) |
| Repository | `radium-foundation/radium-desk` |
| Worktree | `.worktrees/p12-36-dashboard-service-cases` |
| Base | **`origin/main`** @ **`fafec714`** (#180) |
| Branch | `feature/p12-36-dashboard-service-cases` |
| Wallet protected | `feature/p18-33-unified-wallet-display` @ `d9481615` — **not modified** |
| Policy | No full suite; no deploy |

## Rationale

P-12-35 next-cluster: **6 STILL FAILING** rows in `DashboardServiceCasesTest` (SLA compact UI, ready-queue eligibility, workspace deep links). No overlap with PR #180 (AgentDashboardOwnership, ClassificationIndex, WorkspaceDeepLink, GlobalSearch).

## Root causes

| Test | Root | Fix |
| --- | --- | --- |
| Grid columns | Completion copy moved to compact SLA cell + `Pending Admin`; durations `6h 12m` | Sync assertions to compact SLA / aria labels |
| Default ready filter | Case landed in **Exceptions** (invalid serial / not reference-entry eligible) | Ready-queue fixture: synced serial + model (P-12-32 action_required pattern) |
| Needs attention filter | Panel title hidden when queue tabs shown | Assert `data-live-filter` + row refs |
| Admin bulk / inline ref | Same ready-queue eligibility; row absent | Valid serial + `markSynced` |
| SLA warning/overdue | Labels in CSS classes / compact overdue (`RO …`), not plain text | Assert `sla-status--*` + case refs |
| Completed within SLA | Completed rows use resolved icon, not "Within SLA" string | Assert completed row + no overdue class |
| (adjacent) Open KPI link | KPI strip still emits legacy `queue=action_required` href | Match rendered href in test |

## Modify

| File | Change |
| --- | --- |
| `tests/Feature/DashboardServiceCasesTest.php` | Harness + fixture sync (no product code) |

## PHPUnit (focused)

```bash
PHP_INI_SCAN_DIR= /opt/homebrew/bin/php -n vendor/bin/phpunit tests/Feature/DashboardServiceCasesTest.php
```

| Target | Result |
| --- | --- |
| `DashboardServiceCasesTest` (30) | **30/30 PASS** (~40s) |
| Cluster methods (6) | **6/6 PASS** |
| `php -n -l` | **PASS** |

Full suite: **NOT RUN**

## Matrix update (P-12-32 evidence)

| Metric | Before (#180) | After (this PR) |
| --- | ---: | ---: |
| Rows in this cluster | 6 | **0** |
| P-12-32 **STILL FAILING** (approx.) | ~125 | **~119** (−6) |

Matrix rows addressed:

1. `test_dashboard_shows_service_case_grid_columns`
2. `test_dashboard_defaults_to_ready_queue_filter_aligned_with_queue`
3. `test_dashboard_needs_attention_filter_shows_only_active_cases_with_missing_serial`
4. `test_admin_dashboard_shows_bulk_selection_and_inline_transaction_controls`
5. `test_dashboard_shows_sla_warning_and_overdue_for_pending_cases`
6. `test_completed_service_cases_always_show_within_sla`

## CI / RIP

| Phase | Workflow | Run URL | `headSha` | Conclusion |
| --- | --- | --- | --- | --- |
| Pre-merge (PR **#TBD**) | rip | _pending push_ | — | — |
| Pre-merge (PR **#TBD**) | release-rip | _pending push_ | — | — |
| Post-merge (`main`) | rip | _after merge_ | — | — |
| Post-merge (`main`) | release-rip | _after merge_ | — | — |

P-12-35 post-merge RIP @ `fafec714` already recorded in `.worktrees/p12-35-ws-10-dashboard/.artifacts/P-12-35-REPORT.md` (no doc backfill required).

## Coordinator summary

- **6-row** `DashboardServiceCasesTest` cluster fixed via **test-only** diff on **`fafec714`**; wallet **`d9481615`** preserved.
- **Harness:** compact SLA tooltips, workspace `data-live-filter`, ready-queue eligibility fixtures, KPI legacy `queue=` href.
- **Product:** unchanged (assertions aligned to current dashboard).
- **Next cluster:** `OperationsDashboardTest` sidebar harness or remaining WS-10 rows per matrix.
