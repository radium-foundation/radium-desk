# Hardware Needs Action production overlay deploy (P-07-09-302)

**Prompt ID:** RadiumDesk-P-07-09-302  
**Date:** 2026-09-16  
**Type:** Surgical named-file production overlay. **Deployed.**

## Ledger / git

| Item | Value |
|------|--------|
| Ledger next ID | RadiumDesk-P-07-09-302 (after P-07-09-301) |
| Repo | `github.com/radium-foundation/radium-desk` |
| Source worktree | `/Users/ravi/RadiumWebsites/radium-desk-needs-action-overlay` |
| Source branch | `cursor/hardware-needs-action-overlay-ade8` |
| Source SHA | `aeb66090e202490b0bd01a36fbdd231c1fb2e84c` |
| Production | KVM `srv1910783` / `187.127.129.16` / `/var/www/radium-desk` |
| Public URL | `https://desk.radiumbox.com` |
| Dirty POS checkout | `/Users/ravi/RadiumWebsites/radium-desk` untouched |
| PR #15 | **not merged** |

## Mechanism

`install -m 644` of named files only. Not `deploy-kvm.sh`. Not `rsync --delete`. Not wholesale `routes/web.php`. No `php artisan migrate`. No env / Shiprocket / Ably / order / payment / invoice / serial / fulfilment mutation.

Backup: `/var/backups/radium-desk/overlays/p-07-09-302-20260916T053350Z` (retained).

Caches: `optimize:clear` then `optimize`. Queue worker **not** restarted (manifest did not require it).

## Files

| Path | Action | Live SHA-256 |
|------|--------|----------------|
| `HardwareNeedsActionSqlQuery.php` | **new** | `192c42a6…` |
| `HardwareFulfilmentWorkQueue.php` | replace | `7342a434…` |
| `HardwareDashboardWorkspace.php` | replace | `9ce5bdc5…` |
| `HardwareWorkspaceFilter.php` | replace | `1c9d0590…` |
| `HardwareFulfilmentOperationalRow.php` | replace | `1f57a9f9…` |
| `HardwareDashboardLiveService.php` | replace | `2bdebaa9…` |
| `hardware-workspace.blade.php` | replace | `3aa9489f…` |
| `hardware-workspace-nav.blade.php` | replace | `1cd75fdc…` |
| `AppServiceProvider.php` | **surgical** two `use` + two `$this->app->scoped(...)` | `77582948…` |
| `routes/web.php` | **none** | `c5b7a984…` |
| Vite assets | **none** | existing hashed dashboard JS kept |

Pre-deploy hashes of replaced files (backup):

| File | Pre-deploy SHA-256 |
|------|---------------------|
| `HardwareDashboardWorkspace.php` | `c930002e…` |
| `HardwareFulfilmentWorkQueue.php` | `ea4125ad…` |
| `HardwareWorkspaceFilter.php` | `05abaf33…` |
| `HardwareFulfilmentOperationalRow.php` | `990ff40e…` |
| `HardwareDashboardLiveService.php` | `ffd894e4…` |
| `hardware-workspace.blade.php` | `d178de36…` |
| `hardware-workspace-nav.blade.php` | `c2f7f5e0…` |
| `AppServiceProvider.php` | `28dd6921…` |

Intentionally **not** replaced (live hashes unchanged vs P-301):

- `HardwareShipmentEligibility.php` `6f41bbb4…`
- `HardwareShipmentReadiness.php` `44f0fe29…`
- `DashboardLiveController.php` `917c5dbc…`
- `Shipment.php` `9c03a248…`
- `HardwareConfigurableVariantDisplay.php` `309c329c…`
- `hardware-product-cell.blade.php` `6e47d284…`
- `live-dashboard.js` `15ef5120…`

`ConfirmRadiumBoxPaymentOnOrderPaid` remains bound in `AppServiceProvider`.

## Hardware counts (current production data)

Read-only SQL after overlay:

| Metric | Count |
|--------|------:|
| `hardware_fulfilments` total | 938 |
| `state=shipped` | 3 |
| `state=synced` | 0 |
| not shipped/synced | **935** |
| `ingested` | 862 |
| `awb_assigned` | 59 |
| `ready_for_fulfilment` | 9 |
| `shipment_created` | 4 |
| `invoice_issued` | 1 |

**Active chip (default Needs Action):** `workspaceTotal()` = `COUNT` whereNotIn(`shipped`,`synced`) + awaiting + windowed RIN = **935 + 0 + 0 = 935**.

