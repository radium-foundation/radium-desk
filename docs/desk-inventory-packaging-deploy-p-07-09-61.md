# Inventory packaging Stage 1 production deploy — RadiumDesk-P-07-09-61

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-61`  
**Source commit:** `4ff933255188a7ef72330edc038f83ba5f37f0b1`  
**Mechanism:** named-file rsync (no `--delete`) + path-scoped migrate + `RolePermissionSeeder`. Not `./tools/desk deploy`.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-60**. This ticket: **P-07-09-61**.

---

## Why not full `desk deploy`

| Gate | Finding |
|---|---|
| Local worktree | Dirty unrelated statutory docs + untracked investigation markdown |
| HEAD tag | `4ff93325` is not the latest semver tag (`v4.0.67` / `5d14a582`) |
| Pending UPI migrations | `220000` / `220100` / `220200` still Pending |
| Official deskd | Requires clean tree + HEAD == latest semver tag; runs unscoped `migrate --force` |

Official `./tools/desk deploy` would rsync the dirty tree and apply UPI migrations. That is out of scope.

Same class as P-07-09-41 / P-07-09-52 / P-07-09-56.

---

## Production boundary (verified before writes)

| Item | Value |
|---|---|
| Project | Radium Desk |
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` |
| Remote | `git@github.com:radium-foundation/radium-desk.git` |
| Branch | `main` |
| Local / origin HEAD after push | `4ff933255188a7ef72330edc038f83ba5f37f0b1` |
| Server | KVM `srv1910783` / `187.127.129.16` |
| App path | `/var/www/radium-desk` |
| PHP | `/usr/local/lsws/lsphp84/bin/php` |
| Database | `radium_desk` @ `127.0.0.1` |
| Vhost | OpenLiteSpeed `radium-desk` → `desk.radiumbox.com` (`docRoot $VH_ROOT/public/`) |
| Public URL | `https://desk.radiumbox.com` (Cloudflare proxied; A `104.21.42.236` / `172.67.212.65`) |
| `release.json` | unchanged `4.0.67` / `v4.0.67` / `5d14a582` |
| Sibling on host | `/var/www/radiumbox.com` (not touched) |

Pre-overlay SHA-256 of the five replaced files **MATCH** `git show 9a8de4b3` (parent of `4ff93325`). Overlay is that commit’s delta only.

---

## Push

`git push origin main`: `9a8de4b3..4ff93325`.

Verified: local HEAD = `origin/main` = `4ff93325`. Unrelated dirty/untracked docs were not pushed.

---

## Overlay set (10 files from `git show 4ff93325`)

New:

- `app/Http/Controllers/Inventory/ProductPackagingController.php`
- `app/Http/Requests/Inventory/VerifyInventoryProductPackagingRequest.php`
- `app/Models/InventoryProductPackaging.php`
- `database/migrations/2026_09_08_110000_create_inventory_product_packaging_table.php`
- `resources/views/inventory/stock/packaging.blade.php`

Replaced (pre-overlay hashes matched `9a8de4b3`):

- `app/Http/Controllers/Inventory/StockController.php`
- `app/Models/InventoryProduct.php`
- `database/seeders/RolePermissionSeeder.php`
- `routes/web.php`
- `resources/views/inventory/stock/index.blade.php`

Tests/docs/`.env` were not copied. No `--delete`. No Shiprocket / fulfilment / RDE318421 files.

Post-overlay SHA-256 of all ten production files **MATCH** `git show 4ff93325`.

Then `artisan optimize:clear` + `optimize`.

---

## Migration

```
php artisan migrate --force --path=database/migrations/2026_09_08_110000_create_inventory_product_packaging_table.php
```

Result: **DONE** (71.91ms). Batch `[10] Ran`.

Unscoped `migrate --force` was **not** run. UPI migrations remain **Pending**.

Table `inventory_product_packaging` exists. Unique index `inventory_product_packaging_product_unique` on `inventory_product_id`. Row count immediately after migrate: **0** (no historical Shiprocket import).

---

## Permission seed

Pre-seed diff vs `RolePermissionSeeder` map: the **only** pending add was `inventory.packaging.verify` on `admin` / `operations_admin` / `superadmin`. **Zero** would-remove on any role. `hardware_team` unchanged.

```
php artisan db:seed --class=RolePermissionSeeder --force
```

