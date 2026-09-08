# Hardware Fulfilment workflow production deploy — RadiumDesk-P-07-09-68

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-68`  
**Source commit:** `c297a0ae9852bd5f7563b33e48b479c4bf6f971c`  
**Includes:** P-07-09-66 courier workflow + P-07-09-67 dashboard navigation (on top of already-live P-07-09-64/65 snapshot/country).  
**Mechanism:** named-file rsync (no `--delete`) + Vite `public/build` rsync (no `--delete`) + path-scoped migrate `2026_09_08_150000`. Not `./tools/desk deploy`.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-67**. This ticket: **P-07-09-68**.

---

## Why not full `desk deploy`

| Gate | Finding |
|---|---|
| Local worktree | Dirty unrelated statutory docs + untracked investigation markdown |
| HEAD tag | `c297a0ae` is not the latest semver tag (`v4.0.67` / `5d14a582`) |
| Pending UPI migrations | `2026_09_04_220000` / `220100` / `220200` still Pending |
| Official deskd | Requires clean tree + HEAD == latest semver tag; rsync `--delete`; unscoped `migrate --force`; seeds permissions |

Official `./tools/desk deploy` would copy the dirty tree and apply UPI migrations. Out of scope.

Same class as P-07-09-41 / P-07-09-52 / P-07-09-56 / P-07-09-61 / P-07-09-65.

---

## Pre-deploy (verified)

| Item | Value |
|---|---|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` |
| Remote | `git@github.com:radium-foundation/radium-desk.git` |
| Branch | `main` = `origin/main` |
| Local / origin HEAD | `c297a0ae9852bd5f7563b33e48b479c4bf6f971c` |
| Server | KVM `srv1910783` / `187.127.129.16` |
| App path | `/var/www/radium-desk` |
| PHP | `/usr/local/lsws/lsphp84/bin/php` |
| Database | `radium_desk` @ `127.0.0.1` |
| Public URL | `https://desk.radiumbox.com` |
| `release.json` | `4.0.67` / `v4.0.67` / `5d14a582` (unchanged) |
| Prior fulfilment overlay | P-07-09-65 (`32b97ece` files + migrate `130000` batch 11) |
| Shipping | `enabled=false` / `provider=none` / `http=false` / `NullShiprocketGateway` |
| Channel id | KEY_ABSENT (not invented) |
| Pickup nicknames | PRESENT_NON_EMPTY (unchanged) |
| Pickup pincodes | KEY_ABSENT before this ticket |

RDE318421 before: `invoice_issued`, snapshot NULL, country overlay NULL, `commerce_orders.parcel` NULL, no shipment/AWB, `updated_at` `2026-09-08 09:59:30`, courier columns absent.

---

## Overlay set (30 application files from `git show c297a0ae`)

Tests, docs, and `.env` were not copied (except the two documented pincode keys appended later). No `--delete`.

Post-overlay SHA-256 of all 30 production files **MATCH** `c297a0ae`.

Frontend: local `npm run build`, then `public/build/` rsync **without** `--delete`.

| Entry | New hashed asset |
|---|---|
| `resources/js/pages/dashboard.js` | `assets/dashboard-CVcLVN9Z.js` |
| `resources/css/app.css` | `assets/app-If91b3g-.css` |

Old hashed files remain on disk. Login HTML references the new CSS. Dashboard HTML (user 2) references both new hashes. Compiled dashboard JS contains `data-hardware-fulfilment-link`.

Then `artisan optimize:clear` + `optimize`.

---

## Migration

```
php artisan migrate --force --path=database/migrations/2026_09_08_150000_add_hardware_fulfilment_courier_quote.php
```

Result: **DONE** (164.19ms). Batch **[12] Ran**.

Unscoped `migrate --force` was **not** run. UPI `220000` / `220100` / `220200` remain **Pending**. Snapshot/country `130000` remains batch 11 Ran.

No fulfilment/order backfill. New courier columns nullable.

---

## Configuration

Appended only (keys were absent):

