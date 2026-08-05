# Performance Sprint — Regression Root-Cause Analysis

**Date:** 2026-08-05  
**Report:** PHPUnit JSON (`failed=130`, `errors=13` → **143** total)  
**Suite size in report:** 3267 tests · 3124 passed · 462s  
**Constraint:** Do **not** fix tests individually. Recommend product / harness root-cause fixes only.  
**No Canvas.**

---

## Verdict

The 143 reds are **not** 143 independent bugs. They collapse into **about 8 root causes** (7 primary + a thin remainder). The largest leverage is fixing shared harness/contract issues first (version, role bootstrap, session error-bag shape), then the two big domain drifts (Cashfree/outbox, serial-close gate), then presentation leftovers.

| Priority | Root cause | Approx. failures cleared | Fix in |
|----------|------------|--------------------------|--------|
| 1 | **Version expectation** | ~6–8 | Version / changelog resolution vs `phpunit.xml` |
| 2 | **Permission bootstrap** | ~5–15 | Seed roles before `assignRole()` / shared test bootstrap |
| 3 | **Collection→array / session error bag** | ~6 (+ masks more redirect fails) | Flash `errors` as `ViewErrorBag`, not bare array |
| 4 | **Serial-close verification gate** | ~12 | Close/resolve eligibility vs test fixtures (product contract) |
| 5 | **Cashfree + outbox/scheduler pipeline** | ~40–42 | Payment recovery / outbox completion path |
| 6 | **Workforce / leave eligibility** | ~11 | Leave override vs assignment eligibility |
| 7 | **Presentation expectation** (residual HTML) | ~25–35 | Real UI contract drift after removing cascades |
| 8 | **Infrastructure + thin tails** | ~5–8 | Factory/DB bootstrap, dashboard live refresh, query budget |

Estimates overlap slightly where one failure has a primary cause and a secondary symptom (e.g. wrong redirect + `errors` bag shape → surfaces as `all() on array`).

---

## Report inventory

| Metric | Value |
|--------|-------|
| Failures | 130 |
| Errors | 13 |
| **Total red** | **143** |
| Risky | 5 |
| Dominant error signatures | `RoleDoesNotExist: agent`, `Call to a member function all() on array`, version `4.0.0` vs `4.0.3` |
| Dominant failure signatures | HTML `assertSee` / DOCTYPE dumps, Cashfree count/`processed`≠`failed`, HTTP 200↔422 on workspace close |

---

## Root cause clusters

### RC-A — Collection→array / session `errors` bag shape (~6 errors)

**Signature:** `Call to a member function all() on array`

**Reproduced today** on `IdentityWaitingAutoClearTest::test_admin_serial_correction_clears_waiting_and_enters_ready_queue` (and same signature on Order update / Team Performance tests in the report).

**Mechanism (confirmed):** Laravel `TestResponseAssert::injectResponseContext()` does:

```php
$session->get('errors')->all()
```

when a redirect assertion fails. Session key `errors` is a **bare array**, not a `ViewErrorBag` / message bag. That turns an ordinary redirect/location failure into an **Error**, hiding the real assertion.

**Same cluster members in report:**

- `IdentityWaitingAutoClearTest` (×4)
- `OrderTransactionTest::test_superadmin_can_edit_completed_order_without_unlocking`
- `TeamPerformanceIntelligenceTest::test_team_member_sees_only_own_stats`

**Root-cause fix (product / session contract — not test edits):**

1. Find writers that flash validation/domain errors as arrays (`session(['errors' => ...])`, `->with('errors', $array)`, JSON round-trip, etc.).
2. Always flash via `withErrors()` / `ViewErrorBag` so `->all()` remains valid.
3. Optionally harden test harness later (defensive `is_array` check) — **secondary**; fix producers first.

**Estimate:** Fixing RC-A clears **~6 errors** immediately and unmasks the underlying redirect/location failures (likely 2–6 additional actionable fails in the same tests).

---

### RC-B — Permission bootstrap (~5 direct; up to ~15 with cascade)

**Signature:** `There is no role named \`agent\` for guard \`web\`` (+ some 403s)

**Reproduced today** on `Customer360InsightsPresenterTest` — `assignRole(RolePermissionSeeder::ROLE_AGENT)` without seeding roles.

