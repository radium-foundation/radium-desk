# Inventory Stock packaging fields — inspection / design — RadiumDesk-P-07-09-59

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-59`  
**Mode:** Inspection and design only. No application code, schema, production data, Git history, or deployment changes.

Ledger file requested as `docs/cursor-prompt-log.md` — **that file does not exist**. Authoritative ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-58**. This ticket: **P-07-09-59**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

---

## Git

| Item | Value | Class |
|---|---|---|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Branch | `main` = `origin/main` | VERIFIED |
| HEAD | `9a8de4b362dea05f3d52393522fe02e54905a45b` | VERIFIED |
| Describe | `v4.0.67-56-g9a8de4b3` | VERIFIED |
| Worktree | Dirty: unrelated statutory docs + untracked investigation reports. No application-file edits in this ticket. | VERIFIED |

---

## 1. VERIFIED findings

1. `/inventory/stock` is `inventory.stock.index` → `App\Http\Controllers\Inventory\StockController@index` → `resources/views/inventory/stock/index.blade.php`.
2. The page lists **`inventory_stock_balances`** (qty by product × optional variant × branch), not the product catalog.
3. Table columns today: Product (`sku — name`), Variant, Branch, Available, Reserved. **No Product ID column. No weight/length/breadth/height/units.**
4. Stock page has **no row edit** and **no product-detail link**. Actions are Stock in / Reserve / Adjust (separate routes).
5. Auth: any `inventory.view` user can open Stock. `hardware_team` can open Stock and stock-in; **cannot** open `/inventory/products` (`inventory.products.manage` false). Admin roles can manage products.
6. Sellable product is `inventory_products` (`InventoryProduct`): `id`, `sku`, `name`, HSN, GST, `unit_price`, `unit_cost`, serialized/batch flags, `device_model_id`. **No packaging columns.**
7. Variants (`inventory_product_variants`) have sku/name/price only. Stock balances have qty only.
8. Existing product edit is `/inventory/products/{id}/edit` — commercial + tracking fields only. No packaging.
9. Current Shiprocket parcel source for hardware fulfilment is **`commerce_orders.parcel` JSON only**. Eligibility fail-closes if missing. Defaults are not invented. Shipment HTTP form **prohibits** operator parcel fields.
10. Ingest may accept `parcel.weight/length/breadth|width/height/weight_unit` and persist them. Production Desk hardware parcels today are **empty** (P-07-09-57: 0 commerce parcels).
11. Shiprocket create/adhoc sends `weight`, `length`, `breadth`, `height` with **no unit fields**. Admin historically typed kg/cm (INFERRED convention).
12. Historical Shiprocket / Box courier-list values exist as **evidence only** (P-07-09-57 / P-07-09-58). They are **not** verified packs and must not be imported.

---

## 2. INFERRED findings

1. Inventory Admin will measure packs on the Stock screen because that is the daily hardware workspace; product master is hidden from `hardware_team`.
2. One Desk SKU has one packed carton for current hardware fulfilment (qty 1, single physical line). Variant-level packs are not needed on Day 1.
3. Shiprocket adhoc numbers are treated as **kg / cm**. Storing those units explicitly is consistent with ingest’s optional `weight_unit` and with not inventing conversions.

---

## 3. UNKNOWN findings

1. Whether any live `inventory_products` row will need a **second** legitimate pack profile (P-07-09-58 showed multiple historical profiles per SKU).
2. Whether `hardware_team` should be allowed to **write** verified packs, or only Admin.
3. Whether ingest will ever start sending parcel (spoke still omits it for RDE318421).
4. Whether multi-SKU / qty>1 hardware fulfilments will exist; current mapper sends **one** parcel for the whole shipment.
5. Whether Desk `device_models` should ever own packaging (they do not today).

---

## 4. Current `/inventory/stock` architecture

| Item | Value |
|---|---|
| URL | `https://desk.radiumbox.com/inventory/stock` |
| Route | `GET inventory/stock` name `inventory.stock.index` (`routes/web.php`) |
| Prefix | `inventory.*` behind auth + `InventoryAccess::allows` (`inventory.view`) |
| Controller | `StockController::index` |
| Query | `InventoryStockBalance::with(['product','variant','branch'])` scoped by `InventoryBranchScope` |
| Filters | `branch_id`, `product_id` |
| Page size | 40 |
| Related | `GET/POST inventory/stock/in` stock-in only (qty/serials/batch/notes). **Not a product editor.** |
| Nav | Inventory workspace tab “Stock” (`workspace-nav.blade.php`) |

---

## 5. Exact model/table representing the product

**`inventory_products` / `App\Models\InventoryProduct`.**

Identity the Stock page already uses:

| UI need | Current field |
|---|---|
| Product ID | `inventory_products.id` (not shown) |
| SKU | `inventory_products.sku` |
| Product Name | `inventory_products.name` |

Channel/Box model mapping is **separate**: `channel_sku_maps.inventory_product_id` (e.g. Box model 946 → Desk product 28 / `RBMFS110L1`). Do not store packaging on the map table.

