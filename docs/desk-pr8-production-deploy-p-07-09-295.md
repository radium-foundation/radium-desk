# PR #8 production surgical overlay (P-07-09-295)

**Prompt ID:** RadiumDesk-P-07-09-295  
**Date:** 2026-09-15  
**Repository:** `radium-foundation/radium-desk`  
**PR:** https://github.com/radium-foundation/radium-desk/pull/8  
**Branch:** `cursor/hw-ably-shiprocket-sync-c269`  
**Deployed SHA:** `b13df4e7b6291658224ac28fe92dfbe3f81dca47`

## Verdict

**SUCCESS.** Named-file surgical overlay applied to KVM `/var/www/radium-desk`. Tracking remains **OFF**. No `deploy-kvm.sh`. No `rsync --delete`. No wholesale `routes/web.php` replace. No production `.env` change.

## Pre-deploy hardening (committed before overlay)

- `46c1fcb1` — mutation JSON resolves `HardwareWorkspaceScope`/`HardwareWorkspaceFilter` instead of passing a string into `livePayload()` (would TypeError). Live JS drops rows that leave the active view. Scheduler `when()` defaults tracking to false.
- `b13df4e7` — tracking artisan command defaults off when the config key is absent.

## Backup

`/var/backups/radium-desk/overlays/p-07-09-295-20260915T131756Z`

- 19 replaced production files copied with checksums
- 26 then-current Vite assets copied
- `shipments` + `migrations` mysqldump gzip SHA-256 `c9595fd493b287cb11db8abf6085993d4f04c87bce18a3228bba28f37c00b558`

## Migration

`php artisan migrate --path=database/migrations/2026_09_15_100000_add_shiprocket_track_columns_to_shipments.php --force`

- Ran. Columns `provider_track_status` (varchar 64 nullable), `provider_track_normalized` (varchar 32 nullable), `provider_tracked_at` (timestamp nullable).
- Shipments fingerprint unchanged: 64 rows, id-sum 2080.
- UPI migrations remain **Pending**. Blanket `migrate --force` was not used.

## Tracking

`SHIPROCKET_TRACKING_SYNC_ENABLED` **absent**. `config('shipping.tracking.sync_enabled')` = **false**. Command not executed. Status `"19"` still unknown. No new mappings.

## Production E2E Ably

**UNKNOWN — Not performed.** No production mutation was created to prove the socket path.

## Rollback

Restore files from the overlay backup, drop the three nullable columns only if rolling back the migration, then `optimize:clear` + `optimize` and restart `radium-desk-queue-worker`.
