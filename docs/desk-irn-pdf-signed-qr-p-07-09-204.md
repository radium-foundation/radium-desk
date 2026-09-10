# Statutory PDF visual QR from persisted SignedQR — P-07-09-204

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-204  
**Date:** 2026-09-10  
**HEAD at inspect:** `58dce3af41bbfbe11054f8818a6a5e7a56fe3971`  
**Implementation commit:** `216a4a00ddb0ad21f1b830c496a4f6a0e37d2282`  
**Production:** KVM8 `srv1910783` / `187.127.129.16` `/var/www/radium-desk` DB `radium_desk`

**Verdict: PASS — INV-076749 statutory values unchanged; PDF now embeds a visual QR encoded from the stored JWT.**

## Boundary

Validation/PDF gate only. No WhiteBooks GENERATE, Get-IRN, Cancel, or retry. No new invoice. Provider remained `none`. `NullEInvoiceGateway` stayed bound. `worker_may_mint` false. Auto-issue false. Stored IRN/Ack/SignedQR/SignedInvoice were not rewritten. Unrelated dirty POS/ingest/hardware files were not committed or overlaid.

## SignedQRCode format (invoice 1123)

Inspected persisted value only (contents not printed).

| Property | Value |
|----------|--------|
| Length | 944 ASCII bytes |
| SHA-256 | `7b6a84dc5a65c831bf6c7c642c7d54a410131b326ed89b1e3a1332e7962ede80` |
| Prefix | `eyJ` |
| JWT parts | 3 (150 / 450 / 342) |
| PNG / JPEG / data-URL | NO |

WhiteBooks `SignedQRCode` is a NIC JWT. The renderer QR-encodes that exact stored string. It does not decode, reserialize, or invent an image.

## Implementation

`EInvoiceSignedQrMatrix` fail-closes unless the stored value is a 64–4096 byte printable ASCII JWT. `SimplePdfRenderer` draws a 96pt vector QR (run-length rectangles, `% signed-qr-image`) when encoding succeeds. Invalid/empty SignedQR keeps the caption fallback. Invoices without IRN omit QR and caption.

Dependency: `bacon/bacon-qr-code` v3.1.1 (`dasprid/enum` 1.0.7).

## Invoice 1123

| Field | Result |
|--------|--------|
| Invoice | INV-076749 / 1123 |
| IRN | unchanged `d858cf9f0a582a7aec79eade603e66cf584e9a68bdde5a50a823793527a9d776` |
| AckNo | unchanged `172621145994081` |
| AckDt | unchanged `2026-09-10 17:21:00` |
| SignedQR | present, hash unchanged |
| SignedInvoice | `statutory-einvoice/1123/signed-invoice.txt` 2098 bytes SHA-256 `6c2dbe1a0db063c1ac9e5c3a726af703e9a212340fcdd87799cb3fca6072f161` |
| e-invoice rows | 1 |
| Duplicate IRN | none |
| `e_invoice_records.updated_at` | unchanged after PDF rewrite |

## PDF

Verification copy written first (`/tmp/p204-1123-verify.pdf` and overlay folder). Official `statutory-invoices/1123.pdf` rewritten only via `finalizeAfterIrn()` (PDF artifact + document checksum). Raster thumbnail: finder-pattern QR visible, not caption-only. IRN, Ack. No., Ack. date `10 Sep 2026 17:21`, HSN `84716050`, UQC `PCS`, serial `10564153`, totals Rs.2117.80 / Rs.381.20 / Rs.2499.00. JWT not leaked into the PDF.

## Production overlay

Named-file overlay from `216a4a00`. **Not** `deskd`. No migrate. No `.env`.

Backup: `/var/www/radium-desk/storage/app/private/overlays/p-07-09-204-20260910T120823Z`

Overlaid: `EInvoiceSignedQrMatrix.php`, `SimplePdfRenderer.php`, `StatutoryInvoicePdfPayload.php`, `vendor/bacon`, `vendor/dasprid`, Composer PSR-4 autoload entries for those packages. `/up` 200. Web root stayed `755 ravi:ravi`.

## WhiteBooks

GENERATE 0. Get-IRN 0. Cancel 0. Retries 0.

## Left off

Provider `none`. Null gateway. Worker false. Auto-issue false. Global issuance not enabled.
