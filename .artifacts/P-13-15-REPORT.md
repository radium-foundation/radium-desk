# P-13-15 — Stale workspace close/resolve register re-verification

| Field | Value |
| --- | --- |
| Global Prompt ID | **P-13-15** |
| Cursor ledger ID | **RadiumDesk-P-13-15** (claimed in this report only) |
| Repository | `/Users/ravi/RadiumWebsites/radium-desk` |
| Reference | P-13-07 §5.2 workspace close/resolve stale register (16 rows) |
| Worktree | `.worktrees/p13-15-workspace-close-verify` → branch `p13-15-workspace-close-verify` |
| Verification HEAD | `origin/main` @ **`b54b0a53c26f1f123cf37921306929c0fce44543`** (2026-11-13 09:52:01 +0530, merge #216) |
| Historical baseline (P-13-07) | `f358ab04a9a57623085f19c47c6e0045d9b0a7ea` — **ancestor** of current `origin/main`; workspace close paths **unchanged** between the two SHAs |
| Prior focused PASS evidence | P-12-37 @ `2ca010b4` (40/40 on these two classes) |
| Start | **2026-11-13 10:00:08 IST** |
| End | **2026-11-13 10:04:02 IST** |
| Elapsed | **3 minutes 54 seconds** |
| Mode | Evidence-only. No fixes. |

`origin/main` `docs/cursor-prompt-ledger.md` ends at **RadiumDesk-P-13-13**. This prompt claims **RadiumDesk-P-13-15**. **RadiumDesk-P-13-05** and **RadiumDesk-P-13-07** exist only as local `.artifacts/*` reports. The ledger file was **not** appended.

---

## 1. Prechecks

| Check | Result |
| --- | --- |
| Repo root | `/Users/ravi/RadiumWebsites/radium-desk` **PASSED** |
| Primary checkout (wallet) | `feature/p18-33-unified-wallet-display` @ **`d94816153cd2c89f8175d29a1510b38b4c16df6b`** — **not used for PHPUnit** |
| Remote | `github.com:radium-foundation/radium-desk` |
| `git fetch origin main` | **PASSED** |
| Verified `origin/main` | **`b54b0a53`** (advanced since P-13-07’s `f358ab04`; no diff on workspace-close test/fixture paths in `f358ab04..b54b0a53`) |
| Isolated worktree | **PASSED** — created `.worktrees/p13-15-workspace-close-verify` @ `b54b0a53` |
| Testing policy | Read `docs/RADIUMWEBSITES_TESTING_AND_VALIDATION_POLICY.md` v1.0 **PASSED** (Level 1–2 focused run only) |
| P-13-07 report | Read `.artifacts/P-13-07-REPORT.md` **PASSED** |
| Canonical register | Read `origin/main:.artifacts/P-12-32-matrix-reconciliation.csv` — **not edited** |
| Conflicting ownership | **PASSED**. **P-13-14** (`p13-14-stale-dashboard-verify`) owns dashboard SSR rows only. **P-13-05** owns WS-03 Zip row 217. No agent owns `WorkspaceResolveCloseActionTest` / `WorkspaceActionDialogTest` for this pass |
| Test DB isolation | **PASSED** — `phpunit.xml` forces `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` |
| Temporary storage | **PASSED** — `tests/bootstrap.php` on `origin/main` creates `storage/app/tmp` (no shared live DB) |
| Vendor | Symlink `vendor` → `../../vendor` in worktree (no `composer install` in wallet tree) |

---

## 2. Sixteen matrix rows (exact scope)

From P-13-07 §5.2 and `P-12-32-matrix-reconciliation.csv` on `origin/main` (register still **STILL FAILING** for all sixteen):

| CSV line | Test identifier |
| ---: | --- |
| 190 | `Tests.Feature.WorkspaceActionDialogTest::test_agent_close_requires_transaction_id` |
| 192 | `Tests.Feature.WorkspaceActionDialogTest::test_close_action_closes_service_case_with_remark_and_timeline` |
| 193 | `Tests.Feature.WorkspaceActionDialogTest::test_device_case_missing_serial_still_shows_request_serial_number_action` |
| 194 | `Tests.Feature.WorkspaceActionDialogTest::test_exception_id_generation_is_unique_per_type_and_day` |
| 195 | `Tests.Feature.WorkspaceActionDialogTest::test_global_search_finds_service_case_by_exception_id` |
| 196 | `Tests.Feature.WorkspaceActionDialogTest::test_hardware_case_still_requires_serial_verification_before_close` |
| 197 | `Tests.Feature.WorkspaceActionDialogTest::test_legacy_resolve_route_now_closes_service_case` |
| 203 | `Tests.Feature.WorkspaceResolveCloseActionTest::test_admin_can_resolve_with_required_remark` |
| 204 | `Tests.Feature.WorkspaceResolveCloseActionTest::test_agent_close_action_requires_remark_body_and_transaction_id` |
| 205 | `Tests.Feature.WorkspaceResolveCloseActionTest::test_agent_resolve_action_requires_remark_body_and_transaction_id` |
| 206 | `Tests.Feature.WorkspaceResolveCloseActionTest::test_close_action_returns_dashboard_refresh_payload_with_reopen_action_trigger` |
| 207 | `Tests.Feature.WorkspaceResolveCloseActionTest::test_close_action_returns_service_case_timeline_and_header_refresh_payload` |
| 209 | `Tests.Feature.WorkspaceResolveCloseActionTest::test_resolve_action_persists_mention_relationship_without_notification` |
| 210 | `Tests.Feature.WorkspaceResolveCloseActionTest::test_resolve_action_returns_dashboard_refresh_payload` |
| 211 | `Tests.Feature.WorkspaceResolveCloseActionTest::test_resolve_action_returns_service_case_timeline_and_header_refresh_payload` |
| 212 | `Tests.Feature.WorkspaceResolveCloseActionTest::test_resolve_timeline_shows_remark_before_status_change` |

**Excluded by design (same cluster, not in the sixteen):** line 202 `WorkspaceRemarkActionTest` (P-13-07). Line 191 assign-action row is already **VERIFIED FIXED** in the CSV.

**PHPUnit entrypoint:** `DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated` (PHP **8.5.10**, `php -n`, Imagick autoload when present). Every run emitted a benign `Parse error: syntax error, unexpected end of file in Command line code on line 1` before JSON summary (same pattern as P-13-05); exit status and PHPUnit result were unaffected.

---

## 3. Per-row focused runs

Workdir: `.worktrees/p13-15-workspace-close-verify`

Template:

```bash
DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated \
  --filter <method> tests/Feature/<Class>Test.php
```

| Line | Method | Exit | Tests | Assertions | Duration | Disposition |
| ---: | --- | ---: | ---: | ---: | ---: | --- |
| 190 | `test_agent_close_requires_transaction_id` | **0** | 1 | 3 | 2447 ms | **VERIFIED PASS** |
| 192 | `test_close_action_closes_service_case_with_remark_and_timeline` | **0** | 1 | 6 | 2429 ms | **VERIFIED PASS** |
| 193 | `test_device_case_missing_serial_still_shows_request_serial_number_action` | **0** | 1 | 5 | 2724 ms | **VERIFIED PASS** |
| 194 | `test_exception_id_generation_is_unique_per_type_and_day` | **0** | 1 | 5 | 2634 ms | **VERIFIED PASS** |
| 195 | `test_global_search_finds_service_case_by_exception_id` | **0** | 1 | 4 | 4868 ms | **VERIFIED PASS** |
| 196 | `test_hardware_case_still_requires_serial_verification_before_close` | **0** | 1 | 4 | 5837 ms | **VERIFIED PASS** |
| 197 | `test_legacy_resolve_route_now_closes_service_case` | **0** | 1 | 3 | 5700 ms | **VERIFIED PASS** |
| 203 | `test_admin_can_resolve_with_required_remark` | **0** | 1 | 4 | 4569 ms | **VERIFIED PASS** |
| 204 | `test_agent_close_action_requires_remark_body_and_transaction_id` | **0** | 1 | 8 | 2532 ms | **VERIFIED PASS** |
| 205 | `test_agent_resolve_action_requires_remark_body_and_transaction_id` | **0** | 1 | 10 | 2407 ms | **VERIFIED PASS** |
| 206 | `test_close_action_returns_dashboard_refresh_payload_with_reopen_action_trigger` | **0** | 1 | 6 | 2472 ms | **VERIFIED PASS** |
| 207 | `test_close_action_returns_service_case_timeline_and_header_refresh_payload` | **0** | 1 | 7 | 2503 ms | **VERIFIED PASS** |
| 209 | `test_resolve_action_persists_mention_relationship_without_notification` | **0** | 1 | 3 | 2419 ms | **VERIFIED PASS** |
| 210 | `test_resolve_action_returns_dashboard_refresh_payload` | **0** | 1 | 12 | 2490 ms | **VERIFIED PASS** |
| 211 | `test_resolve_action_returns_service_case_timeline_and_header_refresh_payload` | **0** | 1 | 11 | 2936 ms | **VERIFIED PASS** |
| 212 | `test_resolve_timeline_shows_remark_before_status_change` | **0** | 1 | 4 | 2470 ms | **VERIFIED PASS** |

**Per-row totals:** **16/16 PASS**, **101 assertions**, ~**65.9 s** wall time (sequential). Raw log: `.worktrees/p13-15-workspace-close-verify/.artifacts/p13-15-per-row.log`.

**Failures / blockers:** none. **NOT RUN:** 0.

---

## 4. Combined class run (regression guard)

```bash
cd .worktrees/p13-15-workspace-close-verify
DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated \
  tests/Feature/WorkspaceResolveCloseActionTest.php \
  tests/Feature/WorkspaceActionDialogTest.php
```

| Metric | Value |
| --- | --- |
| Exit code | **0** |
| Tests / assertions | **40 / 227** |
| Duration | **45 303 ms** |
| JSON summary | `{"result":"passed","tests":40,"passed":40,"assertions":227}` |

Matches P-12-37’s **40/40** scope for these two files (six extra methods in the files are not among the sixteen stale CSV rows but all passed).

---

## 5. Findings (VERIFIED / INFERRED / UNKNOWN)

| Finding | Label |
| --- | --- |
| All sixteen register rows **PASS** on `origin/main` @ `b54b0a53` | **VERIFIED** |
| Canonical CSV still lists them **STILL FAILING** (P-12-32 @ `27b03d19`) | **VERIFIED** — register is stale relative to current `main` |
| P-13-07 claim: fix landed @ `2ca010b4`, no later change to `CreatesWorkspaceServiceCaseFixtures` or `ServiceCaseCloseRequirementService` through `b54b0a53` | **VERIFIED** (`git log f358ab04..b54b0a53` empty for those paths) |
| Root cause from P-12-37 (placeholder serials → close **422** via `SerialInsightService`) still explains P-12-32 failures; fixtures now use verified MFS110 serial **`6419897`** | **VERIFIED** (trait on `origin/main`; tests exercise `PATCH` `incidents.workspace.resolve` / `incidents.workspace.close` with `assertOk`, timeline/header refresh, and `IncidentStatus::Closed`) |
| Tests are not vacuous: resolve/close paths assert JSON refresh payloads, remark validation, hardware gate (**422** for unverified serial), and DB status transitions | **VERIFIED** (sample: `test_resolve_action_returns_service_case_timeline_and_header_refresh_payload`) |
| Failures on current `main` are **not** application defects in close/resolve for these rows | **VERIFIED** |
| Failures are **not** environment-blocked (no Imagick requirement in this cluster) | **VERIFIED** |
| Classification vs P-12-32 log | **STALE REGISTER** — expectations/fixtures fixed in #175 / P-12-37; matrix never reconciled |

**UNKNOWN:** none material to this scope.

---

## 6. P-13-07 reconciliation update

| P-13-07 category | Count | After P-13-15 |
| --- | ---: | --- |
| Workspace close/resolve stale register (not re-run on `f358ab04`) | 16 | **Re-run complete** — **16 VERIFIED PASS** on `b54b0a53` |
| Analytical “stale register” label | INFERRED | **VERIFIED** on current `main` |

Do **not** edit the shared CSV from this evidence-only prompt.

---

## 7. Checks

| Check | Status |
| --- | --- |
| Repo, branch, HEAD, remote, worktree | **PASSED** |
| `git fetch origin main` + SHA | **PASSED** (`b54b0a53`) |
| Testing policy + P-13-07 report | **PASSED** |
| Ledger ID **RadiumDesk-P-13-15** claimed here | **PASSED** |
| Ledger file append | **NOT RUN** |
| Sixteen focused PHPUnit runs | **PASSED** (16/16) |
| Full PHPUnit suite | **NOT RUN** |
| Application / test / config edits | **NOT RUN** |
| Canonical CSV or register edits | **NOT RUN** |
| Migrations / production | **NOT RUN** |
| WS-04 OPM | **NOT RUN** |

| Row disposition summary | Count |
| --- | ---: |
| VERIFIED PASS | **16** |
| VERIFIED FAIL | **0** |
| BLOCKED | **0** |
| NOT RUN | **0** |

---

## 8. Preservation

| Item | Confirmation |
| --- | --- |
| Wallet checkout | `feature/p18-33-unified-wallet-display` @ **`d94816153cd2c89f8175d29a1510b38b4c16df6b`** — **unchanged** by this prompt (pre-existing dirty files untouched) |
| Isolated DB | SQLite `:memory:` per `phpunit.xml` only |
| Source / test / config / CSV | **No modifications** |
| This deliverable | Untracked `.artifacts/P-13-15-REPORT.md` on wallet checkout; worktree-only logs under `.worktrees/p13-15-workspace-close-verify/.artifacts/` |

**NO — Not performed:** commit, push, PR, merge, deployment, production changes, CSV/register updates, fixes, full PHPUnit.
