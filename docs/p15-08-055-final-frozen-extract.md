# P15-08-055 — Final extract from frozen Hostinger (no transport/apply)

| | |
|---|---|
| ID | `tier1-rehearsal-20260815-165641-e6b1cc` |
| Snapshot | `2026-08-15T22:26:42+05:30` |
| Manifest SHA-256 | `5899c19ae78ec3f6902f16cc628e67e1fbb568b0a233d4814496a4387a266397` |
| Chunks | **9/9**, 0 missing, 0 mismatch, **131** rows |
| Hostinger path | `storage/app/private/db-sync/inbox/tier1-rehearsal-20260815-165641-e6b1cc/` |

Extract-only from VPS `a49da7` checkpoints while Hostinger remained frozen (P15-08-054). Not transported. Not applied. Hostinger not unfrozen.

## Frozen source watermarks (unchanged through extract)

| Table | count | max(id) | max(updated_at) |
|---|---|---|---|
| orders | 38637 | 38849 | 2026-08-15 22:20:18 |
| incidents | 39744 | 39858 | 2026-08-15 22:13:17 |
| cashfree_webhook_logs | 47179 | 47179 | 2026-08-15 22:13:17 |
| finance_journals | 12311 | 12311 | 2026-08-15 22:13:25 |
| finance_journal_lines | 24622 | 24622 | 2026-08-15 22:13:25 |
| `sc` | — | **39898** | 2026-08-15 22:13:17 |

desk/Cashfree still 503. Cron stubs still firing. VPS dark, `sc` still 39893, checkpoints unchanged, `e6b1cc` not on VPS inbox.

## Per-table delta vs a49da7

| Table | Rows | New IDs | In-watermark |
|---|---|---|---|
| orders | 24 | **38845–38849** | 19 ids (≤38844) |
| incidents | 7 | **39854–39858** | 39846, 39852 |
| cashfree_webhook_logs | 5 | **47175–47179** | none |
| finance_journals | 5 | **12307–12311** | none |
| finance_journal_lines | 10 | **24613–24622** | none |
| users | 2 | none | ids **1, 3** |
| reference_sequences | 1 | `sc=39898` | GREATEST later |
| permissions / roles | 68 / 9 | full_replace | |

Other Tier-1 tables: 0 rows.

## FK closure

- incidents 39854–39858 → orders 38845–38849 (all in this extract); 39846→38837, 39852→38843 (in-watermark orders in extract)
- cashfree 47175–47179 → incidents 39854–39858 (all in this extract)
- journal lines 24613–24622 → journals 12307–12311; `account_id` 2/4 already on VPS

`sc` +5 vs VPS 39893 matches 5 new incidents.

This is the **final cutover payload** unless verification later fails. Do not extract again unless that happens.
