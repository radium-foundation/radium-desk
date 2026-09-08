# Deploy Hardware Operations UX v2 — RadiumDesk-P-07-09-96

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-96`  
**Implementation:** `9f42311805d318004bc4fb08d7e1951e1c12659f` (P-07-09-94)  
**Vite validation:** P-07-09-95 (`npm run build` / `vite build`)  
**Mechanism:** individual `install -m 644` from `git archive 9f423118` plus the verified local `public/build` files required by `manifest.json`. Not `./tools/desk deploy`. Not a directory rsync onto `/var/www/radium-desk`. No `--delete`. No migrate. No `.env` change.

Ledger file requested as `docs/cursor-prompt-log.md` — **that file does not exist**. Authoritative ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-95**. This ticket: **P-07-09-96**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

---

## Why not full `desk deploy`

| Gate | Finding | Class |
|------|---------|-------|
| Local worktree | Dirty unrelated `ShiprocketCreateOrderRequest.php` + statutory docs + untracked investigation markdown | VERIFIED |
| HEAD tag | `9f423118` is not latest semver tag (`v4.0.67`) | VERIFIED |
| Pending UPI migrations | Still Pending from prior tickets | INFERRED |
| Official deskd | Would rsync dirty tree, require tag, run unscoped `migrate --force` | VERIFIED |
| P-07-09-90 | Directory `rsync -a` from `mktemp` set web root to `700` and caused public HTTP 403 | VERIFIED |
| Official Vite rsync | `sync_kvm_public_build` uses `--delete` | VERIFIED |

Push is **not** required for named-file overlay. **NO — Not performed.**

---

## Pre-deploy

| Item | Value | Class |
|------|-------|--------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Branch | `main` (ahead of `origin/main` by 21) | VERIFIED |
| Local HEAD | `9f423118` Present Hardware next actions in a Services-style modal | VERIFIED |
| Commit exists | `9f42311805d318004bc4fb08d7e1951e1c12659f` | VERIFIED |
| Worktrees | `radium-desk` feat branch; `radium-desk-phase1-clean` detached; this tree `main` | VERIFIED |
| Server | KVM `srv1910783` / `187.127.129.16` | VERIFIED |
| App path | `/var/www/radium-desk` | VERIFIED |
| PHP | `/usr/local/lsws/lsphp84/bin/php` | VERIFIED |
| Public URL | `https://desk.radiumbox.com` | VERIFIED |
| `release.json` | `v4.0.67` / `5d14a582` (overlay-on-release) | VERIFIED |
| Prior overlay | `p-07-09-93-20260908T135508Z` | VERIFIED |
| Web root before | `755 ravi:ravi` | VERIFIED |
| Shipping | `enabled=true` / `provider=shiprocket` / `HttpShiprocketGateway` | VERIFIED |
| Replace-target hashes | All 13 existing app files MATCH `27feec21` (P-07-09-93) | VERIFIED |
| P-07-09-91 pickup files | Gateway / matcher / documents hashes unchanged; not in P-07-09-94 | VERIFIED |
| Local Vite | `public/build/manifest.json` + hashed assets from P-07-09-95 | VERIFIED |

Dirty / untracked files were **not** in the overlay set.

---

## Overlay set

### Application files (23 from `git archive 9f423118`)

Tests, docs, `.env`, migrations, shipping/statutory dirty files, `HttpShiprocketGateway.php`, and `ShiprocketAlreadyQueuedPickup.php` were **not** copied.

New (10):

- `app/Services/HardwareFulfilment/Data/HardwareFulfilmentProductLines.php`
- `resources/js/hardware-action-dialog.js`
- `resources/js/hardware-dashboard-selection.js`
- `resources/views/dashboard/partials/hardware-product-cell.blade.php`
- `resources/views/inventory/hardware-fulfilments/fragments/action-allocate-serial.blade.php`
- `resources/views/inventory/hardware-fulfilments/fragments/action-confirm.blade.php`
- `resources/views/inventory/hardware-fulfilments/fragments/action-dialog.blade.php`
- `resources/views/inventory/hardware-fulfilments/fragments/action-issue-invoice.blade.php`
- `resources/views/inventory/hardware-fulfilments/fragments/action-package-photo.blade.php`
- `resources/views/inventory/hardware-fulfilments/fragments/action-start-shipment.blade.php`

