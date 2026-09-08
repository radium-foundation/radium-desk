# Hardware shipment packaging snapshot design — RadiumDesk-P-07-09-62

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-62`  
**Mode:** Read-only design. No application code, schema write, production data, Shiprocket call, shipment, deploy, commit, or push.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-61**. This ticket: **P-07-09-62**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

Concrete case: **RDE318421** / fulfilment **1** / commerce order **369** / CO-000369.

---

## Git (this investigation)

| Item | Value | Class |
|---|---|---|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Branch | `main` = `origin/main` | VERIFIED |
| HEAD | `b6b6568c3921ba1a7048ab6166bf50631e24fe64` | VERIFIED |
| Worktree | Dirty unrelated statutory docs + untracked investigation markdown. No application edits in this ticket. | VERIFIED |

---

## 1. VERIFIED findings

### Parcel / order data model and write paths

1. `commerce_orders.parcel` is nullable JSON added in `2026_09_07_125200_add_hardware_fulfilment_p1_foundation`. Cast on `CommerceOrder` as `array`.
2. The **only** application write path is `ChannelIngestService::createOrder()` at first ingest (`'parcel' => $request->parcel`).
3. Ingest validator accepts optional `parcel.weight/length/breadth|width/height/weight_unit` (`nullable`, `min:0` — ingest allows zero; shipment eligibility later requires `> 0`).
4. Re-ingest of the same source with a **different** payload hash is **409**. Exact hash is **200 Duplicate** and does **not** update the order.
5. Isolated fulfilment workflow has **no** parcel/country write step. `--step=ship` calls `HardwareShipmentService::createShipment()` which already requires a persisted parcel.
6. `CreateHardwareFulfilmentShipmentRequest` **prohibits** `parcel`, `weight`, `length`, `breadth`, `height`, pickup, branch, and `channel_id`.
7. Production (P-07-09-61 / later read): order 369 `parcel` is **NULL**. Desk-wide persisted parcels: **0**.

### Catalog packaging

8. Table `inventory_product_packaging` is 1:1 with `inventory_products` (`InventoryProduct::packaging()` HasOne). Unique `inventory_product_id`.
9. Columns: `gross_weight`, `length`, `breadth`, `height`, `weight_unit`, `dimension_unit`, `verified_by_user_id`, `verified_at`, optional `notes`.
10. Units allow-list: **kg** and **cm** only. Re-save re-attests `verified_at` / actor.
11. Permission `inventory.packaging.verify` is admin-team only. Not `inventory.products.manage`. Not granted to `hardware_team`.
12. Product **28** / `RBMFS110L1` has packaging id **4**: **0.240 kg**, **14.00 × 9.00 × 7.00 cm**, verified by Avinash Jha (user 2) at `2026-09-08T11:49:11+05:30`.
13. Eligibility does **not** reference `InventoryProductPackaging` or `inventory_product_packaging`.

### Shipment path

14. `HardwareShipmentEligibility::require()` / `inspect()` / `requireParcel()` read **only** `$order->parcel`.
15. `inspect()` for RDE318421 (P-07-09-61+): `canCreate=false`; blockers: **Shipping address incomplete**, **Parcel dimensions unavailable**, **Shiprocket configuration incomplete**.
16. `require()` fails first on missing `country` (does not reach parcel in the same call). Isolated `requireParcel()` fails: persisted parcel required; defaults not invented.
17. Create flow: lock fulfilment → if bound shipment, return; if `provider_rejected`, do not retry → `require()` → `assertProviderCallable()` → `openShipment()` → search-before-create when needed → `mapper.map()` → gateway `createOrder()`.
18. `shipments.create_snapshot` already exists but today stores only invoice, serials, pickup, branch, source_id — **not** parcel or address.
19. Idempotency key: `hardware:shiprocket:create:{fulfilment_id}`. Unique on `commerce_order_id` and `hardware_fulfilment_id` (one shipment row per fulfilment/order).
20. Mapper sends one parcel (`weight/length/breadth/height`, no units) for the whole shipment. Physical lines only.
21. Bound gateway in production default: `NullShiprocketGateway`. `shipping.enabled=false`, `provider=none`, `http_enabled=false`.
22. `SHIPROCKET_CHANNEL_ID` is omitted from the adhoc payload when empty (`HardwareShipmentMapper` + `ShiprocketCreateOrderRequest::toAdhocPayload()`). Tests set `shipping.channel_id` to `''`.
23. `HardwareFulfilmentEvent` is a **state-transition** log and **enqueues Box callbacks**. It is not a safe place to store a parcel snapshot.

### Country

24. `requireShippingAddress()` requires structured keys `line1`, `city`, `state`, `pincode`, `country` (non-empty). Billing is **not** substituted.
25. Order 369 structured shipping keys: line1, line2, city, state, pincode. **`country` key absent.** Billing country also absent.
26. There is **no** Desk PATCH/correct-country path for an already-ingested commerce order. Adding country via re-ingest would change the payload hash → **409**.
27. Owner-confirmed India (P-07-09-57) was **not written**.

### RDE318421 other gates (ready; unchanged by packaging)

28. State `invoice_issued`; not frozen; payment `paid` `2026-09-07T15:04:08+05:30`; INV-67275 issued; serial `10532319` allocated on product 28 / DELHI-RETAIL; pickup **RADDELHI**; no shipment/AWB. Fulfilment `updated_at` still `2026-09-08T09:59:30+05:30` as of the last read.

---

## 2. INFERRED findings

1. Avinash’s product-28 pack is a **unit carton** for that SKU, not an order-specific overpack.
2. Shiprocket adhoc treats numbers as kg/cm (no unit fields on the wire). Catalog kg/cm can map 1:1 once snapshotted.
3. qty>1 of the same SKU is **not** safely the same as “send the unit carton once.” It may be one larger box or N boxes. One Shiprocket create = one parcel.
4. Multi-SKU hardware in one fulfilment cannot be composed from two catalog packs into one carton without a measured order-level parcel.
5. Writing Desk measurements into `commerce_orders.parcel` would make the order look spoke-supplied and would diverge from `payload_hash`.
6. Live-reading catalog inside `requireParcel()` would let a later Edit pack change the dimensions of a retry/reconcile after the first provider attempt.

---

## 3. UNKNOWN findings

1. Whether Shiprocket’s live create/adhoc **requires** `channel_id` when credentials belong to a custom channel. Desk implementation does **not** require it. Official requirement remains **UNKNOWN**.
2. Whether any future hardware fulfilment will be multi-SKU or qty>1 on Desk.
3. Whether ingest will ever start sending a complete parcel for Box orders (today it does not for RDE318421).
4. Whether a later owner-approved country string must be exactly `India` vs an ISO code. Ingest allows any string ≤64.
5. Whether isolated `--live-shipping` should auto-attach a snapshot or still require the same explicit attach as the UI.

---

## 4. Recommended design

**Do not live-read `inventory_product_packaging` at provider-call time.**  
**Do not backfill `commerce_orders.parcel` for historical orders.**  
**Do not type parcel on the shipment form.**  
**Do not use `HardwareFulfilmentEvent` (callback side effect).**  
**Do not invent country or parcel.**

Create a **fulfilment-scoped, immutable shipment parcel snapshot** copied from verified catalog packaging when strict rules pass. Eligibility then reads, in order:

1. Complete ingest `commerce_orders.parcel` (existing contract; never overwrite).
2. Else fulfilment `parcel_snapshot` if present and valid.
3. Else fail closed (`Parcel dimensions unavailable`).

Snapshot **creation** is a separate idempotent service, not a GET/inspect side effect.

### When a catalog pack may be snapshotted

All must hold:

- Fulfilment has allocated serials (product identity is the serial’s `inventory_product_id`, not description text).
- Exactly **one** physical merchandise SKU.
- Physical **qty = 1**.
- That product has a verified `inventory_product_packaging` row.
- Units are **kg** and **cm**.
- Measures `> 0`.
- `commerce_orders.parcel` is null or incomplete (do not replace a complete ingest parcel).
- No bound shipment yet.
- Snapshot not already present (idempotent no-op if present and matching).

RDE318421 **would qualify** on product/qty/packaging (0.240 / 14×9×7). It would **still** fail ship on country + disabled Shiprocket.

### Required behavior matrix

| Case | Behavior |
|---|---|
| Single-SKU qty=1 + verified kg/cm pack + no ingest parcel | Snapshot allowed; eligibility uses snapshot |
| Multi-SKU | Fail closed until a measured **order-level** parcel exists (ingest or a future measured-order UI — out of scope) |
| qty > 1 | Fail closed (one provider parcel; carton composition unknown) |
| Missing packaging | Fail closed |
| Unverified (no row) | Fail closed |
| Later catalog Edit pack | Must **not** mutate existing snapshots or bound shipments. New fulfilments see the new catalog. Re-attach only if no snapshot yet, or via an explicit replace **before** any shipment row exists |
| Historical orders | No backfill. Snapshot only when attach runs for that fulfilment |

### Lifecycle place

| Step | Snapshot? | Why |
|---|---|---|
| Ingest | No | Packaging often absent; 409 on later payload change |
| Serial allocate | No (too early for ship UX); identity becomes known | Product is known, but attaching here couples allocate to ship |
| Invoice mint | No | Already issued for RDE318421; invoice must not depend on parcel |
| **Attach-from-packaging** (new) after serials, typically `invoice_issued` | **Yes** | Explicit, auditable, idempotent |
| Create Shipment | May call attach **inside the lock** if snapshot missing and rules pass, **then** `require()` | So UI/isolated ship can succeed without a second click; inspect() still never writes |
| After bound shipment | Never replace | Provider already received the first snapshot |

Copy the same numbers into `shipments.create_snapshot.parcel` at `openShipment()` so retries use the shipment row, not the catalog.

### Country (separate from packaging)

Packaging does not solve country.

Safest correction for an already-ingested order:

- **New Admin-only fill-if-absent overlay** on the fulfilment (or a dedicated commerce correction table), **not** a silent UPDATE of ingest JSON and **not** re-ingest.
- Allowed only when structured `country` is missing/empty.
- Value must be an **explicit** owner/operator supplied string (for RDE318421 the previously owner-confirmed value is India — still must be entered on the correction action; do not infer from +91 / West Bengal / billing).
- `requireShippingAddress()` merges: structured country if present, else overlay.
- Shipment form still **prohibits** country.
- Audit the actor, old keys, new country, fulfilment/order ids.
- Do not change payment, invoice, serials, or fulfilment state.
- Do not enqueue a Box callback.

Re-ingest cannot add country without 409. Ad-hoc SQL is not an application path.

### Idempotency and retry

- Attach is idempotent: second call with the same source packaging id/measures is a no-op.
- If snapshot exists and catalog later differs: keep snapshot; do not auto-replace.
- Create shipment: existing bound row returns as today; `provider_rejected` is not retried; ambiguous/retryable still search-before-create.
- Mapper input for retry = snapshot (fulfilment + `create_snapshot`), never a fresh catalog read.
- Isolated `--step=ship` uses the same attach+require path. It still must not call Shiprocket unless a later ticket enables the gateway.

### Effect on existing semantics

| Domain | Effect |
|---|---|
| Payment | None |
| Invoice / INV-67275 | None |
| Serial allocation | None |
| Fulfilment state machine | **No new state.** Attach does not transition `invoice_issued` |
| Ingest / payload_hash | Unchanged if overlay is fulfilment-scoped |
| `commerce_orders.parcel` | Unchanged (recommended) |
| Shiprocket | Still disabled until a later enablement ticket. Design does not enable flags or bind HTTP |
| Historical orders | No rewrite |

---

## 5. Rejected alternatives

| Alternative | Why reject |
|---|---|
| Live-read catalog in `requireParcel()` | Later Edit pack changes retries; not an immutable ship snapshot |
| Silent UPDATE `commerce_orders.parcel` for all historical rows | Backfill forbidden; pretends spoke sent Desk measurements |
| Operator types parcel on Create Shipment | Already prohibited; reopens unmeasured courier typing (P-07-09-57/58 conflict) |
| Import historical Shiprocket 0.24/14×9×7 or 0.5/10×10×10 | Evidence only; Avinash already measured product 28 independently |
| Store snapshot in `hardware_fulfilments.metadata` only | Weak contract; no schema/query; easy to clobber |
| Store snapshot as `HardwareFulfilmentEvent` | Events enqueue Box callbacks |
| Infer country India | Prior tickets forbade writing unconfirmed/inferred country; keep explicit correction |
| Enable Shiprocket / set channel id in this design | Out of scope; channel requirement UNKNOWN |

---

## 6. Required implementation files (if approved)

New:

- `app/Services/HardwareFulfilment/HardwareFulfilmentParcelSnapshotService.php` — attach/idempotency/rules
- `app/Services/HardwareFulfilment/HardwareFulfilmentCountryCorrectionService.php` — fill-if-absent country overlay
- `app/Http/Requests/Inventory/AttachHardwareFulfilmentParcelRequest.php` — no parcel/country body fields (derive only)
- `app/Http/Requests/Inventory/CorrectHardwareFulfilmentShippingCountryRequest.php` — explicit `country` only
- `database/migrations/2026_09_08_XXXXXX_add_hardware_fulfilment_shipment_overlays.php` — additive JSON/columns on `hardware_fulfilments` (see below)
- Tests: `tests/Feature/HardwareFulfilment/HardwareFulfilmentParcelSnapshotTest.php`
- Tests: `tests/Feature/HardwareFulfilment/HardwareFulfilmentCountryCorrectionTest.php`

Modify:

- `app/Services/HardwareFulfilment/HardwareShipmentEligibility.php` — `requireParcel` / `requireShippingAddress` / `inspect` precedence
- `app/Services/HardwareFulfilment/HardwareShipmentService.php` — attach-if-needed inside lock; extend `create_snapshot`
- `app/Services/HardwareFulfilment/HardwareShipmentMapper.php` — unchanged parcel shape (still kg/cm numbers, no units on wire)
- `app/Http/Controllers/Inventory/HardwareFulfilmentShipmentController.php` (or equivalent show/create controller)
- `resources/views/inventory/hardware-fulfilments/show.blade.php` — show snapshot source; do **not** add parcel inputs
- `routes/web.php` — attach / country-correct routes
- `app/Models/HardwareFulfilment.php` — fillable/casts
- `CreateHardwareFulfilmentShipmentRequest` — keep parcel/country **prohibited**

Do **not** modify: payment services, statutory invoice mint, serial allocation, ChannelIngest write (except if a later spoke starts sending parcel — out of scope), Shiprocket env, RDE318421 rows in the implementation ticket until a dedicated write ticket.

### Migration / data

Additive on `hardware_fulfilments` (names indicative):

- `parcel_snapshot` JSON nullable (`weight`, `length`, `breadth`, `height`, `weight_unit`, `dimension_unit`, `source`, `inventory_product_id`, `packaging_id`, `verified_at`, `verified_by_user_id`, `snapshotted_at`, `snapshotted_by_user_id`)
- `shipping_country_overlay` string nullable
- `shipping_country_overlay_at` timestamp nullable
- `shipping_country_overlay_by_user_id` nullable FK

No backfill. No production UPDATE of order 369 in the implementation commit. A later **authorized write** ticket would attach RDE318421’s snapshot (copy 0.240 / 14×9×7 from packaging 4) and apply country only after explicit owner value.

### Tests (implementation ticket)

Extend, do not replace, `HardwareFulfilmentP5ShipmentTest`, `HardwareFulfilmentShipmentUiTest`, `InventoryProductPackagingTest`.

Must cover:

- qty=1 single SKU + verified pack + null order parcel → attach succeeds; inspect parcel formatted; create uses snapshot
- attach is idempotent
- catalog edit after snapshot does not change snapshot or mapper input
- missing / unverified pack → still `Parcel dimensions unavailable`
- qty>1 and multi-SKU → no snapshot; fail closed
- complete ingest parcel wins over catalog
- shipment form still prohibits parcel/country
- country overlay fill-if-absent; reject overwrite of existing country; reject empty; inspect ship-to includes country
- GET inspect does not write
- no Shiprocket HTTP in these tests (Fake/Null)
- existing payment/invoice/serial assertions unchanged

---

## 7. Production-data implications

If this design is later implemented and a write ticket is approved for RDE318421 only:

- Fulfilment 1 would gain a parcel snapshot from packaging 4 (0.240 / 14×9×7).
- Order 369 `parcel` would stay NULL (recommended).
- Country overlay would be written only after an explicit country value.
- INV-67275, serial 10532319, payment, and fulfilment state would stay as they are.
- Other orders would be untouched.

This ticket performs **none** of those writes.

---

## 8. Shiprocket implications

None in this ticket. Design does not enable `SHIPROCKET_ENABLED`, bind `HttpShiprocketGateway`, create a shipment, assign an AWB, or set `SHIPROCKET_CHANNEL_ID`.

Channel id: **implementation optional; provider necessity UNKNOWN.**
