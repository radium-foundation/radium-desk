# PHPUnit full-suite authorization (P-12-40)

**Default:** DENY repository-wide PHPUnit runs unless Owner-granted authorization is present.

Aligned with `docs/RADIUMWEBSITES_TESTING_AND_VALIDATION_POLICY.md` §5 (full PHPUnit execution policy).

## Safe entrypoint

Use **`bin/phpunit-safe`** (or `php scripts/phpunit-guard.php`) instead of bare `vendor/bin/phpunit` when running locally or in automation that should respect the gate.

```bash
# Focused (allowed)
./bin/phpunit-safe tests/Unit/Support/Testing/PhpunitGuardTest.php
./bin/phpunit-safe --filter test_narrow_filter_is_allowed_without_auth

# Full repository suite (requires grant file)
./bin/phpunit-safe
```

## Focused run rules (allow without grant)

A run is **focused** when any of the following is true:

1. A positional argument selects tests: path ending in `.php` under `tests/`, or a class name like `FooTest`, or `ClassTest::method`.
2. `--filter` is non-empty and **not** a catch-all pattern. Denied filters include `.`, `.*`, `^.*$`, and patterns composed only of regex wildcards.
3. `--help`, `--version`, `-h`, `--check-version`, or `--atleast-version` only (info; does not execute tests).

**Not focused** (treated as full suite): bare invocation, `--testsuite Unit|Feature` alone, configuration-only flags, or broad `--filter` values.

## Owner grant (full suite)

Grant file (gitignored): `storage/app/tmp/phpunit-full-suite-auth.json`

Required JSON fields:

| Field | Purpose |
| ----- | ------- |
| `authorized_by` | Owner identifier (display name; no secrets) |
| `authorized_at` | ISO-8601 timestamp when granted |
| `expires_at` | ISO-8601 expiry (must be in the future) |
| `prompt_id` | Cursor ledger ID (e.g. `RadiumDesk-P-12-40`) |
| `sha` | Git commit SHA the grant applies to (40 hex chars) |

### Create a grant (one-shot)

```bash
php scripts/grant-phpunit-full-suite-auth.php \
  --by="Owner" \
  --prompt-id="RadiumDesk-P-12-40" \
  --ttl-minutes=120
```

Optional: `--sha=<40-char>` when `.git/HEAD` is unavailable.

After expiry or commit change (`sha` mismatch), full-suite runs are blocked again until a new grant is written.

## Blocked attempts and logs

Denied runs append a single JSON line (no secrets) to:

- `storage/logs/phpunit-guard.log`
- `.artifacts/phpunit-guard-blocked.log` (when `.artifacts/` exists)

Fields: `at`, `scope`, `reason`, `via`, `argv_summary`.

## Bypass limitation

**`vendor/bin/phpunit` invoked directly does not pass through this gate.** Shell history, IDE test runners, and CI jobs that call PHPUnit explicitly bypass `bin/phpunit-safe`.

Mitigations:

- Prefer `bin/phpunit-safe` in docs and local habit.
- CI uses **path-scoped** `./vendor/bin/phpunit tests/...` (focused); see `.github/workflows/release-rip-ci.yml`.
- Optional full-suite CI: `.github/workflows/phpunit-full-suite-dispatch.yml` (`workflow_dispatch` only; requires `owner_approval_token` matching repository secret `PHPUNIT_FULL_SUITE_OWNER_TOKEN`). **Do not run** without Owner approval.

Existing **P-10-05** wrapper `bin/phpunit-p1010/p1010-run-full-phpunit.sh` remains the authoritative orchestration path for monitored full runs; combine with a grant file when using `bin/phpunit-safe` or document P1010 env gates separately.

## CI / PR behavior

Pull-request workflows (`rip`, domain RIPs, `release-rip`) run **focused** PHPUnit commands only. They do not invoke an unscoped full suite on every PR.
