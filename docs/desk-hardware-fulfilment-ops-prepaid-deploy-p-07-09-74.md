# Hardware fulfilment operational + prepaid overlay — RadiumDesk-P-07-09-74

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-74`  
**Implementation:** `45cf0d59a19c3a1c91525f72196913f86d9e796f` (P-07-09-72 + P-07-09-73) plus a production MySQL FK-name fix on `2026_09_08_160000`.  
**Mechanism:** named-file rsync (no `--delete`) + path-scoped migrate `2026_09_08_160000`. Not `./tools/desk deploy`. No Vite. No `.env` change.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-73**. This ticket: **P-07-09-74**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

---

## Why not full `desk deploy`

| Gate | Finding | Class |
|------|---------|-------|
| Local worktree | Dirty unrelated statutory docs + untracked investigation markdown | VERIFIED |
| HEAD tag | `45cf0d59` is not latest semver tag (`v4.0.67` / `5d14a582`) | VERIFIED |
| Pending UPI migrations | `2026_09_04_220000` / `220100` / `220200` still Pending | VERIFIED |
| Official deskd | Would rsync dirty tree, require tag, run unscoped `migrate --force` | VERIFIED |

Same class as P-07-09-65 / P-07-09-68 / P-07-09-70.

---

## Pre-deploy

| Item | Value | Class |
|------|-------|-------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Branch | `main` = `origin/main` | VERIFIED |
| Local / origin HEAD | `45cf0d59a19c3a1c91525f72196913f86d9e796f` | VERIFIED |
| Server | KVM `srv1910783` / `187.127.129.16` | VERIFIED |
| App path | `/var/www/radium-desk` | VERIFIED |
| PHP | `/usr/local/lsws/lsphp84/bin/php` | VERIFIED |
| Database | `radium_desk` @ `127.0.0.1` | VERIFIED |
| Public URL | `https://desk.radiumbox.com` | VERIFIED |
| `release.json` | ABSENT (overlay-on-`v4.0.67`) | VERIFIED |
| Prior overlays | P-07-09-71 env enablement; P-07-09-70 show UI files | VERIFIED |
| Shipping | `enabled=true` / `provider=shiprocket` / `http=true` / `HttpShiprocketGateway` | VERIFIED |
| Channel id | EMPTY / KEY_ABSENT | VERIFIED |
| Pickups | `RADDELHI` / `RADIUMUM` | VERIFIED |
| Pincodes | `110019` / `400104` | VERIFIED |
| Migrate `130000` | batch 11 Ran | VERIFIED |
| Migrate `150000` | batch 12 Ran | VERIFIED |
| Migrate `160000` | absent before this ticket | VERIFIED |

`45cf0d59` is reachable on `main`. Application files were copied from that commit, not from the dirty worktree. Tests/docs/`.env` were **not** copied.

---

## RDE318421 before this overlay (operator-changed since P-07-09-70)

The prompt assumed parcel snapshot still NULL. Production at deploy time was already further along. **This overlay did not attach the snapshot or fetch courier options.**

| Field | Value | Class |
|-------|-------|-------|
| Fulfilment | `1` / `RDE318421` | VERIFIED |
| State | `invoice_issued` | VERIFIED |
| Serial | `10532319` | VERIFIED |
| Invoice | `294` / `INV-67275` | VERIFIED |
| Parcel snapshot | PRESENT `0.24 kg · 14×9×7 cm` (snapshotted `2026-09-08 14:48:07` by user 2) | VERIFIED |
| `commerce_orders.parcel` | NULL | VERIFIED |
| Country overlay | NULL; structured country KEY_ABSENT | VERIFIED |
| Courier options snapshot | PRESENT; serviceability `cod=0`; 8 options; no selected courier | VERIFIED |
| Shipment / AWB / label / manifest / pickup | none | VERIFIED |
| `updated_at` | `2026-09-08 15:13:37` | VERIFIED |

---

## Overlay set (30 application files)

From `git diff 1575ed35..45cf0d59` excluding tests/docs.

New: package-evidence enum/model/service/requests; collection-mode enum/resolver; label/manifest/pickup/ready-for-pickup requests; documents service; migration `160000`.

Replaced: gateway contract + HTTP/Null adapters; fulfilment controller/model/eligibility/mapper/courier-options; shipment model/readiness; selected create/fetch/select requests; show blade; routes.

Not copied: tests, docs, `.env`, Vite assets, seeder.

No `--delete`. Credentials and Shiprocket enablement flags unchanged.

---

