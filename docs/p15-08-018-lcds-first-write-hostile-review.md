# P15-08-018 — Hostile review before first LCDS VPS write

Canvas: [`/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p15-08-018-lcds-first-write-review.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p15-08-018-lcds-first-write-review.canvas.tsx)

Review date: 2026-08-15. Code-only. No apply, deploy, or production writes.

**Generation:** `tier1-rehearsal-20260815-085333-90fe9d`  
**Snapshot:** `2026-08-15T14:23:35+05:30`  
**Manifest SHA-256:** `db80fcc1f65dd10726547f31b88427580c3b4f3fd6fa3904fca1ad008580b518`

## Verdict

**SAFE FOR CONTROLLED FIRST WRITE**

Empty VPS checkpoints plus this verified inbox generation are a valid first baseline. Apply is PK-upsert / GREATEST / transactional full_replace with checksum-before-write and checkpoint-after-commit. Live Hostinger having moved past the snapshot is expected; the next extract uses VPS watermarks (`id > last_id OR updated_at > watermark`) and will pick up the remaining delta.

## Do not use the default Artisan apply

`php artisan db:sync-delta --apply` always extracts and transports (`skipExtract` defaults false). Using `--generation-id=tier1-rehearsal-20260815-085333-90fe9d` would **overwrite** the verified Hostinger and VPS inbox.

The first write must call `DatabaseSyncApplyService::run(..., skipExtract: true)` from the laptop so orchestrator gates still run and the VPS inbox is consumed as-is.

## Question answers

1. **Empty checkpoints + this generation** — Yes. Extract used VPS-empty checkpoints (epoch). Apply upserts by PK onto the existing darker VPS copy.
2. **Older snapshot as baseline** — Yes. VPS is behind the snapshot. PK upsert brings VPS to snapshot contents. Later extracts catch live Hostinger.
3. **Checkpoint rewind / skip** — Watermarks merge monotonically (`greatestNumericId`, `mergeWatermark`, PK tuple compare). Mutable resume is `id > last_id OR updated_at > watermark OR (updated_at = watermark AND id > last_id_at_watermark)`. Apply does not skip chunks on retry; it re-upserts (idempotent).
4. **Failed chunk + durable writes + checkpoint** — No. Writes are in a transaction; checkpoint is after `commit()`. Failed chunk rolls back; checkpoint unchanged.
5. **Silent secondary-unique ODKU** — No. `pkUpsert()` is SELECT-by-PK then INSERT or UPDATE-by-PK. Laravel `insert()` is not ODKU. Duplicate physical unique throws and rolls back.
6. **UniqueConflictChecker before write** — Yes, per row, before `pkUpsert`. NULL keys skipped. Different PK + same business key throws `UniqueConflictException`.
7. **full_replace + FK** — Yes. One transaction, `SET foreign_key_checks = 1`, delete stale PKs then PK upsert. Failure rolls back both. `permissions` / `roles` run at sync_order 10 before dependents.
8. **Composite PK checkpoints** — `last_pk` stored from last ordered row; extract orders by full PK; resume is tuple `>`. First apply with `last_pk=null` extracts all.
9. **reference_sequences** — GREATEST `current_value` / `updated_at` on PK `name`. Cannot decrease VPS.
10. **Soft deletes** — Query builder extract (no Eloquent scopes); `deleted_at` in NDJSON; upsert copies all non-PK columns.
11. **Manifest/checksums before writes** — Per-chunk SHA-256 and row-count check before each transaction. No whole-manifest SHA re-check at apply start (already verified in P15-08-014/017).
12. **Host safety** — `TargetHostGuard` requires `hostinger_to_vps`, target name `vps`, `base_path() === /var/www/radium-desk`, and refuses source path/host. Cannot disable in production.
13. **VPS dark for this prep** — Process list dark + empty cron + DNS on Cloudflare (P15-08-012). Process list does not prove DNS/cron; operator still confirms `--vps-is-dark` equivalent via `VpsDarkGate` in the orchestrator.
14. **Gate 3** — Runs after apply. Throws if probes fail or dry-run has blockers. Count/sequence drift vs *live* source sets `passed=false` but does **not** throw (intentional for prep snapshots).
15. **Silent loss / wrong PK / checkpoint ahead of data / orphan children / bad resume** — No code path found for silent loss or silent unique-to-other-PK. Partial apply leaves committed prefix; retry re-upserts from table start. FK checks + sync_order prevent applying children before parents in this generation. Wrong resume would require a *rewound* checkpoint, which merge forbids.

## Findings

| ID | Severity | Finding |
|----|----------|---------|
| F1 | HIGH | Artisan `--apply` re-extracts/re-transports and would destroy this generation if the same ID is reused. |
| F2 | MEDIUM | Gate 3 does not fail the command when live source is ahead of the applied snapshot (`passed` can be false). |
| F3 | MEDIUM | Checkpoint file write after commit can fail; data is durable, checkpoint unchanged; retry is idempotent. |
| F4 | LOW | Apply does not preflight all 108 checksums before table 1; verifies per chunk. Inbox already verified. |
| F5 | LOW | P15-08-016 config not deployed to VPS. VPS `UniqueConflictChecker` still uses old `unique_indexes`. Fail-closed (skip missing `name`; `alias` extra check; `INSERT` still hits `normalized_alias` UNIQUE). |
| F6 | LOW | `last_chunk_seq` / `chunk_bounds` overwritten with the latest chunk, not used for resume. |
| F7 | ACCEPTABLE | VPS `:80/:443` listen (Caddy 404). Production DNS is Cloudflare, not VPS. |
| F8 | ACCEPTABLE | UniqueConflictChecker TOCTOU vs INSERT. Safe while VPS has no concurrent writers. |

No BLOCKER.

## Exact next command (not run)

From the laptop repo, after confirming DNS still does not point at `148.113.8.82`:

```bash
cd /Users/ravi/radium-service-desk && php -r "
require 'vendor/autoload.php';
\$app = require 'bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\$report = \$app->make(App\Infrastructure\DatabaseSync\DatabaseSyncApplyService::class)
    ->run(null, 1, 'tier1-rehearsal-20260815-085333-90fe9d', true);
echo json_encode(\$report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
"
```

Fourth argument `true` is `skipExtract`. Do **not** run `php artisan db:sync-delta --apply` for this generation.
