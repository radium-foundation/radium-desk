# Deploy Hardware Dashboard UX — RadiumDesk-P-07-09-93

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-93`  
**Implementation:** `27feec21b55d0d9c17c9589d8776c2b7d23347db` (P-07-09-92)  
**Mechanism:** individual `install -m 644` from `git archive 27feec21`. Not `./tools/desk deploy`. Not a directory rsync onto `/var/www/radium-desk`. No migrate. No Vite rebuild. No `.env` change.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-92**. This ticket: **P-07-09-93**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

---

## Why not full `desk deploy`

| Gate | Finding | Class |
|------|---------|-------|
| Local worktree | Dirty unrelated `ShiprocketCreateOrderRequest.php` + statutory docs + untracked investigation markdown | VERIFIED |
| HEAD tag | `27feec21` is not latest semver tag (`v4.0.67`) | VERIFIED |
| Pending UPI migrations | `2026_09_04_220000` / `220100` / `220200` still Pending | VERIFIED |
| Official deskd | Would rsync dirty tree, require tag, run unscoped `migrate --force` | VERIFIED |
| P-07-09-90 | Directory `rsync -a` from `mktemp` set web root to `700` and caused public HTTP 403 | VERIFIED |

Push is **not** required for named-file overlay. **NO — Not performed.**

---

## Pre-deploy

| Item | Value | Class |
|------|-------|--------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Branch | `main` (ahead of `origin/main` `931b6b59` by 19) | VERIFIED |
| Local HEAD | `27feec21` Present Hardware work on the Services-style dashboard | VERIFIED |
| Commit exists | `27feec21b55d0d9c17c9589d8776c2b7d23347db` | VERIFIED |
| Worktrees | `radium-desk` feat branch; `radium-desk-phase1-clean` detached; this tree `main` | VERIFIED |
| Server | KVM `srv1910783` / `187.127.129.16` | VERIFIED |
| App path | `/var/www/radium-desk` | VERIFIED |
| PHP | `/usr/local/lsws/lsphp84/bin/php` | VERIFIED |
| Public URL | `https://desk.radiumbox.com` | VERIFIED |
| `release.json` | `v4.0.67` / `5d14a582` (overlay-on-release) | VERIFIED |
| Prior overlay | `p-07-09-91-20260908T130317Z` | VERIFIED |
| Web root before | `755 ravi:ravi` | VERIFIED |
| Shipping | `enabled=true` / `provider=shiprocket` / `http=true` / `HttpShiprocketGateway` | VERIFIED |

Dirty / untracked files were **not** in the overlay set.

---

## Overlay set (23 application files from `git archive 27feec21`)

Tests, docs, `.env`, Vite `public/build`, migrations, shipping/statutory dirty files, `HttpShiprocketGateway.php`, and `ShiprocketAlreadyQueuedPickup.php` were **not** copied.

New (3):

- `app/Enums/HardwareDashboardQueue.php`
- `app/Services/HardwareFulfilment/HardwareDashboardWorkspace.php`
- `resources/views/dashboard/partials/hardware-workspace.blade.php`

Replaced (20):

- `app/Enums/HardwareFulfilmentOperationalStage.php`
- `app/Http/Controllers/DashboardController.php`
- `app/Http/Controllers/Inventory/HardwareFulfilmentSerialController.php`
- `app/Services/HardwareFulfilment/Data/HardwareFulfilmentOperationalClassifier.php`
- `app/Services/HardwareFulfilment/Data/HardwareFulfilmentOperationalRow.php`
- `app/Services/HardwareFulfilment/Data/HardwareFulfilmentStepper.php`
- `app/Services/HardwareFulfilment/Data/HardwareShipmentReadiness.php`
- `app/Services/HardwareFulfilment/HardwareFulfilmentWorkQueue.php`
- `app/Services/HardwareFulfilment/HardwareFulfilmentWorkflowService.php`
- `app/Services/HardwareFulfilment/HardwareShipmentDocumentsService.php`
- `app/Services/HardwareFulfilment/HardwareShipmentEligibility.php`
- `app/Support/Customer360/Customer360OverflowMenuPresenter.php`
- `app/Support/HardwareFulfilment/HardwareFulfilmentCustomer360Presenter.php`
- `resources/css/app.css` (source only; no Vite rebuild)
- `resources/js/dashboard-operations-workspace.js` (source only; no Vite rebuild)
- `resources/views/customer-360/partials/hardware-fulfilment.blade.php`
- `resources/views/dashboard/index.blade.php`
- `resources/views/dashboard/partials/recent-service-cases.blade.php`
- `resources/views/inventory/hardware-fulfilments/index.blade.php`
- `resources/views/inventory/hardware-fulfilments/show.blade.php`

`HardwareShipmentDocumentsService.php` from `27feec21` still calls `ShiprocketAlreadyQueuedPickup::matchesRejectedResult()`. Gateway + matcher files were left at the P-07-09-91 overlay.

---

## Named-file overlay

| Item | Value |
|------|-------|
| Backup | `/var/www/radium-desk/storage/app/private/overlays/p-07-09-93-20260908T135508Z` |
| Install | individual `install -m 644` / `ravi:ravi` |
| Hash verify | 23/23 MATCH `27feec21` |
| Web root after install | `755 ravi:ravi` |
| Cache | `optimize:clear` + `optimize` |
| Web root after cache | `755 ravi:ravi` |