Replaced (13):

- `app/Http/Controllers/Inventory/HardwareFulfilmentSerialController.php`
- `app/Models/HardwareFulfilment.php`
- `app/Services/HardwareFulfilment/Data/HardwareFulfilmentOperationalClassifier.php`
- `app/Services/HardwareFulfilment/Data/HardwareFulfilmentOperationalRow.php`
- `app/Services/HardwareFulfilment/HardwareFulfilmentWorkQueue.php`
- `app/Support/HardwareFulfilment/HardwareFulfilmentCustomer360Presenter.php`
- `resources/css/app.css`
- `resources/js/core/global-init.js`
- `resources/js/customer-360-drawer.js`
- `resources/js/pages/dashboard.js`
- `resources/views/customer-360/partials/hardware-fulfilment.blade.php`
- `resources/views/dashboard/partials/hardware-workspace.blade.php`
- `routes/web.php` (adds GET `hardware-fulfilments/{fulfilment}/action-dialog` only vs `27feec21`)

### Vite files (27 required by current `manifest.json`)

Installed individually into `/var/www/radium-desk/public/build/` without `--delete`. Previous hashed assets (`app-If91b3g-.css`, `app-BZXkT9-z.js`, `dashboard-CVcLVN9Z.js`) were **kept**.

- `manifest.json`
- `assets/app-D4lQXdS3.css`
- `assets/app-nLcHXNoc.js`
- `assets/dashboard-BsgJcaDW.js`
- plus the other 23 manifest-referenced chunks/fonts (toast, workspace-registry, bootstrap-icons, etc.)

The user-facing names `public/build/app-*.css` are **not** the on-disk paths. Vite writes `public/build/assets/...`.

---

## Named-file overlay

| Item | Value |
|------|-------|
| Backup | `/var/www/radium-desk/storage/app/private/overlays/p-07-09-96-20260908T153059Z` |
| Timestamp | `20260908T153059Z` |
| Files backed up | 13 replaced app files + previous `public/build/manifest.json` + `rde318421-before.txt` |
| Install | individual `install -m 644` / `ravi:ravi` from `/tmp/hf-ux-9f423118-stage` |
| Hash verify | 23/23 app MATCH `9f423118`; checked Vite hashes MATCH local P-07-09-95 build |
| Web root after install | `755 ravi:ravi` |
| Cache | `optimize:clear` + `optimize` |
| Web root after cache | `755 ravi:ravi` |

`rsync -a` of a `mktemp` directory onto `/var/www/radium-desk`: **NO — Not performed.**  
Vite `--delete`: **NO — Not performed.**

---

## Production verification (GET only)

Public HTTPS and loopback Host-header:

| Check | Result | Class |
|-------|--------|-------|
| `https://desk.radiumbox.com/up` | 200, not LiteSpeed 403 | VERIFIED |
| `https://desk.radiumbox.com/login` | 200; HTML references `app-D4lQXdS3.css` + `app-nLcHXNoc.js`; old hashes absent | VERIFIED |
| Loopback `/up` `/login` | 200 | VERIFIED |
| Web root | `755 ravi:ravi` | VERIFIED |
| Vite HTTP | `/build/manifest.json`, `app-D4lQXdS3.css`, `app-nLcHXNoc.js`, `dashboard-BsgJcaDW.js` all 200 | VERIFIED |

Laravel HTTP kernel as user `2` (same method as P-07-09-93):

