# Hardware Operations UX v2 — RadiumDesk-P-07-09-94

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-94`  
**Follows:** P-07-09-92/93 Hardware Dashboard UX.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-93**. This ticket: **P-07-09-94**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

---

## Intent

Evolve the live Hardware workspace so Admin/Avinash work is:

Dashboard → Hardware → Customer 360 → Next Action modal → existing gated operation → refresh next action.

No second workflow. Existing fulfilment routes remain authoritative.

---

## Verified product contract

| Source | When | Class |
|--------|------|--------|
| Physical `CommerceOrderItem.description` (else `sku` / `catalog_sku`) + `qty` | Fulfilment / commerce lines exist | VERIFIED |
| Physical filter `HardwareFulfilmentEligibility::isPhysicalCommerceItem()` | Same as eligibility / allocation | VERIFIED |
| Support `Order.product_name` | No physical commerce lines | VERIFIED |
| Support `Order` quantity field | Does not exist | VERIFIED |
| SKU map / `InventoryProduct.name` | Allocation only, not display | VERIFIED |

Missing product after those sources is **Product data missing**, never `—`.

Multi-product compact: `Mantra L1 · 1 Q +1` with hover/click detail list.

---

## What changed

- Product + quantity on Hardware table and C360 Hardware card.
- Row checkboxes, select-all, selection bar. Enabled bulk action: **Open selected** only.
- C360 Hardware card is compact; primary button opens a Correct-Customer-style action dialog; Open Fulfilment stays secondary.
- GET `hardware-fulfilments/{id}/action-dialog` injects into `#workspaceModal`.
- Existing POSTs return JSON when `Accept: application/json`, then close modal, refresh C360, and re-open the dialog for the next action. They do **not** auto-execute the next mutation.
- Shipment modal uses existing fetch/select/create routes. Courier is chosen from returned options. No provider call on Dashboard/C360 view.
- Package photo remains one non-blocking evidence track.

---

## What did not change

- Shiprocket contracts, credentials, payment, serial/invoice/shipment/AWB/label/pickup/manifest rules.
- Package-photo closure semantics.
- RIN mapper / ingest / schema / migrate / `.env`.
- Services business logic.
- Blind bulk Create Shipment / Request Pickup / Generate Manifest.

---

## Tests

- `tests/Feature/HardwareFulfilment` + Hardware unit + overflow: **250 passed, 1 skipped**
- Pint on dirty PHP
- PHP syntax on changed classes

Vite rebuild: **NO — Not performed.** Browser JS (selection + modal) needs the existing Vite pipeline before production.

---

## Deploy

**NO — Not performed.**
