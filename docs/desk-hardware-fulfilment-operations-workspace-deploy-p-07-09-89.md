# Hardware Operations Workspace overlay — RadiumDesk-P-07-09-89

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-89`  
**Implementation:** `a12cde1c7654722d347d1141fb1b15b8a7254db9` (P-07-09-87)  
**Mechanism:** named-file rsync (no `--delete`) from `git archive a12cde1c`. Not `./tools/desk deploy`. No migrate. No Vite. No `.env` change.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-88**. This ticket: **P-07-09-89**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

---

## Why not full `desk deploy`

| Gate | Finding | Class |
|------|---------|-------|
| Local worktree | Dirty unrelated statutory docs + untracked investigation markdown | VERIFIED |
| HEAD tag | `1c72c3cd` is not latest semver tag (`v4.0.67`) | VERIFIED |
| Pending UPI migrations | `2026_09_04_220000` / `220100` / `220200` still Pending (not re-checked this ticket; same class as prior overlays) | INFERRED |
| Official deskd | Would rsync dirty tree, require tag, run unscoped `migrate --force` | VERIFIED |
| P-07-09-88 already live | Pickup services must stay at `96d141b0` | VERIFIED |

Push is **not** required for named-file overlay. **NO — Not performed.**

---

## Pre-deploy

| Item | Value | Class |
|------|-------|--------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Branch | `main` (ahead of `origin/main` `931b6b59` by 14) | VERIFIED |
| Local HEAD | `1c72c3cd` (P-07-09-88 overlay record) | VERIFIED |
| P-07-09-87 UI commit | `a12cde1c` | VERIFIED |
| P-07-09-88 pickup commit | `96d141b0` | VERIFIED |
| Server | KVM `srv1910783` / `187.127.129.16` | VERIFIED |
| App path | `/var/www/radium-desk` | VERIFIED |
| PHP | `/usr/local/lsws/lsphp84/bin/php` | VERIFIED |
| Public URL | `https://desk.radiumbox.com` | VERIFIED |
| `release.json` | ABSENT (overlay-on-`v4.0.67`) | VERIFIED |
| Prior overlay | `p-07-09-88-20260908T123725Z` | VERIFIED |
| Shipping | `enabled=true` / `provider=shiprocket` / `http=true` / `HttpShiprocketGateway` | VERIFIED |
| Auto-issue / worker mint | false / false | VERIFIED |

Controller / show / classifier at `a12cde1c` are **identical** to `96d141b0`. The pickup ticket only changed the six service/DTO files. Overlaying `a12cde1c` UI therefore does not roll pickup logic back.

### P-07-09-88 production hashes before overlay (MATCH `96d141b0`)

| File | SHA-256 |
|------|---------|
| `HardwarePickupRequestOutcome.php` | `d45d71d8…ebfc98f` |
| `HardwareShipmentReadiness.php` | `2a21e60f…4b04478` |
| `HardwareShipmentDocumentsService.php` | `17b73ff7…5948ae` |
| `HardwareShipmentEligibility.php` | `07bca1b7…69166a` |
| `ShiprocketPickupResult.php` | `e6d9804d…f7dfb3` |
| `HttpShiprocketGateway.php` | `1356da29…94a449f` |

These six files were **not** in the overlay set. After overlay they still MATCH `96d141b0`.

---

## Overlay set (19 application files from `git archive a12cde1c`)

Tests, docs, `.env`, Vite assets, migrations, shipping/statutory dirty files, and the six P-07-09-88 pickup files were **not** copied. No `--delete`.

Replaced (14):

- `app/Enums/HardwareAwaitingFulfilmentReason.php`
- `app/Http/Controllers/Inventory/HardwareFulfilmentSerialController.php`
- `app/Services/Customer360Service.php`
- `app/Services/HardwareFulfilment/Data/HardwareFulfilmentOperationalClassifier.php`
- `app/Services/HardwareFulfilment/Data/HardwareFulfilmentOperationalRow.php`
- `app/Services/HardwareFulfilment/Data/HardwareFulfilmentOperationalSummary.php`
- `app/Services/HardwareFulfilment/HardwareFulfilmentWorkQueue.php`
- `app/Support/Customer360/Customer360CardCatalog.php`
- `app/Support/Customer360/Customer360OverflowMenuPresenter.php`
- `resources/css/app.css` (source only; no Vite rebuild — pills use existing dashboard chip classes)
- `resources/views/customer-360/drawer-content.blade.php`
- `resources/views/inventory/hardware-fulfilments/index.blade.php`
- `resources/views/inventory/hardware-fulfilments/show.blade.php`
- `routes/web.php`

