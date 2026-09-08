# Shiprocket create-rejection overlay — RadiumDesk-P-07-09-78

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-78`  
**Implementation:** `1d7a9ad7aba5bb6a6630272c7f3f4c3521934511` (P-07-09-77)  
**Mechanism:** named-file rsync (no `--delete`) from `git archive 1d7a9ad7`. Not `./tools/desk deploy`. No migrate. No Vite. No `.env` change.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-77**. This ticket: **P-07-09-78**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

---

## Why not full `desk deploy`

| Gate | Finding | Class |
|------|---------|-------|
| Local worktree | Dirty unrelated statutory docs + untracked investigation markdown | VERIFIED |
| HEAD tag | `1d7a9ad7` is not latest semver tag (`v4.0.67` / `5d14a582`) | VERIFIED |
| Pending UPI migrations | `2026_09_04_220000` / `220100` / `220200` still Pending | VERIFIED |
| Official deskd | Would rsync dirty tree, require tag, run unscoped `migrate --force` | VERIFIED |

Same class as P-07-09-70 / P-07-09-74.

---

## Pre-deploy

| Item | Value | Class |
|------|-------|-------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Branch | `main` (ahead of `origin/main` by 4; origin still `931b6b59`) | VERIFIED |
| Local HEAD | `1d7a9ad7aba5bb6a6630272c7f3f4c3521934511` | VERIFIED |
| Server | KVM `srv1910783` / `187.127.129.16` | VERIFIED |
| App path | `/var/www/radium-desk` | VERIFIED |
| PHP | `/usr/local/lsws/lsphp84/bin/php` | VERIFIED |
| Public URL | `https://desk.radiumbox.com` | VERIFIED |
| `release.json` | ABSENT (overlay-on-`v4.0.67`) | VERIFIED |
| Prior overlay | `p-07-09-74-20260908T095140Z` | VERIFIED |
| Shipping | `enabled=true` / `provider=shiprocket` / `http=true` / `HttpShiprocketGateway` | VERIFIED |
| Channel id | KEY_ABSENT (no `SHIPROCKET_CHANNEL_ID` line) | VERIFIED |
| Pickups | `RADDELHI` / `RADIUMUM` | VERIFIED |
| Pincodes | `110019` / `400104` | VERIFIED |
| Migrate `160000` | batch 13 Ran | VERIFIED |

`1d7a9ad7` contains **no** migration.

---

## Overlay set (6 application files)

From `git archive 1d7a9ad7` (not the dirty worktree). Tests/docs/`.env` were **not** copied.

Replaced:

- `app/Services/HardwareFulfilment/Data/HardwareShipmentReadiness.php`
- `app/Services/HardwareFulfilment/HardwareShipmentEligibility.php`
- `app/Services/HardwareFulfilment/HardwareShipmentService.php`
- `app/Services/Shipping/Data/ShiprocketCreateOrderRequest.php`
- `app/Services/Shipping/HttpShiprocketGateway.php`
- `resources/views/inventory/hardware-fulfilments/show.blade.php`

No `--delete`. Credentials, channel id, pickup, courier, SKU, parcel, and payment mode were **not** changed.

---

## File-hash verification

All 6 production files **MATCH** `1d7a9ad7`.

| File | SHA-256 |
|------|---------|
| HardwareShipmentReadiness.php | `a7d76efe72a5f9341d4853813817f03c8de79148ea805dd7b5ec56035a6eaeb5` |
| HardwareShipmentEligibility.php | `78762132fa5767b7fbfea57d53e863ec99cc3dfb7ab25be84e0ddd3ba4e7d63e` |
| HardwareShipmentService.php | `14f92b17aadc01baeb044d16727065007c379a9eccdced6c3200990e560b7f85` |
| ShiprocketCreateOrderRequest.php | `f800dfdd383d7582bc00bc7a02ae4f579f5fda8dd7f115a1bcc853ead0639706` |
| HttpShiprocketGateway.php | `b9c5945a74fbee38ba4175863a6684c8b80d685f5c95cf702219748b663d1917` |
| show.blade.php | `850bcaed1b58e1175e6a8ef0691bd26f4c1e55f9af1a63c8890fa1f87ff30da2` |

