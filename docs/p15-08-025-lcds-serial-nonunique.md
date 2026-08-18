# P15-08-025 — VPS gap analysis and serial_number migration compatibility

Read-only probes plus one local config change. No apply, extract, transport, deploy, or production data changes.

Generation `tier1-rehearsal-20260815-085333-90fe9d` remains intact (manifest SHA `db80fcc1…580b518`, 108/108 chunks). 14 checkpoints still this generation. No `orders` checkpoint. Conflict JSON preserved.

## Minimum change

`config/database-sync.php` `orders.business_unique_keys`: drop `serial_number`. Keep `order_id` and `cashfree_payment_id`. No UniqueConflictChecker change. No DB UNIQUE index.

**Resume cannot use this until the VPS apply path sees the same config.** VPS still has legacy `unique_indexes` including `serial_number` and old `SyncTableDefinition` (no `physicalUniqueIndexes`). `remote_apply.php` uses VPS config. Do not deploy in this step.

## Tests

`php artisan test tests/Unit/DatabaseSync tests/Feature/DatabaseSync` — 65 passed, 152 assertions.
