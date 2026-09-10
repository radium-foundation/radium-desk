# WhiteBooks Get-IRN-by-document-details error contract — P-07-09-196

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-196  
**Date:** 2026-09-10  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk-irn-foundation`  
**Branch:** `feat/irn-foundation-phase-a`  
**HEAD at inspect:** `4586d6be` (P-195)

Read-only verification. Adapter not modified. GENERATE/Cancel not called. Provider remained `none`. `NullEInvoiceGateway` stayed bound.

## Verdict

**PARTIALLY VERIFIED** by one production read-only Get-IRN probe.

Production **IRN found** remains P-194. Production **IRN not found** is now verified as HTTP 200 / `status_cd=0` / error `2154`, not HTTP 404.

All other error conditions remain **UNKNOWN**.

## Documentation reviewed

| Source | Result |
|---|---|
| WhiteBooks Postman `E-INVOICE-API` GETIRNBYDOCDETAILS | Request shape matches P-194. Example errors are empty `text/plain` HTTP 404 and 500 stubs reused across endpoints. 404 text: “requested entity is not found **or** requested API is not found.” 48-hour limit is a description only. **Does not establish a JSON error envelope.** |
| `E-invoicing-error-codes.docx` | NIC e-invoice codes (`einv-apisandbox.nic.in`). Code **2154** message is “IRN details are not found”; the NIC *reason* paragraph is GENERATE-duplicate wording. **Not proof of WhiteBooks production HTTP/JSON.** |
| WhiteBooks developer portal screenshots (2026-09-10) | API keys / overview / resources. No Get-IRN error JSON. |
| P-193 / P-194 / P-195 ledger + adapter | Success path verified and implemented. Error JSON left unverified until this gate. |

NIC behaviour is not used as WhiteBooks production proof.

## Production probe

Documentation did not establish the error contract. One safe lookup was used:

- Invoice `INV-076748` / id 1091 / issued `2026-09-10 15:33:35` / Delhi GSTIN prefix `07`
- Tax invoice, issued, buyer GSTIN present, `e_invoice_records.status=skipped`, IRN empty
- Chosen because it is a real document known **not** to have an IRN (only stored IRN in production remains `INV-076746`)
- Not a malformed request. Not an invalid credential. Within hours of issue (not a 48-hour-window test)

Request (P-194 shape, not the production overlay adapter):

- Auth: 1 × `GET /einvoice/authenticate`
- Get-IRN: 1 × `GET /einvoice/type/GETIRNBYDOCDETAILS/version/V1_03?param1=INV&email=…`
- Headers: `docnum`, `docdate=10/09/2026`, `ip_address=187.127.129.16` (in-process; env IP still KEY_ABSENT), client pair, `username`, `auth-token`, `gstin`
- Password **not** sent on Get-IRN
- GENERATE: 0. Cancel: 0. Retries: 0

Sanitized Get-IRN evidence:

| Field | Value | Class |
|---|---|---|
| HTTP | 200 | VERIFIED |
| Body | JSON, 120 bytes | VERIFIED |
| Top-level keys | `irp`, `status_cd`, `status_desc` | VERIFIED |
| `status_cd` | `"0"` | VERIFIED |
| `status_desc` | stringified JSON `[{"errorCode":"2154","errorMessage":"IRN details are not found"}]` | VERIFIED |
| `error_cd` / `error_desc` | absent | VERIFIED |
| `data` | key absent | VERIFIED |
| `data.Irn` | absent | VERIFIED |

Auth: HTTP 200, `status_cd=1`, token present (not stored). Invoice and IRN counts unchanged (1105 invoices, 1 filled IRN). Candidate IRN still empty.

## Condition table

| Condition | HTTP | WhiteBooks response | Classification |
|---|---|---|---|
| IRN found | 200 | `status_cd=1`, `data` present with `Irn`/`AckNo`/`AckDt`/`SignedInvoice`/`SignedQRCode`/`Status` (P-194, `INV-076746`) | VERIFIED |
| IRN not found | 200 | `status_cd=0`, no `data`, `status_desc` string contains `errorCode=2154` / `errorMessage=IRN details are not found` | VERIFIED (this probe, never-issued document) |
| Invalid request | | | UNKNOWN |
| Unauthorized | | | UNKNOWN |
| Expired/invalid token | | | UNKNOWN |
| Outside 48-hour window | | Postman description only; not probed | UNKNOWN |
| Temporary provider failure | | | UNKNOWN |
| Server failure | | Postman stub HTTP 500 empty body only | UNKNOWN |

Successful Get-IRN with `status_cd=1` and no `data.Irn`: **UNKNOWN** (not observed).

HTTP 404/500 as production Get-IRN not-found or server-failure: **UNKNOWN**. Production not-found was **not** 404.

## Adapter comparison (P-195, unchanged)

Current mapping:

- HTTP 404 → `ambiguous`
- Get-IRN HTTP 5xx → `temporary_failure`
- no retries
- HTTP 2xx with no `data.Irn` → `ambiguous` (`mapFetch`)

**Not justified as the production not-found contract.** Production not-found is HTTP 200 / `status_cd=0` / `2154`. HTTP 404 is not that signal. 5xx remains unverified.

The 2xx empty-IRN path would still fail closed as `ambiguous` and would **not** parse `2154` out of `status_desc`. After an ambiguous GENERATE, recovery would keep Get-IRN-only and would not treat verified not-found as “never issued.”

**Required future change (not done here):** consume `status_cd=0` + `2154` as verified not-found; do not treat HTTP 404 as not-found; do not invent other NIC codes.

## Safety

Provider `none`. Null gateway bound. `worker_may_mint=false`. Auto-issue `false`. No schema/config/deploy. No credentials/tokens/SignedInvoice/SignedQR printed.
