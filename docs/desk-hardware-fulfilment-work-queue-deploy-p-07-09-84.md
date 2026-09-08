# Hardware Fulfilment Work Queue overlay — RadiumDesk-P-07-09-84

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-84`  
**Implementation:** `9664345d44d34cc5443127d1a7c1748899d03aa2` (P-07-09-83)  
**Mechanism:** named-file rsync (no `--delete`) from `git archive 9664345d`. Not `./tools/desk deploy`. No migrate. No Vite. No `.env` change.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-83**. This ticket: **P-07-09-84**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

---

## Why not full `desk deploy`

| Gate | Finding | Class |
|------|---------|-------|
| Local worktree | Dirty unrelated statutory docs + untracked investigation markdown + uncommitted city-clamp DTO | VERIFIED |
| HEAD tag | `9664345d` is not latest semver tag (`v4.0.67`) | VERIFIED |
| Pending UPI migrations | `2026_09_04_220000` / `220100` / `220200` still Pending | VERIFIED |
| Official deskd | Would rsync dirty tree, require tag, run unscoped `migrate --force` | VERIFIED |

Same class as P-07-09-70 / P-07-09-74 / P-07-09-78 / P-07-09-80.

Push is **not** required for named-file overlay. **NO — Not performed.**

---

## Pre-deploy

| Item | Value | Class |
|------|-------|-------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Branch | `main` (ahead of `origin/main` by 6; origin `931b6b59`) | VERIFIED |
| Local HEAD | `9664345d44d34cc5443127d1a7c1748899d03aa2` | VERIFIED |
| Server | KVM `srv1910783` / `187.127.129.16` | VERIFIED |
| App path | `/var/www/radium-desk` | VERIFIED |
| PHP | `/usr/local/lsws/lsphp84/bin/php` | VERIFIED |
| Database | `radium_desk` @ `127.0.0.1` | VERIFIED from prior overlays; not written |
| Public URL | `https://desk.radiumbox.com` | VERIFIED `/up` 200 |
| `release.json` | ABSENT (overlay-on-`v4.0.67`) | VERIFIED |
| Prior overlay | `p-07-09-80-20260908T111110Z` | VERIFIED |
| Shipping | `enabled=true` / `provider=shiprocket` / `http=true` / `HttpShiprocketGateway` | VERIFIED |
| Channel id | `579891` (unchanged) | VERIFIED |
| Auto-issue / worker mint | false / false | VERIFIED |
| Migrate `160000` | batch 13 Ran | VERIFIED |

RDE318421 before this overlay (unchanged afterwards):

| Field | Value |
|-------|-------|
| Fulfilment | `1` / `awb_assigned` / `updated_at` `2026-09-08 16:55:54` |
| Serial | `10532319` |
| Invoice | `294` / `INV-67275` |
| Provider shipment | `1568724940` |
| Courier | `Delhivery_Surface` / `15084` |
| AWB | `284931178067631` |
| Label | present |
| Package evidence | none |
| Pickup / manifest | none |
| Shipment `updated_at` | `2026-09-08 16:56:08` |
| Attempts | 2 |

---

## Overlay set (13 application files from `git archive 9664345d`)

Tests, docs, `.env`, Vite assets, migrations, and the dirty city-clamp DTO were **not** copied. No `--delete`.

P-07-09-76 Awaiting Fulfilment was never overlaid. Production was missing those classes. Copying only the 9 files *changed* in `9664345d` would 500, because Work Queue injects `HardwareAwaitingFulfilmentQueue`. The four additional files already exist **at** `9664345d` and are required for that commit to boot.

Replaced (3):

- `app/Http/Controllers/Inventory/HardwareFulfilmentSerialController.php`
- `app/Services/HardwareFulfilment/HardwareFulfilmentEligibility.php`
- `resources/views/inventory/hardware-fulfilments/index.blade.php`

New (10):

- `app/Enums/HardwareFulfilmentOperationalStage.php`
- `app/Enums/HardwareAwaitingFulfilmentReason.php`
- `app/Services/HardwareFulfilment/HardwareFulfilmentWorkQueue.php`
- `app/Services/HardwareFulfilment/HardwareAwaitingFulfilmentQueue.php`
- `app/Services/HardwareFulfilment/Data/HardwareFulfilmentOperationalClassifier.php`
- `app/Services/HardwareFulfilment/Data/HardwareFulfilmentOperationalRow.php`
- `app/Services/HardwareFulfilment/Data/HardwareFulfilmentOperationalSummary.php`
- `app/Services/HardwareFulfilment/Data/HardwareAwaitingFulfilmentClassifier.php`
- `app/Services/HardwareFulfilment/Data/HardwareAwaitingFulfilmentRow.php`
- `app/Services/HardwareFulfilment/Data/HardwareAwaitingFulfilmentSummary.php`

