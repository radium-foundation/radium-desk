# P-20-15 — Controlled production GST activation readiness

| Field | Value |
| --- | --- |
| Task | **P-20-15** |
| Cursor ledger | `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef` |
| Desk ledger row | **RadiumDesk-P-20-15** |
| Risk | **HIGH** — production GST verification, statutory invoicing, audit integrity |
| Start (IST) | **2026-11-20 09:38:46** |
| End (IST) | **2026-11-20 ~09:45 IST** (approx.) |
| Duration | **~7 min** (read-only SSH + documentation) |

## Task outcome

| Item | Status |
| --- | --- |
| Readiness package | **PASSED** |
| Production mutations | **NO — Not performed** |
| Production known-good (flags ON) | **Not claimed** |

---

## Production baseline (VERIFIED — read-only SSH 2026-11-20)

| Check | Value |
| --- | --- |
| Release | **v4.1.102** / **`339e3dc99`** |
| Deployed at | **2026-11-20 08:41:35 IST** |
| Repo `main` | **`4943e775e`** (**not** on prod) |
| `STATUTORY_BUYER_GSTIN_VERIFICATION_ENABLED` | **OFF** (key absent) |
| `STATUTORY_BUYER_GSTIN_B2C_FALLBACK_ENABLED` | **OFF** (key absent) |
| Migrations **66–68** | **Ran** |
| Receipt publish | **on**, allowlist **8** IDs |
| PRE-RIP `…0836…7edf904` | **PASS** |
| POST-RIP `…0843…339e3dc` | **PASS** |

---

## Recovery position

| Item | Class | Result |
| --- | --- | --- |
| P-20-09 disposable restore **`20261120T025830Z`** | **VERIFIED** | Structural drill **PASS** (pre–v4.1.102 / pre-migration **120000** in drill snapshot) |
| Latest on-host backup **`20261120T025830Z`** manifest build | **VERIFIED** | **`7edf904bb`** — **predates** P-20-10 deploy |
| Backup after **`339e3dc99`** + migrations **66–68** | **BLOCKED** | **Missing** — required before Owner Stage A |
| Restore proof for **current** DB state | **INFERRED** | Same pipeline as P-20-09; **not re-run** in P-20-15 |

---

## Deliverables

| Artifact | Path |
| --- | --- |
| Two-stage runbook + gates + monitoring | `docs/desk-p20-15-controlled-production-gst-activation-readiness.md` |
| This report | `.artifacts/P-20-15-controlled-production-gst-activation-readiness-report.md` |

---

## Test / validation results (this task)

| Activity | Result |
| --- | --- |
| PHPUnit (full or focused re-run) | **NOT RUN** — P-20-14 bundle cited; policy: no full suite |
| Protected production journeys (flags ON) | **NOT RUN** |
| Live WhiteBooks on prod | **NOT RUN** |
| Fresh backup + drill post-**339e3dc99** | **BLOCKED** — Owner action required |
| RIP verify (POST/PRE) | **PASSED** |
| Read-only prod config probe | **PASSED** |

---

## Changes ledger

| Class | Items |
| --- | --- |
| **Preserved** | Prod flags OFF; allowlist 8; statutory history; controls/tests |
| **Extended** | P-20-15 runbook + report; ledger row |
| **Changed** | None in production |
| **Removed** | None |

---

## Production actions

| Action | Status |
| --- | --- |
| Deployment | **NO — Not performed** |
| Flag activation | **NO — Not performed** |
| Live provider calls | **NO — Not performed** |
| Production minting | **NO — Not performed** |
| IRN generation | **NO — Not performed** |
| Receipt publication expansion | **NO — Not performed** |

---

## Git / PR

| Item | Value |
| --- | --- |
| Branch | `feature/p20-15-gst-activation-readiness` |
| Target | `main` |
| PR | *(after push)* |
| Merge SHA | *(after merge)* |

---

## Remaining risks

1. **No post-deploy backup** at current schema/code — DR for flag activation is **incomplete** until Owner backup.  
2. **`4943e775e`** on `main` is documentation/tests only — prod unchanged (**acceptable** for flag rollout on **`339e3dc99`**).  
3. Stage A enables **live provider** on **state-mismatch** orders only — operational load and hold rate **UNKNOWN** on prod.  
4. Protected production journeys with flags ON still **required** before known-good claim.

---

## Next Owner decision (exact)

Authorize **in writing**, as **separate** tasks:

1. **Fresh encrypted production backup** after **`339e3dc99`** (and optional disposable restore drill).  
2. **Stage A activation:** `STATUTORY_BUYER_GSTIN_VERIFICATION_ENABLED=true`, fallback **OFF**, with named protected journeys, observers, and rollback approver.  
3. After Stage A gates pass: **Stage B:** `STATUTORY_BUYER_GSTIN_B2C_FALLBACK_ENABLED=true`.  
4. Explicit permission for **any** production statutory **mint** and **live WhiteBooks** HTTP during journey execution.  

**P-20-15 does not authorize steps 1–4.**