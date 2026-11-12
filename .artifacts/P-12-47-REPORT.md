# P-12-47 — WS-10 Operations Control Center test harness

**Programme (IST):** 2026-11-12 23:05  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk`  
**Worktree:** `.worktrees/p12-47-ws10-dashboard` → `feature/p12-47-ws10-dashboard`  
**Verification HEAD:** `origin/main` @ **`801c6a19ea25bbae043b98c830f49822864910fe`** (post **#197**)  
**Wallet checkout:** `d9481615` — **verified, not modified**  
**Full PHPUnit:** **NOT RUN** (P-12-40 policy)

---

## 1. Ledger

| Field | Value |
| --- | --- |
| Prompt | **P-12-47** |
| Ledger ID | **RadiumDesk-P-12-47** (**CLAIMED**) |
| Claim artifact | `.artifacts/ledger-claim-RadiumDesk-P-12-47.md` |
| Scope | One bounded WS-10 dashboard subcluster; matrix reconcile |

---

## 2. Repository state @ verify

| Item | Value |
| --- | --- |
| Expected baseline | `801c6a19` |
| Fetched `origin/main` | **`801c6a19`** (2026-11-12 IST) |
| P-12-47 worktree | created from `origin/main` @ `801c6a19` |
| Wallet worktree | `feature/p18-33-unified-wallet-display` @ **`d9481615`** — **verified, not modified** |
| `feature/p12-46-next-cluster` | **not touched** (merged #197) |
| `feature/p12-45-statutory-pdf-logo` | **not touched** (merged #196) |

---

## 3. PR #196 / #197 ancestry on `main`

| PR | Merge SHA | State |
| --- | --- | --- |
| **#196** P-12-45 | `77984991` | MERGED — ancestor of `801c6a19` ✓ |
| **#197** P-12-46 | `801c6a19` | MERGED — tip of `origin/main` ✓ |

---

## 4. WS-10 STILL FAILING count (actual, matrix CSV)

| Source | Count |
| --- | ---: |
| Pre-P12-47 register estimate | 109 (WS-10) |
| **Counted** `STILL FAILING` + `WS-10` in CSV @ `801c6a19` (pre-fix) | **109** |
| **Post-P12-47** (7 rows closed) | **102** |

---

## 5. Cluster selection

| | |
| --- | --- |
| **Selected** | **Operations Control Center** — first-paint SSR + Ira compact lazy load (`ControlCenterFirstPaintTest`, `IraOperationsBrainTest::test_admin_dashboard_shows_ira_briefing_panel`) |
| **Rationale** | Reproducible failures @ `801c6a19`: Vite 500 without `withoutVite()`; stale first-paint copy vs current Control & Admin workspace; Ira briefing deferred to `ira_compact` live group (aligned with `OperationsDashboardTest`) |
| **Reconcile-only** | Five `OperationsDashboardTest` + one `AutomationOperationsDashboardTest` rows **PASS** on `801c6a19` (stale P-12-32) |
| **Deferred** | `OperatorDashboardCacheTest` query-count (3 failures); `WorkspaceResolveCloseActionTest` bulk (passes — stale); WS-04 OPM tail; Platform ExecutiveCommandCenter strip |

---

## 6. Worktree ownership

| Worktree | Branch | Overlap with P-12-47 files |
| --- | --- | --- |
| `p12-46-next-cluster` | `feature/p12-46-next-cluster` | **None** (WS-11 tests only) |
| `p12-45-statutory-pdf-logo` | `feature/p12-45-statutory-pdf-logo` | **None** (merged) |
| `p12-47-ws10-dashboard` | `feature/p12-47-ws10-dashboard` | **Owner** |

---

## 7. Root cause

| Test | Mechanism |
| --- | --- |
| `ControlCenterFirstPaintTest` | Missing `withoutVite()` → Vite manifest 500; assertions referenced retired **Mission Control** / Platform Health first-paint strip vs current **Control & Admin** nav + lazy integration/Ira placeholders |
| `test_admin_dashboard_shows_ira_briefing_panel` | Full-page GET only SSR skeleton; Ira content requires `admin.operations.live` group `ira_compact` (same pattern as `OperationsDashboardTest`) |

**Classification:** TEST HARNESS / stale expectations — **no application query or permission changes**.

---

## 8. Reproduction (pre-fix @ `801c6a19`)

```bash
cd .worktrees/p12-47-ws10-dashboard
DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated \
  tests/Feature/Operations/ControlCenterFirstPaintTest.php