**Shipped/Completed chip:** `COUNT` whereIn(`shipped`,`synced`) = **3**. Shipped scope GET rendered **3** rows.

Pre-deploy default All rendered **936** Active rows because that path used inspect-minus-`isShippedWorkspaceItem` (stage `Completed`). SQL shipped/synced is 3; inspect Completed-stage on that snapshot was 2, so inspect Active = 938 − 2 = 936. The default view now uses the SQL chip (935). That is the P-301 overlay contract, not delivered-as-Shipping.

Shipping SQL (excludes delivered track): **27** rows. `provider_track_normalized=delivered` on non-shipped HF: **26**. Out for Pickup GET: **2**. In Transit GET: **8**. Picked Up: **0**. No “Out for Delivery”.

Needs Action: **17** (Product Mapping Required 6, Awaiting Serial 3, AWB Pending 4, Package Photo Pending 4). Page size **40**, one page, inspected **17**.

## Performance (immediate pre-deploy vs first post-deploy SSR)

Authenticated process-local kernel, `session.driver=array`, query log enabled.

| Surface | When | Wall | Queries | SQL | PHP | HTML | Rows | Filter |
|---------|------|------|---------|-----|-----|------|------|--------|
| `/dashboard` | before | 4350 ms | 180 | 575 ms | 3775 ms | 227 KB | 0 | Ready; live-updates=1 |
| `/dashboard` | after | 2813 ms | 117 | 506 ms | 2308 ms | 240 KB | 0 | Ready; live-updates=1 |
| Hardware | before | 4005 ms | 222 | 479 ms | 3525 ms | **2.12 MB** | **936** | **all**; live-updates=0 |
| Hardware | after | **943 ms** | **73** | 549 ms | **393 ms** | **134 KB** | **17** | **needs_action**; inspected 17 |

Hardware vs immediate pre-deploy: wall **−3062 ms**, queries **−149**, PHP **−3132 ms**, HTML **−1.99 MB**. SQL **+70 ms** (SQL is not the win; PHP/HTML are).

All filter remains the inspect-all path: page 1 **40** rows / Page 1 of 24, inspected **938**, HTML ~196 KB (no longer 2.12 MB). Page 2 **40** rows / Page 2 of 24.

## Regression (GET / read-only)

| Surface | Result |
|---------|--------|
| `/up` | 200 |
| `/login` | 200 |
| `/dashboard` unauth | 302 |
| Hardware unauth | 302 |
| Hardware default | 200, Needs Action selected, four queues visible, compact nav |
| Shipping / out_for_pickup / in_transit | 200 |
| Date `from`/`to` | 200, still Needs Action |
| `GET /dashboard/live/hardware` | 200; keys `rows`, `filter_counts`, `scope_counts` |
| Purchasing index | 200 |
| POS counter | 200 |
| C360 `dashboard/orders/1/customer-360` | 200 |
| Historical document (no query) | **422** (route present; missing document query — not an overlay file change) |
| Variant cell | `dashboard-hardware-product__primary` present; production product-cell hash unchanged |
| Shiprocket AWB/shipment APIs | **NO** — not performed |
| RBP145 / RBP167 / RBP170 | **NO** — not touched |

Unauth purchasing / historical / POS / C360 / live hardware: all **302**.

Laravel log during this gate: pre-existing Bonvoice 1020 + `RadiumBoxEnrichmentSyncStatus` match errors (not this overlay). One `FatalError` 128M is the CLI profiler with query log on All, not HTTP. HTTP php.ini `memory_limit=128M`; default Needs Action inspects 17 rows.

## Database / Shiprocket

- Migration: **NO** — Not performed.
- DB writes by this gate: **NO** — Not performed.
- Schema: track columns already present; unchanged.
- Shiprocket API mutation / courier / AWB / shipment: **NO** — Not performed.

## Rollback

**Not required.** Backup retained at `/var/backups/radium-desk/overlays/p-07-09-302-20260916T053350Z`.

## Remaining risks

- `hw_filter=all` (and other legacy filters) still inspect the full fulfilment set in PHP, then paginate. Default view no longer does this.
- Active chip is SQL 935 on Needs Action; All still recomputes inspect Active (legacy). Do not treat 935 and 936 as interchangeable without stating the method.
- Queue worker not restarted; overlay PHP is request-scoped dashboard code.

## Decision

**SUCCESS**
