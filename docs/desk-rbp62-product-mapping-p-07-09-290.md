# RBP62 product mapping investigation — RadiumDesk-P-07-09-290

**Date:** 2026-09-15  
**Prompt ID:** `RadiumDesk-P-07-09-290`  
**Mode:** Investigate mapping-required classifier. Smallest safe code change is regression coverage only. No production data write. No deploy.

## Verdict

The Hardware dashboard shows **Product mapping required** when `HardwareSkuMapService::findProduct(channel, model_id)` returns null. That check is **Owner `channel_sku_maps` by `(channel, model_id)`**, then active + serialized Desk product. **Stock is not an input.**

The displayed name **Precision Biometrics PB 510 / PB1000 L1 F** is the commerce line `description` (Box `order_details.product_name`). It is not a resolved Desk SKU.

This is **legitimate missing mapping**, not a dashboard presentation bug and not a stock-availability bug. Desk cannot safely infer PB 510 vs PB 1000 from that combined listing. Production `RBP62` rows were **not** rewritten.

**Follow-on:** P-07-09-291 production SELECT names live `model_id` **1402** and proposes (does not insert) `radiumbox_com` / 1402 → `RBPB1000L1`. See `docs/desk-rbp62-production-mapping-p-07-09-291.md`.

## Prompt ID

Next unused ledger ID after `RadiumDesk-P-07-09-289`.

## Repo gate

| Item | Value |
|------|--------|
| Repo | `/agent/repos/radium-desk` · `github.com/radium-foundation/radium-desk` |
| Base | `main` `c738d635` = `origin/main` |
| Worktree | clean before this branch |
| Remote | `origin` |
| Production SELECT | **NO** — `ravi@187.127.129.16` SSH `Permission denied (publickey)` |
| Production write | **NO** |
| Deploy | **NO** |

## Path (code-verified)

```
RBP62
→ orders.order_id (support)
→ commerce_orders (support_order_id or UPPER(source_id))
→ commerce_order_items (physical_merchandise or model_id present)
→ HardwareFulfilmentProductLines::resolve()  → dashboard product name
→ hardware_fulfilments (if ingested)
→ HardwareSkuMapService::findProduct(channel, model_id)
→ HardwareAwaitingFulfilmentClassifier / HardwareFulfilmentOperationalClassifier
→ operatorStatus() = "Product mapping required"
```

### Displayed product name

`HardwareFulfilmentProductLines` uses `commerce_order_items.description`, else sku/catalog_sku, else support `orders.product_name`. It does **not** query inventory or `channel_sku_maps`. Combined listing text is therefore expected even when mapping fails.

### Mapping-required condition

Exactly one of:

1. **Awaiting (no HF):** paid hardware support order, unique Commerce, physical line(s), `findProduct` null → `HardwareAwaitingFulfilmentReason::ProductMappingRequired`.
2. **HF present:** state ≠ `ingested`, zero allocated serials, `findProduct(fulfilment.channel, item.model_id)` null → status **Product mapping required**, next action **View** (not Allocate Serial).

`findProduct` is `requireProduct` caught. `requireProduct` fails closed when:

- `model_id` is null / < 1
- no `channel_sku_maps` row for `(channel, model_id)`
- mapped `inventory_products` row missing
- mapped product `is_active` is false
- mapped product `is_serialized` is false

Name, SKU text, and available serial counts are **not** keys.

### What “stock available” means

`HardwareSerialAllocationService::requirements()` counts `inventory_serials` with status Available at `DELHI-RETAIL` / `MUMBAI` **only after** `findProduct` succeeds. Without a map, `available_qty` is **0** even if a similarly named SKU (`RBPB1000L1`) has serials. The dashboard classifier never reads stock.

Operators seeing stock on Inventory for `RBPB1000L1` / `RBTOUCH510` are looking at a **different relationship** than fulfilment mapping.

## Known-good compare

Owner-approved Box maps from P-07-09-27 (production data ticket; eight rows):

| Box `model_id` | Desk `inventory_products.id` (that ticket) |
|----------------|---------------------------------------------|
| 951 | 22 |
| 1006 | 30 |
| 946 | 28 |
| 945 | 27 |
| 930 | 17 |
| 931 | 18 |
| 1723 | 5 |
| 926 | 4 |

**Not mapped:** 950, 1010, **1003**, **1004**. Tests treat **946** (Mantra MFS110) as the known-good mapped hardware model: physical line + map → Review / Allocate Serial.

Box catalog from P-01-09-06 (Admin `product_stock`, not Desk maps):

| Box `products.id` | Name | Notes |
|------------------|------|--------|
| 1003 | PB 1000 - L1 (`PPPB1000L1`) | Stocked model (`attribute_id=5`) |
| 1004 | PB 510 | `status=0` (inactive) with leftover qty |

Desk opening SKU master includes `RBPB1000L1` and `RBTOUCH510`. Those SKUs are **not** a map from Box `model_id`.

The combined storefront name cannot choose 1003 vs 1004. P4 forbids name inference (MFS 100 vs 110; UGR 86 vs 89). PB 510 vs PB 1000 is the same class of collision (different serial rules: `H…` vs `LN`/`LU`).

## Classification

| Candidate | Result |
|-----------|--------|
| Missing product ID | Possible only if `model_id` null; Box emits `physical_merchandise` only when `order_details.modelid` is present. **UNKNOWN for live RBP62** without SELECT. |
| Missing model ID | Same. |
| Mismatched product/model | Combined listing is parent-style copy; identity is still Box `modelid`. |
| Inactive Desk product | Would also make `findProduct` null; **UNKNOWN** without SELECT. |
| Variant mismatch | Day-1 maps ignore variant; one `(channel, model_id)` → one Desk product. Combined PB 510 / PB1000 cannot be inferred. |
| Inventory linkage | Stock of `RBPB1000L1` is not linkage. |
| Stale fulfilment snapshot | Name comes from commerce description, not a SKU snapshot. |
| Classifier bug | **No.** Missing map is fail-closed by design (P-07-09-153). |
| Dashboard presentation bug | **No.** Name and status come from different fields on purpose. |
| Legitimate missing mapping | **Yes** for this listing class vs P0-M1 maps. Live `model_id` was unknown in this ticket; P-07-09-291 SELECT names it **1402**. |

## Required production data change (not performed)

Do **not** invent a map in this ticket.

1. Production SELECT only: `orders` / `commerce_orders` / `commerce_order_items` / `hardware_fulfilments` / `channel_sku_maps` / `inventory_products` / available serial counts for the **actual** `model_id` on RBP62.
2. Owner-approved `channel_sku_maps` row: `channel=radiumbox_com`, that `model_id`, `inventory_product_id` of the chosen serialized active Desk SKU (PB 1000 **or** PB 510, not both unless there are two lines).
3. Do not map from listing text. Do not map 1003 and 1004 to the same Desk product unless Owner confirms they are the same physical SKU (they are not in historical Admin stock).

**Impact if left unmapped:** RBP62 stays Exceptions / View; serial allocation stays blocked. Purchasing/history/C360/B2B/date paths unchanged.

**Rollback:** N/A — no production write. If a later Owner map is applied, delete that `channel_sku_maps` row to restore fail-closed.

## Tests

Regression coverage uses synthetic `RBP*` ids and Box model `1003`. It does **not** hard-code production `RBP62` and does not insert production maps.

## Explicit non-actions

- No `channel_sku_maps` INSERT
- No RBP62 UPDATE
- No deskd / named-file overlay
- No rsync
