# P-13-19 — Order identity protection and serial correction (matrix rows 115–116, 137)

| Field | Value |
| --- | --- |
| Global Prompt ID | **P-13-19** |
| Cursor ledger ID | **RadiumDesk-P-13-19** |
| Repository | `/Users/ravi/RadiumWebsites/radium-desk` |
| Remote | `git@github.com:radium-foundation/radium-desk.git` |
| Worktree | `.worktrees/p13-19-order-identity` → `feature/p13-19-order-identity` |
| Verified `origin/main` (start) | **`f1493999c1d9c586b991d5caa8aad254b7777abc`** |
| Start | **2026-11-13 10:41:00 IST** |
| End | **2026-11-13 10:44:00 IST** |

---

## Exact queue assignment (from `.artifacts/P-13-06-NEXT-AGENT-QUEUE.md`)

## P-13-19 — Order identity protection and serial correction

| Field | Value |
| --- | --- |
| Lines | **115**, **116**, **137** |
| Methods | `OrderIdentityProtectionTest` (2), `RequestCorrectSerialTest::test_manual_request_correct_serial_persists_audit_and_timeline_entries` |
| Root cause | **INFERRED** identity lock + timeline dedupe + manual correction audit |
| Ownership | Order identity protection services, serial correction workspace actions |
| Focused regression | `OrderIdentityProtectionTest`, `RequestCorrectSerialTest` |
| Dependencies | Serial insight / workspace close paths (P-13-15 closed) — read-only conflict check |
| Overlap risk | Low |
| Risk | Normal |
| Parallel worktree | **Yes** |

**Boundaries:** No canonical CSV/register edits; wallet WIP untouched.

---

## Assigned register rows

| CSV line | Test identifier | P-12-32 outcome |
| ---: | --- | --- |
| **115** | `Tests.Feature.OrderIdentityProtectionTest::serial_correction_remains_locked_after_correction` | FAIL |
| **116** | `Tests.Feature.OrderIdentityProtectionTest::timeline_includes_initial_protection_event_only_once_per_field` | FAIL |
| **137** | `Tests.Feature.RequestCorrectSerialTest::test_manual_request_correct_serial_persists_audit_and_timeline_entries` | FAIL |

**Canonical register not edited** (not assigned).

---

## Elapsed time by stage

| Stage | Elapsed |
| --- | --- |
| VERIFY | ~3 min |
| INSPECT / reproduce | ~4 min |
| MODIFY | ~2 min |
| TEST | ~3 min measured |
| REVIEW / REPORT / GIT | ~5 min (estimate) |
| **Total** | **~17 min** (wall-clock estimate) |

---

## Findings and classification

| Row | Root cause | Classification | Label |
| --- | --- | --- | --- |
| **115** | Workspace `correct-serial-number` authorize uses `SerialCorrectionEligibilityService` → paid Cashfree order required via `Order::isAgentCorrectableIdentityOrder()`; test incident order omitted `cashfree_payment_id` → **403** | **Test-harness defect** (fixture gap) | **VERIFIED** |
| **116** | `Customer360OperatorTimelinePresentation::shouldHideTechnicalEvent()` hid all `identity-protection:*` dedupe keys from operator timeline; dedupe in `TimelineService` already limits to one event per field | **Application defect** (over-aggressive visibility filter) | **VERIFIED** |
| **137** | `shouldHideDuplicateCommunication()` hid `serial-correction:audit:*` events despite audit/timeline source writing `Serial correction requested` | **Application defect** (over-aggressive visibility filter) | **VERIFIED** |

**Stale register:** **INFERRED** — failures were reproducible on `f1493999`, not matrix-only noise.

---

## Behaviour

| | |
| --- | --- |
| Preserved | Identity lock on correction, RadiumBox sync respect, audit payloads, timeline dedupe by `dedupeKey` |
| Extended | Operator Customer 360 timeline shows identity-protection and serial-correction-request events |
| Changed | `Customer360OperatorTimelinePresentation` visibility rules; `OrderIdentityProtectionTest` paid-order fixture for workspace serial correction |
| Removed | Nothing |

---

## Focused validation

**Assigned cluster:**

```bash
cd .worktrees/p13-19-order-identity
/opt/homebrew/bin/php -n /tmp/composer.phar dump-autoload -o   # baseDir = this worktree
DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated \
  --filter 'serial_correction_remains_locked_after_correction|timeline_includes_initial_protection_event_only_once_per_field|test_manual_request_correct_serial_persists_audit_and_timeline_entries' \
  tests/Feature/OrderIdentityProtectionTest.php tests/Feature/RequestCorrectSerialTest.php
```

| Result | Tests | Assertions | Duration | Exit |
| --- | ---: | ---: | ---: | ---: |
| **PASS** (post-fix) | 3/3 | 19 | 4226 ms | 0 |

**Pre-fix repro:** 0/3 — 403; protection event count 0; timeline missing serial correction.

**Focused regression (queue):**

| Suite | Result | Count | Assertions | Duration |
| --- | --- | ---: | ---: | ---: |
| `OrderIdentityProtectionTest.php` | **PASS** | 8/8 | 71 (class total includes all methods) | (in 15-test batch) |
| `RequestCorrectSerialTest.php` | **PASS** | 7/7 | — | (in 15-test batch) |
| **Both classes** | **PASS** | 15/15 | 71 | 14553 ms |

**Neighbouring:**

| Suite | Result | Count | Duration |
| --- | --- | ---: | ---: |
| `WorkspaceCorrectSerialNumberTest.php` | **PASS** | 7/7 | 7566 ms |
| `Customer360UnifiedTimelineTest.php` | **PASS** | 10/10 | 10169 ms |

**Pint:** **PASS** (`vendor/bin/pint --dirty`)

Full PHPUnit suite: **NOT RUN**

---

## Diff review

| Check | Status |
| --- | --- |
| Scope limited to P-13-19 identity/timeline + one test fixture | **PASSED** |
| No secrets / env / customer data | **PASSED** |
| No canonical CSV | **PASSED** |
| Wallet @ `d9481615` | **VERIFIED** unchanged |
| Wallet WIP | **NOT RUN** (git verify only) |

---

## Git / PR / CI

| Field | Value |
| --- | --- |
| Branch | `feature/p13-19-order-identity` |
| Starting HEAD | `f1493999c1d9c586b991d5caa8aad254b7777abc` |
| Commit | *(pending footer)* |
| PR | *(pending)* |
| Merge SHA | *(pending)* |

Production / deployment: **NO — Not performed**

---

## Risks / follow-up

- Matrix rows **115–116–137** remain STILL FAILING in CSV until a register reconciliation pass.
- Operator timeline may show slightly more events (intended product visibility).

---

*End of P-13-19 report (pre-merge footer).*