Post-overlay SHA-256 of all 13 production files **MATCH** `9664345d`. All 12 PHP files pass `php -l`. Shipping DTO remains `9ad445d2` (P-07-09-80). Then `artisan optimize:clear` + `optimize`.

---

## Production verification (user 2 Avinash Jha via Laravel kernel)

Browser click-through was **unavailable**. HTTP/application verification:

| Check | Result |
|-------|--------|
| `https://desk.radiumbox.com/up` | 200 |
| Unauth `/inventory/hardware-fulfilments` | 302 |
| Auth GET index (default) | 200; Work Queue is the default list |
| Date banner | `Active date range (IST): 2026-09-05 00:00` → current IST |
| Open Fulfilments tab | 200; RDE318421 present |
| Awaiting Fulfilment tab | 200; RDE318421 **absent**; no create button |
| RDE318421 on Work Queue | one table row + mention in the info banner (not a second queue row) |
| Next action | `Label/Packing Pending` / `Record Package / Label-Applied Evidence` |
| Bulk controls | Create All / Create fulfilment / Initialize fulfilment **absent** |
| Shipment/AWB/pickup/manifest POST on index | **absent** |
| Index forms | layout search (GET) + logout (POST) + Work Queue filter (GET) |
| Show `/inventory/hardware-fulfilments/1` | 200; AWB + label URL present |
| `laravel.log` `create/adhoc` | 0 |
| Fulfilment / shipment counts | still 1 / 1 |

GET only. No allocate / invoice / create / AWB / pickup / manifest POST.

---

## Counts vs P-07-09-83 baseline

| | P-07-09-83 report | After this overlay | |
|--|--:|--:|--|
| Qualifying | 13 | **7** | 6 review + 1 fulfilment |
| Awaiting Fulfilment (review) | 12 | **6** | see below |
| In-progress fulfilment | 1 | **1** | RDE318421 |
| Blocked / review | 9 | **9** | 7 frozen + HOLD `RDE318438` + blocked `RDE318400` |
| Work-list rows | 22 | **16** | 7 + 9 |
| Excluded | 21 | **21** | unpaid 0 + Desk-completed 2 + RIN 19 |

The six review-shaped orders still exist and still have no fulfilment:

`RDE318477`, `RDE318482`, `RDE318486`, `RDE318487`, `RDE318489`, `RDE318490`

They are hidden because Work Queue compares `orders.created_at` (stored/cast `Asia/Kolkata`) to a **UTC-converted** `now`. App timezone is `Asia/Kolkata`. At verify time `now` UTC was `11:47`; those six `created_at` values are `13:20`–`16:24` the same calendar day, so SQL excludes them. The on-screen range still says through current IST. **This is a `9664345d` filter bug, not a data change.** Not fixed in this deployment-only ticket.

Visible review rows: `RDE318401`, `RDE318434`, `RDE318435`, `RDE318437`, `RDE318467`, `RDE318469`.

---

## RDE318421 after overlay

Identical to the pre-overlay snapshot. `updated_at` still `2026-09-08 16:55:54` / shipment `16:56:08`. Provider shipment `1568724940`, AWB `284931178067631`, label present, package evidence still pending.

---

## Backup / rollback

Backup: `/var/www/radium-desk/storage/app/private/overlays/p-07-09-84-20260908T114603Z`

Contains the 3 replaced files plus `NEW_FILES.txt`.

If rollback is required: restore those 3 files, delete the 10 new files listed in `NEW_FILES.txt`, then `optimize:clear` + `optimize`. No migrate to undo.

Rollback was **not** required.

---

## Not performed

- Fulfilment / serial / invoice / shipment / AWB / pickup / manifest writes: **NO — Not performed.**
- RDE318421 mutation: **NO — Not performed.**
- Batch / automatic hardware processing: **NO — Not performed.**
- Full `./tools/desk deploy`: **NO — Not performed.**
- Any migration, including UPI: **NO — Not performed.**
- Vite / `.env` / channel id / credentials: **NO — Not performed.**
- Push: **NO — Not performed.**
- Timezone filter fix: **NO — Not performed.** (deployment-only)
- Other projects: **NO — Not performed.**
