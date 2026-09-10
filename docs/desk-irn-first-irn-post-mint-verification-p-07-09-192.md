# First IRN post-mint verification — P-07-09-192

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-192  
**Date:** 2026-09-10  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk-irn-foundation`  
**Branch:** `feat/irn-foundation-phase-a`  
**HEAD at inspect:** `825702de` (P-191)  
**Production:** KVM8 `srv1910783` / `187.127.129.16` `/var/www/radium-desk` DB `radium_desk`

**Verdict: POST-MINT VERIFICATION PASSED**

Read-only. No WhiteBooks HTTP. No `.env`/code/deploy. Dirty IRN worktree untouched except this doc + ledger.

## A — Stored record (invoice 1062)

One `e_invoice_records` row. `status=submitted`, `provider=whitebooks`.

| Field | Expected | Stored | Match |
|-------|----------|--------|-------|
| IRN | `974b217a7c86b097e083d625fa28691e11879539376c1a2bf5d670c309438119` | same | YES |
| AckNo | `172621144003124` | same | YES |
| AckDt | `2026-09-10 14:57:00` | same | YES |

No duplicate IRN row. One outbox `statutory-irn:1062` (`completed`, attempts=1, processed 14:22:03 during skip; GENERATE was the later P-191 one-shot).

## B — Signed QR

Returned. Persisted on `e_invoice_records.signed_qr` (len 944, nonempty). Laravel log: 0 AuthToken / SignedQR / GENERATE hits. Blob not printed.

## C — Signed invoice

**VERIFIED PRESENT** in GENERATE (`has_signed_invoice=true`).  
**VERIFIED NOT PERSISTED:** no `signed_invoice` column; `WhitebooksResponseMapper::safeSuccessPayload` stores only the flag. Not implemented in this prompt.

## D — PDF

`storage/app/private/statutory-invoices/1062.pdf` (6909 bytes, SHA-256 `c88627ae…` matches document checksum). Opens as PDF 1.4, 1 page.

Displayed: INV-076746, full IRN, Ack. No. + Ack. date `10 Sep 2026 14:57`, caption `Signed QR issued with this IRN.` Totals Rs.4320.34 / Rs.777.66 / Rs.5098.00. No `/XObject` image (visual QR is a future hook, not an empty placeholder). Raster thumbnail inspected; no rewrite.

## E — Idempotency (no mint, no persist, no HTTP)

`recordHasIssuedIrn=true`, `mustNotResubmit=true`, `mustRecover=false`. Processor returns before `gateway->submit()`. Outbox key already exists (`firstOrCreate` would not insert). `process()` not invoked.

## F — Integrity

Invoice totals unchanged. Line UQC still NULL. Catalog `RBMFS110L1` = `PCS`. `irn_filled=1`. Other hardware IRNs null. Global provider `none`; Null bound; worker/auto-issue false.

## Remaining (non-blocking)

Visual signed-QR image is not drawn. PDF line UQC shows `-`. HSN sits tight against wrapped description.