---

## 6. Existing relevant fields

| Location | Weight / L / B / H / units | Semantics |
|---|---|---|
| `inventory_products` | **none** | Commercial + tracking only |
| `inventory_product_variants` | **none** | Child SKU/price |
| `inventory_stock_balances` | **none** | On-hand qty |
| `commerce_orders.parcel` | JSON `weight`, `length`, `breadth`/`width`, `height`, optional `weight_unit` | Per-order snapshot from ingest; eligibility source |
| Shiprocket mapper | float L/B/H/W | Provider payload; no units |
| Box catalog (not Desk) | often NULL; spec HTML is device body | **Not** a Desk field; **not** packed parcel |

There is **no** existing Desk field whose semantics are “verified packed shipment.” Do not reuse `unit_price` / `unit_cost` / HSN. Do not treat Box `38 mm` / `200 g` or historical `0.24/14×9×7` as this field.

---

## 7. Recommended data model

**Do not put packaging on `inventory_stock_balances`.** The same SKU appears once per branch; a pack is not a stock quantity. Writing it on balances would duplicate or conflict across Delhi/Mumbai.

**Do not use `commerce_orders.parcel` as the catalog.** That is a per-order snapshot. It is currently empty and has no Inventory UI.

**Do not invent values into existing unused columns** — there are none.

**Recommended owner:** a dedicated **1:1** table `inventory_product_packaging` keyed by `inventory_product_id`.

Domain reasons:

- Packaging is a **product/SKU fact**, not a movement and not a branch balance.
- “Verified” must be distinguishable from “absent.” A missing row means **not verified** — fail closed, same as today’s missing order parcel.
- Verification metadata (`verified_at`, `verified_by_user_id`, optional note) does not belong on the commercial product row.
- Historical Shiprocket profiles must never be copied into this table automatically.
- Later, a 1:N table could hold multiple legitimate packs; Day 1 should stay 1:1 so Shiprocket still gets one carton.

Suggested columns (implementation later, not created now):

| Column | Meaning |
|---|---|
| `inventory_product_id` | unique FK |
| `gross_weight` | packed weight, `decimal` > 0 |
| `length`, `breadth`, `height` | packed outer dimensions, `decimal` > 0 |
| `weight_unit` | required enum/string, e.g. `kg` |
| `dimension_unit` | required enum/string, e.g. `cm` |
| `verified_at`, `verified_by_user_id` | measured by a person; not an import |
| `notes` | optional, no provider dump |

Do **not** add a “source=shiprocket_history” path.

Extending `inventory_products` with the same columns is a weaker alternative: simpler join, but mixes commercial edits with a measured-pack attestation and makes “unverified” look like nullable leftovers.

---

## 8. Recommended UI layout

Keep **one** packaging record. Surface it on Stock (operational) and optionally on the product form (catalog). A separate packaging spreadsheet/page is **unnecessary**.

**Stock table** (`/inventory/stock`) — add columns, do not replace qty:

| Product ID | SKU | Product | Variant | Branch | Avail | Rsvd | Pack | Weight | L × B × H | Units | Action |
|---|---|---|---|---|---:|---:|---|---|---|---|---|

- Pack: `Missing` / `Verified` (from the packaging row, **not** from history).
- Same product on two branches shows the **same** pack (it is not per-balance).
- Action “Record pack” / “Edit pack” opens a **small modal or dedicated GET/PUT** on that `product_id`. It must not edit qty, serials, or price.
- Stock-in form stays qty/serials only.

**Product edit** (`/inventory/products/{id}/edit`): read-only or same fields for admins who already manage products. Same table, same validation. Do not let product-manage save packaging without verification fields.

Hardware team can **see** Missing/Verified on Stock. Whether they can **write** is a permission decision (UNKNOWN). Default recommendation: **Admin + a new `inventory.packaging.verify` permission**, not `inventory.products.manage` (that would hide the write path from hardware and overload product-master rights).

---

## 9. Recommended validation rules

- All four measures required together. Partial save rejected.
- Each of `gross_weight`, `length`, `breadth`, `height` numeric and **> 0**. Zero/blank is missing, not a default.
- `weight_unit` and `dimension_unit` required. Day-1 allow-list: `kg` and `cm` only (matches Shiprocket adhoc). Other units **rejected**, not converted.
- Labels must say **packed / gross**, not net/device weight.
- No import from P-07-09-57/58 datasets. No silent fill from Box catalog.
- Updating an existing verified row requires a new `verified_at` / actor (reattest). Do not overwrite quietly.
- Variants: Day 1 ignore variant; pack is on the parent `inventory_products` row used by `channel_sku_maps`.

---

## 10. Recommended authorization rules

| Action | Permission |
|---|---|
| See Stock + pack status | existing `inventory.view` |
| Stock in / transfer | unchanged |
| Write/verify packaging | **new** `inventory.packaging.verify` (grant to inventory admin roles; hardware_team only if owner decides) |
| Product commercial edit | unchanged `inventory.products.manage` |
| Create shipment / type parcel on fulfilment | remains **prohibited**; parcel still not an operator override |

