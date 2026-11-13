# P-13-21 — Customer-waiting automation cleanup (matrix rows 11, 12, 184)

| Field | Value |
| --- | --- |
| Global Prompt ID | **P-13-21** |
| Cursor ledger ID | **RadiumDesk-P-13-21** |
| Repository | `/Users/ravi/RadiumWebsites/radium-desk` |
| Remote | `git@github.com:radium-foundation/radium-desk.git` |
| Worktree | `.worktrees/p13-21-customer-waiting` → `feature/p13-21-customer-waiting` |
| Verified `origin/main` (start) | **`ac3a3d03186d3b6f6f483529426c937e29c579e1`** |
| Start | **2026-11-13 11:33:00 IST** |
| End | **2026-11-13 11:40:00 IST** (estimate) |

---

## Exact queue assignment

| Field | Value |
| --- | --- |
| Lines | **11**, **12**, **184** |
| Methods | `CustomerWaitingAppointmentRepairCommandTest::test_closed_incident_is_skipped`, `CustomerWaitingLegacyCleanupTest::test_cleanup_writes_timeline_entry_without_customer_notifications`, `WaitingStateOwnershipTest::test_no_op_reassignment_does_not_create_audit_or_notification` |
| Focused regression | The three test classes |
| Dependencies | Incident status sync (P-13-09 closed) — no Blade edits |

---

## Assigned register rows

| Line | Test | P-12-32 |
| ---: | --- | --- |
| **11** | `CustomerWaitingAppointmentRepairCommandTest::test_closed_incident_is_skipped` | STILL FAILING |
| **12** | `CustomerWaitingLegacyCleanupTest::test_cleanup_writes_timeline_entry_without_customer_notifications` | STILL FAILING |
| **184** | `WaitingStateOwnershipTest::test_no_op_reassignment_does_not_create_audit_or_notification` | STILL FAILING |

**Canonical register not edited.**

---

## Numbered implementation steps (evidence)

| Step | Action | Acceptance | Evidence |
| ---: | --- | --- | --- |
| **1** | Reproduce assigned tests on `ac3a3d03` | 3 failures recorded | 0/3 FAIL @ 4228 ms |
| **2** | Diagnose row **11** | Root cause identified | `IncidentWaitingStateService::start()` refuses closed incidents → no active waiting row → `skipped: 0` (**TEST_SETUP**) |
| **3** | Fix row **11** | Closed + active waiting legacy scenario | Test: open case → waiting → close without clearing waiting |
| **4** | Diagnose row **12** | Root cause identified | Legacy close used `updateStatus` with default broadcast + statutory mint → **1** `outbox_events` (**APP**) |
| **5** | Fix row **12** | No customer outbound side effects | `broadcast: false`, `issueStatutoryInvoice: false` on legacy cleanup close |
| **6** | Diagnose row **184** | Root cause identified | `reassign()` to same user still called `applyAssignment` when `assignment_origin` differed (**APP**) |
| **7** | Fix row **184** | No audit/notification on no-op | Early return in `ServiceCaseAssignmentService::reassign()` when assignee unchanged |
| **8** | Focused validation | Assigned **3/3** + classes **17/17** | See below |

---

## Findings

| Row | Classification | Label |
| ---: | --- | --- |
| **11** | Test-harness defect | **VERIFIED** |
| **12** | Application defect | **VERIFIED** |
| **184** | Application defect | **VERIFIED** |

---

## Behaviour

| | |
| --- | --- |
| Preserved | Repair command skip rules, legacy cleanup audit/timeline, normal close statutory mint |
| Extended | `updateStatus(..., issueStatutoryInvoice:)` for migration-only closes |
| Changed | Legacy cleanup close suppression; manual no-op reassign guard; repair test fixture |
| Removed | Nothing |

---

## Focused validation

**Assigned (post-fix):**

```bash
cd .worktrees/p13-21-customer-waiting
/opt/homebrew/bin/php -n /tmp/composer.phar dump-autoload -o
DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated \
  --filter 'test_closed_incident_is_skipped|test_cleanup_writes_timeline_entry_without_customer_notifications|test_no_op_reassignment_does_not_create_audit_or_notification' \
  tests/Feature/Automation/CustomerWaitingAppointmentRepairCommandTest.php \
  tests/Feature/Automation/CustomerWaitingLegacyCleanupTest.php \
  tests/Feature/WaitingStateOwnershipTest.php
```

| Result | Tests | Assertions | Duration | Exit |
| --- | ---: | ---: | ---: | ---: |
| **PASS** | 3/3 | 13 | 4054 ms | 0 |

**Three classes (queue “focused regression”):**

| Suite | Result | Tests | Duration |
| --- | --- | ---: | ---: |
| `CustomerWaitingAppointmentRepairCommandTest` | **PASS** | 5/5 | (in batch) |
| `CustomerWaitingLegacyCleanupTest` | **PASS** | 8/8 | (in batch) |
| `WaitingStateOwnershipTest` | **PASS** | 4/4 | (in batch) |
| **Combined** | **PASS** | **17/17** | **16172 ms** |

**Neighbour:** `CustomerWaitingLifecycleTest` — **PASS** (full class, json runner).

Full PHPUnit suite: **NOT RUN**

---

## Git / PR / CI

*(pending merge footer)*

Production / deployment: **NO — Not performed**

Wallet @ **`d9481615`**: **VERIFIED** unchanged.

---

*End of P-13-21 report (pre-merge footer).*
