# Desk AWB courier reliability (P-07-09-303)

Investigation + smallest safe AWB path change. No production DB write. No live Shiprocket mutation. Not merged. Not deployed.

## Ledger

Next unused ID after overlay `P-07-09-302` (this worktree’s copied ledger ended at `P-07-09-289`). File is `docs/cursor-prompt-ledger.md` (no `cursor-prompt-log.md`).

## A. Desk courier-selection lifecycle

1. Operator **Get Courier Options** → `HardwareShipmentCourierOptionsService::fetch` → `GET /courier/serviceability/` with pickup postcode, delivery postcode, weight, COD flag. `order_id` is sent only when a bound provider order id already exists.
2. Options + recommended id are snapshotted on the fulfilment (`courier_options_snapshot`, fingerprint, TTL from `shipping.courier_options_ttl_seconds`, default 900s). Fetch **clears** the stored selection.
3. Operator **Select Courier** persists `selected_courier_id` / name if the id is in the fresh snapshot.
4. **Create Shipment** requires that selection (`requireValidSelection`) and copies courier onto the local shipment row. Shiprocket create/adhoc does not re-quote.
5. Destination, pickup, weight, dimensions, and payment/collection mode can change after selection; fingerprint then goes stale and create is blocked until a new fetch/select. After the provider shipment exists, the previous Desk AWB path **did not re-quote**; it reused the stored courier id.
6. AWB: `HardwareFulfilmentSerialController::storeAwb` → `HardwareShipmentService::assignAwb` → `HttpShiprocketGateway::assignAwb` (`POST /courier/assign/awb` with `shipment_id` + `courier_id`).

## B. Shiprocket API (Desk)

| Call | Desk path | Body / query |
|------|-----------|----------------|
| Serviceability | `GET /courier/serviceability/` | `pickup_postcode`, `delivery_postcode`, `weight`, `cod`, optional `order_id` |
| Create | `POST /orders/create/adhoc` | mapped order; courier is local only |
| Assign AWB | `POST /courier/assign/awb` | `shipment_id`, `courier_id` |

HTTP 400 `Given courier not serviceable` is non-retryable. Auth failure and DNS/timeout stay distinct classes. Endpoint remains `apiv2.shiprocket.in`.

## C. Portal vs Desk (RDP21 / RBP145 / RBP167)

Production SELECT (read-only) from this gate:

| Order | HF | External shipment | Desk stored courier | Snapshot recommended | AWB on Desk | Pickup |
|-------|----|-------------------|---------------------|----------------------|-------------|--------|
| RBP145 | HF814 | 1585271779 | 15084 Delhivery_Surface | 15106 | null (`shipment_created`) | RADDELHI |
| RBP167 | HF934 | 1585266078 | 15084 | 15106 (same pattern) | null | RADDELHI |
| RDP21 | HF91 | 1585251560 | 15084 | 15106 | null (`shipment_created`) | RADDELHI (Pune destination) |

15084 **was** present in the pre-create serviceability list, then Assign AWB returned HTTP 400. 15084 is **not** globally invalid (historical successful assigns exist).

**Portal Assign AWB HTTP request: UNKNOWN.** No portal capture. Portal success on RDP21 is consistent with a later re-quote / different courier; it is not evidence that Desk should hard-code 15106. Desk still has no AWB for these rows, so portal success did not sync back.

## D. Failure classes (kept separate)

- Stale stored courier after create (this gate).
- Courier genuinely not in the current serviceability list.
- Provider HTTP 400 on assign even after a current quote.
- Auth failure.
- DNS/timeout (retryable).

## Implementation

Before AWB, re-quote serviceability **with** `order_id`. Keep the stored courier if it is still listed. If not, use Shiprocket’s **recommended** id only when that id is in the current list (never the first row, never hard-coded 15084→15106). Persist. Assign once.

### Provider HTTP 400 “Given courier not serviceable” semantics (VERIFIED)

- `HttpShiprocketGateway::interpret()` throws on HTTP 4xx; `assignAwb` never reads `awb_code` from error responses.
- `assignAwb` returns `ShiprocketAwbResult` with `status=rejected`, `awb=null`, `retryable=false`.
- Production RDP21 remained `awb=null` after this rejection.
- Listed in serviceability does **not** guarantee AWB acceptance.

### Alternate-courier recovery (local only; not deployed in Gate A)

After one definitive `Given courier not serviceable` rejection with no AWB:

1. Fresh serviceability quote with `order_id`.
2. Select current **recommended** courier only if it differs from the rejected id and is in the fresh list.
3. Persist selection on fulfilment + shipment.
4. Exactly **one** alternate `POST /courier/assign/awb` attempt.
5. No second shipment. No retry of the rejected courier. No retry on generic HTTP 400, auth, timeout, or ambiguous responses.

Portal HTTP request remains **UNKNOWN**. Portal success does not prove Desk reproduces portal parameters.

## Tests vs baseline

Focused AWB + gateway tests passed (fake Shiprocket only).

Unrelated / local baseline, not caused by this AWB change:

- `test_shipment_is_blocked_before_invoice` / `test_readiness_blocks_courier_options_and_create`: serial allocation auto-issues when `public/brand/stamp-bgr.png` exists; those tests assume `SERIALS_ALLOCATED`.
- Courier/show GET 500s without `public/build` (Vite). With a local build symlink they pass except the stamp auto-invoice cases above.