`php -l` clean on all 6. Then `artisan optimize:clear` + `optimize`.

---

## RDE318421

Unchanged by this overlay. `updated_at` still `2026-09-08 16:04:30` (fulfilment) / `16:05:08` (shipment).

| Field | Before | After |
|-------|--------|-------|
| Fulfilment | 1 / `invoice_issued` | same |
| Serial | `10532319` | same |
| Invoice | 294 / `INV-67275` | same |
| Payment | paid | same |
| Country | India | same |
| Parcel snapshot | `0.24 kg · 14×9×7` | same |
| Commerce parcel | NULL | same |
| Courier | Delhivery_Surface / 15084 | same |
| Shipment 1 | failed / provider_rejected / attempts 1 | same |
| last_error | `Oops! Invalid Data.` | same |
| Provider order / shipment / AWB / label | null | same |

Read-only `inspect()` now surfaces the stored message (no DB write):

- `providerRejection` = `Shiprocket rejected shipment creation: Oops! Invalid Data.`
- status = `Not created — provider rejected`
- generic blocker `Provider validation error` is gone
- `canCreate` = false (`Courier selection required` because options are no longer treated as a fresh valid selection)
- Create / AWB / label forms **absent** on GET show (200)

`/up` = 200.

---

## Backup / rollback

Backup: `/var/www/radium-desk/storage/app/private/overlays/p-07-09-78-20260908T105306Z`

Contains the 6 pre-overlay files.

If rollback is required: restore those 6 files, then `optimize:clear` + `optimize`. No migrate to undo.

Rollback was **not** required.

---

## Not performed

- Second Create Shipment / any Shiprocket write: **NO — Not performed.**
- AWB / label / pickup / manifest: **NO — Not performed.**
- Parcel / payment / invoice / serial / country / fulfilment state write: **NO — Not performed.**
- Channel ID invent or `.env` change: **NO — Not performed.**
- Courier change: **NO — Not performed.**
- Full `./tools/desk deploy`: **NO — Not performed.**
- Any migration, including UPI: **NO — Not performed.**
- Vite: **NO — Not performed.**
- Push: **NO — Not performed.**

---

## Completion report

| Field | Value |
|-------|-------|
| Project | Radium Desk |
| Repository | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` |
| Branch | `main` |
| Before SHA | production files = P-07-09-74 overlay hashes; repo HEAD `1d7a9ad7` |
| After SHA | production files = `1d7a9ad7`; repo see report commit |
| Target implementation SHA | `1d7a9ad7aba5bb6a6630272c7f3f4c3521934511` |
| Prompt ID | `RadiumDesk-P-07-09-78` |
| Production server/path | `srv1910783` `/var/www/radium-desk` |
| Backup | `storage/app/private/overlays/p-07-09-78-20260908T105306Z` |
| Deployment mechanism | named-file rsync, no `--delete` |
| Files deployed | 6 |
| Migration | **NO — Not performed.** |
| File-hash verification | 6/6 MATCH `1d7a9ad7` |
| PHP lint | clean |
| Production health | `/up` 200; show/1 200 |
| RDE318421 changed | **NO — Not performed.** |
| Shiprocket calls | **NO — Not performed.** |
| Shipment created | **NO — Not performed.** |
| AWB | **NO — Not performed.** |
| Label | **NO — Not performed.** |
| Pickup | **NO — Not performed.** |
| Manifest | **NO — Not performed.** |
| Provider error | still `Oops! Invalid Data.` (unchanged stored row) |
| Provider error fields available for next attempt | Yes, after the next authorized create; this attempt’s field list remains UNKNOWN |
| Pushed | **NO — Not performed.** |
| Deployed | Yes — overlay |
| Rollback status | Available, not used |
| Remaining risks | Next authorized create must refresh courier options, then search-before-create. Channel id still empty. Do not invent it. |