# FAIL — ViteManifestNotFoundException / assertion on Mission Control workspace

DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated --filter test_admin_dashboard_shows_ira_briefing_panel \
  tests/Feature/IraOperationsBrainTest.php
# FAIL — Vite 500, then missing Ira copy on SSR-only GET
```

---

## 9. Remediation

| File | Change |
| --- | --- |
| `tests/Feature/Operations/ControlCenterFirstPaintTest.php` | `withoutVite()`; first-paint assertions aligned with lazy health/Ira + Control & Admin workspace |
| `tests/Feature/IraOperationsBrainTest.php` | `withoutVite()`; assert Ira briefing via `ira_compact` live JSON |

---

## 10. Post-fix validation (`bin/phpunit-isolated` @ worktree)

| Suite | Result |
| --- | --- |
| `ControlCenterFirstPaintTest` | **1/1 PASS** |
| `IraOperationsBrainTest` | **11/11 PASS** |
| `OperationsDashboardTest` | **28/28 PASS** (regression) |
| `AutomationOperationsDashboardTest` | **10/10 PASS** (regression) |
| Full suite | **NOT RUN** |

---

## 11. Matrix row updates (7)

| Test | After | Evidence |
| --- | --- | --- |
| `ControlCenterFirstPaintTest::test_control_center_ssr…` | **VERIFIED FIXED** | P-12-47 harness fix |
| `IraOperationsBrainTest::test_admin_dashboard_shows_ira_briefing_panel` | **VERIFIED FIXED** | P-12-47 harness fix |
| Four `OperationsDashboardTest` matrix rows | **VERIFIED FIXED** | PASS @ `801c6a19` reconcile |
| `AutomationOperationsDashboardTest::test_sidebar…` | **VERIFIED FIXED** | PASS @ `801c6a19` reconcile |

**Totals:** **122** VERIFIED FIXED · **105** STILL FAILING · **1** ENVIRONMENT BLOCKED (228 rows).

---

## 12. PR / CI

| Item | Value |
| --- | --- |
| Branch | `feature/p12-47-ws10-dashboard` |
| PR | **[#198](https://github.com/radium-foundation/radium-desk/pull/198)** @ `59c290ec` |

---

## 13. Policy / wallet

| Item | Status |
| --- | --- |
| `docs/PHPUNIT_FULL_SUITE_AUTHORIZATION.md` | Honored |
| Wallet `d9481615` | Read-only verify |
| WS-04 OPM | Excluded |
| `bin/phpunit-isolated` | Used exclusively |

---

## 14. Residual risks / backlog

- **WS-10:** **102** rows remain; many P-12-32 rows may be stale (sampled `AdministrationHomeTest`, `DashboardServiceCasesTest`, `WorkspaceResolveCloseActionTest` → PASS @ `801c6a19`).
- **Operator dashboard cache:** Three real assertion failures (slow query count) — separate cluster.
- **Platform / Mission Control naming:** Other matrix rows may still reference retired Mission Control copy.

---

## 15. Coordinator summary

- **RadiumDesk-P-12-47** claimed; baseline **`801c6a19`**; **#196/#197** on `main`.
- **WS-10 STILL FAILING:** counted **109** → **102** after one Operations Control Center cluster.
- **Test-only** fix; matrix CSV + remaining-failures register refreshed.
- Wallet **`d9481615`** untouched. No shared-file conflict with P-12-45/46.
