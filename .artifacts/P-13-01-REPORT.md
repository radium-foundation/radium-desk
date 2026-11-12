# P-13-01 — WS-10 Platform health snapshot cache regeneration

**Programme (IST):** 2026-11-13 01:00 → 2026-11-13 01:25  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk`  
**Worktree:** `.worktrees/p13-01-ws10` → `feature/p13-01-ws10`  
**Verification HEAD:** `origin/main` @ **`b69ee268`** (post **#201** / P-12-50)  
**Wallet checkout:** `d94816153cd2c89f8175d29a1510b38b4c16df6b` — **verified, not modified**  
**Full PHPUnit:** **NOT RUN** (P-12-40 policy)

---

## 1. Ledger

| Field | Value |
| --- | --- |
| Prompt | **P-13-01** |
| Ledger ID | **RadiumDesk-P-13-01** (**CLAIMED** 2026-11-13 01:00 IST) |
| Claim artifact | `.artifacts/ledger-claim-RadiumDesk-P-13-01.md` |
| Scope | Platform `platform:health:snapshot` regeneration after `cache:clear` / flush |

---

## 2. Repository state @ verify

| Item | Value |
| --- | --- |
| Expected baseline | `b69ee268` (#201) |
| Fetched `origin/main` | **`b69ee268`** |
| P-13-01 worktree | created from `origin/main` @ `b69ee268` |
| Wallet worktree | `feature/p18-33-unified-wallet-display` @ **`d9481615`** — **verified, not modified** |
| #201 ancestry | `b69ee268` is ancestor of `origin/main` ✓ |

---

## 3. PR ancestry on `main`

| PR | Merge SHA | State |
| --- | --- | --- |
| **#201** P-12-50 | `b69ee268` | MERGED — tip @ verify ✓ |

---

## 4. WS-10 STILL FAILING count (matrix CSV)

| Source | Count |
| --- | ---: |
| Post-P12-50 register | 98 STILL FAILING (95 WS-10) |
| **Post-P13-01** (3 snapshot pipeline rows closed) | **95** STILL FAILING (**92** WS-10) |

---

## 5. Cluster selection

| | |
| --- | --- |
| **Selected** | Platform health **snapshot cache regeneration pipeline** (`PlatformCacheAuditReproductionTest` + `SnapshotCacheRegenerationPipelineTest`) |
| **Rationale** | Highest-impact **ops/platform** follow-up deferred from P-12-50; reproducible APP DEFECT (not Vite); bounded 3 matrix rows; durable heartbeat contract already on main |
| **Deferred** | `DashboardServiceCasesTest` (6 rows) — **STALE** (6/6 PASS @ `b69ee268`, no code change per no-bulk-stale policy); Platform-wide Vite harness expansion; WS-04 OPM |

### Method → matrix row map

| Test method | Matrix CSV row |
| --- | --- |
| `PlatformCacheAuditReproductionTest::test_reproduce_clear_load_refresh_reload_and_capture_writers` | Row 120 |
| `SnapshotCacheRegenerationPipelineTest::test_optimize_clear_style_cache_flush…` | Row 121 |
| `SnapshotCacheRegenerationPipelineTest::test_second_refresh_after_flush…` | Row 122 |

---

## 6. Worktree ownership

| Worktree | Branch | Overlap |
| --- | --- | --- |
| `p13-01-ws10` | `feature/p13-01-ws10` | **Owner** |
| `p12-50-platform-ecc-vite` | merged (#201) | **None** |
| Wallet primary | `feature/p18-33-unified-wallet-display` @ `d9481615` | **Read-only** |

---

## 7. Root cause

| Observation | Evidence |
| --- | --- |
| `PlatformHealthSnapshotService::probe()` memoizes `$probedThisRequest` per HTTP request (scoped binding) | After `Cache::flush()` (optimize:clear), memo still returned snapshot **without** re-running `store()` |
| Zone JSON refresh + audit harness expect `platform:health:snapshot` writes | `PlatformCacheAuditReproductionTest` recorded 0 snapshot writes; pipeline tests saw `current()` **null** post-flush |
| Durable scheduler/presence heartbeats correct | `PlatformHealthCache` file path survives flush; failure was shared snapshot key not republished |

**Classification:** **APP DEFECT** — request-scoped probe memo vs cache flush coherence.

---

## 8. Reproduction (pre-fix @ `b69ee268`)

```bash
cd .worktrees/p13-01-ws10
ln -sf ../../vendor vendor
env PHP_INI_SCAN_DIR= /opt/homebrew/bin/php -n "$(which composer)" dump-autoload -o
DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated \
  tests/Feature/Platform/SnapshotCacheRegenerationPipelineTest.php \
  tests/Feature/Platform/PlatformCacheAuditReproductionTest.php
