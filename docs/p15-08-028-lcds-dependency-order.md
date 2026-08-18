# P15-08-028 — Dependency-aware LCDS apply order

No apply, extract, transport, deploy, or DB writes.

## Inspection

`tablesInSyncOrder()` sorted by `sync_order` then **name**. Equal-order 40 therefore applied `cashfree_webhook_logs` before `incidents`.

`cashfree_webhook_logs.depends_on` was only `['orders']`. The `incident_id` FK is not in that list, so topology alone would not have fixed P15-08-027. Metadata was updated to `['orders', 'incidents']`.

Other same-order risks already had `depends_on` (e.g. `finance_journal_lines` vs `finance_journals`, `customer_data_correction_items` vs parent, `commercial_service_restorations` vs `refund_requests`, `incident_bonvoice_call_links` vs `incidents`). Alphabetical order would have applied those dependents first; topology now honors the edges.

## Fix

Kahn topological order in `DatabaseSyncManifest`: ready set is `(sync_order, name)`. Cycles and unknown tables fail closed. FK checks and checkpoints unchanged.

## Tests

`php artisan test tests/Unit/DatabaseSync tests/Feature/DatabaseSync` — **68 passed, 160 assertions**.
