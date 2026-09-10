# Recovery-only Get-IRN path — P-07-09-198

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-198  
**Date:** 2026-09-10  
**HEAD at inspect:** `b70fd83a`

## Binding

- Normal issuance: `EInvoiceGateway` remains `NullEInvoiceGateway`. `STATUTORY_EINVOICE_PROVIDER` stays `none`. Worker/auto-issue stay false.
- Recovery: `EInvoiceIrnRecoveryService` uses `WhitebooksIrnRecoveryGateway`, which exposes **only** `fetchExisting()`. No `submit()`. Not called from the outbox worker.
- Processor `recover()` still requires issuance flags; those flags were not enabled.

## Production probe

Overlay WhiteBooks adapter still lacks the P-194 `param1`/header Get-IRN shape (`getIrnHeaders` absent). Recovery service is not on the overlay (not deployed). Overlay `fetchExisting()` was **not** called.

Invoice `INV-076746` / 1062 / RDE318400 was already `submitted` with IRN. It was **not** mutated to ambiguous.

One inline P-194 Get-IRN (auth 1 + Get-IRN 1). GENERATE 0. Cancel 0. DB writes 0.

| Field | Result |
|---|---|
| HTTP | 200 |
| status_cd | `1` |
| IRN | match stored `974b217a7c86b097e083d625fa28691e11879539376c1a2bf5d670c309438119` |
| AckNo | `172621144003124` |
| AckDt | `2026-09-10 14:57:00` |
| Signed QR | present, len 944 (contents not printed) |
| SignedInvoice | returned, not stored |
| e_invoice_records | 1 row, status `submitted`, IRN/updated_at unchanged |

Provider `none`. Null gateway. Worker false. Auto-issue false.
