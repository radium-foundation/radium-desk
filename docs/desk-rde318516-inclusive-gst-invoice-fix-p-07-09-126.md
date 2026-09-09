# Hardware invoice inclusive GST one-paisa fix — RadiumDesk-P-07-09-126

**Date:** 2026-09-09  
**Prompt ID:** `RadiumDesk-P-07-09-126`  
**Mode:** Code fix for hardware invoice mint GST rounding. Do not issue RDE318516.

## Pre-change

| Field | Value |
| --- | --- |
| Repository | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` |
| Branch | `main` |
| Before SHA | `c29e3dcd2456822bfbe724d856f132b17935a656` |
| Worktree | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` |
| Remote | `origin` `git@github.com:radium-foundation/radium-desk.git` |
| Production | KVM `srv1910783` `/var/www/radium-desk` DB `radium_desk` |

HF15 before: `serials_allocated`, 10 serials, invoice count 0. Commerce CO-000811 still 21177.96 / 3812.04 / 24990.00.

## Change

`HardwareInclusiveGstReconciler` (hardware mint only):

1. Gross `line_total` is the invoice total.
2. GST rate is the stored percentage, or `round((tax / taxable) × 100, 2)` when omitted.
3. Compare stored exclusive identity and stored taxable+tax vs gross in **paise**.
4. Exact match keeps stored taxable/tax (RDE318434 / RDE318517).
5. Both deltas ≤ 1 paisa: invoice `taxable = round(gross / (1 + rate/100), 2)`, `tax = round(taxable × rate/100, 2)`, and `taxable + tax` must equal gross in paise.
6. Larger deltas still throw `GST amount does not match taxable value × rate.`
7. Commerce rows are not written.
8. `GstSplitService` is unchanged: Delhi seller + Karnataka POS → IGST; intra-state → CGST/SGST on the projected tax.

RDE318516 invoice projection: taxable **21177.97**, IGST **3812.03**, total **24990.00**.

## Tests

Unit + feature coverage for qty 1 / qty 10 / qty 5, >1-paisa fail, missing tax, intra-state CGST/SGST, interstate IGST, gross paise identity, idempotency, serial-count. Existing P3 + HTTP + GstSplit tests still pass.

## Not performed

Issue Invoice for RDE318516. Commerce SQL. Serial change. Shipment/AWB/Shiprocket. Global outbox. `desk deploy` full rsync (dirty unrelated tree). Migrations. `.env`.
