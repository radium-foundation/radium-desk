# Hardware fulfilment show UI + India country rule — RadiumDesk-P-07-09-69

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-69`  
**Mode:** Implementation. No production deploy. No RDE318421 write. No Shiprocket call.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-68**. This ticket: **P-07-09-69**.

Owner visual reference reviewed:  
`/Users/ravi/Downloads/screencapture-desk-radiumbox-inventory-hardware-fulfilments-1-2026-09-08-13_55_21.pdf` (2 pages).

---

## Pre-verification

| Item | Value | Class |
|------|-------|-------|
| Repository | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Branch | `main` | VERIFIED |
| Before SHA | `cb31b89181cc92438c016e6e13ec46654e1a1dcb` | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Worktree | Unrelated dirty statutory docs + untracked investigation markdown. Not committed. | VERIFIED |
| Production | Overlay-on-`v4.0.67` / `c297a0ae` workflow. Not modified by this ticket. | VERIFIED |

Show route: `GET /inventory/hardware-fulfilments/{fulfilment}` → `HardwareFulfilmentSerialController@show`.  
Readiness: `HardwareShipmentEligibility::inspect()`.  
Serial query: `HardwareFulfilmentWorkflowService::allocatedSerialNumbers()` (allocated status).  
Invoice routes: `finance.invoices.show`, `finance.invoices.pdf`.  
Country (P-64/65): fill-if-absent overlay + operator input. Replaced for readiness by owner India rule.

---

## PDF problems confirmed

Page 1: header and product card said `1 serial required` while ALLOCATED `10532319` and quantity 1 were visible. Invoice `INV-67275` was plain text. Country `Missing — not inferred`. Parcel `Unavailable — not persisted`. Catalog pack already showed verified `0.24 kg · 14×9×7 cm`. Blockers mixed real gates with implementation wording. Shiprocket `(not called)`.

Page 2: Attach parcel snapshot copy + Shipping country input + Record country.

---

## Root causes

**Serial:** Readiness already counted allocated serials correctly and did **not** emit a serial blocker for RDE318421. The Blade always printed line `qty` as `1 serial required`, independently of `hardware_fulfilment_serials`. Different sources: product card used commerce qty; allocated card used the serial relation.

**Invoice:** Show rendered `$shipment->invoice` as text. Canonical routes already existed (`/finance/invoices/{id}` and `/pdf`). Hardware operators lacked `finance.invoices.view`, so a raw finance link would 403.

**Country:** P-64 required an explicit overlay when structured `country` was empty. Owner rule is now: hardware shipment country is always India. No operator input. No source-data rewrite.

---

## What landed

1. Product card uses `allocated_qty` / `allocated_serials` from the fulfilment serial relation. Complete allocation shows Quantity / Serial / **Allocated**. No contradictory required copy.
2. Invoice number is a **View invoice** + **GST PDF** action to the existing finance routes. Hardware operators may open only the invoice linked to a fulfilment they can operate. Finance users keep full invoice access. Unlinked invoices stay 403 for hardware_team. Register remains finance-gated.
3. `HardwareFulfilmentCountryCorrectionService::resolvedCountry()` returns `India` for shipment readiness. `canCorrect()` is false. Country form removed. Overlay / `commerce_orders.shipping_address_structured` are not written by GET/inspect.
4. Operator page sections: Fulfilment, Product, Invoice, Shipping readiness, actual blockers only. Implementation wording moved out of the primary list. Parcel snapshot gate kept (`Attach verified packaging`). Shiprocket stays disabled.

GET/show/inspect do not mutate fulfilment, order, parcel, serial, invoice, or shipment.

---

## Tests

New: `tests/Feature/HardwareFulfilment/HardwareFulfilmentShowReadinessTest.php`.  
Updated country / shipment UI / parcel / serial allocation UI assertions.

PHP: 63 relevant + 39 navigation/P5/P6 = passed.

JS `customer-360-drawer.test.js`: fulfilment-link interceptor still passes. Three **pre-existing** failures remain: tests expect `http://localhost/dashboard/service-cases/42/customer-360` but fetch uses `/dashboard/service-cases/42/customer-360`. Not treated as fixed.

Pint: passed on dirty application files.

Frontend build: NO — Not performed. Blade-only; no Vite source change.

---

## Not performed

- Production deploy
- RDE318421 data write
- Parcel snapshot attach
- Shiprocket enable / call / create / AWB
- Permission model change (`hardware.fulfilment.operate` unchanged; `hardware.fulfilment.ship` not added)
- New `/inventory/shipments` namespace
