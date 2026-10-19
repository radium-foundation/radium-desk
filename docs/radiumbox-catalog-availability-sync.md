# RadiumBox catalog availability sync (Desk side)

Paired with RadiumBox `docs/desk-availability-sync-p18-80.md`. Policy: `docs/desk-radiumbox-availability-regression-integration-policy.md`.

## Flags (default off)

| Env | Desk |
|-----|------|
| Push | `RADIUMBOX_AVAILABILITY_SYNC_ENABLED=false` |

Desk is SSOT for **owner-approved** `radiumbox_com` `channel_sku_maps` only. No auto-mapping or mirroring of Box `is_stock_avialable`.

## Deployment and enablement order

**RIP checks (PHPUnit + Pint + CI when configured) must pass on both repositories before any step below.**

1. Deploy RadiumBox with migrations for `desk_mapped_catalog_models` and `desk_availability_snapshots` (flags remain off).
2. Enable Box ingest: `DESK_AVAILABILITY_INGEST_ENABLED=true`.
3. Enable Desk push: `RADIUMBOX_AVAILABILITY_SYNC_ENABLED=true`, `php artisan config:cache`, restart Desk queue workers.
4. Run `php artisan radiumbox:backfill-catalog-availability` (or rely on live stock/map triggers) until every **owner-mapped** SKU has a fresh snapshot on Box. After push is enabled, the scheduler repeats that command every 10 minutes (`2-59/10 * * * *`, `withoutOverlapping`) so quiet periods stay inside the 1800-second stale window. Sync logs older than 14 days are pruned daily at 03:40.
5. Verify snapshots on Box (`received_at` within `DESK_AVAILABILITY_STALE_AFTER_SECONDS`) for mapped `model_id`s.
6. Enable Box enforcement last: `DESK_AVAILABILITY_ENFORCEMENT_ENABLED=true`.

Enabling enforcement before ingest/backfill fails closed for registered mapped models with missing or stale snapshots. Unmapped catalog variants are unchanged on Box.
