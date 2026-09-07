# P5 hardware Shiprocket shipment foundation

**Project:** Radium Desk  
**Prompt:** **RadiumDesk-P-07-09-19**  
**Date:** 2026-09-07  
**Type:** Implementation. Null+Fake Shiprocket adapter + hardware shipment gates. No live HTTP.  
**Prior:** P-07-09-12 … P-07-09-18.

Classification: **VERIFIED** / **OWNER-LOCKED** / **INFERRED** / **UNKNOWN**.

---

## 0. Repository verification (before modify)

| Item | Value | Class |
|------|-------|-------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Branch | `main` (P1–P4 ahead of origin) | VERIFIED |
| Before SHA | `a7d8f5ca70794c221eff7c5a72b4c6f53ae14d4f` | VERIFIED |
| Shiprocket on main | absent | VERIFIED |
| WIP S2–S6 | `backup/statutory-shipping-wip-20260905` + untracked feat worktree | VERIFIED |
| Admin `ShipRocketController` | not in this repo | VERIFIED |

Architecture matches P0–P4 serial-first + invoice-before-shipment. P5 adds gates WIP S4 lacked.

---

## 1. Integration mechanism

`HardwareShipmentService` is the only hardware writer.

1. Validate gates (no provider call).
2. Persist a local `shipments` row (`shipment_no` = `HW-{source_id}`).
3. On retry/timeout/ambiguous: `searchOrders(shipment_no)` before create.
4. `createOrder` via `ShiprocketGateway`.
5. Bind `external_order_id` / `external_shipment_id` once.
6. Transition `INVOICE_ISSUED` → `SHIPMENT_CREATED`.
7. `assignAwb` separately → `AWB_ASSIGNED`. AWB is never invented.

Production bind: `NullShiprocketGateway`. `SHIPROCKET_ENABLED=false`, `SHIPROCKET_PROVIDER=none`. Tests bind `FakeShiprocketGateway` only. **No HTTP client exists.**

Auth (documented, unused): `POST https://apiv2.shiprocket.in/v1/external/auth/login` with env email/password. Token is not acquired in P5. Credentials stay empty. Admin/Box values are not copied.

---

## 2. Pickup mapping

| Fulfilment branch | Config key | Production value |
|-------------------|------------|------------------|
| `DELHI-RETAIL` | `shipping.pickup_locations.delhi` / `SHIPROCKET_PICKUP_DELHI` | **UNKNOWN** (empty) |
| `MUMBAI` | `shipping.pickup_locations.mumbai` / `SHIPROCKET_PICKUP_MUMBAI` | **UNKNOWN** (empty) |

Customer / POS state is not an input. Historical `RADDELHI` is not used. Empty nickname fail-closes with no provider call.

---

## 3. Gates (fail-closed)

Shipment create requires all of:

- not a frozen `RDE*`
- `INVOICE_ISSUED`
- linked statutory invoice + invoice number
- allocated serials matching physical qty and invoice-locked list
- active fulfilment branch
- configured pickup for that branch
- structured **shipping** address: line1, city, state, pincode, country, phone, email
- persisted parcel weight/length/breadth/height (no invented defaults)
- Shiprocket enabled and a non-`none` gateway

Billing address is never substituted for shipping.

---

## 4. Idempotency / timeout

| Item | Rule |
|------|------|
| Merchant `order_id` | `HW-{source_id}` — never rotated |
| Idempotency key | `hardware:shiprocket:create:{fulfilment_id}` |
| One shipment / fulfilment | unique `hardware_fulfilment_id` + `commerce_order_id` |
| Provider ids / AWB | written only when local column is empty |
| Timeout after accept | persist `ambiguous`; next call searches before create |
| Provider 5xx / retryable | no state advance; reconcile/retry allowed |
| Provider 4xx / rejected | `failure_class=provider_rejected`; create is not retried |
| Duplicate operator | returns the bound shipment |

Provider-side idempotency of create/adhoc is **UNKNOWN**. Desk does not assume the API returns existing ids.

Provider calls run **after** the local shipment row is committed so timeout/reject status survives.

---

## 5. Persisted provider IDs

On `shipments`: `external_order_id`, `external_shipment_id`, `awb`, `courier_id`, `courier_name`, `pickup_location`, `invoice_number`, `serial_numbers`.

On `hardware_fulfilments`: `shipment_id`, `shipment_no`, `provider_shipment_id`, `awb`, `provider_awb`.

---

## 6. Address / parcel / country

Shipping structured fields required. Country must be present on the order; `India` is not defaulted.

Parcel must already be on the commerce order. Catalog defaults are not invented. Live seven-order parcels remain **UNKNOWN** and would fail closed.

Email is required by the official adhoc contract. Missing email fail-closes.

---

## 7. Non-goals

Live HTTP. Webhook. Pickup generation as a required P5 step. Ingest enable. Cashfree correlate. Seven pending orders. Admin writer. `radiumbox_prod`. Deploy.

---

## 8. Remaining dependencies

| Item | Status |
|------|--------|
| P0-M2 pickup nicknames | UNKNOWN / Owner |
| Live API email/password/channel_id | UNKNOWN |
| HTTP client | not implemented |
| P6 Box callback | implemented (P-07-09-20); sender remains OFF |
| P7 observe ingest | later |
| P8 new-order then seven | later |

---

## 9. Classification

**VERIFIED:** Null bound; Fake used in tests; gates reject READY/SERIALS_ALLOCATED/missing address/branch/pickup/serials; Delhi vs Mumbai pickup follows stock; timeout search-binds; rejected create is not retried.

**OWNER-LOCKED:** invoice + serials before shipment; stock-location pickup; seven frozen; no Admin credentials; no live enable.

**INFERRED:** billing_* Shiprocket fields are filled from the validated **shipping** address with `shipping_is_billing=true` (not from commerce billing).

**UNKNOWN:** live nicknames, credentials, channel id, create/adhoc retry response body, default courier.
