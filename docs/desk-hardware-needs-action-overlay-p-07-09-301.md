# Hardware Needs Action overlay reconciliation (P-07-09-301)

**Prompt ID:** RadiumDesk-P-07-09-301  
**Date:** 2026-09-16  
**Type:** Overlay-compatible local branch. **Not deployed.**

## Ledger / git

| Item | Value |
|------|--------|
| Ledger next ID | RadiumDesk-P-07-09-301 (after P-07-09-300) |
| Repo | `github.com/radium-foundation/radium-desk` |
| Validated PR #15 | `cursor/hardware-needs-action-queue-ade8` @ `f6ed6473` |
| Overlay branch | `cursor/hardware-needs-action-overlay-ade8` |
| Overlay worktree | `/Users/ravi/RadiumWebsites/radium-desk-needs-action-overlay` |
| Before SHA | `f6ed6473` |
| Production path | `/var/www/radium-desk` (KVM `srv1910783`) |
| Dirty POS checkout | `/Users/ravi/RadiumWebsites/radium-desk` untouched |

## Production overlay inventory (live hashes)

| File | Production SHA-256 | Notes |
|------|--------------------|--------|
| `HardwareDashboardWorkspace.php` | `c930002e…` | Default filter **All**; inspect-all presenter |
| `HardwareFulfilmentWorkQueue.php` | `ea4125ad…` | Chip = inspect minus `isShippedWorkspaceItem` |
| `HardwareShipmentEligibility.php` | `6f41bbb4…` | ConfigurableVariant + `providerTrack*` |
| `HardwareShipmentReadiness.php` | `44f0fe29…` | Track constructor fields |
| `HardwareNeedsActionSqlQuery.php` | **missing** | |
| `HardwareWorkspaceFilter.php` | `05abaf33…` | All / Ready / Exceptions / Pickup / Scheduled only |
| `HardwareFulfilmentOperationalClassifier.php` | `8d5295fd…` | Identical to PR #15 |
| `DashboardLiveController.php` | `917c5dbc…` | `GET /dashboard/live/hardware` already registered |
| `AppServiceProvider.php` | `28dd6921…` | Matches git-main; no WorkQueue scoped bind |
| `routes/web.php` | `c5b7a984…` | Purchasing / historical / C360 extras; live hardware route present |
| `live-dashboard.js` | `15ef5120…` | Identical to PR #15 |
| `Shipment.php` | `9c03a248…` | Track fillable |
| `HardwareConfigurableVariantDisplay.php` | `309c329c…` | Production catalog / UC / UGR maps |
| `hardware-product-cell.blade.php` | `6e47d284…` | Primary / secondary variant cell |

Protected production behavior kept: Active/Shipped chip, `hw_scope` / `hw_filter`, `provider_track_normalized`, ConfigurableVariant, purchasing, historical, C360, invoice/POS routes, Ably live-updates=0 on Hardware, existing row actions.

## What this branch ports

From validated PR #15, onto production overlay files rather than replacing them:

- Default Hardware view: Needs Action
- Compact nav: Needs Action / Shipping / Completed / All
- Four queues: Product Mapping Required, Awaiting Serial, AWB Pending, Package Photo Pending
- SQL `COUNT`/`EXISTS` for those queues (Active, non-ingested)
- Page size 40; inspect selected page IDs only
- Shipping subfilters use persisted `provider_track_normalized` (`out_for_pickup`, `picked_up`, `in_transit`); **no Out for Delivery**
- Delivered track is excluded from Shipping SQL
- Production Eligibility / Readiness / Shipment / variant display / product cell / LiveController restored to live hashes
- `AppServiceProvider`: request-scoped `HardwareFulfilmentWorkQueue` + `HardwareNeedsActionSqlQuery` (surgical vs production main provider)
- `routes/web.php`: **no change** — live hardware endpoint already exists

## Production-scale evidence (read-only)

Current live SSR (this gate, process-local `session.driver=array`, no cache flush, no deploy):

| Surface | Wall | Queries | SQL | PHP | HTML | Rows | Filter |
|---------|------|---------|-----|-----|------|------|--------|
| `/dashboard` | 4614 ms | 570 | 767 ms | 3847 ms | 174 KB | 0 | (Ready Queue) |
| `/dashboard?queue=hardware` | 4221 ms | 222 | 607 ms | 3614 ms | 2.12 MB | **936** | **all** |

Hardware still inspects the Active table. `data-live-updates-enabled` remains 1 on Ready / 0 on Hardware.

Read-only SQL against live `radium_desk` (candidate query shape, not candidate PHP):

