# P15-08-050 — Final incremental extract (no transport/apply)

| | |
|---|---|
| ID | `tier1-rehearsal-20260815-163004-a49da7` |
| Snapshot | `2026-08-15T22:00:05+05:30` |
| Manifest SHA-256 | `d2508848001acdc9e1002e3c24ef1356cc39c65455551499616f5611258f80c9` |
| Chunks | **9/9**, 0 missing, 0 mismatch, 160 rows |
| Hostinger path | `storage/app/private/db-sync/inbox/tier1-rehearsal-20260815-163004-a49da7/` |

Extract-only from VPS checkpoints (`CheckpointAuthority::pullFromTarget` + `TableDeltaExtractor`, tier 1). Not transported. Not applied. Previous generations untouched.

## Preflight

VPS dark=`true`. `sc=39883` (unchanged after extract). Applicable delta tables checkpointed as `8a90b6` (orders 38834, incidents 39843, cashfree 47161, journals 12296, lines 24592, users 17). Leftover `19cd9f` / `46fb82` / `90fe9d` only on zero-delta tables. No unexpected generation. Hostinger SSH 3/3 OK. Checkpoint fingerprint unchanged after extract.

## Non-empty chunks

| Table | Rows | id_min–id_max | Notes |
|---|---|---|---|
| orders | 24 | 37416–38844 | Expected 38835–38842 **plus** live 38843–38844 **plus** in-watermark updates (incl. 38833–38834) |
| incidents | 12 | 39842–39853 | Expected 39844–39851 **plus** 39852–39853; 39842–39843 in-watermark |
| cashfree_webhook_logs | 13 | 47162–47174 | Expected 47162–47172 **plus** 47173–47174 |
| finance_journals | 10 | 12297–12306 | Expected 12297–12304 **plus** 12305–12306 |
| finance_journal_lines | 20 | 24593–24612 | Expected 24593–24608 **plus** 24609–24612 |
| users | 3 | 1–12 | ids **1, 3, 12** |
| reference_sequences | 1 | `sc` | `current_value=39893`, `updated_at=2026-08-15 22:00:02` |
| permissions | 68 | 1–70 | full_replace |
| roles | 9 | 1–9 | full_replace |

Other Tier-1 tables: **0 rows** (including bonvoice logs/events/links).

`sc` +10 vs VPS 39883 matches 10 new incidents (39844–39853). Apply later uses GREATEST. Do not SET `sc` by hand.

## FK closure

- Every extracted incident `order_id` is in this orders chunk (38833–38844).
- Every cashfree `incident_id` is in this incidents chunk (39843–39853); 47163 → 39843 also already on VPS.
- Every journal line `journal_id` is in this journals chunk (12297–12306); `account_id` 2/4 already on VPS.
- Users 1, 3, 12 cover assignees. Apply order: orders → incidents → cashfree; journals → lines.

## Untouched

- VPS inbox still only `90fe9d` / `46fb82` / `19cd9f` / `8a90b6` (no `a49da7`)
- Prior SHAs unchanged on Hostinger
- VPS checkpoints 27 files unchanged; dark=`true`; `sc` still 39883

Do **not** transport or apply in this step.