After:

| Role | `inventory.packaging.verify` |
|---|---|
| admin / operations_admin / superadmin | **YES** |
| hardware_team | **NO** |

**Avinash Jha** (user 2, `avinash@radiumbox.com`): roles `admin` + `hardware_team`. Receives the permission **via `admin`**. `hardware_team` was not broadened. Direct extra grant was not added.

`inventory.products.manage` is not required for packaging; Avinash already had it via `admin`.

---

## Production verification

| Check | Result |
|---|---|
| `/inventory/stock` columns | Product ID, SKU, Product, Pack, Gross weight, L × B × H, Units — present |
| Unverified rows | **Not verified**; dimensions/weight **—**; no guessed 0.24 / 14×9×7 |
| Record pack | Available to Avinash (`canVerifyPackaging` true) |
| Edit pack | Stock list had no Edit pack yet (0 verified catalog rows). Edit executed on disposable test SKU |
| kg / cm | Explicit on Stock Units column and Record pack form (kg/cm only selects) |
| Unauth live GET `/inventory/stock` | 302 → `/login` |
| Unauth live GET packaging | 302 → `/login` |
| Sushant (user 8, hardware_team, no admin) GET | **403** |
| Sushant PUT FormRequest | **AUTHORIZATION_DENIED** |
| Zero / negative / `g` / `mm` | **REJECTED** |
| 1:1 unique | Duplicate insert rejected |
| Product 28 / `RBMFS110L1` | No packaging row. Branch qty unchanged (Delhi 930 / Mumbai 1) |
| RDE318421 | `invoice_issued`; parcel **null**; country **absent**; AWB/shipment **none**; `updated_at` still `2026-09-08T09:59:30+05:30` |
| `commerce_orders.parcel` | still **0** of 478 |
| Shiprocket | `enabled=false` / `http=false` / `provider=none` / `NullShiprocketGateway`. No create/AWB/API |
| Browser logged-in click-through | **NO — Not performed.** No operator browser session in this deploy. Authenticated HTML was rendered via Stock/packaging controllers as user 2. |

### Disposable test record (not a real SKU)

Created only because Stock had no existing packaging row and real products were not to be overwritten:

| Field | Value |
|---|---|
| Product | id **87** / SKU **`PKG-UI-P070961`** / name `Packaging UI deploy test (P-07-09-61)` |
| Record pack | `0.111` kg, `11.10 × 8.80 × 6.60` cm, user 2, notes `P-07-09-61 record-pack verification only` |
| Edit pack | same row id **1** → `0.122` kg, `12.20 × 9.90 × 7.70` cm, notes `P-07-09-61 edit-pack verification only` |
| Verified by / at | Avinash Jha / `2026-09-08T11:37:48+05:30` |

No stock quantity was created for this SKU. It does **not** appear on `/inventory/stock` until stocked. Catalog now has this one verified pack row.

---

## Backup / rollback

Backup: `/var/www/radium-desk/storage/app/private/overlays/p-07-09-61-20260908T060646Z`

Contains the five replaced files, `MANIFEST.before`, `STATE.before`, `MANIFEST.after`, `STATE.after`.

`desk rollback` remains **disabled** on KVM.

If rollback is required:

1. Copy the five backed-up files back.
2. Delete the five new files listed above.
3. `php artisan migrate --force --path=database/migrations/2026_09_08_110000_create_inventory_product_packaging_table.php --pretend` then `migrate:rollback --force --step=1` **only if** batch 10 contains solely this migration (verify first). That drops `inventory_product_packaging` (currently the disposable test row only).
4. Restore previous `RolePermissionSeeder.php` and re-run `db:seed --class=RolePermissionSeeder --force` to drop `inventory.packaging.verify` from admin-team roles.
5. `optimize:clear` + `optimize`.
6. Optionally deactivate/delete product 87.

Rollback was **not** required.

---

## Explicit non-goals (held)

- No Shiprocket eligibility / shipment / API.
- No `commerce_orders.parcel` write.
- RDE318421 not modified.
- Country blocker not solved.
- No historical packaging import.
- No automatic packaging populate.
- No stock quantity / branch-stock semantic change.
- No other project.
- No new tag / `v4.0.68`.
- Full `./tools/desk deploy`: **NO — Not performed.**
