# P15-08-035 — Bonvoice FK blocker (inspect only)

Canvas: [`p15-08-035-bonvoice-fk-blocker.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p15-08-035-bonvoice-fk-blocker.canvas.tsx)

No apply, extract, transport, deploy, config/code change, or DB write.

**Generation:** `tier1-rehearsal-20260815-085333-90fe9d`  
**Stop:** `incident_bonvoice_call_links` chunk 7, row `id=12402`, `bonvoice_call_event_id=24695`

## 1. Why `bonvoice_call_events` is Tier 2

In `config/database-sync.php` it sits under **“Tier 2: integrations and messaging (order 80)”** with `bonvoice_webhook_logs`, `bonvoice_call_alerts`, Interakt, and outbox. That bucket is operational/history ingest, not the orders/incidents spine.

`incident_bonvoice_call_links` is **Tier 1 / sync_order 40** with `depends_on: ['incidents']` only. The physical FK to `bonvoice_call_events` is not in metadata. Kahn order cannot pull a Tier 2 table into a `--tables=` Tier 1 apply, and this generation’s extract does not contain events.

## 2–4. Missing parents in this generation

| Source | `incident_bonvoice_call_links` | `bonvoice_call_events` | `bonvoice_webhook_logs` |
|---|---|---|---|
| Generation extract | 12,494 rows, 7 chunks, ids 1–12497 | **not present** | **not present** |
| VPS | count 12,398, max_id 12401, checkpoint last_id **12003** seq **6** | count 24,688, max_id **24692** | count 25,181, max_id **25181** |
| Hostinger | count 12,524, max_id 12527 | count 24,965, max_id 24969 | count 25,458, max_id 25458 |

From the sealed inbox ndjson:

- 12,494 unique `bonvoice_call_event_id` values (no nulls).
- **12,398 present on VPS, 96 missing.**
- All 96 missing refs are in **chunk 7**. Chunks 1–6: 0 missing.
- Chunk 7 = 494 rows (ids 12004–12497): 398 have VPS parents; **96 do not** (ids 12402–12497).
- Missing event ids: **24695–24892** (not contiguous). First fail matches production: link 12402 → event 24695 → incident 38952 (incident exists on VPS).
- Hostinger has event 24695 (`webhook_log_id=25184`, same `call_id` as the failed INSERT). Hostinger has **all 96** parents. Range 24695–24892 contains **198** Hostinger events; this extract references 96 of them.
- Hostinger: **every** call event has `webhook_log_id` (0 null). Logs for the 198-event range: **25184–25381**. VPS logs stop at **25181**, so those logs are also absent.

## 5. Other Tier-1 dependents

Migrations that FK to `bonvoice_call_events`:

| Child | Tier | FK |
|---|---|---|
| `incident_bonvoice_call_links` | 1 / 40 | `bonvoice_call_event_id` CASCADE |
| `bonvoice_call_alerts` | 2 / 80 | `bonvoice_call_event_id` CASCADE |

Only the links table is Tier 1. Alerts are not in this generation.

Events themselves FK to `bonvoice_webhook_logs` (`webhook_log_id` nullable, `nullOnDelete`). On live Hostinger that column is always populated, so it is a real apply-time FK.

## 6. Safest dependency/order change (not applied)

Keep FK checks enabled. Do not disable gates.

1. Promote **`bonvoice_webhook_logs`** to Tier 1, `sync_order` 35 (or 40).
2. Promote **`bonvoice_call_events`** to Tier 1, `sync_order` 35, `depends_on: ['bonvoice_webhook_logs']`.
3. Set **`incident_bonvoice_call_links.depends_on`** to `['incidents', 'bonvoice_call_events']`.

Required order: **webhook logs → call events → incident links**.

Do not add `depends_on` events while events stay `sync_order` 80: `DatabaseSyncManifest::validateDependencies()` rejects a dependency with a **higher** `sync_order`.

Promoting events **without** webhook logs would stop on the next 1452 (`webhook_log_id=25184`).

## 7–8. Add to this generation? Extend vs new extract?

**Do not add `bonvoice_call_events` into generation 90fe9d.**

- Extract lists 30 tables / 108 chunks. Events, webhook logs, and alerts are absent.
- Manifest SHA is pinned: `db80fcc1f65dd10726547f31b88427580c3b4f3fd6fa3904fca1ad008580b518`.
- `DeltaApplyRunner` **skips** tables missing from `extract.json`. A config-only promote plus `skipExtract=true` would skip events and fail again on chunk 7.
- Mutating the inbox/manifest would break the checksum seal and mix a later Hostinger snapshot into a frozen generation.

**A new generation/extract is required** for `bonvoice_webhook_logs` and `bonvoice_call_events` (and then remaining links / leftover Tier 1). Do not resume 90fe9d until those parents exist on VPS.

## Checkpoint note (no repair)

Chunk apply is transactional (`TableDeltaApplier::applyChunk` commit-after-all-rows). Failing row 12402 was not inserted. VPS already has link ids through **12401** (clone/pre-existing), which is why count is 12,398 while LCDS checkpoint remains **12003**. Those rows are not a reason to delete or hand-repair.

## Recommended next action

1. Stop. Do not resume apply.
2. Change config as in §6 (separate authorized change + VPS file deploy).
3. **New extract** of at least `bonvoice_webhook_logs` + `bonvoice_call_events`.
4. Apply that new generation on VPS (dark, FK on), then resume remaining `incident_bonvoice_call_links` / leftover Tier 1 — either via a new extract of remaining tables or a later skipExtract of 90fe9d **after** parents exist.
5. Hostinger remains source. No DNS/service changes.

Inspected 2026-08-15. Hostinger SSH was read-only (`SELECT` / Laravel bootstrap). Inbox files were read, not rewritten.
