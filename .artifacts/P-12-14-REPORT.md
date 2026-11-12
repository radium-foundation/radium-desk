# P-12-14 — WS-06 hardware/dashboard HTTP 302 remediation

| Field | Value |
|--------|--------|
| **Prompt** | RadiumDesk-P-12-14 |
| **Worktree** | `.worktrees/p12-14-ws-06-hardware` |
| **Branch** | `feature/p12-14-ws-06-hardware` |
| **Base** | `origin/main` @ `b89187fb` |
| **Policy** | `docs/RADIUMWEBSITES_TESTING_AND_VALIDATION_POLICY.md` — focused PHPUnit only |

---

## Prechecks

| Check | Result |
|--------|--------|
| Bounded `git fetch origin main` | **PASS** (~3.5s) → `b89187fb` |
| Wallet WIP `feature/p18-33-unified-wallet-display` @ `d9481615` | **UNCHANGED** |
| PR **#166** (WS-02 Order factory) | **OPEN**, CI **SUCCESS**, mergeable |
| PR **#167** (WS-04 OPM wallet flags) | **OPEN**, CI **SUCCESS**, mergeable |
| P-12-09 ws trees | **Not used** for implementation |

**Dependency note:** WS-06 is independent of #166/#167; no code conflict. Remaining matrix items in WS-04/WS-02 still blocked on those PR merges.

---

## Investigation (P-12-04 matrix + appendix)

**WS-06 root cause:** `http-redirect-instead-of-200` after Operations Workspace Phase 1 canonical `workspace=` redirects (`DashboardController` lines 53–63). Legacy `queue=` / `filter=` URLs correctly 302 for bookmarking (`HardwareDashboardCanonicalRedirectTest`, `AgentWorkspaceDeepLinkTest`), but **~38 stable** dashboard/hardware tests still called legacy URLs with `assertOk()`.

| Group | WS | Count (stable @ `5e640b74`) | Fix strategy |
|--------|-----|---------------------------|--------------|
| `http-redirect-instead-of-200` | WS-06 | 33 | `dashboard/c360` + hardware dashboard |
| `http-redirect-instead-of-200` | WS-06 | 5 | ready queue / assignment (same redirect) |
| **Production gap** | — | — | `canonicalDashboardQuery()` dropped `hw_filter`, `hw_scope`, `hw_queue` on redirect |

Honesty: Prompt title “~302 hardware failures” maps to **HTTP 302** (not ~302 test count). Matrix stable **38** WS-06 failures; hardware-named substring in jsonl ≈ 32 lines (overlap with dashboard/c360).

---

## Implementation

1. **`OperationsWorkspaceResolver::canonicalDashboardQuery`** — preserve `hw_filter`, `hw_scope`, `hw_queue` when canonicalizing legacy dashboard URLs.
2. **`Tests\TestCase::dashboardQuery()`** — map legacy `queue`/`filter` to `workspace=` for assertions expecting HTTP 200 (no redirect-loop tests weakened).
3. **Feature tests** — WS-06 matrix classes updated to use `dashboardQuery()`; `withoutVite()` where dashboard SSR lacked manifest in worktree.
4. **Regression:** `OperationsWorkspaceResolverTest::test_canonical_dashboard_query_preserves_hardware_sub_filters`.

---

## Focused validation (local)

| Suite | Result |
|--------|--------|
| WS-06 batch (16 classes, 162 tests) | **135 PASS** / 27 FAIL |
| HTTP **302** in batch | **0** (WS-06 target met) |
| Remaining 27 FAIL | Pre-existing assertion/Vite/env (outside WS-06 redirect class) |

Sample hardware filters: `HardwareDashboardNeedsActionQueueTest`, `HardwareDashboardCanonicalRedirectTest`, `OperationsWorkspaceResolverTest` — **PASS**.

---

## Git / PR

- Branch pushed: `feature/p12-14-ws-06-hardware`
- PR: *(see PR URL after `gh pr create`)*

---

## Remaining stable failures (inherited matrix)

| Metric | Value |
|--------|--------|
| Pre P-12-14 stable (P-12-04/09) | **227** |
| WS-06 redirect class addressed | **~38** |
| **Estimated remaining** (no full suite re-run) | **~189** (+ PR #166/#167 not merged) |

Full suite **NOT RUN**. Deploy **NO**.
