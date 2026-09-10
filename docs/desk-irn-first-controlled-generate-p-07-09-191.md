# First controlled WhiteBooks GENERATE — P-07-09-191

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-191  
**Date:** 2026-09-10  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk-irn-foundation`  
**Branch:** `feat/irn-foundation-phase-a`  
**HEAD at inspect:** `fdc84fee` (P-190 ledger)  
**Production:** KVM8 `srv1910783` / `187.127.129.16` `/var/www/radium-desk` DB `radium_desk`

**Verdict: SUCCESS — one GENERATE, one IRN on INV-076746 / invoice 1062.**

## Boundary

One in-process mint. `.env` provider remained `none`. `NullEInvoiceGateway` stayed bound. `worker_may_mint` stayed hardcoded `false`. Auto-issue remained `false`. IP was set in-process only (`187.127.129.16`); `STATUTORY_EINVOICE_GSP_IP_ADDRESS` stayed KEY_ABSENT. No Get-IRN. No second GENERATE. No deploy. No catalog/UQC mutation.

## Invoice selection

No hardware B2B invoice newer than 1062 exists (ids 1063+ are rdservice.in SAC 998313). **INV-076746** is the newest eligible B2B hardware invoice:

- issued 2026-09-10 14:21:29, source RDE318400
- no IRN, no prior GENERATE (P-188/190 were map-only)
- `e_invoice_records.status=skipped` with `skip_reason=worker_may_mint_off`
- `mustNotResubmit=false`, `mustRecover=false`
- one PCS-mapped SKU `946` → `RBMFS110L1`, HSN 84716050, no AMC/service line

## Payload (inspected before GENERATE)

`SupTyp=B2B`, `DocDtls.Typ=INV`, `No=INV-076746`, `Dt=10/09/2026`. Seller `07AAICP1128M1Z9`. Buyer `21CHNPS8997L1Z7`. HSN 84716050. Qty 2. Unit `PCS`. UnitPrice 2160.17. AssAmt 4320.34. GstRt 18. IGST 777.66. TotInvVal 5098. IsServc `N`. No EWB block.

## Request

1. Authenticate OK (`token_present=true`; token not stored).
2. One `POST https://api.whitebooks.in/einvoice/type/GENERATE/version/V1_03`.
3. Persist via existing `EInvoiceProcessor::persistResult` + `finalizeAfterIrn`.

## Result

HTTP 200, `status_cd=1`. IRN stored. AckNo `172621144003124`. AckDt `2026-09-10 14:57:00`. Signed QR stored. Signed invoice returned (flag only; not stored). PDF rewritten `statutory-invoices/1062.pdf`. `irn_filled=1`. Other hardware invoices still skipped / no IRN.

## Left off

Provider `none`. Null gateway bound. Worker mint false. Auto-issue false. `.env` IP still absent.
