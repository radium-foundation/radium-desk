# First new-invoice WhiteBooks GENERATE — P-07-09-203

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-203  
**Date:** 2026-09-10  
**HEAD at inspect:** `25f12271f380265ec1bfb88eb50b7c367577d702`  
**Production:** KVM8 `srv1910783` / `187.127.129.16` `/var/www/radium-desk` DB `radium_desk`

**Verdict: SUCCESS — one new invoice, one GENERATE, IRN persisted.**

## Boundary

No global IRN enablement. `.env` provider remained `none`. `NullEInvoiceGateway` stayed bound. `worker_may_mint` false. Auto-issue false. IP from `STATUTORY_EINVOICE_GSP_IP_ADDRESS`. No Get-IRN. No retry. No Cancel. No deploy. Unrelated dirty worktree files not committed.

Hardware fulfilment of paid order RDE318171 was not used: isolated `markReady` refuses `ordered_at` 2026-09-04 (before 2026-09-05 cutoff). Finance mint of that order also fails closed (no branch, missing line GST %).

## Invoice

Created through deployed POS `completeSale` + `issueFromPosSale` (not the worker).

| Field | Value |
|-------|--------|
| Invoice | INV-076749 / 1123 |
| Source | inventory sale 2 / POS-000002 |
| Issuer | DELHI-RETAIL / seller `07AAICP1128M1Z9` |
| Buyer GSTIN | `27AAICP1128M1Z7` (verified Mumbai registration; not invented) |
| SKU | RBMFS110L1 |
| HSN | 84716050 |
| UQC | PCS |
| Qty | 1 |
| Taxable | 2117.80 |
| IGST | 381.20 |
| Total | 2499.00 |

Payload audit before GENERATE: eligible, submittable, gaps none, UnitPrice × Qty = AssAmt, IsServc `N`, no EWB block.

## WhiteBooks

1. Authenticate HTTP 200 `status_cd=1`
2. One `POST /einvoice/type/GENERATE/version/V1_03` HTTP 200 `status_cd=1`

IRN `d858cf9f0a582a7aec79eade603e66cf584e9a68bdde5a50a823793527a9d776`. AckNo `172621145994081`. AckDt `2026-09-10 17:21:00`. Signed QR present. SignedInvoice 2098 bytes on private `local` `statutory-einvoice/1123/signed-invoice.txt` SHA-256 `6c2dbe1a0db063c1ac9e5c3a726af703e9a212340fcdd87799cb3fca6072f161`. No public URL. INV-076746 IRN unchanged.

## PDF

Rewritten `statutory-invoices/1123.pdf`. Opens. Shows invoice number, IRN, Ack, HSN, PCS, totals. Signed QR is the caption only — no QR image XObject.

## Left off

Provider `none`. Null gateway. Worker false. Auto-issue false.