**Mechanism:** `RefreshDatabase` leaves an empty permissions graph. Tests that call `assignRole('agent')` without `$this->seed(RolePermissionSeeder::class)` (or equivalent bootstrap) explode. Related 403s appear when actors lack seeded capabilities.

**Direct members:** 4× `Customer360InsightsPresenterTest` errors + `OrderIdentityProtectionTest` 403.

**Likely cascade:** Customer 360 drawer HTML asserts that render “empty” or missing actions when the actor never received real roles/permissions (~8–10 presentation fails in the C360 drawer cluster).

**Root-cause fix:**

1. Ensure shared test bootstrap (or base feature/unit case used by role-touching tests) seeds `RolePermissionSeeder` once per DB refresh.
2. Prefer `RolePermissionSeeder::ROLE_*` constants everywhere (already true in many places).
3. Do **not** mass-edit each test’s assertions — fix seeding once.

**Estimate:** **~5** hard errors cleared; **~10–15** total if C360 presentation rows are permission-starved.

---

### RC-C — Version expectation (~6)

**Signature:** Expected `4.0.0`, actual `4.0.3` (and changelog empty-state / snapshot version drift)

**Reproduced today** on `ChangelogServiceTest::test_entries_mark_only_matching_version_as_current`.

**Mechanism:** `phpunit.xml` pins `APP_VERSION=4.0.0`, while `VersionService` / changelog resolution prefers Git tag / CHANGELOG current section (`4.0.3` at report time). Tests that assume the env pin lose.

**Members:** `ChangelogServiceTest` (×3), `VersionServiceTest`, `PlatformIdentityTest` changelog empty state, `ReleaseSnapshotCommandTest` version in output.

**Root-cause fix (product resolution policy for testing):**

1. In testing, make `VersionService` honor `APP_VERSION` when explicitly set (or document that Git wins and sync `phpunit.xml` to the tagged version as a **release** step — not per-test edits).
2. Keep a single source of truth: tag ↔ CHANGELOG ↔ Whatʼs New (per release workflow).

**Estimate:** **~6** cleared. Does **not** explain most DOCTYPE `assertSee` failures (those needles are unrelated UI strings).

---

### RC-D — Serial-close / resolve verification gate (~12)

**Signature:** Expected `200`, received `422` with  
`Serial number must be verified or corrected before closing this service case.`  
(and one inverse: expected `422`, got `200`)

**Mechanism:** Workspace close/resolve path enforces serial verification. Fixtures in several workspace tests still expect pre-gate success (or the reverse for hardware).

**Root-cause fix:** Align **product close eligibility** with the intended operator contract (gate is correct vs tests outdated). If the gate is intentional, update **fixture setup** in one shared workspace test helper (create verified serial / mark corrected) — still one root fix, not 12 assertion rewrites.

**Estimate:** **~12** HTTP workspace failures.

---

### RC-E — Cashfree + outbox / scheduler pipeline (~40–42)

**Signatures:**

- Count / identity mismatches (`N is identical to N`)
- Webhook log `processed` vs `failed`
- Recovery command output missing `Successfully recovered`
- Outbox pending/retry counts
- Scheduler “runs in background” expectations

**Mechanism:** One payment → order → outbox → enrichment → recovery chain drifted (status transitions, retry metrics, or command wiring). Outbox/scheduler failures sit on the same async spine.

**Root-cause fix:** Restore the Cashfree success path invariants (commit order, outbox write, processor completion, recovery idempotency) in the services/commands — not by weakening assertions test-by-test.

**Estimate:** **~35** Cashfree-named failures + **~7** outbox/scheduler ≈ **~40–42**.

---

### RC-F — Workforce / leave eligibility (~11)

**Signatures:** Approved leave should block assignment / override availability; roster size; holiday skip; presence redirect for superadmin.

**Mechanism:** Leave/holiday authority vs smart-assignment / round-robin eligibility disagree.

**Root-cause fix:** Single eligibility authority (`WorkforceAuthorityService` / leave impact) must be the only gate used by assignment + IRA leave impact + calendar tests.

**Estimate:** **~11**.

---

### RC-G — Presentation expectation (residual HTML) (~25–35)

**Signatures:** `assertSee` / string contains on full page or Customer 360 drawer HTML; diverse needles (`Waiting for Service Reference`, `Assigned To`, Integrations anchors, email template CTA, etc.).

**Mechanism:** After removing cascades from RC-B/C/D, remaining HTML mismatches are real UI copy/structure drift (labels, anchors, drawer sections).

