# P-13-42 — Row 189 workforce event catalog (LeaveCancelled)

| Global Prompt | **P-13-42** |
| Cursor ledger | **RadiumDesk-P-13-42** |
| Baseline | **`0e2e2261`** @ `origin/main` (post P-13-41) |

---

## Root cause

**Stale test expectation (TEST)** — not an unintended dispatch regression.

| Item | Evidence |
| --- | --- |
| Failure | `assertTrue(LeaveCancelled->isReserved())` — enum returns **false** (only `HalfDayRecorded`, `PerformanceCalculated`, `SalesCredited`, `IncentiveAwarded` are reserved) |
| Producer | `LeaveRequestAmendmentService::applyApprovedAmendment()` publishes **`WorkforceEventType::LeaveCancelled`** on approved cancellation amendments |
| Enum docblock | Lists active producers including leave lifecycle; cancel is implemented |

**Conclusion:** `LeaveCancelled` is a live event type; catalog `isReserved()` correctly marks it **not** reserved. Matrix row 189 test predates cancel publishing.

---

## Change

- `WorkforceEventIsolationTest::test_reserved_event_types_exist_but_are_not_produced_yet` — `LeaveCancelled` **reserved** → **not reserved** (`assertFalse`).

No application / enum changes.

---

## Event flow (LeaveCancelled)

```
Leave cancellation amendment approved
  → LeaveRequestAmendmentService::applyApprovedAmendment (Cancellation)
  → WorkforceAuditEvent::LeaveCancelled (audit)
  → publishLeaveEvent(WorkforceEventType::LeaveCancelled)
  → SafeWorkforceEventPublisher (fail-safe wrapper)
```

---

## PHPUnit

| Scope | Result | Assertions | ms |
| --- | --- | ---: | ---: |
| Row 189 filter | **1/1 PASS** | 12 | 2326 |
| `WorkforceEventIsolationTest` class | **6/6 PASS** | 37 | 6865 |
| `WorkRecognitionTest::test_recognition_events_are_not_reserved` | **1/1 PASS** | 5 | 2300 |
| `PayrollMonthLockTest::test_payroll_locked_event_is_not_reserved` | **PASS** | — | — |
| `ContributionEngineTest` class | **PASS** | — | — |
| `ExtraQualificationEngineTest` class | **PASS** | — | — |

Full suite: **NOT RUN**.

---

## Risks

- Register row **189** unchanged until register-reconcile prompt.
- Future reserved types must update **both** `WorkforceEventType::isReserved()` and this catalog test.

---

*End of P-13-42 report.*
