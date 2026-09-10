# IRN catalog UQC readiness — P-07-09-186

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-186  
**Date:** 2026-09-10  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk-irn-foundation`  
**Branch:** `feat/irn-foundation-phase-a`  
**HEAD at inspect:** `97c6d4d1` (P-185 mapper)  
**Production:** KVM8 `srv1910783` `/var/www/radium-desk` DB `radium_desk`

**Verdict: BLOCKED — 0 of 9 priority SKUs assigned. All remain NULL.**

No catalog mutation. No invoice mutation. IRN remains OFF.

## Scope

Nine P-170 / P-175 evidence-backed B2B hardware SKUs only. Services not examined for assignment. No bulk catalog update.

## IRN safety (production, before and after)

| Flag | Value |
|------|--------|
| `STATUTORY_EINVOICE_PROVIDER` | `none` |
| Gateway | `NullEInvoiceGateway` |
| `worker_may_mint` | `false` |
| `auto_issue_on_pos_complete` | `false` |
| statutory invoices | 1061 |
| `e_invoice_records` | 1061 all `skipped` |
| `irn` filled | 0 |
| WhiteBooks this prompt | not called |

## Mapper (P-185)

`EInvoiceUqcMapper` accepts NIC codes including `GGK`, `MLT`, `PCS`, `NOS`, `UNT`. Mapper acceptance is **not** a product assignment. Missing/unknown still fail closed. `GGR` is rejected.

## Catalog fields available

`inventory_products`: `id,sku,name,hsn_code,uqc,gst_percentage,unit_price,unit_cost,is_serialized,tracks_batch,is_active,device_model_id`.

There is **no** sales-unit / UOM column. Opening-inventory field matrix (P-04-09-08) already recorded unit-of-measure **absent** on Admin `products`.

`inventory_product_packaging` stores shipping `weight_unit=kg` and `dimension_unit=cm` only. P-169: packaging kg/cm is **not** GST UQC.

## Before (all nine)

Queried production `inventory_products` by id. All nine found. All `uqc` NULL. No unexpected stored UQC.

| ID | SKU | Name | HSN | serialized | uqc before | packaging |
|----|-----|------|-----|------------|------------|-----------|
| 28 | RBMFS110L1 | Mantra MFS 110 L1 Single Fingerprint Biometric Scanner | 84716050 | yes | NULL | 0.240 kg, 14×9×7 cm |
| 22 | RBIMSOE3L1 | Morpho MSO 1300 E3 RD L1 … | 84716050 | yes | NULL | 0.200 kg, 10×8×8 cm |
| 5 | RBUGR89GPS | RADIUM UGR86 89-NaviC UIDAI Approved GPS | 85269190 | yes | NULL | 0.100 kg, 12×6×4 cm |
| 2 | RBFM220UFP | Access FM220 USB L1 Single Fingerprint Scanner | 84716050 | yes | NULL | 0.200 kg, 13×8×7 cm |
| 4 | RBUGR86GPS | RADIUM UGR 86 UIDAI Approved USB GPS Receiver | 85269190 | yes | NULL | 0.100 kg, 12×6×4 cm |
| 17 | RBFUTFS80H | Futronic FS80H USB 2.0 Biometric Single Fingerprint Scanner | 84716050 | yes | NULL | 0.200 kg, 12×8×8 cm |
| 18 | RBFUTFS88H | Futronic FS88H Single Fingerprint USB Biometric Scanner | 84716050 | yes | NULL | 0.200 kg, 12×8×8 cm |
| 27 | RBMFS100L0 | Mantra MFS 100 USB Single Fingerprint Biometric Scanner | 84716050 | yes | NULL | 0.200 kg, 14×9×7 cm |
| 30 | RBMIS100IR | Mantra MIS100 V2 Single Iris Scanner Biometric Device | 84716050 | yes | NULL | 0.240 kg, 16×9×5 cm |

Stock `available_qty` is integer device counts (serial-backed). That is inventory counting, not a NIC UQC.

## Other sources inspected (not sufficient)

| Source | Finding | Use as UQC? |
|--------|---------|-------------|
| `inventory_products.uqc` | NULL on all 9 | no |
| Packaging kg/cm | shipping dimensions; not GST quantity unit | **no** — would be KGS/CMS if misused |
| `statutory_invoice_items.uqc` | RBMFS110L1 1 line, RBFM220UFP 1 line, both uqc empty; others none | no |
| `commerce_order_items.uqc` | same two SKUs, uqc empty | no |
| POS `inventory_sale_lines` | 0 lines for these product ids | no |
| `channel_sku_maps` | Box model ids 946/951/… and RIN slugs; no unit | no |
| `radiumbox_prod.products` | no UQC column (`hsn_code`, min/max qty only); Desk DB user cannot SELECT; root inspect: no unit/uqc column | no |
| Opening workbook | one serial = one physical unit; no UQC column | no — serialisation ≠ PCS/NOS |
| P-185 mapper | PCS/NOS/UNT are *allowed codes* | not product evidence |

## Assignment decision

For every SKU: **leave NULL**.

Reason (same for all nine): no authoritative stored **sales quantity unit**. Serialized hardware and integer qty show discrete devices; the prompt forbids inferring `PCS` or `NOS` from that. Packaging kg/cm must not be mapped to `KGS`/`CMS` as the invoice UQC.

| ID | SKU | Assigned | After | Blocker |
|----|-----|----------|-------|---------|
| 28 | RBMFS110L1 | no | NULL | no_authoritative_sales_unit |
| 22 | RBIMSOE3L1 | no | NULL | no_authoritative_sales_unit |
| 5 | RBUGR89GPS | no | NULL | no_authoritative_sales_unit |
| 2 | RBFM220UFP | no | NULL | no_authoritative_sales_unit |
| 4 | RBUGR86GPS | no | NULL | no_authoritative_sales_unit |
| 17 | RBFUTFS80H | no | NULL | no_authoritative_sales_unit |
| 18 | RBFUTFS88H | no | NULL | no_authoritative_sales_unit |
| 27 | RBMFS100L0 | no | NULL | no_authoritative_sales_unit |
| 30 | RBMIS100IR | no | NULL | no_authoritative_sales_unit |

**Changed count:** 0  
**Remaining NULL:** 9 of 9  
**Other products touched:** 0  
**Catalog uqc_filled (all 87 products):** 0

## After re-query

Same nine rows, `uqc` still NULL. HSN, GST%, prices, names unchanged. Invoice/e_invoice counts unchanged.

## Owner input needed to unblock

An explicit sales-unit decision per SKU (or a stored catalog UOM field populated from a statutory source), then a later prompt may write `inventory_products.uqc` to a P-185 mapper code. Do not treat “sold as pieces” as that decision.

## Not performed

Production UPDATE, WhiteBooks, GENERATE/Get-IRN, provider/gateway/worker/auto-issue changes, deploy, service UQC, historical invoice backfill.
