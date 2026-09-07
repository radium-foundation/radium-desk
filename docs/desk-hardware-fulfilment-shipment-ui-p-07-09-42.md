# Isolated Shiprocket shipment workflow — RadiumDesk-P-07-09-42

**Date:** 2026-09-07  
**Prompt ID:** `RadiumDesk-P-07-09-42`  
**Mode:** Implementation / preparation only. No live Shiprocket create. No AWB. No serial. No invoice. No Box callback.

Ledger file `docs/cursor-prompt-log.md` does not exist. Authoritative ledger: `docs/cursor-prompt-ledger.md`. Last used ID: `RadiumDesk-P-07-09-41`. This ticket: **`RadiumDesk-P-07-09-42`**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**. Never upgraded.

---

## 0. Pre-verification

| Item | Value | Class |
|------|-------|-------|
| Repository | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Branch | `main` (tracks `origin/main`) | VERIFIED |
| Before SHA | `29c4b26a792b1b7b98472c91ebaa89ddbbcd00ae` | VERIFIED |
| Worktree | `radium-desk-pos-release` on `main`; siblings `radium-desk` / `radium-desk-phase1-clean` unused | VERIFIED |
| Local `.env` | ABSENT | VERIFIED |

Unrelated dirty docs existed before this ticket and were not part of the shipment work.

---

## 1. Existing implementation (inspected first)

| Surface | Finding | Class |
|---------|---------|-------|
| `HardwareShipmentService` | Present. Local row first, search-before-create on timeout/ambiguous, bind provider ids once | VERIFIED |
| `HardwareShipmentEligibility` | Invoice, serial qty, shipping address, parcel, branch, pickup gates | VERIFIED |
| `NullShiprocketGateway` | Production default bind | VERIFIED |
| `HttpShiprocketGateway` | Present, unbound unless enabled + provider `shiprocket` + `http_enabled` + credentials | VERIFIED |
| Models/tables | `shipments`, `shipment_events`; fulfilment shipment/AWB columns | VERIFIED |
| UI | Serial allocation only. Shipment/AWB routes existed but were hidden | VERIFIED |
| Permission | `hardware.fulfilment.operate` via `HardwareFulfilmentAccess` (also `inventory.view`) | VERIFIED |
| Pickup | `DELHI-RETAIL` → `SHIPROCKET_PICKUP_DELHI`; `MUMBAI` → `SHIPROCKET_PICKUP_MUMBAI` | VERIFIED |
| Production nicknames | Delhi `RADDELHI`; Mumbai `RADIUMUM` (P-07-09-31 / P-07-09-35) | VERIFIED in prior docs |
| `SHIPROCKET_API_EMAIL` / `PASSWORD` | Production PRESENT (P-07-09-35). Local KEY_ABSENT (no `.env`) | VERIFIED |
| `SHIPROCKET_CHANNEL_ID` | EMPTY. Admin hardcoded id not copied | VERIFIED |
| API base | `https://apiv2.shiprocket.in/v1/external` | VERIFIED default |
| Historical Admin payload | Official `POST /orders/create/adhoc`; merchant `order_id` = `HW-{source_id}`; billing from validated shipping; `shipping_is_billing=true` | VERIFIED in Desk mapper + P5 docs |
| Parcel source | `commerce_orders.parcel` only. Inventory product has no L/W/H/weight columns. Architecture: catalog measurements NULL | VERIFIED |
| Address source | `commerce_orders.shipping_address_structured`. Billing is not substituted | VERIFIED |
| Invoice/serial prerequisites | State must be `INVOICE_ISSUED`; allocated serials must match physical qty and invoice-locked list | VERIFIED |
| RDE318421 | `ready_for_fulfilment`; serial/invoice/shipment not created. Ingest omitted country/parcel | VERIFIED in P-07-09-38/39 |

**INFERRED:** Admin `channel_id` still exists in historical source and may be required by some Shiprocket panel setups.

**UNKNOWN:** Whether an empty `channel_id` is accepted by live create/adhoc for this account. Not invented. Not tested live.

