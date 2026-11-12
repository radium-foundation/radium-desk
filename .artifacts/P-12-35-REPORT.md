# P-12-35 — WS-10 dashboard assertion subcluster (Agent ownership + classification lean load)

| Field | Value |
| --- | --- |
| Prompt | **RadiumDesk-P-12-35** (claimed) |
| Repository | `radium-foundation/radium-desk` |
| Worktree | `.worktrees/p12-35-ws-10-dashboard` |
| Base | **`origin/main`** @ **`fbc9c888`** (#179) |
| Branch | `feature/p12-35-ws-10-dashboard` |
| Wallet protected | `feature/p18-33-unified-wallet-display` @ `d9481615` — **not modified** |
| Policy | No full suite; no deploy |

## Rationale (smallest independent cluster)

Selected **Agent dashboard ownership + classification index** (7 matrix rows) sharing:

- **Workspace deep-link / legacy `queue=` redirects** (P-12-14 pattern) — drilldown tests used `followingRedirects()` on legacy URLs while product canonicalizes to `workspace=`.
- **Agent completed-queue assignee scope** — snapshot `incidentsForQueue('completed', $agent)` and filter counts must respect support-role assignee scope (parity with `DashboardPersonalizationService::resolveAssignedToScope`).
- **Lean classification test drift** — lean load now eager-loads `refundRequests` for classifier parity; assertions updated.

Excluded: LegacyOrder (#171–172), Customer360 x-order-identifier (P-12-22), rows already VERIFIED FIXED on P-12-32 matrix.

## Root causes

| Area | Root | Fix |
| --- | --- | --- |
| `AgentDashboardOwnershipTest` (5 drilldown/HTTP) | Legacy `queue=` → 302 canonicalization; Vite manifest in feature tests | `withoutVite()`; hit `workspace=` URLs directly |
| `test_agent_completed_classification_*` | Global completed bucket leaked into scoped agent counts | Product: scoped fast path + `scopeUserForQueue` for completed/waiting; classifier `matchesQueue` assignee guard |
| `DashboardClassificationIndexTest::test_lean_load_*` | Test forbade `refund_requests` queries now required for lean index | Sync assertions to lean-load contract |
| `DashboardGlobalSearchTest::test_dashboard_redirect_preserves_q_param` | Strict redirect URL order (`q` vs `queue`) | Assert location contains required query pairs |
| `AgentWorkspaceDeepLinkTest` | Vite; deep links use `data-workspace` not `workspace=` substring in `queue=` hrefs | `withoutVite()`; assert `data-workspace` |

## Modify

| File | Change |
| --- | --- |
| `app/Services/Dashboard/DashboardSnapshot.php` | Agent-scoped completed queue resolution; `scopeUserForQueue`; `scopeQueueIncidentsForUser`; `incidentsForFilter` completed counts |
| `app/Services/Operations/OperationsQueueClassifier.php` | Scoped `matchesQueue` for completed / waiting_customer |
| `tests/Feature/AgentDashboardOwnershipTest.php` | Harness + workspace URLs + snapshot cache isolation |
| `tests/Feature/Dashboard/DashboardClassificationIndexTest.php` | Lean-load assertion sync |
| `tests/Feature/AgentWorkspaceDeepLinkTest.php` | Vite + deep-link assertions |
| `tests/Feature/DashboardGlobalSearchTest.php` | Redirect query preservation assertion |

## PHPUnit (focused)

Commands: `PHP_INI_SCAN_DIR= /opt/homebrew/bin/php -n vendor/bin/phpunit …` (worktree vendor)

| Target | Result |
| --- | --- |
| `AgentDashboardOwnershipTest` (16) | **16/16 PASS** (~21s) |
| `DashboardClassificationIndexTest` (5) | **5/5 PASS** |
| `AgentWorkspaceDeepLinkTest` (6) | **6/6 PASS** |
| `DashboardGlobalSearchTest` (11) | **11/11 PASS** |
| **Cluster total** | **38/38 PASS** (~46s) |

Full suite: **NOT RUN**

## Matrix update (P-12-32 evidence)

| Metric | Before | After |
| --- | ---: | ---: |
| Rows in this cluster | 7 | **0** |
| P-12-32 **STILL FAILING** (approx., post #178) | 132 | **~125** (−7) |

Matrix rows addressed:

1. `AgentDashboardOwnershipTest::test_agent_completed_classification_remains_available_without_navigation_tab`
2. `AgentDashboardOwnershipTest::test_agent_my_attention_drilldown_shows_same_assigned_cases`
3. `AgentDashboardOwnershipTest::test_agent_waiting_customer_drilldown_shows_assigned_cases_only`
4. `AgentDashboardOwnershipTest::test_admin_waiting_customer_queue_remains_global`
5. `AgentDashboardOwnershipTest::test_admin_attention_queue_remains_global`
6. `DashboardClassificationIndexTest::test_lean_load_skips_row_only_relations`
7. (Harness) `DashboardGlobalSearchTest::test_dashboard_redirect_preserves_q_param` — adjacent search-rows redirect parity

## CI / RIP

| Phase | Workflow | Run URL | `headSha` | Conclusion |
| --- | --- | --- | --- | --- |
| Pre-merge | rip | _pending PR push_ | — | — |
| Pre-merge | release-rip | _pending PR push_ | — | — |
| Post-merge | rip | _after merge_ | — | — |
| Post-merge | release-rip | _after merge_ | — | — |

## Coordinator summary

- **7-row** WS-10 dashboard assertion subcluster fixed on **`fbc9c888`** with **2 app files** + focused test harness updates.
- **Product:** agent completed-queue counts and `incidentsForQueue` now honor assignee scope for support roles.
- **Harness:** workspace deep links + Vite isolation (P-12-29 / P-12-14 patterns).
- Wallet **`d9481615`** preserved; no full suite / deploy.
- **Next cluster:** `DashboardServiceCasesTest` SLA/grid assertions (6 rows) or `OperationsDashboardTest` sidebar harness.
