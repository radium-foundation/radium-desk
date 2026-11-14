# P-14-36 — Hardware workspace: support serial without fulfilment

**Global ID:** P-14-36  
**Cursor ledger:** assign **`RadiumDesk-P-14-36`** on append.

## Root cause (VERIFIED)

Hardware dashboard SQL (`workCandidateOrderQuery` / legacy awaiting queue) treated a populated **support-order `serial_number`** as “Desk already completed,” excluding paid **RBP\*** / **RDE\*** rows that had **no `hardware_fulfilments` record**. Service workflows can record the serial before fulfilment opens (RBP2138 / SC74110 pattern).

## Fix

- Classifier: support serial + paid hardware commerce → **`FulfilmentNotOpened`** (not `DeskAlreadyCompleted`).
- SQL: **`unfulfilledHardwareWorkCandidateOrderQuery`** includes serial-gap rows when ingested hardware commerce exists; transaction id still gates true completion.
- Diagnostics: **`HardwareFulfilmentGapDiagnostics`** count/sample for monitoring.

## Production backfill (NOT in this task)

Owner-approved plan for **RBP2138**:

| Step | Target | Evidence |
|------|--------|----------|
| 1 | Open HF from awaiting dialog for support order **73007** / commerce **CO-022155** (id **22155**) | Prod read-only verified 2026-11-14 |
| 2 | Idempotency: `hardware_fulfilments.idempotency_key` / `source_id=RBP2138` | No duplicate HF rows |
| 3 | Rollback | Delete HF row only if created in error; no Box mutation |

**Database changes: NO — Not performed.**