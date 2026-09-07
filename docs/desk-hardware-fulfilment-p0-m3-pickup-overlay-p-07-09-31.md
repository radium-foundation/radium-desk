# P0-M3 Owner pickup nicknames + surgical P0-M2 overlay

**Project:** Radium Desk  
**Prompt:** **RadiumDesk-P-07-09-31**  
**Date:** 2026-09-07  
**Type:** Production surgical overlay + pickup env only. Flags remain off.  
**Prior ledger row:** P-07-09-29 (P0-M2). P-07-09-30 was a read-only audit chat and is not reused.

Classification: **VERIFIED** / **OWNER-LOCKED**.

---

## 0. Before modify

| Item | Value | Class |
|------|-------|-------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Branch | `main` | VERIFIED |
| Local HEAD | `632819ff023bb0474c00e1edf125b6c434b6c577` | VERIFIED |
| `origin/main` | `e4c3aec3` | VERIFIED |
| Production | `srv1910783` `/var/www/radium-desk` `radium_desk` | VERIFIED |
| `release.json` | 4.0.67 / `5d14a582` | VERIFIED |
| P0-M2 on production before | ABSENT | VERIFIED |
| Pickup keys before | both KEY_ABSENT | VERIFIED |
| Mechanism | `config/shipping.php` → `SHIPROCKET_PICKUP_DELHI` / `SHIPROCKET_PICKUP_MUMBAI` | VERIFIED |
| Hardware migrations | P1/P2/P4/P5 Ran; UPI still Pending | VERIFIED |

Owner-confirmed nicknames: Delhi `RADDELHI`, Mumbai `RADIUMUM`.

---

## 1. What was applied

Production `.env` (those two keys only):

- `SHIPROCKET_PICKUP_DELHI=RADDELHI`
- `SHIPROCKET_PICKUP_MUMBAI=RADIUMUM`

No Shiprocket API keys, no `SHIPROCKET_ENABLED`, no Box ingest secret.

Surgical rsync (no `--delete`, no `migrate --force`, no `artisan down`) of:

- `app/Services/HardwareFulfilment/HardwareSerialAllocationService.php`
- `app/Http/Controllers/Inventory/HardwareFulfilmentSerialController.php`
- `bootstrap/app.php`
- `resources/views/inventory/hardware-fulfilments/show.blade.php`

Then `optimize:clear` + `optimize` so cached config/views picked up `.env` and the new blade.

---

## 2. After verify

| Check | Result |
|-------|--------|
| File SHA-256 vs local P0-M2 | MATCH (all four) |
| `establishFulfilmentBranch` | present |
| Resolver Delhi | `RADDELHI` |
| Resolver Mumbai | `RADIUMUM` |
| SKU maps | 8 approved rows; 950/1010 = 0 |
| Hardware fulfilments / serials / shipments | 0 / 0 / 0 |
| Gateway | `NullShiprocketGateway` |
| Callback | `NullBoxFulfilmentCallbackGateway` |
| shipping.enabled / provider | false / none |
| auto_issue / worker_may_mint / correlate / callback | all false |
| Frozen seven | 7 `active`; 0 commerce/HF/invoice |
| `/up` `/login` | 200 |
| UPI migrations | still Pending |
| Maintenance | UP |

In-memory `InventoryBranch` objects were used for resolver checks. No serial, invoice, or shipment rows were written. No Shiprocket HTTP.

---

## 3. Not performed

Push, tag, full `desk deploy`, Box/Admin change, ingest enable, live shipping, callback enable, auto-mint, frozen-order processing, UPI migrate, rsync `--delete`.