---

## 2. What this ticket added

- `HardwareShipmentEligibility::inspect()` for operator-facing blockers without creating a shipment.
- Payment-verified and mixed-physical-branch fail-closed checks on create.
- Fulfilment show page **Shipment** card: status, pickup branch, pickup location, ship-to, parcel, invoice, serial.
- **Create Shipment** only when every gate passes, including a callable non-Null gateway. Confirmation lists order / product / serial / invoice / pickup / ship-to / parcel / Provider: Shiprocket.
- Operator cannot POST branch, pickup, parcel, or provider order id (`CreateHardwareFulfilmentShipmentRequest`).
- Same permission as serial allocation. No new admin permission.
- Fake/Null only in tests. `Http::preventStrayRequests()` on shipment tests.

Live flags were **not** enabled.

---

## 3. Gates (create)

A shipment is created only when all are true:

1. Fulfilment exists and is not frozen.
2. Eligible hardware fulfilment (`INVOICE_ISSUED`).
3. Payment verified (`paid` / `paid_at` / `paid_recognized_at`).
4. Required serial quantity allocated.
5. Physical branch known from allocated stock serials.
6. Authoritative statutory invoice with number.
7. Complete structured shipping address (not billing).
8. Persisted parcel weight/length/breadth/height > 0. No invented defaults.
9. Pickup derived from physical branch (`RADDELHI` / `RADIUMUM` when env is set).
10. Shiprocket config valid and bound gateway is not Null/`none`.
11. User has `hardware.fulfilment.operate`.
12. No bound shipment already exists.
13. Prior `provider_rejected` is not retried. Timeout/ambiguous searches before create.

Mixed Delhi+Mumbai stock fails closed. Operator cannot override branch or pickup.

---

## 4. Safety

| Action | Result |
|--------|--------|
| Live Shiprocket request | NO |
| Live shipment / AWB | NO |
| Serial allocated | NO |
| Invoice created | NO |
| Callback sent | NO |
| `SHIPROCKET_ENABLED` / `HTTP_ENABLED` / provider | unchanged, default off |
| Hardware fulfilment global enable | NO |
| RDE318421 / RDE318400 / frozen seven | untouched |
| Credentials in Git / docs / UI | NO |

Production UI will show **Shiprocket configuration incomplete** and hide Create Shipment while the Null gateway remains bound.

RDE318421 would also fail closed on parcel (and likely country) until those values are established from a verified source. Catalogue dimensions remain UNKNOWN/NULL.

---

## 5. Tests

Focused: `HardwareFulfilmentP5ShipmentTest`, `HardwareFulfilmentShipmentUiTest`, `HttpShiprocketGatewayTest`, `HardwarePickupResolverTest`, `HardwareFulfilmentSerialAllocationUiTest`.

Relevant hardware/shipping: **155** tests, **154** passed, **1** skipped.

Pint: passed. Static analysis: no phpstan/larastan in this repo — not run.

---

## 6. Not performed

Commit, push, deploy, live Shiprocket create/AWB, serial allocation, invoice issuance, Box callback, global flag enablement, production `.env` writes, processing of RDE318421.

---

## 7. P-07-09-44 hardening addendum

**Prompt:** `RadiumDesk-P-07-09-44`  
**Mode:** Technical readiness only. No live create. RDE318421 may remain READY.

Search-before-create now **fails closed** when search is retryable or found-without-bindable-ids. A second create is not issued. Authentication failures are marked retryable/ambiguous (not `provider_rejected`) so a later authorized retry can search first. `ShiprocketDisabledException` surfaces as validation, not a 500.

Shipment card **Status** is the local shipment state (`Not created` / reconcile / created), not the fulfilment lifecycle. Missing parcel/address/pickup read as unavailable/incomplete/not derived. Provider shows `Shiprocket (not called)` until a provider shipment is bound.

Added tests: unverified payment; retryable search does not create; ready UI shows `Not created` and does not claim a bound shipment.
