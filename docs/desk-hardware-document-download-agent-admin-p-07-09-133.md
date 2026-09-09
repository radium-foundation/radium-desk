# Agent/Admin persisted Label and Manifest download — RadiumDesk-P-07-09-133

**Date:** 2026-09-09  
**Prompt ID:** `RadiumDesk-P-07-09-133`  
**Mode:** Authorization fix for persisted shipment document downloads, then commit/push/named-file overlay. No shipment create/AWB/label/pickup/manifest. No RDE318516 write.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-132**. This ticket: **P-07-09-133**.

---

## Pre-change

| Item | Value |
|------|-------|
| Repository | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` |
| Remote | `origin` `git@github.com:radium-foundation/radium-desk.git` |
| Branch | `main` |
| Before SHA | `fd08af5b11683082c6b6058d6eb85a6825c5d685` (P-07-09-131; present as HEAD; not yet on `origin/main`) |
| `origin/main` | `598479f713ff9ac86eae977dd5980da0ce3e261c` |
| Unrelated dirty tree | Present (shipping mapper + statutory docs + untracked investigation markdown). Not included. |

P-07-09-131 already implemented qty>1 measured parcel, fulfilment-level snapshot immutability, and GET downloads that redirect to persisted `label_url` / `manifest_url`. Downloads were gated by `hardware.fulfilment.operate`, so support `agent` received 403.

## Authorization

Existing model preserved:

- Mutations (create, AWB, generate label/manifest, pickup, parcel, serials) remain `HardwareFulfilmentAccess::allows()` → `hardware.fulfilment.operate` (admin-team + `hardware_team`).
- Support `agent` still has no operate, no Hardware show, no action-dialog, no generate POST.
- Documents are not public. Routes stay behind `auth` + `active`.
- No `hardware.fulfilment.operate` grant to `agent`.
- No new Spatie permission (would require a production seeder). Download uses the existing `agent` role plus `orders.view`, which Customer 360 already requires.

`HardwareFulfilmentAccess::allowsDocumentDownload()`:

1. Operators (`allows()`) may download; branch scope still applies when `fulfilment_branch_id` is set.
2. Support `agent` with `orders.view` may download without branch assignment.
3. `support_specialist`, `employee`, and unprivileged users remain 403.

Customer 360 shows Download Label / Download Manifest when `canDownloadDocuments` is true, independent of `canOperate`. Hardware Fulfilment shipment popup remains operator-only and already included the download fragment.

Downloads still call `persistedDocumentUrl()` and `redirect()->away()`. They do not call generate. Missing URL → 404. Fake generate counters stay unchanged.

## Qty > 1 (unchanged from P-07-09-131)

Qty 1: verified catalog packaging. Qty > 1: operator must enter L/B/H cm + actual packed weight kg before courier/create. No unit-dimension multiply, no historical inference, no catalog copy as outer carton. Snapshot source `measured_shipment_package` on the fulfilment. `commerce_orders.parcel` and `inventory_product_packaging` are not written. Immutable once shipment-bound. Volumetric display is L×B×H/5000; Desk still sends actual packed weight.

## Tests / build / lint

- PHPUnit HardwareFulfilment feature+unit: 294 passed, 1 skipped
- Focused auth/parcel/volumetric tests: 39 passed
- Pint `--test` on changed PHP: passed
- `php -l` on changed PHP: passed
- Vite build: passed (`app-BeDp7CqJ.js` contains measured-parcel JS)
- Vitest: hardware-action-dialog 15 passed. Broader suite had 5 pre-existing failures in operations-dashboard / customer-360-drawer / workspace session (not in this diff)

## Files in this commit

- `app/Support/HardwareFulfilment/HardwareFulfilmentAccess.php`
- `app/Http/Controllers/Inventory/HardwareFulfilmentSerialController.php`
- `app/Support/HardwareFulfilment/HardwareFulfilmentCustomer360Presenter.php`
- `resources/views/customer-360/partials/hardware-fulfilment.blade.php`
- tests listed above
- ledger + this report

Dirty `ShiprocketCreateOrderRequest.php` and unrelated docs: **not included**.

## Production / shipment mutations

Recorded after push and named-file overlay. No create/AWB/label generate/pickup/manifest/outbox/migrate/.env.