| Check | Result | Class |
|-------|--------|-------|
| `/dashboard?workspace=hardware` | 200; workspace; Ready / Exceptions / Pickup / Completed; `Search hardware`; Product; Status; Next Action; checkboxes; select-all; selection bar; Open selected; no bulk ship/pickup/manifest | VERIFIED |
| Quantity | Compact in Product cell (`Mantra … · 1 Q`), not a separate column header | VERIFIED |
| Live Services polling | not enabled on Hardware | VERIFIED |
| RDE318421 | present; `Ready for Pickup`; next `Ready` (non-mutating); product `· 1 Q`; `data-incident-id=52511` | VERIFIED |
| RIN3512331 | `Blocked` + `RIN mapping required` + `View` | VERIFIED |
| RIN3512344 | `Blocked` + `RIN mapping required` + `View` | VERIFIED |
| `/dashboard?workspace=action_required` | 200; Services cases present; no Hardware workspace / Search hardware / select-all; new Vite hashes | VERIFIED |
| C360 `52511` (RDE318421) | Hardware card; `Mantra … · Qty 1`; stage `Ready for Pickup`; next `Ready`; **Open Fulfilment** present; primary action-dialog hidden because Ready is non-mutating | VERIFIED |
| GET action-dialog `/inventory/hardware-fulfilments/1/action-dialog` | 200; `c360-correction-dialog`; RDE318421; no `Label-applied package photo` | VERIFIED |
| C360 `52246` (RIN3512331) | Hardware card; Blocked; mapping-required; no Open Fulfilment | VERIFIED |
| Show `/inventory/hardware-fulfilments/1` | 200; exactly one `Package Photo`; no `Label-applied package photo`; `package_before_label` only | VERIFIED |
| HTTP 403 | none on kernel or public `/up` `/login` | VERIFIED |

Interactive browser click of checkboxes / Open selected / C360 primary button: **NO — Not performed.** No browser automation was available. Selection + action-dialog **code** is present in the deployed hashed JS/CSS.

Deployed bundle markers (public HTTPS GET):

- CSS: `dashboard-hardware-selection`, product popover classes
- `app-nLcHXNoc.js`: `data-hardware-action-dialog`, `data-hardware-action-form`, `refresh_customer360`, `action_dialog_url`
- `dashboard-BsgJcaDW.js`: select-all, open/clear selected, product detail, `1 selected`

---

## RDE318421 after overlay (unchanged)

| Field | Before | After |
|-------|--------|-------|
| Fulfilment | `1` / `awb_assigned` | same |
| Provider shipment | `1568724940` | same |
| External order | `1572506854` | same |
| Courier | `Delhivery_Surface` / `15084` | same |
| AWB | `284931178067631` | same |
| Package evidence | 2 | 2 |
| `ready_for_pickup_at` | `2026-09-08 17:54:42` | same |
| `hf.updated_at` | `2026-09-08 17:54:42` | same |
| Local `pickup_requested_at` | `2026-09-08 18:35:50` | same |
| `manifest_id` | `NULL` | same |
| `manifest_generated_at` | `2026-09-08 18:36:07` | same |
| `shipment.updated_at` | `2026-09-08 18:36:07` | same |
| HF count | 1 | 1 |

This ticket did not click Request Pickup / Generate Manifest / Create Shipment.

---

## Shiprocket

New `laravel.log` bytes during GET verification: **0** matches for `shiprocket`, `/v1/external`, `courier/serviceability`, pickup, or manifest.

Live Shiprocket mutation: **NO — Not performed.**

---

## Pre-existing C360 Vitest note (P-07-09-95)

23 passed / 3 failed. Failures expect `http://localhost/dashboard/service-cases/42/customer-360`; implementation fetches `/dashboard/service-cases/42/customer-360`. P-07-09-94 only added Hardware click-ignore selectors. **Not changed** in this deployment.

---

## Not performed

- Schema / migrate / UPI migrate: **NO — Not performed.**
- `.env` / secrets: **NO — Not performed.**
- Fulfilment / serial / invoice / shipment / AWB / label / pickup / manifest writes: **NO — Not performed.**
- RDE318421 data write: **NO — Not performed.**
- RIN mapper / auto-create / bulk: **NO — Not performed.**
- Interactive browser clicks: **NO — Not performed.**
- Full `deskd` / directory rsync onto web root / Vite `--delete`: **NO — Not performed.**
- Push: **NO — Not performed.**
- Rollback: **NO — Not performed.**

---

## Remaining risks

- Interactive checkbox / Open selected / C360 primary-button click was not exercised in a real browser.
- Official `./tools/desk deploy` remains unsafe on this dirty worktree.
- C360 Vitest still has 3 pre-existing absolute-vs-relative URL assertion failures.

---

## Rollback

Restore the 13 replaced app files and previous `public/build/manifest.json` from `p-07-09-96-20260908T153059Z`. Delete the 10 new application paths. Leave older hashed Vite files in place (they were not removed). Then `optimize:clear` + `optimize`. Confirm `/var/www/radium-desk` stays `755`. Do **not** restore pickup gateway/matcher files from this backup (they were not replaced). Rollback was **not** required.