New (5):

- `app/Enums/HardwareOperationsSection.php`
- `app/Http/Requests/Inventory/IssueHardwareFulfilmentInvoiceRequest.php`
- `app/Services/HardwareFulfilment/Data/HardwareFulfilmentStepper.php`
- `app/Support/HardwareFulfilment/HardwareFulfilmentCustomer360Presenter.php`
- `resources/views/customer-360/partials/hardware-fulfilment.blade.php`

Post-overlay SHA-256 of all 19 production files **MATCH** `a12cde1c`. All overlaid PHP files pass `php -l`. Then `artisan optimize:clear` + `optimize`.

Backup: `/var/www/radium-desk/storage/app/private/overlays/p-07-09-89-20260908T124520Z`

---

## Tests / lint

P-07-09-87 (`a12cde1c`) was already tested. This ticket made **no source changes**. Reconfirm on the current tree (implementation files unchanged):

| Suite | Result |
|-------|--------|
| Work Queue + Operations sections | passed |
| Dashboard / 360 navigation | passed |
| Awaiting Fulfilment | passed |
| Stepper + classifier | passed |
| Overflow menu | passed |
| Issue Invoice HTTP | passed |
| **37 tests / 365 assertions** | passed |

Pint: **NO — Not performed** (no source edit). Production `php -l` on overlaid PHP: clean.

---

## Production verification (user 2 via Laravel HTTP kernel)

Browser click-through was **unavailable**. Public `https://desk.radiumbox.com/up` was **200** on the pre-overlay check and **403** on later curls from the same host (WAF/edge; not an application 500). Kernel GETs below are **200**.

| Check | Result |
|-------|--------|
| Default `/inventory/hardware-fulfilments` | 200; heading **Hardware Operations** |
| Tabs | Work Queue / Open Fulfilments / Awaiting Fulfilment present |
| Pills | All **24** · Needs Fulfilment **12** · In Progress **1** · Ready for Pickup **0** · Exceptions **11** |
| Needs Fulfilment next action | `Review &amp; Start` (escaped) |
| Exceptions | `Blocked — RIN mapping required` |
| Row 360 hook | `data-hardware-ops-360` present |
| Create All / Create fulfilment / Select All | **absent** |
| Invoice POST on the list | **absent** |
| Open Fulfilments | 200; RDE318421 present |
| Awaiting Fulfilment | 200; RDE318421 **absent**; no create button |
| Show `/inventory/hardware-fulfilments/1` | 200; `c360-journey-tracker`; 11 labels Review→Ready; AWB / Delhivery_Surface / `1568724940` / Print Shipping Label |
| 360 RIN3512344 | 200; `#hardware-fulfilment`; mapping-required; Start Hardware Fulfilment present (disabled control); stepper present |
| 360 RDE318490 | 200; Hardware section + stepper |
| `laravel.log` `create/adhoc` | **0** |
| last 200 log lines `generate/pickup` | **0** |

GET only. Request Pickup was **not** clicked. No allocate / invoice / create / AWB / pickup / manifest POST.

RDE318421 appears once as a table row plus the existing info-banner mention (count 2 in full HTML). HF total stays **1**.

---

## RDE318421 after overlay (unchanged)

| Field | Before | After |
|-------|--------|-------|
| Fulfilment | `1` / `awb_assigned` | same |
| Provider shipment | `1568724940` | same |
| Courier | `Delhivery_Surface` / `15084` | same |
| AWB | `284931178067631` | same |
| Label | present | same |
| Package evidence | 2 | 2 |
| `ready_for_pickup_at` | `2026-09-08 17:54:42` | same |
| `updated_at` | `2026-09-08 17:54:42` | same |
| Local `pickup_requested_at` | empty (pending P-07-09-88 reconcile) | empty |
| Manifest | not generated | not generated |
| HF / shipment counts | 1 / 1 | 1 / 1 |

Show still offers **Request Pickup** because local pickup is still NULL. That is the already-deployed P-07-09-88 pending-reconcile state. This ticket did not click it and did not call Shiprocket.

---

## Rollback

Restore the 14 replaced files from `p-07-09-89-20260908T124520Z`. Delete the 5 paths in `NEW_FILES.txt`. Then `optimize:clear` + `optimize`. Do **not** restore pickup services from this backup (they were not replaced). Rollback was **not** required.

---

## Safety

No fulfilment / serial / invoice / shipment / AWB / pickup / manifest writes. RDE318421 not rewritten. RIN rows not rewritten. rdservice.in not modified. Shiprocket mutation endpoints not called. Batch/automatic processing remains OFF. Unrelated shipping/statutory dirty files were not copied.
