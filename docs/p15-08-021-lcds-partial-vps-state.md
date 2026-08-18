# P15-08-021 — Partial LCDS VPS state (read-only)

Canvas: `/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p15-08-021-lcds-partial-vps-state.canvas.tsx`

Generation: `tier1-rehearsal-20260815-085333-90fe9d`  
Inspected: 2026-08-15, VPS `remote_inspect.php` export-checkpoints + table-stats. No apply, extract, transport, or file changes.

## Inbox (read-only)

Path: `/var/www/radium-desk/storage/app/private/db-sync/inbox/tier1-rehearsal-20260815-085333-90fe9d/`

| Check | Result |
|-------|--------|
| Extract manifest | `tier1-rehearsal-20260815-085333-90fe9d.extract.json` present |
| generation_id | matches |
| direction | `hostinger_to_vps` |
| snapshot_at | `2026-08-15T14:23:35+05:30` |
| tables | 30 |
| chunk entries | 108 |
| `*.ndjson.gz` on disk | 108, all present (0 missing) |
| `vps-checkpoints.json` in inbox | extract-time snapshot `{"authority":"vps","checkpoints":[]}` — not the live store |

## Live VPS checkpoints

14 files. Every `last_generation_id` is `tier1-rehearsal-20260815-085333-90fe9d`. Every `applied_at` is `2026-08-15T15:01:47+05:30`. No later `applied_at`. No other generation.

No files for `orders`, `cashfree_webhook_logs`, `finance_journals`, `finance_journal_lines`.

`reference_sequences`: present, string PK, `last_id` 0, `last_updated_at` `2026-08-15 14:23:30`, `last_chunk_seq` 1, bounds `sc`/`sc`. VPS count 1 (matches extract).

`finance_bank_accounts`: no checkpoint, extract chunks 0, VPS count 0 — expected skip.

## Next table

`orders` (sync_order 30). All order-10/20 tables with chunks are checkpointed. Empty order-10 `finance_bank_accounts` has nothing to apply.

## Resume (`skipExtract=true`)

Inbox intact; do not re-extract (would overwrite). `DeltaApplyRunner` re-walks all tables (does not skip checkpointed ones). Re-apply of the 14 is PK upsert / full_replace. First new work is `orders` (20 chunks, extract 38318 rows vs VPS 37743).

Resume is **safe from these observations**. Do not wipe generation or VPS checkpoints.

## Confirmations

- `orders`: no VPS checkpoint
- `cashfree_webhook_logs`: no VPS checkpoint
- `finance_journals` / `finance_journal_lines`: no checkpoints (still pre-apply VPS data: 11418 / 22836)
- `reference_sequences`: checkpointed this generation at 15:01:47
- no foreign generation ids
- no `applied_at` after the partial apply
