# P-13-47 — Row 124 superadmin login / workforce session

| Global Prompt | **P-13-47** |
| Cursor ledger | **RadiumDesk-P-13-47** |
| Started | **2026-11-13 ~15:01 IST** |
| Ended | **2026-11-13 ~15:10 IST** |
| Baseline | **`4d3b9d89`** @ `origin/main` (P-13-45 merge) |

---

## Row 124 mapping

| Field | Value |
| --- | --- |
| Register | `.artifacts/P-12-32-matrix-reconciliation.csv` line **124** |
| Test | `Tests\Feature\PresenceEngineTest::test_superadmin_login_does_not_create_workforce_session` |
| Original evidence | P-12-32 phpunit @ `27b03d19` → **FAIL** |
| Risk domain | Workforce presence / login routing (**LOW**) — not payments, wallet, or external integrations |

---

## Root cause — **TEST (stale redirect)**

| Label | Finding |
| --- | --- |
| **VERIFIED** | Failure at `assertRedirect(route('dashboard'))` — actual **`/admin/platform`** (`ExecutiveCommandCenterTest::test_superadmin_login_lands_on_command_center` documents intended behaviour since platform home). |
| **VERIFIED** | `AuthenticatedSessionController::store()` sets home to `admin.platform.index` when `platform-dashboard.view` is granted (superadmin has permission per `RolePermissionSeeder`). |
| **VERIFIED** | Superadmin is **not** attendance-tracked: `PresenceEngineService::startSession()` returns early via `tracksPresence()` → `isAttendanceTracked()` (superadmin omitted). No `WorkSession` row — core assertion of the test remains valid. |
| **INFERRED** | P-12-32 failure message in JSON did not distinguish redirect vs session; on current main only redirect was stale. |

**Classification:** Stale test expectation (post-login landing URL). **Not** an application defect.

---

## Correction (test-only)

`PresenceEngineTest` — superadmin login expects `route('admin.platform.index', absolute: false)` instead of `dashboard`.

---

## Preserved / Extended / Changed / Removed

| | |
| --- | --- |
| **Preserved** | Assertion that superadmin login does **not** create `WorkSession`; attendance gating in `PresenceEngineService` |
| **Extended** | Owner exclusion record (rows 19, 98) |
| **Changed** | Test redirect target; matrix row 124 → VERIFIED FIXED; P-12-44 exclusion section |
| **Removed** | — |

---

## PHPUnit

| Command | Exit | Result | Assertions | ms |
| --- | ---: | --- | ---: | ---: |
| `--filter test_superadmin_login` `PresenceEngineTest` | 0 | **PASS** | 3 | ~2800 |
| `PresenceEngineTest` (full class) | 0 | **23/23 PASS** | 84 | 21802 |
| `ExecutiveCommandCenterTest::test_superadmin_login_lands_on_command_center` | 0 | **PASS** | 2 | 3208 |
| `WorkforceAuthorityServiceTest` + `OperationsRoleServiceTest` | 0 | **PASS** | — | — |

Full suite: **NOT RUN**. Pint/phpstan: **NOT RUN** (single-line test change).

---

## Owner decisions recorded

| Item | Status |
| --- | --- |
| P-13-44 / row 98 | **SKIPPED — WONT FIX** (ledger + `.artifacts/P-13-47-OWNER-DECISIONS.md`) |
| P-13-46 / row 19 | **SKIPPED — WONT FIX** (same) |
| Rows 135, 136 | **NOT investigated** (per scope) |

---

## Production / wallet / external

| Action | Status |
| --- | --- |
| Production access | **NO — Not performed** |
| Deployment | **NO — Not performed** |
| Production data changes | **NO — Not performed** |
| External calls | **NO — Not performed** |
| Wallet WIP `d9481615` | **Preserved — unchanged** |

---

## Git / CI

Populated after merge in ledger append row.

---

*End of P-13-47 report.*
