# P-12-49 — WS-10 workspace KPI / Admin Ready queue overlay

**Programme (IST):** 2026-11-12 23:52 → 2026-11-13 00:05  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk`  
**Worktree:** `.worktrees/p12-49-ws10-app-defect` → `feature/p12-49-ws10-app-defect`  
**Verification HEAD:** `origin/main` @ **`698863192600ff8ada223dffa42dfca51af7ced7`** (post **#199**)  
**Wallet checkout:** `d9481615` — **verified, not modified**  
**Full PHPUnit:** **NOT RUN** (P-12-40 policy)

---

## 1. Ledger

| Field | Value |
| --- | --- |
| Prompt | **P-12-49** |
| Ledger ID | **RadiumDesk-P-12-49** (**CLAIMED** 2026-11-12 23:52 IST) |
| Claim artifact | `.artifacts/ledger-claim-RadiumDesk-P-12-49.md` |
| Scope | WS-10 `OperationsModelFoundationTest` workspace KPI / Ready queue count integrity (2 matrix rows) |

---

## 2. Repository state @ verify

| Item | Value |
| --- | --- |
| Expected baseline | `69886319` (#199) |
| Fetched `origin/main` | **`69886319`** (2026-11-12 23:48 IST) |
| P-12-49 worktree | created from `origin/main` @ `69886319` |
| Wallet worktree | `feature/p18-33-unified-wallet-display` @ **`d9481615`** — **verified, not modified** |
| P-12-48 branch | **merged** (#199); not modified |

---

## 3. PR #199 ancestry on `main`

| PR | Merge SHA | State |
| --- | --- | --- |
| **#199** P-12-48 | `69886319` | MERGED — tip of `origin/main` at claim ✓ |

---

## 4. WS-10 STILL FAILING count (actual, matrix CSV @ `69886319` pre-fix)

| Source | Count |
| --- | ---: |
| Post-P12-48 register | 99 (WS-10) |
| **Counted** `STILL FAILING` + `WS-10` in CSV @ `69886319` (pre-fix) | **99** |
| **Post-P12-49** (2 rows closed) | **97** |

---

## 5. Cluster selection

| | |
| --- | --- |
| **Selected** | **Workspace dashboard KPI integrity** — `OperationsModelFoundationTest` action-required vs waiting-customer counts |
| **Rationale** | Reproducible **FAIL** @ `69886319`: `action_required` and `open_cases` KPIs counted **2** when **1** expected; not Vite/stale (unlike Platform ECC rows) |
| **Deferred** | Platform `MissionControlCompletionTest` / `ExecutiveCommandCenterTest` SSR (Vite manifest — harness cluster, similar to P-12-47) |

### Method → matrix row map

| Test method | Matrix CSV row |
| --- | --- |
| `test_waiting_customer_cases_are_excluded_from_action_required_counts` | Row 109 |
| `test_open_kpi_excludes_waiting_customer_completed_and_hardware_cases` | Row 108 |

---

## 6. Worktree ownership

| Worktree | Branch | Overlap |
| --- | --- | --- |
| `p12-49-ws10-app-defect` | `feature/p12-49-ws10-app-defect` | **Owner** |
| `p12-48-operator-dashboard-cache` | merged | **None** |

---

## 7. Root cause

| Observation | Evidence |
| --- | --- |
| Waiting-customer cases still appeared in **Admin Ready** (`action_required`) bucket | Pre-fix `bin/phpunit-isolated` → `Failed asserting that 2 is identical to 1.` on both matrix methods @ `69886319` |
| Overlay rule mirrored any `isReadyForReferenceEntry` case into `action_required`, including cases whose **primary** queue was `waiting_customer` | `DashboardClassificationIndex::buildFromIncidents()` + `DashboardSnapshot::warmQueueIncidents()` |
| `open_cases` KPI inflated because it sums `action_required` queue counts | `DashboardSnapshot::openCount()` |

**Classification:** **VERIFIED APPLICATION DEFECT** — queue overlay semantics; not PHPUnit harness.

---

## 8. Reproduction (pre-fix @ `69886319`)

```bash
cd .worktrees/p12-49-ws10-app-defect
ln -sf ../../vendor vendor
/opt/homebrew/bin/php "/Users/ravi/Library/Application Support/Herd/bin/composer" dump-autoload -o
DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated \
  --filter 'test_waiting_customer_cases_are_excluded|test_open_kpi_excludes_waiting' \
  tests/Feature/OperationsModelFoundationTest.php