## Migration

First run of `2026_09_08_160000` **FAILED**: MySQL identifier

`hardware_fulfilment_package_evidences_hardware_fulfilment_id_foreign`

exceeds 64 characters. Laravel left the migration **Pending**. DDL had auto-committed an empty package-evidence table plus the new shipment/fulfilment columns.

Fix: name the fulfilment FK `hw_pkg_ev_fulfilment_fk` (same pattern as `hw_pkg_ev_user_fk`).

Partial schema was rolled back (empty table dropped; unused new columns dropped). Path-scoped migrate then:

```
php artisan migrate --force --path=database/migrations/2026_09_08_160000_add_hardware_fulfilment_operational_workflow.php
```

Result: **DONE**. Batch **[13] Ran**.

Unscoped `migrate --force` was **not** run. UPI `220000` / `220100` / `220200` remain **Pending**.

Then `artisan optimize:clear` + `optimize`.

---

## File-hash verification

29/29 application files **MATCH** `45cf0d59`.  
Migration file **MATCH** the post-fix worktree (not the original `45cf0d59` long-FK version).

PHP lint on key overlaid files: clean.

---

## Production verification (user 2 via Laravel kernel)

| Check | Result |
|-------|--------|
| Hardware Dashboard | 200; Fulfilment / Shipment → `/inventory/hardware-fulfilments/1` |
| Show `/inventory/hardware-fulfilments/1` | 200 |
| Serial | `10532319` **Allocated** |
| Invoice | `INV-67275` + View invoice → `/finance/invoices/294` 200 |
| Country | India; overlay still NULL |
| Parcel | snapshot attached (pre-existing); `commerce_orders.parcel` still NULL |
| Payment mode UI | **Prepaid**; **`COD yes` absent** |
| inspect() collection mode | `prepaid` / `Prepaid` |
| Get Courier Options / Select Courier | visible because snapshot + options already existed; **not submitted** |
| Create Shipment | hidden (courier not selected) |
| Generate / Print / Download Manifest | hidden / Print absent / Download absent (no URL) |
| Package evidence kinds | `package_before_label`, `package_label_applied`; 0 rows for fulfilment 1 |
| Permission | `hardware.fulfilment.operate` present; `hardware.fulfilment.ship` absent |
| Gateway | `HttpShiprocketGateway`; enabled/http true |
| Channel id | still empty |
| Manifest code | `POST /manifests/generate` present; `/manifests/print` and `/orders/print/manifest` absent |
| `/up` `/login` | 200; CF `DYNAMIC`; `no-cache, private` |
| Unauth show | 302 → `/login` |
| RDE318421 after | identical to before; `updated_at` still `2026-09-08 15:13:37` |
| laravel.log | first-attempt FK `1059` ERROR at 15:21:51 IST (this ticket, then fixed). No Shiprocket write errors. Unrelated Interakt INFO after 15:22. |

Interactive Cloudflare session as Avinash: **NO — Not performed.**  
Live serviceability / create / AWB / label / manifest / pickup: **NO — Not performed.**

---

## Backup / rollback

Backup: `/var/www/radium-desk/storage/app/private/overlays/p-07-09-74-20260908T095140Z`

Contains the 18 replaced files that existed before this overlay + `STATE.after.json`. New files had no prior version.

`desk rollback` remains disabled on KVM.

If rollback is required:

1. Restore the 18 backed-up files.
2. Remove the 12 files that were new in this overlay.
3. Path-scoped migrate rollback of `2026_09_08_160000` only (drops empty package-evidence table and the unused shipment/fulfilment columns).
4. `optimize:clear` + `optimize`.

Do not roll back P-07-09-65 / 68 / 70 / 71. Do not touch UPI migrations. Do not restore `.env`.

Rollback was **not** required.

---

## Not performed

- Full `./tools/desk deploy`: **NO — Not performed.**
- Unscoped migrate / UPI `220000` / `220100` / `220200`: **NO — Not performed.**
- Parcel snapshot attach: **NO — Not performed.** (already present)
- Country write: **NO — Not performed.**
- Courier select / shipment / AWB / label / manifest / pickup: **NO — Not performed.**
- Live Shiprocket write or serviceability refetch: **NO — Not performed.**
- COD enablement / credential / channel-id change: **NO — Not performed.**
- Vite build / cache purge: **NO — Not performed.**
- New `hardware.fulfilment.ship`: **NO — Not performed.**
- New tag: **NO — Not performed.**
- RDE318421 shipment transaction: **NO — Not performed.**
