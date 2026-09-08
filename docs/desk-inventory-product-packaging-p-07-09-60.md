# Inventory product packaging master — RadiumDesk-P-07-09-60

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-60`  
**Stage:** 1 only. Catalog verification. **Not** a Shiprocket source.

Implements `docs/desk-inventory-stock-packaging-design-p-07-09-59.md`.

---

## What was added

| Item | Value |
|---|---|
| Table | `inventory_product_packaging` |
| Model | `App\Models\InventoryProductPackaging` |
| Relationship | `InventoryProduct::packaging()` **HasOne** / unique `inventory_product_id` |
| Permission | `inventory.packaging.verify` — admin-team roles only. **Not** granted to `hardware_team`. **Not** `inventory.products.manage`. |
| Routes | `GET/PUT inventory/stock/packaging/{product}` (`inventory.stock.packaging.edit` / `.update`) |
| UI | Stock table columns + Record pack / Edit pack page |

Columns: `gross_weight`, `length`, `breadth`, `height`, `weight_unit`, `dimension_unit`, `verified_by_user_id`, `verified_at`, optional `notes`, timestamps.

Units allow-list: **kg** and **cm**. Other units are rejected. No conversion. No historical Shiprocket backfill.

---

## Stage 1 boundary (unchanged)

- `HardwareShipmentEligibility` still reads only `commerce_orders.parcel`.
- Shipment form still prohibits operator parcel.
- Verified packaging does **not** copy onto orders and does **not** authorize shipment.
- RDE318421 was not modified.
- Country blocker is unchanged.

---

## Operator notes

`hardware_team` can see Stock pack status (`Not verified` / `Verified`) but cannot open Record pack until granted `inventory.packaging.verify`.

Production permission sync (not run by this ticket):

```bash
php artisan db:seed --class=RolePermissionSeeder --no-interaction
```
