# Hardware fulfilment show UI production deploy — RadiumDesk-P-07-09-70

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-70`  
**Implementation:** `d13857528aba8be5367476c655449df1fddbc191` (P-07-09-69)  
**Mechanism:** named-file rsync (no `--delete`). Not `./tools/desk deploy`. No migrate. No Vite build.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-69**. This ticket: **P-07-09-70**.

---

## Why not full `desk deploy`

| Gate | Finding | Class |
|------|---------|-------|
| Local worktree | Dirty unrelated statutory docs + untracked investigation markdown | VERIFIED |
| HEAD tag | `d1385752` is not latest semver tag (`v4.0.67` / `5d14a582`) | VERIFIED |
| Pending UPI migrations | `2026_09_04_220000` / `220100` / `220200` still Pending | VERIFIED |
| Official deskd | Would rsync dirty tree, require tag, run unscoped `migrate --force` | VERIFIED |

Same class as P-07-09-65 / P-07-09-68.

---

## Pre-deploy

| Item | Value | Class |
|------|-------|-------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Branch | `main` = `origin/main` | VERIFIED |
| Local / origin HEAD | `d13857528aba8be5367476c655449df1fddbc191` | VERIFIED |
| Server | KVM `srv1910783` / `187.127.129.16` | VERIFIED |
| App path | `/var/www/radium-desk` | VERIFIED |
| PHP | `/usr/local/lsws/lsphp84/bin/php` | VERIFIED |
| Database | `radium_desk` @ `127.0.0.1` | INFERRED from prior verified deploys; host path VERIFIED |
| Public URL | `https://desk.radiumbox.com` | VERIFIED |
| `release.json` | ABSENT (overlay-on-`v4.0.67`) | VERIFIED |
| Prior overlay | `p-07-09-68-20260908T081447Z` | VERIFIED |
| Shipping | `enabled=false` / `provider=none` / `http=false` / `NullShiprocketGateway` | VERIFIED |
| Channel id | EMPTY | VERIFIED |
| Migrate `130000` | batch 11 Ran | VERIFIED |
| Migrate `150000` | batch 12 Ran | VERIFIED |

`d1385752` is a reachable commit on `main`. Application files in the worktree match that commit. Tests/docs in the commit were **not** copied.

RDE318421 before: `invoice_issued`; serial `10532319`; invoice 294 `INV-67275`; snapshot NULL; country overlay NULL; structured country KEY_ABSENT; order parcel NULL; no shipment/AWB; `updated_at` `2026-09-08 09:59:30`.

---

## Overlay set (8 application files from `git show d1385752`)

Replaced:

- `app/Http/Controllers/Finance/StatutoryInvoiceController.php`
- `app/Http/Controllers/Inventory/HardwareFulfilmentSerialController.php`
- `app/Services/HardwareFulfilment/Data/HardwareShipmentReadiness.php`
- `app/Services/HardwareFulfilment/HardwareFulfilmentCountryCorrectionService.php`
- `app/Services/HardwareFulfilment/HardwareSerialAllocationService.php`
- `app/Services/HardwareFulfilment/HardwareShipmentEligibility.php`
- `app/Support/HardwareFulfilment/HardwareFulfilmentAccess.php`
- `resources/views/inventory/hardware-fulfilments/show.blade.php`

Not copied: tests, docs, `.env`, Vite assets, migrations, seeder.

Post-overlay SHA-256 of all 8 production files **MATCH** `d1385752`. All 7 PHP files pass `php -l`. Then `artisan optimize:clear` + `optimize`.

---

## Production verification (user 2 Avinash Jha via Laravel kernel)

| Check | Result |
|-------|--------|
| Hardware Dashboard | 200; clickable rows; Fulfilment / Shipment → `/inventory/hardware-fulfilments/1` |
| RDE318477 isolated row | no fulfilment link; no HardwareFulfilment row |
| 360 for RDE318421 | Open Order + Fulfilment / Shipment → show/1 |
| Show `/inventory/hardware-fulfilments/1` | 200 |
| Serial | `10532319` **Allocated**; `1 serial required` absent |
| Invoice | `INV-67275` + View invoice → `/finance/invoices/294` 200 |
| Country UI | Record country / Shipping country input absent |
| inspect() country | `India`; `countryMissing=false`; overlay still NULL |
| Blockers | Parcel packaging not attached; Shipping is not enabled |
| Attach verified packaging | visible; **not submitted** |
| Create / Get Courier Options | hidden |
| Gateway | `NullShiprocketGateway`; enabled/http false |
| HTTP recorded | 0 |
| `/up` `/login` | 200 |
| Unauth show | 302 → `/login`; CF `DYNAMIC`; `no-cache, private` |
| RDE318421 after | identical to before; `updated_at` unchanged |
| laravel.log after 14:10 IST | no new ERROR/Shiprocket/hardware lines from this overlay |

Pre-existing Bonvoice outbox SQLSTATEs at 14:04 / 14:08 IST are unrelated. Interactive Cloudflare session as Avinash: **NO — Not performed.**

---

## Backup / rollback

Backup: `/var/www/radium-desk/storage/app/private/overlays/p-07-09-70-20260908T084033Z`

Contains the 8 replaced files + `STATE.after.json`.

`desk rollback` remains disabled on KVM.

If rollback is required: restore those 8 files from the backup, then `optimize:clear` + `optimize`. No migrate to undo.

Rollback was **not** required.

---

## Not performed

- Parcel snapshot attach: **NO — Not performed.**
- Country overlay / structured country write: **NO — Not performed.**
- Shiprocket enable / HTTP / channel / courier / create / AWB: **NO — Not performed.**
- Full `./tools/desk deploy`: **NO — Not performed.**
- Any migration, including UPI `220000` / `220100` / `220200`: **NO — Not performed.**
- Vite build: **NO — Not performed.**
- Permission seeder / `hardware.fulfilment.ship`: **NO — Not performed.**
- New tag: **NO — Not performed.**