# FAIL — action_required / open_cases expected 1, actual 2
```

---

## 9. Remediation

| File | Change |
| --- | --- |
| `OperationsQueueClassifier.php` | `shouldMirrorInAdminReadyQueue()` — exclude waiting/hardware (and non-overlay primary queues) before Ready mirror |
| `DashboardClassificationIndex.php` | Use shared mirror helper; filter/dedupe `action_required` materialization |
| `DashboardSnapshot.php` | Same mirror helper in warm path + `incidentsForQueue` fallback; exclude waiting/hardware from Ready counts |
| `OperationsModelFoundationTest.php` | `withoutVite()` + `DashboardSnapshotStore::forget()` before KPI assertions (scoped snapshot refresh) |

---

## 10. Post-fix validation (`bin/phpunit-isolated` @ worktree)

| Suite | Result (IST 00:04) |
| --- | --- |
| `OperationsModelFoundationTest` | **18/18 PASS** |
| `DashboardClassificationIndexTest` | **5/5 PASS** |
| `AgentDashboardOwnershipTest` | **16/16 PASS** |
| **Cluster total** | **39/39 PASS** |
| Full suite | **NOT RUN** |

---

## 11. Matrix row updates (2)

| Test | After | Evidence |
| --- | --- | --- |
| `…test_waiting_customer_cases_are_excluded_from_action_required_counts` | **VERIFIED FIXED** | P-12-49 @ `69886319` |
| `…test_open_kpi_excludes_waiting_customer_completed_and_hardware_cases` | **VERIFIED FIXED** | P-12-49 @ `69886319` |

**Totals:** **127** VERIFIED FIXED · **100** STILL FAILING · **1** ENVIRONMENT BLOCKED (228 rows).

---

## 12. Stale vs genuine triage (sample @ `69886319`)

| Area | Repro @ main | Class |
| --- | --- | --- |
| Platform ECC / Mission Control SSR | **FAIL** (Vite manifest) | Harness — defer bounded cluster |
| `DashboardServiceCasesTest` (matrix STILL FAILING rows) | **PASS** | Stale matrix |
| `AgentDashboardOwnershipTest` (1 matrix row) | **PASS** | Stale matrix |
| `DashboardClassificationIndexTest` (1 matrix row) | **PASS** | Stale matrix |
| **This cluster** | **FAIL** → fixed | **Application defect** |

---

## 13. PR / CI

| Item | Value |
| --- | --- |
| Branch | `feature/p12-49-ws10-app-defect` |
| PR | *(pending — rip / release-rip pre-merge)* |

---

## 14. Policy / wallet

| Item | Status |
| --- | --- |
| `docs/RADIUMWEBSITES_TESTING_AND_VALIDATION_POLICY.md` | Honored |
| Wallet `d9481615` | Read-only verify |
| WS-04 OPM | Excluded |
| `bin/phpunit-isolated` | Used exclusively |

---

## 15. Residual risks / backlog

- **WS-10:** **97** rows remain STILL FAILING in matrix register.
- Platform workspace SSR tests still need `withoutVite()` + copy parity (P-12-47 pattern) — separate bounded prompt.
- Shared `vendor` autoload `baseDir` must be regenerated from the active worktree before PHPUnit (`composer dump-autoload -o`).

---

## 16. Coordinator summary

- **RadiumDesk-P-12-49** claimed **2026-11-12 23:52 IST**; baseline **`69886319`** (#199).
- Selected **one** genuine workspace dashboard defect cluster (2 reproducible matrix rows).
- **Application fix** to Admin Ready overlay + KPI materialization; focused **39/39** PASS.
- Matrix + remaining-failures register refreshed (**127 / 100 / 1**).
- Wallet **`d9481615`** untouched.

---

## 17. Parent handoff

Deliverables: `.artifacts/P-12-49-REPORT.md`, `.artifacts/ledger-claim-RadiumDesk-P-12-49.md`, updated `.artifacts/P-12-32-matrix-reconciliation.csv`, `.artifacts/P-12-44-REMAINING-FAILURES.md`, branch `feature/p12-49-ws10-app-defect`.

**Next suggested WS-10 cluster:** Platform Mission Control / ECC SSR harness (@ deferred from P-12-47), or bounded stale reconcile for PASS-on-main dashboard rows with evidence logs only.
