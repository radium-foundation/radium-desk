# WhiteBooks IRN CANCEL contract — P-25-09-49

## Scope

Desk adapter endpoint implemented in `WhitebooksEInvoiceGateway::CANCEL_PATH`:

`POST /einvoice/type/CANCEL/version/V1_03?email={issuer_email}`

## Local implementation (code-derived)

Request body built by `WhitebooksNicPayloadFactory::cancelBody()`:

| Field | Source | Notes |
| --- | --- | --- |
| `Irn` | submitted `EInvoiceRecord.irn` | required |
| `CnlRsn` | `config('statutory_invoices.einvoice.irn_cancel_reason_code')` | default `'3'` |
| `CnlRem` | cancellation reason string | trimmed; max 100 chars; fallback `Statutory invoice cancellation` |

Authentication follows the same signed-header pattern as GENERATE / GETIRNBYDOCDETAILS:

- `GET /einvoice/authenticate?email=…` → `AuthToken`
- `POST CANCEL` with `client_id`, `client_secret`, `username`, `auth-token`, `gstin`, `ip_address`

Response mapping (`WhitebooksResponseMapper::mapCancel()`):

- `status_cd = 0` → permanent failure (`cancel_rejected`)
- success when `data.CancelDate` or `data.CancelDt` present, or `data.Status` in `CNL` / `CAN` / `Cancelled`, or `status_cd = 1` with `data.Irn`
- otherwise → unknown (`ambiguous_cancel_response`)

HTTP classification in gateway:

- `401` / `403` → permanent (`cancel_unauthorized`)
- `400–499` (except above) → permanent (`cancel_validation_error`)
- `429`, `5xx`, timeout/transport → unknown
- malformed JSON on `200` → unknown

## Authoritative provider / NIC documentation in repo

**UNKNOWN** — no local WhiteBooks or NIC document was found that independently verifies the CANCEL V1_03 request/response contract.

Verified in-repo references exist only for:

- `GET /einvoice/authenticate`
- `POST GENERATE V1_03`
- `GET GETIRNBYDOCDETAILS V1_03` (P-194 / P-196)

## Live verification

**NO** — this prompt does not call live WhiteBooks or NIC.

## Credit Note (CRN) GENERATE payload

CRN IRN payload fields are derived from existing GENERATE mapper conventions:

- `DocDtls.Typ = CRN`
- `RefDtls.PrecDocDtls[]` with `InvNo`, `InvDt`, optional `Irn` from linked original invoice

**UNKNOWN** — no live CRN GENERATE verification against WhiteBooks in this prompt.
