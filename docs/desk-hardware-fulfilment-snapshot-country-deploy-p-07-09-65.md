# Parcel snapshot + country overlay production deploy — RadiumDesk-P-07-09-65

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-65`  
**Source commit:** `32b97eced104ccf34b26af459dbdebce9e155228`  
**Mechanism:** named-file rsync (no `--delete`) + path-scoped migrate + `RolePermissionSeeder`. Not `./tools/desk deploy`.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-64**. This ticket: **P-07-09-65**.

---

## Why not full `desk deploy`

| Gate | Finding |
|---|---|
| Local worktree | Dirty unrelated statutory docs + untracked investigation markdown |
| HEAD tag | `32b97ece` is not the latest semver tag (`v4.0.67` / `5d14a582`) |
| Pending UPI migrations | `220000` / `220100` / `220200` still Pending |
| Official deskd | Requires clean tree + HEAD == latest semver tag; runs unscoped `migrate --force` |

Official `./tools/desk deploy` would rsync the dirty tree and apply UPI migrations. Out of scope.

Same class as P-07-09-41 / P-07-09-52 / P-07-09-56 / P-07-09-61.

---

## Pre-deploy (verified)

| Item | Value |
|---|---|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` |
| Remote | `git@github.com:radium-foundation/radium-desk.git` |
| Branch | `main` = `origin/main` |
| Local / origin HEAD | `32b97eced104ccf34b26af459dbdebce9e155228` |
| Server | KVM `srv1910783` / `187.127.129.16` |
| App path | `/var/www/radium-desk` |
| PHP | `/usr/local/lsws/lsphp84/bin/php` |
| Database | `radium_desk` @ `127.0.0.1` |
| Public URL | `https://desk.radiumbox.com` |
| `release.json` | ABSENT (still overlay-on-4.0.67) |
| Shipping | `enabled=false` / `provider=none` / `http=false` / `NullShiprocketGateway` |
| Sibling | `/var/www/radiumbox.com` not touched |

Replaced files that already existed matched `b6b6568c` except the older fulfilment controller/show (serial + raw `storeShipment`, no FormRequest). Those were brought forward to `32b97ece` as intended. New files were absent.

RDE318421 before: `invoice_issued`, parcel NULL, no snapshot column, country key absent, no shipment/AWB, `updated_at` `2026-09-08T09:59:30+05:30`.

---

## Overlay set (15 files from `git show 32b97ece`)

New:

- `app/Http/Requests/Inventory/AttachHardwareFulfilmentParcelRequest.php`
- `app/Http/Requests/Inventory/CorrectHardwareFulfilmentShippingCountryRequest.php`
- `app/Http/Requests/Inventory/CreateHardwareFulfilmentShipmentRequest.php` (absent on host before)
- `app/Services/HardwareFulfilment/HardwareFulfilmentCountryCorrectionService.php`
- `app/Services/HardwareFulfilment/HardwareFulfilmentParcelSnapshotService.php`
- `database/migrations/2026_09_08_130000_add_hardware_fulfilment_shipment_overlays.php`

Replaced:

- `app/Http/Controllers/Inventory/HardwareFulfilmentSerialController.php`
- `app/Models/HardwareFulfilment.php`
- `app/Services/HardwareFulfilment/Data/HardwareShipmentReadiness.php`
- `app/Services/HardwareFulfilment/HardwareShipmentEligibility.php`
- `app/Services/HardwareFulfilment/HardwareShipmentService.php`
- `app/Support/HardwareFulfilment/HardwareFulfilmentAccess.php`
- `database/seeders/RolePermissionSeeder.php`
- `resources/views/inventory/hardware-fulfilments/show.blade.php`
- `routes/web.php`

Tests/docs/`.env` were not copied. No `--delete`.

Post-overlay SHA-256 of all 15 production files **MATCH** `git show 32b97ece`.

Then `artisan optimize:clear` + `optimize`.

---

## Migration

```
php artisan migrate --force --path=database/migrations/2026_09_08_130000_add_hardware_fulfilment_shipment_overlays.php
```

Result: **DONE** (129.80ms). Batch **[11] Ran**.

Unscoped `migrate --force` was **not** run. UPI migrations remain **Pending**.

No fulfilment/order backfill. New columns nullable.

---

## Permission seed

Expanded `permissionsForRole` preview: **only** add `hardware.fulfilment.correct-country` to `admin` / `operations_admin` / `superadmin`. **Zero** would-remove.

```
php artisan db:seed --class=RolePermissionSeeder --force
```

| Role | `hardware.fulfilment.correct-country` |
|---|---|
| admin / operations_admin / superadmin | **YES** |
| hardware_team | **NO** |

Avinash Jha (user 2, `admin` + `hardware_team`) receives it via **admin**. Sushant (user 8, `hardware_team` without admin) does not.

---

## Production verification

| Check | Result |
|---|---|
| File hashes | 15/15 MATCH `32b97ece` |
| Migration | batch 11 Ran |
| `/inventory/hardware-fulfilments` as user 2 | 200; lists RDE318421 INVOICE ISSUED |
| Fulfilment 1 show as user 2 | 200; INV-67275, serial 10532319, Paid |
| Blockers | Shipping address incomplete; Parcel dimensions unavailable; Shiprocket configuration incomplete |
| Parcel source | Unavailable |
| Catalog pack | `0.24 kg · 14×9×7 cm` · catalog / verified |
| Country | Missing — not inferred; Record country visible to admin |
| hardware_team country | Sushant `can_correct=false`; show 403 (existing Delhi branch scope) |
| Create Shipment | hidden (`canCreate=false`) |
| Assign AWB | hidden |
| inspect() write | none; snapshot/overlay still null; `updated_at` unchanged |
| Unauth list/show | 302 → `/login` |
| `/up` `/login` | 200 |
| Shiprocket API | no laravel.log lines; Null gateway; env enable/provider/http/channel KEY_ABSENT |
| RDE318421 | unchanged (see below) |

Attach button is visible (rules would allow a later authorized attach). **Not clicked.**

---

## RDE318421 after deploy

| Field | Value |
|---|---|
| State | `invoice_issued` |
| Parcel snapshot | **null** |
| Country overlay | **null** |
| Structured country | key **absent** |
| `commerce_orders.parcel` | **null** (desk-wide still 0) |
| Shipment / AWB | none |
| `updated_at` | `2026-09-08T09:59:30+05:30` |

---

## Backup / rollback

Backup: `/var/www/radium-desk/storage/app/private/overlays/p-07-09-65-20260908T073735Z`

Contains the nine replaced files, `MANIFEST.before`, `STATE.before`, `MANIFEST.after`, `STATE.after`.

`desk rollback` remains **disabled** on KVM.

If rollback is required:

1. Restore the nine backed-up files.
2. Delete the six new files listed above (including `CreateHardwareFulfilmentShipmentRequest.php` if rolling back to the pre-overlay absence).
3. Confirm batch 11 contains solely `2026_09_08_130000`, then `migrate:rollback --force --step=1`.
4. Restore previous `RolePermissionSeeder.php` and re-run `db:seed --class=RolePermissionSeeder --force`.
5. `optimize:clear` + `optimize`.

Rollback was **not** required.

---

## Not performed

- Attach parcel snapshot to RDE318421: **NO — Not performed.**
- Write country to RDE318421: **NO — Not performed.**
- Modify `commerce_orders.parcel`: **NO — Not performed.**
- Shiprocket call / enable / HTTP bind / create / AWB: **NO — Not performed.**
- Full `./tools/desk deploy`: **NO — Not performed.**
- New tag: **NO — Not performed.**
