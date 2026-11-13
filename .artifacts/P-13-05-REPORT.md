# P-13-05 — WS-03 ZipArchive environment blocker

| Field | Value |
| --- | --- |
| Global Prompt ID | **P-13-05** |
| Cursor ledger ID | **RadiumDesk-P-13-05** (claimed in this report only) |
| Repository | `/Users/ravi/RadiumWebsites/radium-desk` |
| Start | **2026-11-13 09:01:35 IST** |
| End | **2026-11-13 09:04:33 IST** |
| Elapsed | **2 minutes 58 seconds** |
| Mode | Read-only diagnosis. Remediation not implemented. |

`origin/main` `docs/cursor-prompt-ledger.md` ends at **RadiumDesk-P-13-04**. This prompt claims **RadiumDesk-P-13-05**. The ledger file was not appended. **RadiumDesk-P-13-07** is a separate local planning report and does not own this row.

---

## 1. Prechecks

| Check | Result |
| --- | --- |
| Repo root | `/Users/ravi/RadiumWebsites/radium-desk` **PASSED** |
| Primary checkout | `feature/p18-33-unified-wallet-display` @ **`d94816153cd2c89f8175d29a1510b38b4c16df6b`** |
| Remote | `git@github.com:radium-foundation/radium-desk.git` |
| `git fetch origin main` | **PASSED** |
| `origin/main` | **`f358ab04a9a57623085f19c47c6e0045d9b0a7ea`** (2026-11-13 01:19:56 +0530, PR #207) |
| Known baseline | **MATCH** |
| Testing policy | Read `docs/RADIUMWEBSITES_TESTING_AND_VALIDATION_POLICY.md` v1.0 on `origin/main` **PASSED** |
| Conflicting ownership | **PASSED**. No `p13-05` worktree. P-13-07 names this row as the excluded P-13-05 item and does not edit it |

Focused PHPUnit ran in `.worktrees/p13-04-ws10-dashboard` (`ad29b831`), not in the wallet checkout. These blobs match `origin/main`: `tests/bootstrap.php`, `phpunit.xml`, `tests/Unit/Finance/CaMonthlyReportXlsxPackageValidatorTest.php`, `app/Support/Finance/CaMonthlyReportXlsxPackageValidator.php`.

---

## 2. Matrix row

From `origin/main:.artifacts/P-12-32-matrix-reconciliation.csv` line **217**, cited by `.artifacts/P-13-07-REPORT.md`:

| Field | Value |
| --- | --- |
| Test id | `Tests.Unit.Finance.CaMonthlyReportXlsxPackageValidatorTest::test_validator_flags_minimal_package_missing_excel_required_parts` |
| Class / method | `Tests\Unit\Finance\CaMonthlyReportXlsxPackageValidatorTest::test_validator_flags_minimal_package_missing_excel_required_parts` |
| File | `tests/Unit/Finance/CaMonthlyReportXlsxPackageValidatorTest.php` |
| Workstream | **WS-03** |
| Register status | **ENVIRONMENT BLOCKED** |
| Register evidence | `P-12-32 phpunit @27b03d19 → ERROR` |
| Sole ENVIRONMENT BLOCKED row | **VERIFIED** (CSV count = 1) |

P-12-32 log `phpunit-full-0092.log` @ `27b03d19` (2026-11-12 15:40:43 +0530):

`ZipArchive::close(): Failure to create temporary file: No such file or directory`

Assertions recorded: **0**. The validator never ran.

---

## 3. Findings

| Finding | Label |
| --- | --- |
| `ext-zip` is present. Homebrew PHP **8.5.10** (`/opt/homebrew/bin/php -n`): `extension_loaded('zip')` yes, `ZipArchive` yes. Herd PHP **8.4.23** (`php -n`): zip yes | **VERIFIED** |
| The test writes `storage_path('app/tmp/ca-monthly-invalid-package.xlsx')` with `ZipArchive::CREATE \| OVERWRITE`, then `close()`. It does not create the directory itself | **VERIFIED** (source on `origin/main`) |
| Missing parent directory: `open` returns true, `close()` warns `Failure to create temporary file: No such file or directory` and returns false. Reproduced with `/opt/homebrew/bin/php -n` against `/tmp/p1305-zip-missing/does-not-exist/pkg.xlsx`. Same path with the directory present: `close` true and the file exists | **VERIFIED** |
| `storage/app` is gitignored (`*` with exceptions). `storage/app/tmp` is not in git. A fresh tree has no tmp directory until something creates it | **VERIFIED** (`storage/app/.gitignore`, `docs/phpunit-test-environment.md`) |
| `tests/bootstrap.php`, wired by `phpunit.xml` `bootstrap="tests/bootstrap.php"`, creates `storage/app/tmp` and `storage/app/tmp/ca-monthly-report` | **VERIFIED** |
| That bootstrap is on `origin/main` via **`b79c6d7b`** ([#182](https://github.com/radium-foundation/radium-desk/pull/182), 2026-11-12 18:17:09 +0530), which is after the matrix SHA `27b03d19` and is an ancestor of `f358ab04` | **VERIFIED** |
| Focused test with `bin/phpunit-isolated` on the matching worktree: **1 test, 1 passed, 2 assertions, 396 ms** | **VERIFIED** |
| Classification | **HARNESS**, not a missing extension, not misconfigured `php.ini`, not an application defect in `CaMonthlyReportXlsxPackageValidator` |
| The CSV label ENVIRONMENT BLOCKED is stale relative to current `main` | **VERIFIED** (failure predates #182; current focused run passes) |
| PHPUnit turned the warning into an error at P-12-32. This run’s raw CLI shows a warning and `close=false` because no PHPUnit error handler was installed | **VERIFIED** both observations. The promotion path is the P-12-32 log |
| Deterministic when the parent directory is absent | **VERIFIED** |
| Other CA monthly tests also write under `storage/app/tmp`. `CaMonthlyReportExportBenchmarkTest` calls `File::ensureDirectoryExists` itself. The matrix blocked only this unit row | **VERIFIED** for the matrix count. Whether every other writer fails without bootstrap was **NOT RUN** |
| Launcher printed `Parse error: syntax error, unexpected end of file in Command line code on line 1` before the JSON result. Exit status was still 0 and the test passed | **VERIFIED** output. Cause of that parse line is **UNKNOWN**. It is not the ZipArchive failure |

---

## 4. Commands

```text
git fetch origin main
git rev-parse origin/main
# f358ab04a9a57623085f19c47c6e0045d9b0a7ea  MATCHES baseline

/opt/homebrew/bin/php -n -r 'extension_loaded("zip"); class_exists("ZipArchive");'
# PHP 8.5.10, zip yes

/opt/homebrew/bin/php -n  # ZipArchive::close on a missing parent under /tmp
# Warning: ZipArchive::close(): Failure to create temporary file: No such file or directory
# close=false

cd .worktrees/p13-04-ws10-dashboard
DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated \
  --filter test_validator_flags_minimal_package_missing_excel_required_parts \
  tests/Unit/Finance/CaMonthlyReportXlsxPackageValidatorTest.php
# {"result":"passed","tests":1,"passed":1,"assertions":2,"duration_ms":396}
```

`storage/app/tmp` already existed in that worktree (created 2026-11-13 01:11). This run did not delete it. The pass shows the standard bootstrap entrypoint succeeds. The missing-directory failure was reproduced outside the repo under `/tmp`, then removed.

---

## 5. Root cause

**VERIFIED.** `ZipArchive::close()` cannot create its temp file when `storage/app/tmp` does not exist. The unit test assumes that directory. It is gitignored, so a clean checkout does not have it. P-12-32 ran at `27b03d19`, before `tests/bootstrap.php` (#182) created the directory at suite start. `ext-zip` is loaded. The validator class is not what failed.

---

## 6. Proposed remediation (not done)

Smallest change: **do not change application code, PHP, or packages.** The harness fix is already on `f358ab04`.

1. Keep `phpunit.xml` → `tests/bootstrap.php` and `bin/phpunit-isolated` as the entrypoints (`docs/phpunit-test-environment.md`).
2. In a later prompt that is allowed to edit the register, reclassify CSV line 217 from ENVIRONMENT BLOCKED to **VERIFIED FIXED (harness)**, using the focused pass above. Update `.artifacts/P-12-44-REMAINING-FAILURES.md` counts in that same prompt. This prompt did not edit either file.
3. Validation for that prompt: the same filtered `bin/phpunit-isolated` command. Full PHPUnit is not required for this row. Do not run it unless a release gate asks for it.

**Risk:** Low. A custom PHPUnit invocation that skips `tests/bootstrap.php` will hit the same `close()` warning. No production behavior depends on this test directory.

**Owner approval:** Not required for the already-merged bootstrap. Required only if a follow-up prompt edits the canonical failure register. No production, migration, or dependency approval.

---

## 7. Checks

| Check | Status |
| --- | --- |
| Repo, branch, HEAD, remote, worktrees | **PASSED** |
| `origin/main` equals `f358ab04` | **PASSED** |
| Policy and ledger read | **PASSED** |
| Ledger ID claimed in this report | **PASSED** |
| Ledger file append | **NOT RUN** |
| P-13-07 row identified | **PASSED** |
| Ownership conflict check | **PASSED** |
| PHP zip diagnostic | **PASSED** |
| Missing-directory reproduction | **PASSED** |
| Focused PHPUnit | **PASSED** (1/1) |
| Full PHPUnit | **NOT RUN** |
| Migrations / production | **NOT RUN** |
| Remediation | **NOT RUN** |

---

## 8. Preservation

No application, test, configuration, dependency, environment-variable, database, infrastructure, or production state was changed. The canonical CSV and remaining-failure register were not edited. No commit, push, PR, merge, or deploy.

Wallet checkout remains `feature/p18-33-unified-wallet-display` @ **`d94816153cd2c89f8175d29a1510b38b4c16df6b`**. It was not used to run tests. This report is an untracked file `.artifacts/P-13-05-REPORT.md` and was not staged. The focused test’s worktree (`p13-04-ws10-dashboard`) showed no tracked-file changes afterward. The `/tmp` reproduction directory was removed.

**NO — Not performed:** application or test edits, package installs, php.ini or env changes, deleting `storage/app/tmp`, register/CSV edits, ledger append, full PHPUnit, migrations, production access, wallet WIP edits, commits, pushes, pull requests, merges, deploys.
