# P15-08-042 — Incremental extract after 46fb82

Extract-only. Not transported. Not applied. Generations `46fb82` and `90fe9d` untouched.

## Generation

| | |
|---|---|
| ID | `tier1-rehearsal-20260815-154314-19cd9f` |
| Snapshot | `2026-08-15T21:13:15+05:30` |
| Manifest SHA-256 | `d1653e63412b1848fff0ae2e145e4cfdb24361f15da874f6228cf8a41d57dfa3` |
| Tables selected | 32 (current Tier 1) |
| Chunks | **11/11**, 0 missing, 0 mismatch |
| Hostinger path | `storage/app/private/db-sync/inbox/tier1-rehearsal-20260815-154314-19cd9f/` |

## Preflight

VPS dark=`true`. `sc=39854` before extract (unchanged after). Applicable delta tables checkpointed as `46fb82`. Leftover `90fe9d` only on zero-delta reference tables. No third generation. Hostinger SSH OK.

## Non-empty chunks

| Table | Rows | Chunks | id_min–id_max |
|---|---|---|---|
| orders | 72 | 1 | 37274–38830 (new IDs + in-watermark updates) |
| incidents | 50 | 1 | 39777–39839 |
| cashfree_webhook_logs | 31 | 1 | 47127–47157 |
| finance_journals | 25 | 1 | 12268–12292 |
| finance_journal_lines | 50 | 1 | 24535–24584 |
| bonvoice_webhook_logs | 3 | 1 | 25464–25466 |
| bonvoice_call_events | 3 | 1 | 24975–24977 |
| users | 3 | 1 | 1–10 (in-watermark updates) |
| reference_sequences | 1 | 1 | `sc` |
| permissions | 68 | 1 | 1–70 (full_replace) |
| roles | 9 | 1 | 1–9 (full_replace) |

`incident_bonvoice_call_links` and other Tier-1 tables: **0 rows** (no post-checkpoint delta).

`reference_sequences.sc` in extract: **current_value=39879**, `updated_at=2026-08-15 21:09:06`. Apply later uses GREATEST (do not SET manually). VPS still 39854.

## FK closure

Required parents present in this generation (non-empty or already on VPS with 0-delta):

- orders before incidents; cashfree after both
- webhook logs before events; links 0 extra rows
- journals before journal lines

No required parent missing from the 32-table closure.

## Untouched

- VPS inbox still only `90fe9d` + `46fb82`
- SHAs `db80fcc1…580b518` and `ffd2152e…eb5580`
- VPS still dark; `sc` still 39854