# FAIL 3/6 — current() null after flush; 0 audit snapshot writes
```

---

## 9. Remediation

| File | Change |
| --- | --- |
| `app/Services/Platform/Health/PlatformHealthSnapshotService.php` | If memo exists but cache key missing, clear memo and **re-probe + store** |
| `tests/Feature/Platform/PlatformHealthSnapshotUnificationTest.php` | Regression: `test_probe_republishes_shared_snapshot_after_cache_flush_in_same_request` |
| `docs/cursor-prompt-ledger.md` | Append **RadiumDesk-P-13-01** |
| `.artifacts/P-12-32-matrix-reconciliation.csv` | Rows 120–122 → VERIFIED FIXED |
| `.artifacts/P-12-44-REMAINING-FAILURES.md` | Totals + list |

---

## 10. Post-fix validation (`bin/phpunit-isolated` @ worktree)

| Suite | Result | Duration (ms) |
| --- | --- | ---: |
| Cluster (cache audit + snapshot pipeline + unification + Mission Control) | **18/18 PASS** | 25545 |
| `SnapshotCacheRegenerationPipelineTest` | **4/4 PASS** | (in cluster) |
| `PlatformCacheAuditReproductionTest` | **2/2 PASS** | (in cluster) |
| `PlatformHealthSnapshotUnificationTest` (incl. new regression) | **8/8 PASS** | 7559 |
| Full suite | **NOT RUN** | — |

---

## 11. Matrix row updates (3 closed)

| Test | After | Evidence |
| --- | --- | --- |
| `…test_reproduce_clear_load_refresh_reload_and_capture_writers` | **VERIFIED FIXED** | P-13-01 @ `b69ee268` |
| `…test_optimize_clear_style_cache_flush_does_not_persist_false_critical…` | **VERIFIED FIXED** | P-13-01 @ `b69ee268` |
| `…test_second_refresh_after_flush_keeps_non_critical…` | **VERIFIED FIXED** | P-13-01 @ `b69ee268` |

**Totals:** **132** VERIFIED FIXED · **95** STILL FAILING · **1** ENVIRONMENT BLOCKED (228 rows).

---

## 12. Stale vs genuine triage

| Area | Repro @ main | Class |
| --- | --- | --- |
| Snapshot cache pipeline (3 rows) | **FAIL** → fixed | **APP DEFECT** |
| `DashboardServiceCasesTest` (6 matrix rows) | **PASS** @ `b69ee268` | **STALE** (not bulk-updated) |
| `RefundRequestTest::test_dashboard_shows_refund_status_counts` | **FAIL** (Vite manifest) | **TEST HARNESS** (candidate P-13-02) |

---

## 13. PR / CI

| Item | Value |
| --- | --- |
| Branch | `feature/p13-01-ws10` |
| PR | *(pending — rip / release-rip pre-merge)* |

---

## 14. Policy / wallet

| Item | Status |
| --- | --- |
| `docs/RADIUMWEBSITES_TESTING_AND_VALIDATION_POLICY.md` | Honored |
| `docs/phpunit-test-environment.md` | Honored (`bin/phpunit-isolated`, worktree `dump-autoload -o`) |
| Wallet `d9481615` | Read-only verify |
| WS-04 OPM | Excluded |
| `composer dump-autoload -o` | Executed in worktree |
| Full PHPUnit suite | **NOT RUN** |

---

## 15. Residual risks / backlog

- **WS-10:** **92** matrix rows remain STILL FAILING.
- Stale **DashboardServiceCases** matrix rows (6) need evidence-only reconcile in a future bounded prompt (not bulk-closed here).
- Dashboard SSR tests still missing `withoutVite()` in non-Platform namespaces (`RefundRequestTest` confirmed).

---

## 16. Coordinator summary

- **RadiumDesk-P-13-01** claimed **2026-11-13 01:00 IST**; baseline **`b69ee268`** (#201).
- Fixed scoped probe memo surviving `Cache::flush()` so Mission Control / platform zone refresh republishes `platform:health:snapshot`.
- **18/18** focused cluster + regression PASS; matrix **+3** VERIFIED FIXED.
- Wallet **`d9481615`** untouched.

---

## 17. Next recommended cluster

**P-13-02 candidate:** `RefundRequestTest::test_dashboard_shows_refund_status_counts` — customer-facing refund KPI on dashboard; reproducible **ViteManifestNotFoundException** (harness, same pattern as P-12-47/P-12-50, single matrix row). Alternative: triage **6 STALE** `DashboardServiceCasesTest` rows with evidence-only matrix update (explicit non-bulk).