**Also includes:** Driver installation email template branding assert.

**Root-cause fix:** Fix presenters / Blade for the **shared** contracts (dashboard queue labels, C360 sections, admin anchors). Still product-side — not 30 one-off `assertSee` edits unless the product intentionally changed.

**Estimate:** **~25–35** after cascade removal (raw HTML cluster in the report is ~35 plus related drawer rows).

---

### RC-H — Infrastructure & thin tails (~5–8)

| Subtype | Count | Example | Fix |
|---------|------:|---------|-----|
| Missing factory / DB | 2 | `Order::factory()` undefined; `no such table: users` without migrations | Unit test must use `RefreshDatabase` + factories, or avoid Eloquent |
| Customer360 controller arity | 1 | `Customer360Controller::show()` ArgumentCountError (500) | Signature / route model binding |
| Dashboard live refresh | 2 | `QueueIntegrityLiveRefreshTest` event / `remove_rows` null | Broadcast + live payload contract (related to snapshot/live path) |
| Query expectation | 1 | Team availability batched `whereIn` | Keep batching in overview service |
| Allowlist / misc | ~2 | `CaseQueueReadModel` allowlist; recent activity SC id | Small product allowlist / presenter |

**Dashboard snapshot cache (performance sprint):** only a thin slice of *this* 143 report. On current `main` (`5547a2d`), `OperatorDashboardCache` stores an Eloquent `Collection` under `operator.dashboard.snapshot:v1` without global test `Cache::flush()`. That is a **latent amplifier** for dashboard flakes on full runs (array cache survives `RefreshDatabase`). Treat as a mandatory hardening fix even though it is not the bulk of this report.

**Estimate:** **~5–8** in-report; snapshot-cache hardening prevents a future multi-dozen dashboard cascade.

---

## Minimum-fix impact model

```
Fix RC-C Version              →  ~6–8
Fix RC-B Permission bootstrap →  ~5–15
Fix RC-A errors bag shape     →  ~6 (+ unmask redirects)
Fix RC-D Serial-close gate    →  ~12
Fix RC-E Cashfree/outbox      →  ~40–42
Fix RC-F Workforce/leave      →  ~11
Fix RC-G Presentation residual→  ~25–35
Fix RC-H Infra + snapshot TTL →  ~5–8 (+ future dashboard insulation)
────────────────────────────────────
Expected coverage of 143      →  ~110–137 depending on cascade overlap
```

**Practical minimum sequence (fewest code changes, most reds):**

1. Version resolution under `APP_ENV=testing`  
2. RolePermission seed in shared bootstrap  
3. Session `errors` always `ViewErrorBag`  
4. Cashfree/outbox success path  
5. Serial-close fixture/eligibility alignment  
6. Leave eligibility single authority  
7. Residual presentation + snapshot-cache test isolation  

---

## Performance sprint interaction (commit `5547a2d`)

This analysis is of the **143-red PHPUnit report** (130+13). Separately, the performance sprint on `main` adds caches that can create **new** cascades if not isolated in tests:

| Sprint change | Risk if unfixed | Recommended product fix |
|---------------|-----------------|-------------------------|
| `OperatorDashboardCache` active-incident `Collection` | Cross-test snapshot pollution (`CACHE_STORE=array`) | Disable snapshot cache when `app()->runningUnitTests()`, or `Cache::flush()` in `TestCase::setUp`, or store serializable IDs not live models |
| Case Intelligence cross-request cache | Stale IRA/AI between tests | Same: test disable or flush by key prefix |
| Email Intake widget cache | Stale KPI counts | Already forgotten on process in places; flush in test bootstrap |
| Team Activity lazy SSR shell | Presentation asserts expecting roster on first paint | Product already intentional; any remaining fails are presentation-contract (RC-G), not test noise |

Do **not** “fix” these by rewriting every dashboard test. Fix cache policy at the source.

---

## Explicit non-goals

- No per-test assertion rewrites as the first move  
- No weakening production gates to match fixtures without an explicit product decision  
- No Canvas  

---

## Recommended next step

Implement **RC-C → RC-B → RC-A** (version, roles, errors bag) as a single harness/contract PR, re-run the suite, then attack **RC-E** (Cashfree/outbox) and **RC-D** (serial-close). Re-cluster whatever remains under ~30 before touching presentation.

---

*End of regression analysis.*
