# P-20-39 — Shipment tracking fix and clean merchant reference transition

| Field | Value |
| --- | --- |
| Task | **P-20-39** |
| Cursor ledger | `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef` |
| Branch | `fix/p20-39-shiprocket-track-clean-merchant-ref` |
| Start (IST) | **2026-11-20 ~20:10** |
| End (IST) | **2026-11-20 ~20:28** |
| Duration | **~18 minutes** (implementation + focused tests) |
| Production baseline (unchanged) | **v4.1.105** / **`e71c44a1a`** |

---

## Executive summary

1. **Fixed** `trackByShipment`: Shiprocket requires **`order_id` + `shipment_id`**; missing `order_id` now returns a structured rejection; empty activity history surfaces provider `error` instead of a false success.
2. **Implemented** Owner-requested **clean merchant references** for **new** hardware Shiprocket orders: `shipment_no` / `order_id` = **`{source_id}`** (e.g. `RBP2281`).
3. **Preserved** all **historical** `HW-{source_id}` rows and **dual search/reconcile** so provider orders created under HW- remain discoverable without creating duplicate Shiprocket orders.

---

## Step 3 — Decision gate

| Gate | Result | Confidence |
| --- | --- | --- |
| No collision across Desk hardware fulfilments | `source_id` unique per `(channel, source_type)` on `hardware_fulfilments` | **VERIFIED** (schema) |
| Shiprocket accepts bare reference | Same JSON `order_id` field; RBP1739 pattern already uses `HW-RBP` prefix only in channel id, not a Shiprocket constraint on letters | **INFERRED** |
| Existing shipments identifiable | Unchanged `shipments.shipment_no` in DB; search tries stored + legacy + clean | **VERIFIED** |
| Idempotency intact | Local row + multi-ref search before create; no second create on reconcile | **VERIFIED** (tests) |
| Dual lookup | `HardwareShipmentMerchantReference::searchReferences()` | **VERIFIED** |
| No production rows modified | Code-only; no migration | **VERIFIED** |
| Cross-channel Shiprocket collision | Non-Desk orders with same string | **UNKNOWN** — mitigated by Desk-unique RBP/RDE prefixes |

**Proceed:** **YES** for **new** shipments only.

---

## Reference design

| Role | Old (historical) | New (from deploy of this change) |
| --- | --- | --- |
| Desk `shipments.shipment_no` | `HW-RBP2281` | `RBP2281` |
| Shiprocket create `order_id` | same as row | same as row |
| Shiprocket `channel_order_id` | provider echo | provider echo |
| Provider numeric ids | `external_order_id`, `external_shipment_id` | unchanged (immutable) |

**No rotation** of existing production merchant references.

---

## Tracking API correction

| Item | Before | After |
| --- | --- | --- |
| Endpoint | `GET /courier/track?shipment_id=` | `GET /courier/track?shipment_id=&order_id=` |
| Missing `order_id` | HTTP 400 from provider | Desk `ShiprocketTrackResult` `rejected` without HTTP |
| Empty activity DB | Treated as low status | `error` populated; `activities` empty; status `unknown` |
| Callers | `HardwareShipmentDocumentsService::providerTrackForShipment` | Passes `external_order_id` when AWB absent |

---

## Files changed

| Change | Path |
| --- | --- |
| **Added** | `app/Services/HardwareFulfilment/HardwareShipmentMerchantReference.php` |
| **Added** | `tests/Unit/HardwareFulfilment/HardwareShipmentMerchantReferenceTest.php` |
| **Changed** | `HttpShiprocketGateway.php`, `ShiprocketGateway.php`, `NullShiprocketGateway.php`, `FakeShiprocketGateway.php` |
| **Changed** | `HardwareShipmentService.php`, `HardwareShipmentDocumentsService.php` |
| **Changed** | Tests: `HttpShiprocketGatewayTest`, `HardwareFulfilmentP5ShipmentTest`, `HardwareFulfilmentShipmentUiTest`, `HardwareFulfilmentP6CallbackTest` |
| **Extended** | `docs/desk-hardware-fulfilment-p5-shiprocket-p-07-09-19.md` (merchant id rule) |

| Category | Items |
| --- | --- |
| **Preserved** | Historical `HW-*` `shipment_no` values; external id binding; idempotency keys |
| **Removed** | `HardwareShipmentService::shipmentNo()` HW--only helper |
| **Not changed** | `HardwareExternalShipmentService` local `HW-` for external courier rows (non-Shiprocket API) |

---

## Focused test results

| Suite | Result |
| --- | --- |
| `HardwareShipmentMerchantReferenceTest` | **PASS** (4) |
| `ShiprocketGatewayContractTest` | **PASS** (2) |
| `HttpShiprocketGatewayTest` (full) | **PASS** (42) |
| `HttpShiprocketGatewayTest` (`track_by_shipment*`) | **PASS** (3) |
| `HardwareFulfilmentP5ShipmentTest` (new + legacy search) | **PASS** (3) |
| `HardwareFulfilmentShipAndLabelOrchestrationTest` + UI + P6 callback | **PASS** (37) |

Full PHPUnit suite: **NOT RUN** (per policy / task scope).

---

## Production actions

| Action | Performed |
| --- | --- |
| Deploy | **NO** |
| Production API | **NO** |
| DB migration | **NO** |
| Existing HW- references | **NO change** |

---

## Remaining risks / next release

1. **First new order after deploy** uses `RBP…` on Shiprocket; ops should recognise clean refs in panel.
2. **P0-1** from P-20-38 (sanitized provider audit events) still open.
3. **Provider escalation** for RBP2281/2310 pickup error unchanged.
4. Release gate: deploy + smoke one **new** fulfilment create (non-prod first) + confirm search reconcile on legacy row.

---

## Git / PR

See commit and PR URL after push (recorded in commit message footer).