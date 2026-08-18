# P15-08-019 — LCDS checkpoint contamination after aborted first write

Canvas: `/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p15-08-019-lcds-checkpoint-contamination.canvas.tsx`

Investigation only. No files deleted, no apply, no deploy.

## Verdict

Local `orders.json` / `reference_sequences.json` with `gen-del` / `gen-seq` are **PHPUnit runtime artifacts**, not production apply output. They were written at **14:44:22 IST** by `ReferenceSequenceMergeTest`, which constructs `TableCheckpointStore` without isolating `database-sync.table_checkpoint_directory`.

Separately, the aborted first-write **did apply on VPS** for 14 early tables of generation `tier1-rehearsal-20260815-085333-90fe9d` at **15:01:47 IST**. `orders` and `cashfree_webhook_logs` were **not** applied. Count equality on those two tables does not prove the apply never ran.

## Classification of local files

| File | `last_generation_id` | Class |
|------|----------------------|--------|
| `storage/app/private/db-sync/checkpoints/orders.json` | `gen-del` | **B** — test runtime artifact in the real checkpoint directory |
| `storage/app/private/db-sync/checkpoints/reference_sequences.json` | `gen-seq` | **B** — same |
| VPS `*.json` for 14 tables | `tier1-rehearsal-20260815-085333-90fe9d` | **C** — real apply artifacts on the target |

Not A (committed fixtures). Not laptop production apply.

## Where `gen-del` and `gen-seq` are generated

Only in `tests/Unit/DatabaseSync/ReferenceSequenceMergeTest.php`:

- `test_reference_sequences_uses_greatest_merge()` → `applyChunk(..., 'gen-seq')`
- `test_deleted_at_is_copied()` → `applyChunk(..., 'gen-del')` with order `id=5`, `updated_at`/`deleted_at` `2026-08-15 12:30:00` — matches local `orders.json` exactly

`gen-fk` is used in `test_foreign_key_checks_remain_enabled()`, which expects an exception before checkpoint write; no `incidents.json` on disk.

Repo search: `gen-del` / `gen-seq` exist only in that test file. `last_generation_id` / `recordSuccessfulChunk` / `applied_at` live in `TableCheckpointStore`.

## Why the test did not isolate storage

`new TableCheckpointStore` with no `config(['database-sync.table_checkpoint_directory' => …])`. Default is `storage_path('app/private/db-sync/checkpoints')`. Apply uses sqlite `:memory:` then `recordSuccessfulChunk` writes the **laptop filesystem**.

Isolated correctly: `CheckpointAdvancementTest`, most of `Phase2SafetyFixTest`.  
`Phase2SafetyFixTest` PK-only unique-conflict test also uses the default store, but expects an exception so it should not write a checkpoint.

## Timeline

| Time (IST) | Event |
|------------|--------|
| 14:44:22 | Local `orders.json` / `reference_sequences.json` written (`applied_at`) |
| ~14:47 | PHPUnit run: 63 passed, 148 assertions (P15-08-016) |
| 14:58:02 | Local `state.json` dry-run (`last_dry_run_at`) |
| 15:01:47 | VPS table checkpoints written for generation `tier1-rehearsal-20260815-085333-90fe9d` |

Local contamination **predates** the first-write attempt.

## Production DB

- Hostinger: not a target of apply.
- Test DB: sqlite `:memory:` only.
- VPS: **14 tables were upserted** (checkpoint after commit). `orders` / `cashfree_webhook_logs` counts still 37743 / 46048 — apply stopped before those tables. Unchanged counts on already-populated PK tables do not mean zero writes.

VPS tables with checkpoints (all `applied_at` 15:01:47+05:30): `device_model_aliases`, `device_models`, `finance_accounts`, `finance_cash_accounts`, `finance_expense_categories`, `finance_payment_methods`, `finance_settings`, `model_has_permissions`, `model_has_roles`, `permissions`, `reference_sequences`, `role_has_permissions`, `roles`, `users`. No `orders.json` on VPS.

## Can local checkpoints affect the next apply?

No. `DatabaseSyncApplyService` uses `CheckpointAuthority::pullFromTarget()` (SSH VPS `export-checkpoints`). `skipExtract=true` skips extract/transport; apply still runs `remote_apply.php` on VPS against VPS inbox and **VPS** `TableCheckpointStore`. Laptop `storage/app/private/db-sync/checkpoints/` is not on that path.

A code path **can** write local checkpoints without touching VPS: PHPUnit `TableDeltaApplier` + default `TableCheckpointStore`. That is what happened at 14:44.

## Generation safety

Inbox generation `tier1-rehearsal-20260815-085333-90fe9d` remains the prepared payload. It is **partially applied** on VPS. Resume must use `skipExtract=true` for that generation id so extract does not rewrite inbox. Re-applying already-applied tables is PK-upsert idempotent. Do **not** treat local `gen-del`/`gen-seq` files as VPS authority.

## Smallest required fix (not applied)

In `ReferenceSequenceMergeTest`, set `database-sync.table_checkpoint_directory` to a unique temp dir (same pattern as `CheckpointAdvancementTest`) and delete it in `tearDown`. Optionally fail Phase 2 tests if the store directory equals the default production path.

Do not treat deleting the two local JSON files as production recovery; they are ignored test junk.

## Git status

Checkpoint JSON files are **ignored** (`storage/app/private/db-sync/.gitignore` `*`). Not tracked.

Working tree (unrelated to this contamination): modified P15-08-016 index-parity files; untracked `docs/p15-08-018-lcds-first-write-hostile-review.md`, `tests/Unit/DatabaseSync/SchemaIndexParityGateTest.php`.
