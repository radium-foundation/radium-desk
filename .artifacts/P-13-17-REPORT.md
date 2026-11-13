# P-13-17 — Incoming email intake (matrix rows 80–81)

| Field | Value |
| --- | --- |
| Global Prompt ID | **P-13-17** |
| Cursor ledger ID | **RadiumDesk-P-13-17** |
| Repository | `/Users/ravi/RadiumWebsites/radium-desk` |
| Worktree | `.worktrees/p13-17-incoming-email` → `feature/p13-17-incoming-email-intake` |
| Verified `origin/main` (start) | **`f2b5eb237b7db4f08b9537c41f740d1e387b1de7`** |
| Start | **2026-11-13 10:11:05 IST** |
| End | **2026-11-13 10:16:42 IST** |

---

## Assigned rows

| CSV line | Test identifier | P-12-32 outcome |
| ---: | --- | --- |
| **80** | `Tests.Feature.IncomingEmailIntakePhase1Test::test_ingest_propagates_unrelated_query_exceptions` | FAIL |
| **81** | `Tests.Feature.IncomingEmailIntakePhase1Test::test_ingest_returns_existing_message_on_rfc_message_id_insert_race` | ERROR |

Source: `.artifacts/P-12-32-matrix-reconciliation.csv` on `origin/main` @ `f2b5eb23`. **Canonical register not edited** (P-13-06 owns CSV).

---

## Findings

| Item | Label |
| --- | --- |
| Root cause | **VERIFIED** test-harness defect: `TestCase::partialMock(IncomingEmailIngestService::class)` does not run the real constructor, so promoted readonly **`IncomingEmailFilterService`** was never initialized after that dependency was added to `IncomingEmailIngestService::ingest()` (label early-skip path @ line 41). |
| Row 80 expected behaviour | **VERIFIED** — non–RFC-duplicate `QueryException` (e.g. FK **1452**) must **propagate**; ingest must not treat it as idempotent race recovery. |
| Row 81 expected behaviour | **VERIFIED** — on RFC unique violation, second `findExisting()` returns the winner row; ingest returns existing message; single DB row. |
| Application defect | **None** — production ingest / idempotency logic unchanged. |
| Stale register | **INFERRED** — rows were failing due to harness, not live ingest regression on `f2b5eb23`. |

---

## Behaviour

| | |
| --- | --- |
| Preserved | `IncomingEmailIngestService` dedupe, label skip, race recovery, audit/outbox paths |
| Extended | Test helper `partialIngestServiceWithMockedFindExisting()` with explicit constructor wiring |
| Changed | Two tests now use helper instead of `partialMock()` |
| Removed | Nothing |

---

## Focused validation

**Cluster (assigned):**

```bash
cd .worktrees/p13-17-incoming-email
DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated \
  --filter 'test_ingest_propagates_unrelated_query_exceptions|test_ingest_returns_existing_message_on_rfc_message_id_insert_race' \
  tests/Feature/IncomingEmailIntakePhase1Test.php
```

| Result | Tests | Assertions | Duration | Exit |
| --- | ---: | ---: | ---: | ---: |
| **PASS** (post-fix) | 2/2 | 6 | 3280 ms | 0 |

**Pre-fix repro:** ERROR/FAIL — uninitialized `$filterService` and Mockery call-count failures.

**Regressions:**

| Suite | Result | Count | Duration |
| --- | --- | ---: | ---: |
| `tests/Feature/IncomingEmailIntakePhase1Test.php` | **PASS** | 18/18 (97 assertions) | 18267 ms |
| `tests/Feature/IncomingEmail/IncomingEmailLearningCenterAccessTest.php` | **PASS** | 9/9 (56 assertions) | 13845 ms |

Full PHPUnit suite: **NOT RUN**.

---

## Checks

| Check | Status |
| --- | --- |
| Repo / worktree / `origin/main` | **PASSED** |
| Testing policy | **PASSED** |
| Ledger **RadiumDesk-P-13-17** | **PASSED** |
| Register / CSV edits | **NOT RUN** (forbidden) |
| Real email / prod mailbox / live integrations | **NOT RUN** |
| Wallet @ `d9481615` | **VERIFIED** unchanged |
| Pint on touched test file | **PASSED** |

---

## Git / PR / CI

| Field | Value |
| --- | --- |
| Branch | `feature/p13-17-incoming-email-intake` |
| Commit | *(post-push)* |
| PR | *(post-create)* |
| Merge SHA | *(post-merge)* |

---

## Risks / Owner decisions

None required — harness-only correction; no policy or integration change.

**NO — Not performed:** deployment, production mail configuration, canonical register update, full PHPUnit.

---

*End of P-13-17 report.*