Do not reuse `hardware.fulfilment.operate` for packaging write (that permission allocates serials / may ship).

---

## 11. Required future Shiprocket integration changes

**Do not change this in the implementation of the Stock UI alone.** Ship remains fail-closed until a later, explicit ticket.

Today:

```
commerce_orders.parcel  →  HardwareShipmentEligibility::requireParcel()
                        →  HardwareShipmentMapper  →  adhoc weight/length/breadth/height
```

Later, when a packaging row is **verified**:

1. Resolve Desk product from the fulfilment’s allocated serial / `channel_sku_maps` / line SKU.
2. If the fulfilment is **one physical SKU** and packaging is verified **and** units are `kg`/`cm`:
   - either copy that pack onto `commerce_orders.parcel` as an explicit persist step, **or**
   - allow eligibility to read verified packaging when order parcel is null.
3. Prefer an **explicit persist** onto the order (audit: order snapshot at ship time). Do not live-read catalog only (later pack edits would rewrite history).
4. Multi-SKU or qty that needs a different carton: still fail closed until a measured order-level parcel exists.
5. Never auto-fill from historical Shiprocket.
6. Keep shipment form `parcel` **prohibited**.
7. Mapper still sends no unit keys (provider has none). Eligibility should reject non-`kg`/`cm` verified rows.

RDE318421 still also lacks **country**. Packaging does not solve that.

---

## 12. Migration / backfill implications

- Additive migration only. No change to existing product or stock values.
- **No backfill.** Every SKU starts `Missing`.
- Do not seed 946 / `RBMFS110L1` from 0.24/14×9×7 or 0.5/10×10×10.
- Production migrate is a later deploy decision; this ticket creates no migration.

---

## 13. Tests to add in the implementation phase

- Stock index shows Product ID, Missing pack, and no invented numbers.
- User with `inventory.view` only cannot PUT packaging.
- User with `inventory.packaging.verify` can create a complete verified row; `verified_at` / actor set.
- Incomplete / zero / missing units rejected.
- Non-kg/cm rejected (no conversion).
- Two stock rows (Delhi + Mumbai) for the same product show one pack.
- `hardware_team` without the new permission: 403 on write; 200 on Stock read.
- Product commercial update does not require or wipe packaging.
- `CreateHardwareFulfilmentShipmentRequest` still prohibits parcel.
- Eligibility still fail-closes when packaging is missing **and** order parcel is null (until the later integration ticket).
- No test may insert historical Shiprocket values as fixtures for “verified.”

Existing coverage to extend, not replace: `InventoryPosAccessTest`, `InventoryPosOperationalWorkflowTest`, `InventoryStockServiceTest`, hardware shipment eligibility tests.

---

## 14. Risks and blockers

| Risk | Notes |
|---|---|
| Wrong owner (stock balance) | Duplicate packs per branch; wrong domain |
| Treating history as verified | P-07-09-58 API vs Box conflict on 946 |
| Wiring Shiprocket in the same PR as the UI | Would ship invented/unmeasured packs |
| Gating write on `products.manage` | Hardware operators on Stock cannot record a measured pack |
| Qty>1 / multi-SKU | One catalog pack may be wrong; keep fail-closed |
| Country still missing on RDE318421 | Packaging does not unblock ship alone |
| Dirty worktree | Implementation must not mix statutory doc noise into an app commit |

---

## 15. Files likely to change in a later implementation (not this ticket)

- `database/migrations/*_create_inventory_product_packaging.php` (new)
- `app/Models/InventoryProductPackaging.php` (new)
- `app/Models/InventoryProduct.php` (relation only)
- `app/Http/Controllers/Inventory/StockController.php` (eager-load + flags)
- `app/Http/Controllers/Inventory/ProductPackagingController.php` (new) or stock-scoped update action
- `resources/views/inventory/stock/index.blade.php`
- `resources/views/inventory/products/partials/form.blade.php` (optional display)
- `database/seeders/RolePermissionSeeder.php`
- `routes/web.php`
- `tests/Feature/Inventory/*`
- Later ticket only: `HardwareShipmentEligibility.php`, order persist path — **not** in the Stock UI PR

---

## 16. Separate packaging sheet

**Unnecessary.** Stock already lists every in-hand SKU. Add columns + a verify action. Product edit can show the same record. A third workspace tab would hide the work from the screen operators already use.

---

## 17. Confirmation — no modifications (application / data)

- Application code: **NO — Not performed.**
- Database writes / migrations: **NO — Not performed.**
- Production writes: **NO — Not performed.**
- Shiprocket API: **NO — Not performed.**
- Historical value population: **NO — Not performed.**
- Commit / push / deploy: **NO — Not performed.**

Docs only: this report + ledger row `RadiumDesk-P-07-09-59`.
