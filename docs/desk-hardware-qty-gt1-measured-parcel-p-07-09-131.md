# Qty > 1 measured shipment parcel + document downloads — RadiumDesk-P-07-09-131

**Date:** 2026-09-09  
**Prompt ID:** `RadiumDesk-P-07-09-131`  
**Mode:** Implementation. No RDE318516 production write. No Shiprocket live call. No `--force`. No migrate. No global outbox.

Last used ledger ID at start of this ticket was **P-07-09-129**. Concurrent ticket **P-07-09-130** (RDE318469 READY inspect) was appended during implementation. This ticket: **P-07-09-131**.

---

## Qty 1

Unchanged. Eligible verified catalog packaging still snapshots onto `hardware_fulfilments.parcel_snapshot` with source `inventory_product_packaging`. RDE318434-class orders (`0.24 kg / 14 × 9 × 7 cm`) continue without operator carton entry.

## Qty > 1

Catalog attach remains refused (`Catalog packaging can only be snapshotted for quantity 1`). Dimensions are **not** multiplied, copied from the unit pack, or inferred from historical Shiprocket shipments.

Operator path:

1. Pack the complete outer carton.
2. Enter Length / Breadth / Height (cm) and Actual Packed Weight (kg) on **Package Dimensions — Complete Packed Shipment**.
3. Save the measured fulfilment parcel.
4. Get Courier Options → select courier → Create Shipment.

`Get Courier Options`, `Create Shipment`, and `HardwareShipmentEligibility::require()` fail closed until a complete ingest parcel or a valid fulfilment snapshot exists.

## Measured parcel storage

Extends existing `hardware_fulfilments.parcel_snapshot` JSON. Does **not** create a second parcel table.

Stored fields include: `hardware_fulfilment_id`, `quantity`, measured `weight` / `length` / `breadth` / `height`, `volumetric_weight`, `source=measured_shipment_package`, `save_for_future_requested`, `snapshotted_by_user_id`, `snapshotted_at`.

Immutable after a bound shipment exists. Replace is allowed only until that bound shipment. `commerce_orders.parcel` is not written. `inventory_product_packaging` is not written.

## Save Package Dimension for future order

Optional checkbox. Persists `save_for_future_requested` on the fulfilment snapshot only.

**Packaging-master limitation:** `inventory_product_packaging` is a 1:1 qty-1 verified unit pack. Write/verify requires `inventory.packaging.verify` (admin-team; **not** `hardware_team`). It cannot represent a qty-10 / qty-5 outer carton without a new quantity-specific catalog shape.

**Smallest safe extension later:** a quantity-aware packaging variant (or carton template) keyed by product + packed qty, with its own verify/audit, not a mutation of the qty-1 row.

## Volumetric weight

No divisor existed in the Shiprocket mapper/gateway. Desk sends **actual packed weight** as `weight` plus L/B/H. The provider computes chargeable weight.

Display/audit formula (Shiprocket India domestic, documented because none existed in code):

`L × B × H (cm) / 5000 = kg`

Shown as **Actual Weight** and **Volumetric Weight**. Actual weight is not replaced by volumetric weight.

## Documents

`shipments.label_url` and `shipments.manifest_url` were already persisted on generate. New GET routes redirect away to those URLs and do not call generate:

- `inventory.hardware-fulfilments.label.download`
- `inventory.hardware-fulfilments.manifest.download`

Shown only when the persisted URL exists, on:

- Hardware Fulfilment show
- Shipment action popup/modal
- Customer 360 hardware card

## Authorization

Controller middleware: `HardwareFulfilmentAccess::allows()` → `hardware.fulfilment.operate`.

Granted to **admin** and **hardware_team** (Hardware Agent). Not granted to support `agent`.

When `fulfilment_branch_id` is set, `InventoryBranchScope::assertCanOperate` also applies.

Unauthorized users receive 403 even if they know the download URL.

## RDE318516 readiness

Code path is ready for HF15 / qty 10: measure the actual outer carton, save the fulfilment snapshot, then courier options / create. This ticket did **not** write HF15, invent dimensions, or create a shipment.

## Production

**NO — Not performed.** No migrate, no deskd, no RDE318516 mutation, no Shiprocket live call, no outbox processing.