- `SHIPROCKET_PICKUP_PINCODE_DELHI=110019`
- `SHIPROCKET_PICKUP_PINCODE_MUMBAI=400104`

Did **not** set `SHIPROCKET_ENABLED`, `SHIPROCKET_PROVIDER`, `SHIPROCKET_HTTP_ENABLED`, or `SHIPROCKET_CHANNEL_ID`. Credentials and pickup nicknames unchanged. `NullShiprocketGateway` still bound.

`RolePermissionSeeder` was **not** re-run. `hardware.fulfilment.operate` unchanged. `hardware.fulfilment.ship` does not exist.

---

## Production verification

| Check | Result |
|---|---|
| File hashes | 30/30 MATCH `c297a0ae` |
| Migration `150000` | batch 12 Ran |
| Migration `130000` | still batch 11 Ran |
| `/up` `/login` | 200 |
| Unauth list/show | 302 → `/login` |
| Hardware Dashboard as user 2 | 200; clickable rows; new Vite hashes |
| RDE318421 row | `Fulfilment / Shipment` → `/inventory/hardware-fulfilments/1` |
| RDE318477 row (no fulfilment) | no fulfilment link, no invented id |
| 360 for RDE318421 | Related includes `Open Order` + `Fulfilment / Shipment` → show/1 |
| `/inventory/hardware-fulfilments` | 200; lists RDE318421 |
| Fulfilment 1 show | 200; Shipment / Not created / Parcel; `Shiprocket (not called)` |
| inspect() | `canCreate=false`, `canFetchCourierOptions=false`, `canSelectCourier=false`, `canAssignAwb=false` |
| Blockers | Shipping address incomplete; Parcel dimensions unavailable; Shiprocket configuration incomplete |
| Create Shipment / Assign AWB / Get Courier Options | hidden (not ready / Shiprocket disabled) |
| Gateway | `NullShiprocketGateway`; enabled/http false |
| Public new CSS/JS | 200; sizes match local build |
| Stale dashboard/CSS in live HTML | NO |
| laravel.log after 13:40 IST | no new ERROR / Shiprocket lines from this overlay |
| RDE318421 | unchanged (see below) |

`inspect()` was read-only and did not persist.

---

## RDE318421 after deploy

| Field | Value |
|---|---|
| State | `invoice_issued` |
| Parcel snapshot | **null** |
| Country overlay | **null** |
| Structured country | key **absent** |
| `commerce_orders.parcel` | **null** |
| Courier snapshot / selected courier | **null** |
| Shipment / AWB | none |
| `updated_at` | `2026-09-08 09:59:30` |

---

## Backup / rollback

Backup: `/var/www/radium-desk/storage/app/private/overlays/p-07-09-68-20260908T081447Z`

Contains the 21 replaced files (including previous `manifest.json`), `STATE.before`, `STATE.after`.

`desk rollback` remains **disabled** on KVM.

If rollback is required:

1. Restore the backed-up replaced files.
2. Delete the new PHP files added by this overlay (courier request/data objects, navigation, migrate file).
3. Confirm batch 12 contains solely `2026_09_08_150000`, then `migrate:rollback --force --step=1`.
4. Remove the two appended pincode keys if rolling configuration back.
5. Restore previous `public/build/manifest.json` (old hashed assets were not deleted).
6. `optimize:clear` + `optimize`.

Rollback was **not** required.

---

## Not performed

- Attach RDE318421 parcel snapshot: **NO — Not performed.**
- Correct RDE318421 country: **NO — Not performed.**
- Modify `commerce_orders.parcel`: **NO — Not performed.**
- Shiprocket HTTP / enable / channel id / create / AWB: **NO — Not performed.**
- Full `./tools/desk deploy`: **NO — Not performed.**
- UPI migrations `220000` / `220100` / `220200`: **NO — Not performed.**
- Permission seeder / new `hardware.fulfilment.ship`: **NO — Not performed.**
- New tag: **NO — Not performed.**
- Browser click-through as Avinash in Cloudflare session: **NO — Not performed.** (server-rendered HTML + compiled JS verified)
