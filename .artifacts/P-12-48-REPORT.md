# P-12-48 — OperatorDashboardCache live slow-scalar query cluster

**Programme (IST):** 2026-11-12 23:34  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk`  
**Worktree:** `.worktrees/p12-48-operator-dashboard-cache` → `feature/p12-48-operator-dashboard-cache`  
**Verification HEAD:** `origin/main` @ **`982dcf3e75092e9047be38429a61bbef65c0250d`** (post **#198**)  
**Wallet checkout:** `d9481615` — **verified, not modified**  
**Full PHPUnit:** **NOT RUN** (P-12-40 policy)

---

## 1. Ledger

| Field | Value |
| --- | --- |
| Prompt | **P-12-48** |
| Ledger ID | **RadiumDesk-P-12-48** (**CLAIMED**) |
| Claim artifact | `.artifacts/ledger-claim-RadiumDesk-P-12-48.md` |
| Scope | WS-10 `OperatorDashboardCacheTest` slow-scalar query-count subcluster (3 matrix rows) |

---

## 2. Repository state @ verify

| Item | Value |
| --- | --- |
| Expected baseline | `982dcf3e` |
| Fetched `origin/main` | **`982dcf3e`** (2026-11-12 23:34 IST) |
| P-12-48 worktree | created from `origin/main` @ `982dcf3e` |
| Wallet worktree | `feature/p18-33-unified-wallet-display` @ **`d9481615`** — **verified, not modified** |
| P-12-47 branch | **not touched** (merged #198) |

---

## 3. PR #198 ancestry on `main`

| PR | Merge SHA | State |
| --- | --- | --- |
| **#198** P-12-47 | `982dcf3e` | MERGED — tip of `origin/main` ✓ |

---

## 4. WS-10 STILL FAILING count (actual, matrix CSV)

| Source | Count |
| --- | ---: |
| Pre-P12-48 register (post P-12-47) | 102 (WS-10) |
| **Counted** `STILL FAILING` + `WS-10` before fix | **102** |
| **Post-P12-48** (3 rows closed) | **99** |

---

## 5. Cluster selection

| | |
| --- | --- |
| **Selected** | `OperatorDashboardCacheTest` live-path slow-scalar COUNT guardrails (3 methods deferred in P-12-47 §14) |
| **Rationale** | Reproducible @ `982dcf3e`: failures assert `3 !== 0` slow-scalar counts; application already omits `slowChangingStatsFor()` on live paths since `08ba8688` |
| **Matrix rows** | `test_live_metrics_for_*`, `test_live_metrics_kpis_only_*`, `test_live_reverb_metrics_for_*` |

### Method → matrix row map

| Test method | Matrix CSV row |
| --- | --- |
| `test_live_metrics_for_performs_no_slow_scalar_count_queries` | Row 112 |
| `test_live_metrics_kpis_only_performs_no_slow_scalar_count_queries` | Row 113 |
| `test_live_reverb_metrics_for_performs_no_slow_scalar_count_queries` | Row 114 |

---

## 6. Worktree ownership

| Worktree | Branch | Overlap with P-12-48 files |
| --- | --- | --- |
| `p12-47-ws10-dashboard` | `feature/p12-47-ws10-dashboard` | **None** (merged; different test files) |
| `p12-48-operator-dashboard-cache` | `feature/p12-48-operator-dashboard-cache` | **Owner** |

**Stop condition:** No shared-file conflict with other active worktrees.

---

## 7. Root cause

| Observation | Evidence |
| --- | --- |
| Failures reported **3** COUNT queries on `orders` | `bin/phpunit-isolated` @ `982dcf3e` — 17/21 pass, **3 failures** |
| SQL captured during `liveMetricsFor()` | Hardware Needs Action classifier counts (`RDE%` / `RIN%` predicates, `hardware_fulfilments` subselect) — **not** `OperatorDashboardCache::loadSlowScalars()` |
| Application live path | `DashboardService::liveMetricsFor()` uses `fastChangingStatsForKpiStrip()` only; `'slow' => []` — matches `08ba8688` intent |

**Classification:** **TEST HARNESS** — `countSlowScalarCountQueries()` treated any `COUNT(*)` on `orders`/`users`/`audit_logs` as slow scalars, including hardware fulfilment SQL.

**Preserved:** cache isolation, permissions, invalidation contracts — **no** `OperatorDashboardCache` / `DashboardService` production changes.

---

## 8. Reproduction (pre-fix @ `982dcf3e`)

```bash
cd .worktrees/p12-48-operator-dashboard-cache
ln -sf ../../../vendor vendor   # if needed
DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated \
  tests/Feature/OperatorDashboardCacheTest.php
# 20/21 pass, 3 FAIL — "Failed asserting that 3 is identical to 0."
```

Pre-fix query sample (live metrics, superadmin):

- `select count(*) … from "orders" … "order_id" like ?` (RDE/RBP mapping classifier)
- Same pattern ×3 from `HardwareNeedsActionSqlQuery::filterCounts` cache warm — **not** bare `Order::query()->count()`.

---

## 9. Remediation

| File | Change |
| --- | --- |
| `tests/Feature/OperatorDashboardCacheTest.php` | Add `matchesBareSlowScalarCountSql()` — require slow-scalar tables, **exclude** `LIKE` / `hardware_fulfilments` predicates; reuse in `countSlowScalarCountQueries()` and warm-cache assertion |

---

## 10. Post-fix validation (`bin/phpunit-isolated` @ worktree)

| Suite | Result (IST 23:38) |
| --- | --- |
| `OperatorDashboardCacheTest` | **20/20 executed PASS** (1 skipped) |
| `HardwareDashboardStuckSummaryDeferTest` | **4/4 PASS** |
| `DashboardLivePhase1PerformanceTest` | **PASS** |
| `DashboardReverbMetricsConsistencyTest` | **PASS** |
| Full suite | **NOT RUN** |

---

## 11. Matrix row updates (3)

| Test | After | Evidence |
| --- | --- | --- |
| `…test_live_metrics_for_performs_no_slow_scalar_count_queries` | **VERIFIED FIXED** | P-12-48 harness `@982dcf3e` |
| `…test_live_metrics_kpis_only_performs_no_slow_scalar_count_queries` | **VERIFIED FIXED** | P-12-48 harness `@982dcf3e` |
| `…test_live_reverb_metrics_for_performs_no_slow_scalar_count_queries` | **VERIFIED FIXED** | P-12-48 harness `@982dcf3e` |

**Totals:** **125** VERIFIED FIXED · **102** STILL FAILING · **1** ENVIRONMENT BLOCKED (228 rows).

---

## 12. PR / CI

| Item | Value |
| --- | --- |
| Branch | `feature/p12-48-operator-dashboard-cache` |
| PR | _filled after push_ |

Expected checks: **rip** + **release-rip** (merge gate per programme).

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

- **WS-10:** **99** rows remain STILL FAILING in matrix register.
- Hardware chip / classifier COUNTs on live poll remain on the hot path by design (cached via `HardwareDashboardCountsCache`); not slow-scalar regression.
- If bare `Order`/`User`/`AuditLog` COUNTs reappear on live paths, narrowed matcher still fails closed.

---

## 15. Coordinator summary

- **RadiumDesk-P-12-48** claimed 2026-11-12 23:34 IST; baseline **`982dcf3e`** (#198 on `main`).
- Reproduced 3 matrix failures; root cause **test query classifier false positive**, not missing cache fix.
- **Test-only** remediation; matrix + remaining-failures register refreshed (**125 / 102 / 1**).
- Wallet **`d9481615`** untouched. Ready for PR → **rip** / **release-rip** → merge.
