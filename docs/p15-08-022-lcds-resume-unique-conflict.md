# P15-08-022 — Resume apply stopped on unique conflict

Generation: `tier1-rehearsal-20260815-085333-90fe9d`  
Path: `DatabaseSyncApplyService::run(null, 1, generation, skipExtract=true)`  
No `db:sync-delta --apply`, no extract, no transport, no deskd.

## Preflight (passed)

- VPS dark: `dark=true`, `active=[]`
- TargetHostGuard on VPS: pass
- Manifest SHA-256 `db80fcc1…580b518`, 108/108 chunk checksums match
- VPS checkpoints: 14 tables, this generation only
- Apply lock free (laptop and VPS)

## Result: STOPPED

Remote apply threw `UniqueConflictException` on **orders** before any orders checkpoint.

Conflict artifact (preserved):  
`/var/www/radium-desk/storage/app/private/db-sync/conflicts/tier1-rehearsal-20260815-085333-90fe9d.json`

| Field | Value |
|-------|--------|
| unique_index | serial_number |
| key | FPSPL1141XX |
| source_pk | 1520 |
| target_pk | 1458 |

Failed chunk rolled back. No `orders.json` checkpoint.

## Tables

The 14 previously checkpointed tables were re-upserted (idempotent). `applied_at` is now `2026-08-15T17:55:12+05:30`. Same generation. Same checksums.

| Table | Completed this run |
|-------|-------------------|
| orders | **no** |
| cashfree_webhook_logs | **no** |
| finance_journals / finance_journal_lines | **no** |
| Gate 3 | **not run** (apply threw before verifyAfterApply) |

Recon still `orders_count=37743`, `cashfree_webhook_logs_count=46048`. Inbox unchanged (108 chunks). Locks free. Hostinger: read-only probes only.

No recovery attempted.
