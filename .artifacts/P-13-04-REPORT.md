# P-13-04 — WS-10 dashboard assignee (People column) regression

**Programme (IST):** 2026-11-13 01:07 → 2026-11-13 01:35  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk`  
**Worktree:** `.worktrees/p13-04-ws10-dashboard` → `feature/p13-04-ws10-dashboard`  
**Verification HEAD:** `origin/main` @ **`489fd91effb11ecc7a533fa1d3a14f54d9080cb1`** (#202 / P-13-01)  
**Wallet checkout:** `d94816153cd2c89f8175d29a1510b38b4c16df6b` — **verified, not modified**  
**Full PHPUnit:** **NOT RUN** (policy §4 Level 1–2)  
**Production:** **NOT RUN**

---

## 1. Ledger

| Field | Value |
| --- | --- |
| Prompt | **P-13-04** |
| Ledger ID | **RadiumDesk-P-13-04** (**CLAIMED** 2026-11-13 IST) |
| Claim artifact | `.artifacts/ledger-claim-RadiumDesk-P-13-04.md` |
| Scope | Dashboard SSR assignee avatar / People column |

---

## 2. Baseline verification

| Item | Value |
| --- | --- |
| `git fetch origin main` | **`489fd91e`** ✓ |
| Merge | **#202** `feature/p13-01-ws10-platform-cache-snapshot` |

---

## 3. Register / ownership

| Source | WS-10 STILL FAILING |
| --- | ---: |
| Post-P13-01 `.artifacts/P-12-44-REMAINING-FAILURES.md` | **92** |

**Excluded (active / reserved):**

| Owner | Scope |
| --- | --- |
| **P-13-02** (`p13-02-refund-dashboard`) | `RefundRequestTest::test_dashboard_shows_refund_status_counts` |
| **P-13-03** (not ledger-claimed) | Operational queue/KPI cluster (`QueueIntegrityLiveRefreshTest`, `BackfillReadyQueueCommandTest`, `OperationsSupportOwnershipTest`, …) |

**Re-verified @ `489fd91e` (pre-fix):**

| Test | Result |
| --- | --- |
| `DashboardServiceCasesTest` (6 methods) | **PASS** — register **STALE** (same as P-13-01 note) |
| `DashboardClassificationIndexTest` | **PASS** |
| `ServiceCaseAssignmentTest::test_dashboard_displays_owner_first_name` | **FAIL** |

---

## 4. Cluster selection

| | |
| --- | --- |
| **Selected** | **Dashboard assignee display** — `ServiceCaseAssignmentTest::test_dashboard_displays_owner_first_name` |
| **Matrix row** | 161 (`P-12-32-matrix-reconciliation.csv`) |
| **Rationale** | Single bounded rendering regression on real `GET /dashboard` SSR path; independent of refund KPI and queue broadcast work |

---

## 5. Reproduction & root cause

**Pre-fix command:**

```bash
cd .worktrees/p13-04-ws10-dashboard
ln -sf ../../vendor vendor
DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated \
  --filter test_dashboard_displays_owner_first_name \
  tests/Feature/ServiceCaseAssignmentTest.php
# FAIL — assertSee Assigned To; dashboard empty row (data-service-cases-loaded=0)
```

| Observation | Evidence |
| --- | --- |
| Dashboard returned empty Ready Queue grid | `action_required` count **0** in KPI strip; empty-state row only |
| Incident used non-validated serial `SN-OWNER-1` | `ServiceCaseAssignmentEligibilityService::isReadyForReferenceEntry()` → **false** → not `OperationQueue::ActionRequired` |
| Assignee Blade partial is correct when row renders | `dashboard/partials/service-case-row.blade.php` + `x-dashboard-user-avatar` |

**Classification:** **STALE HARNESS / fixture drift** (not assignee rendering defect). Aligns with P-12-49+ ready-queue eligibility and enriched serial contract used by `DashboardServiceCasesTest::createAdminOpenCase`.

**Query / cache (affected regression):** `DashboardClassificationIndexTest::test_lean_load_skips_row_only_relations` — **PASS** @ 489fd91e; lean index仍 **single** `incidents` SELECT (asserted in test). No cache mutation in this cluster.

---

## 6. Remediation

| File | Change |
| --- | --- |
| `tests/Feature/ServiceCaseAssignmentTest.php` | Ready-eligible order (`B47C11929` + `markSynced`); `IncidentStatus::Open`; `DashboardSnapshot::load()->queueCounts()` guard **before** HTML asserts; assert `RD-OWNER-1` in grid |
| `docs/cursor-prompt-ledger.md` | Append **RadiumDesk-P-13-04** |

**Shared matrix CSV:** **NOT MODIFIED** (per prompt).

---

## 7. Post-fix validation

```bash
DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated \
  tests/Feature/ServiceCaseAssignmentTest.php \
  tests/Feature/Dashboard/DashboardClassificationIndexTest.php
```

| Suite | Result | Duration |
| --- | --- | ---: |
| Focused cluster | **31/31 PASS** (76 assertions) | **29 960 ms** |
| Owner test alone | **1/1 PASS** (6 assertions) | **3 524 ms** |
| Pint `--dirty` | **PASS** | **883 ms** |

---

## 8. Git / PR (fill after push)

| Item | SHA / URL |
| --- | --- |
| Commit | _pending_ |
| PR | _pending_ |
| Merge | _pending_ |

---

## 9. Remaining risks

- **91** WS-10 matrix rows still open post-closure (register not recomputed in this prompt).
- `DashboardServiceCasesTest` six rows remain on register but pass @ `489fd91e` — candidate for stale register sweep (out of scope).
- Operational queue cluster still unowned until **P-13-03** claims ledger.

---

## 10. Policy attestation

| Gate | Status |
| --- | --- |
| Full suite | **NOT RUN** |
| Production deploy | **NOT RUN** |
| Wallet WIP `d9481615` | **Preserved** |
| WS-04 / wallet code | **Untouched** |
