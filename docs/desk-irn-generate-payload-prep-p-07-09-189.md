# IRN GENERATE payload preparation — P-07-09-189

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-189  
**Date:** 2026-09-10  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk-irn-foundation`  
**Branch:** `feat/irn-foundation-phase-a`  
**HEAD at inspect:** `d3393c6d` (P-188 BLOCKED audit)  
**Production:** KVM8 `srv1910783` `/var/www/radium-desk` — IRN remains OFF. **Not deployed.**

## P-188 reproduction (invoice 1062)

| Field | Stored | GENERATE before this prompt |
|-------|--------|------------------------------|
| Catalog `RBMFS110L1` | `PCS` | unused |
| Line `uqc` | NULL | `missing_uqc`, `generateBody=null` |
| Stored `unit_price` | 2549.00 GST-inclusive | copied to NIC `UnitPrice` |
| `AssAmt` | 4320.34 | 4320.34 |
| Qty | 2 | 2 |

## Part A — UQC

Statutory line UQC remains the snapshot when populated. GENERATE mapping now calls `EInvoiceUqcMapper::resolveLineOrCatalog()`.

If the line is empty, catalog UQC is read **only** when the product is unambiguous:

1. exactly one `inventory_products` row whose `sku` equals the line SKU, or
2. exactly one Owner `channel_sku_maps` row for this invoice channel + numeric model id (Box `946` → `RBMFS110L1`).

The catalog code is used only if the mapper accepts it (`PCS` is on the P-185 NIC master). No PCS/NOS default from serialization. Invalid catalog codes fail closed (`unsupported_uqc`). Missing both sources → `missing_uqc`. Historical `statutory_invoice_items.uqc` is not written.

## Part B — UnitPrice

Desk `unit_price` on invoice 1062 is the **GST-inclusive selling price** (₹2,549 × 2 = ₹5,098). Stored `taxable_value` / `AssAmt` is tax-exclusive (₹4,320.34). IGST 18% = ₹777.66. Totals are already consistent and were not rewritten.

NIC 1.1 `UnitPrice` is the tax-exclusive rate such that `UnitPrice × Qty = TotAmt` (and `AssAmt` when discount is 0).

Correct payload for 1062:

`UnitPrice = 4320.34 / 2 = 2160.17`  
`2160.17 × 2 = 4320.34 = AssAmt`  
`4320.34 + 777.66 = 5098.00 = TotItemVal`

The factory no longer copies stored inclusive `unit_price` into NIC `UnitPrice`.

## Part C — PCS whitelist

`EInvoiceUqcMapper` already includes `PCS` from P-185 on this branch. Tests assert acceptance. Production overlay still lacks `PCS` until a later deploy. **NO deploy in this prompt.**

## Production data

Invoice 1062 line UQC must remain NULL. Catalog `PCS` unchanged. No WhiteBooks. Provider `none`.