`rsync -a` of a `mktemp` directory onto `/var/www/radium-desk`: **NO — Not performed.**

---

## Production verification (GET only)

Public HTTPS and loopback Host-header:

| Check | Result | Class |
|-------|--------|-------|
| `https://desk.radiumbox.com/up` | 200, not LiteSpeed 403 | VERIFIED |
| `https://desk.radiumbox.com/login` | 200, not LiteSpeed 403 | VERIFIED |
| Loopback `/up` | 200 | VERIFIED |
| Loopback `/login` | 200 | VERIFIED |
| Web root | `755 ravi:ravi` | VERIFIED |

Laravel HTTP kernel as user `2` (same method as P-07-09-89/91):

| Check | Result | Class |
|-------|--------|-------|
| `/dashboard?workspace=hardware` | 200; `data-hardware-workspace`; `dashboard-hardware-table` | VERIFIED |
| Chips | Ready, Exceptions, Pickup, Completed | VERIFIED |
| Search | `Search hardware...` | VERIFIED |
| Live Services polling | `data-live-updates-enabled=0` | VERIFIED |
| Services tbody on Hardware | absent | VERIFIED |
| RDE318421 row | present; status `Ready for Pickup`; next `Ready` (non-mutating); `data-incident-id=52511` | VERIFIED |
| RIN3512331 | present; `Blocked` + `RIN mapping required`; next `View` | VERIFIED |
| RIN3512344 | present; `Blocked` + `RIN mapping required`; next `View` | VERIFIED |
| `/dashboard?workspace=action_required` | 200; Services cases table; no Hardware workspace; no `Search hardware...` | VERIFIED |
| `/inventory/hardware-fulfilments` | 200 | VERIFIED |
| `/inventory/hardware-fulfilments/1` | 200; RDE318421 | VERIFIED |
| C360 `52511` (RDE318421) | Hardware section; 10-step rail (no Packing); caption `Operational steps are complete.`; one action `Open Fulfilment` | VERIFIED |
| C360 `52246` / `52253` (RINs) | `Hardware cannot start yet.` + mapping copy; no Open Fulfilment | VERIFIED |
| Package Photo on show | exactly one `Package Photo` heading; no `Label-applied package photo`; only `package_before_label` upload | VERIFIED |
| HTTP 403 | none on kernel or public `/up` `/login` | VERIFIED |

Interactive browser click of the Hardware row was **NO — Not performed.** The row carries `data-incident-id="52511"`, which is the existing Dashboard → Customer 360 hook. C360 was opened via GET.

No mutating POST (pickup / manifest / AWB / label / invoice / serial / shipment) was made.

---

## RDE318421 after overlay (unchanged)

| Field | Before | After |
|-------|--------|-------|
| Fulfilment | `1` / `awb_assigned` | same |
| Provider shipment | `1568724940` | same |
| Courier | `Delhivery_Surface` / `15084` | same |
| AWB | `284931178067631` | same |
| Label | present | same |
| Package evidence | 2 (`package_before_label` + `package_label_applied`) | 2 |
| `ready_for_pickup_at` | `2026-09-08 17:54:42` | same |
| `hf.updated_at` | `2026-09-08 17:54:42` | same |
| Local `pickup_requested_at` | `2026-09-08 18:35:50` | same |
| `manifest_id` | `NULL` | same |
| `manifest_generated_at` | `2026-09-08 18:36:07` | same |
| `shipment.updated_at` | `2026-09-08 18:36:07` | same |
| HF count | 1 | 1 |

Local pickup was already set before this ticket (not by this overlay). This ticket did not click Request Pickup / Generate Manifest.

---

## Shiprocket

New `laravel.log` bytes during GET verification: **0** matches for `shiprocket`, `/v1/external`, `courier/serviceability`, pickup, or manifest. Viewing Dashboard / Customer 360 / show did not trigger a provider call.

Live Shiprocket mutation: **NO — Not performed.**

---

## Not performed

- Schema / migrate / UPI migrate: **NO — Not performed.**
- `.env` / secrets: **NO — Not performed.**
- Fulfilment / serial / invoice / shipment / AWB / label / pickup / manifest writes: **NO — Not performed.**
- RDE318421 data write: **NO — Not performed.**
- RIN mapper / auto-create / bulk: **NO — Not performed.**
- Vite rebuild / `public/build` overlay: **NO — Not performed.**
- Full `deskd` / directory rsync onto web root: **NO — Not performed.**
- Push: **NO — Not performed.**
- Rollback: **NO — Not performed.**

---

## Remaining risks

- Compiled Vite assets are still the previous `public/build` bundle. Hardware UX is Blade-driven on a full-page load of `/dashboard?workspace=hardware`. Soft-switch from a Services chip to Hardware may still use the older compiled JS until the next Vite deploy. Hardware sub-chips (Ready / Exceptions / Pickup / Completed) already full-page navigate.
- New CSS classes (`.dashboard-hardware-table`, status chips) may not appear until Vite rebuild; the table still uses existing Services table classes.

---

## Rollback

Restore the 20 replaced files from `p-07-09-93-20260908T135508Z`. Delete the 3 new paths. Then `optimize:clear` + `optimize`. Confirm `/var/www/radium-desk` stays `755`. Do **not** restore pickup gateway/matcher files from this backup (they were not replaced). Rollback was **not** required.
