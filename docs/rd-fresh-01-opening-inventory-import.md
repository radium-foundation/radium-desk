# RD-FRESH-01 — Opening inventory import foundation

**Project:** Radium Desk  
**Ledger:** RadiumDesk-P-04-09-11 · P-04-09-07 · P-04-09-06 · P-04-09-05  
**Date:** 2026-09-04  
**Branch:** `feat/rd-fresh-01-inventory-pos`  
**Canvas:** [`rd-fresh-01-pos-production-readiness.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-RadiumWebsites-radium-desk/canvases/rd-fresh-01-pos-production-readiness.canvas.tsx)  
**Template contract:** [`rd-fresh-01-inventory-opening-field-matrix.md`](rd-fresh-01-inventory-opening-field-matrix.md)

This is the safe import foundation for the agreed opening-inventory Excel template. It does **not** import Admin `product_stock` and does **not** invent quantities.

**Actual inventory workbook import:** **NO** — the completed physical-count workbook path/source was not provided or verified. Only the empty agreed template at `storage/app/private/inventory-opening/rd-fresh-01-opening-inventory-template.xlsx` was opened (gitignored; headers verified).

**Commit:** foundation checkpoint `11d14223`. P-04-09-11 added CLI permission enforcement, stopped copying catalog unit cost onto blank serials, and fail-closes a Desk variant that belongs to another parent.

## Verdict

Desk can now **preview** and **apply** the P-01-09-08 workbook shape. Preview never writes stock. Apply is one transaction, fail-closed, and idempotent per file checksum. Branches are never auto-created. Existing SKUs are never rewritten. Duplicate serials and replayed quantity rows are rejected.

POS retail complete / cancel / transfer / reservation behaviour is unchanged.

## Mapping (VERIFIED from the empty template)

| Sheet | Required columns | Desk target |
|-------|------------------|-------------|
| Inventory Opening | Opening Date, Branch Code, SKU, Condition, Stock Status, Serial Number, Quantity, plus optional Variant SKU / Unit Cost / Selling Price / Counted By / Remarks | `inventory_serials` or `inventory_stock_balances` + `inventory_movements.type=opening` |
| SKU Master | SKU, Product Name, Serialized, GST %, Default Selling Price, Active | Create **missing** `inventory_products` / variants only |
| Branches | Branch Code (and name/GSTIN/address for humans) | **Lookup only.** Must already exist in Desk |

Serialized: one unit per row, qty=1, serial mandatory, condition New/Used/Refurbished, stock status Available or Damaged.  
Non-serialized: blank serial, qty≥1. Damaged quantity is **rejected** (Desk has no `damaged_qty`; do not invent one).

## Safety rules (do not relax)

- Dry-run / Preview is the default HTTP action and the artisan command without `--apply`.
- Apply requires `--apply` plus `--actor=` on the CLI, or a confirmation checkbox in the Admin UI.
- Same SHA-256 workbook cannot apply twice.
- Serial uniqueness remains global. Workbook duplicate serials are flagged on both rows; nothing is auto-corrected.
- Quantity identity (`sku|variant|branch|date|status|condition|qty|unit_cost|remarks`) cannot be applied twice.
- Missing Desk branch → block. Suggested template GSTINs are not copied (Bihar GSTIN remains an owner confirmation).
- Existing SKU name/price is not overwritten. Serialized flag or GST % mismatch vs SKU Master is blocking.
- Selling price / unit cost on an opening row do not change catalog price. Unit cost may be stored on the serial when provided. A blank serial unit cost stays null; catalog cost is not copied.
- Opening movements use `occurred_at` = Opening Date and `opening_import_batch_id` for audit. History is append-only.

## How to run (later, when the filled workbook path is verified)

```bash
php artisan inventory:opening-import /absolute/path/to/workbook.xlsx --actor=admin@example.com
php artisan inventory:opening-import /absolute/path/to/workbook.xlsx --apply --actor=admin@example.com
```

Admin UI: Inventory → Opening import (permission `inventory.opening.import`, admin team only). Hardware and agents are 403. The artisan command uses the same permission and refuses inactive actors.

## Schema added

Migration `2026_09_04_200000_add_inventory_opening_import_foundation`:

- `inventory_products.unit_cost` nullable
- `inventory_serials.condition` nullable (`new` / `used` / `refurbished`)
- `inventory_serials.unit_cost` nullable
- `inventory_opening_import_batches` (unique `source_checksum`)
- `inventory_opening_import_rows` (unique applied identity when applied)
- `inventory_movements.opening_import_batch_id` nullable
- Movement type `opening`

`down()` drops only these objects.

## Classification

| Item | Class | Note |
|------|-------|------|
| Template sheet/column names | VERIFIED | Read from the empty xlsx |
| Condition stored on serials | VERIFIED need | Unblocked the P-01-09-08 import gate |
| Unit cost optional | VERIFIED | Not invented when blank |
| Do not auto-create branches | VERIFIED | Bihar GSTIN was UNKNOWN in the matrix |
| Damaged quantity unsupported | VERIFIED gap | Reported, not invented |
| Completed workbook contents | UNKNOWN | Path not provided |
| Production permission seed | UNKNOWN until `deskd` | Seeder is idempotent; not run here |

## Isolation

| Surface | This ticket |
|---------|-------------|
| AWS | Not accessed |
| `radiumbox_prod` / RadiumBox Read API contract | Unchanged |
| rdservice.in / rdservice.net / radiumsign.com | Not modified |
| Desk production / `deskd` | Not run |
| Statutory / Shiprocket dirty WIP | Left in the worktree |
| Opening workbook apply | Not performed |
