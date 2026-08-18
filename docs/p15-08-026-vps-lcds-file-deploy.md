# P15-08-026 — VPS file-only LCDS policy deploy

VPS only. No apply, extract, transport, deskd, or DB writes.

## Pre

- Tests: 65 passed, 152 assertions
- Deployed: `config/database-sync.php` + `app/Infrastructure/DatabaseSync/` (rsync -avz, no --delete)
- Hostinger: not touched

## Transferred (content differed)

- `config/database-sync.php`
- `SyncTableDefinition.php`
- `SchemaIndexParityGate.php`
- `UniqueConflictChecker.php`
- `DatabaseSyncDryRunService.php`

Remaining 30 PHP files already matched laptop SHA-256.

## Post

- All 35 LCDS PHP files + config SHA-256 match laptop
- VPS resolves `orders.business_unique_keys` = `order_id`, `cashfree_payment_id`; `serial_in_business=false`
- TargetHostGuard pass; VPS dark `true`
- Checkpoints: 14 files, fingerprints unchanged (including mtime)
- Inbox: 108 chunks, manifest `db80fcc1…580b518`
- recon: orders 37743, cashfree_webhook_logs 46048

Generation not resumed.