| Metric | Count |
|--------|------:|
| HF total | 938 |
| Shipped/synced | 3 |
| Active non-ingested/non-shipped (Needs Action SQL universe, minus frozen/hold) | 65 |
| Package Photo Pending | 4 |
| Shipping (excludes delivered track) | 27 |
| Out for Pickup | 2 |
| Picked Up | 0 |
| In Transit | 8 |
| Active + delivered track (kept off Shipping) | 21 |

Candidate **HTTP SSR wall on production was not measured.** The only way to collect that is to overlay the candidate files. That is a separate deployment gate.

Local SQLite 72+4 / pagination fixtures from P-300 remain the local proof only.

## Tests

PR-focused Hardware/Dashboard PHPUnit: **73 passed / 73**.

Pint `--test` on overlay PHP: PASS.

Vitest (`live-dashboard`, `live-dashboard-reverb`, `hardware-action-dialog`): **74 passed**.

Vite build: PASS (`dashboard-BVwn-4xB.js` 211 KB / gzip 57 KB). JS source `live-dashboard.js` is unchanged vs production.

Broader Dashboard + Hardware PHPUnit: pre-existing `stamp-bgr.png` PDF errors (92), RBP prefix policy, classification-index lean-load, serial-allocation 1 vs 0. Additional Hardware show/awaiting 500s in the overlay worktree were Vite manifest absence until `public/build` was linked; they are not treated as overlay logic failures.

## Deployment (separate gate)

See proposed named-file manifest in this document. **Do not deploy in P-301.**

### Named-file overlay (proposed)

Backup first: `/var/backups/radium-desk/overlays/p-07-09-301-<UTC>/` copy of every replaced production file.

Mechanism: `install -m 644 -o www-data -g www-data`. Not `deskd`. Not `rsync --delete`. Not `deploy-kvm.sh`. Not wholesale `routes/web.php`.

| Source (overlay branch) | Production destination | Overlay? | Expected live hash after |
|--------------------------|------------------------|----------|--------------------------|
| `HardwareNeedsActionSqlQuery.php` | `app/Services/HardwareFulfilment/HardwareNeedsActionSqlQuery.php` | **new file** | `192c42a6…` |
| `HardwareFulfilmentWorkQueue.php` | same path | replace | `7342a434…` |
| `HardwareDashboardWorkspace.php` | same path | replace | `9ce5bdc5…` |
| `HardwareWorkspaceFilter.php` | same path | replace | `1c9d0590…` |
| `HardwareFulfilmentOperationalRow.php` | same path | replace | `1f57a9f9…` |
| `HardwareDashboardLiveService.php` | same path | replace | `2bdebaa9…` |
| `hardware-workspace.blade.php` | `resources/views/dashboard/partials/` | replace | `3aa9489f…` |
| `hardware-workspace-nav.blade.php` | same | replace | `1cd75fdc…` |
| `AppServiceProvider.php` | **surgical only** | add two `use` + two `$this->app->scoped(...)` next to existing `DashboardSnapshotStore` bind | not whole-file |
| `routes/web.php` | — | **none** | keep `c5b7a984…` |
| `HardwareShipmentEligibility.php` | — | **none** (already `6f41bbb4…`) | |
| `DashboardLiveController.php` | — | **none** (already `917c5dbc…`) | |
| Built JS/CSS | — | **none required** (`live-dashboard.js` identical `15ef5120…`; keep existing hashed assets) | |

### `routes/web.php`

No insertion. Production already has:

`Route::get('/dashboard/live/hardware', [DashboardLiveController::class, 'hardware'])->name('dashboard.live.hardware');`

### `AppServiceProvider`

Do not replace the provider. Insert after the existing `DashboardSnapshotStore` scoped bind:

```php
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkQueue;
use App\Services\HardwareFulfilment\HardwareNeedsActionSqlQuery;
// ...
$this->app->scoped(HardwareFulfilmentWorkQueue::class);
$this->app->scoped(HardwareNeedsActionSqlQuery::class);
```

Preserve payment / RadiumBox listeners and all other binds.

### DB

**No production migration. No data change.**  
`shipments.provider_track_*` already exist (`2026_09_15_100000_add_shiprocket_track_columns_to_shipments` is already in production `migrations`). The same migration file is in this branch for local sqlite only. **Do not run `php artisan migrate`.**

### Rollback

Restore the replaced files from the P-301 backup directory. Delete the new `HardwareNeedsActionSqlQuery.php`. Revert the two ASP scoped lines. `optimize:clear` + `optimize`. Do not overlay git-main WorkQueue. Do not restore purchasing/historical/`web.php`.

## Decision

**READY FOR SEPARATE DEPLOYMENT GATE**

Candidate production SSR wall is **not** claimed. Overlay compatibility is established by live hashes + restored Eligibility/track/variant + surgical routes/ASP.
