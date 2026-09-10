# Persist WhiteBooks SignedInvoice — P-07-09-201

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-201  
**Date:** 2026-09-10  
**HEAD before:** `fe8662fcbe0ad0973323ef4e02e1565a72bbd1cc`  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk-irn-foundation`  
**Branch:** `feat/irn-foundation-phase-a`

## Storage decision

WhiteBooks `SignedInvoice` is a signed statutory string (JWT-like). Statutory PDFs already use private `Storage::disk('local')` (`storage/app/private`, gitignored). `signed_qr` stays in the database because it is small and needed for PDF rendering. SignedInvoice is larger, not printed on the PDF, and must not be JSON-reserialized.

**Chosen representation:** private filesystem + metadata on `e_invoice_records`.

| Item | Value |
|------|--------|
| Disk | `local` (`storage/app/private`) |
| Path | `statutory-einvoice/{invoice_id}/signed-invoice.txt` |
| Payload | exact WhiteBooks string (no decode/rewrite) |
| Metadata | `signed_invoice_disk`, `signed_invoice_path`, `signed_invoice_sha256`, `signed_invoice_bytes`, `signed_invoice_persisted_at` |
| Flag | stored `has_signed_invoice` is true only after a successful persist |
| Public URL | none (`publicUrl()` always returns null) |
| Encryption | none invented; same disk as statutory PDFs. App-level `Crypt` is not used for PDFs. |

## Why not a DB blob

`signed_qr` is already `text` on `e_invoice_records`. SignedInvoice is a bulk signed payload. Putting it in JSON `response_payload` previously only stored a flag to avoid leaking it. Private files match `statutory-invoices/{id}.pdf`.

## Safety

- Null/empty cannot overwrite an existing file.
- Same payload is idempotent (first write kept).
- IRN/Ack/QR unchanged by SignedInvoice persist.
- Persist failure leaves IRN/QR intact and sets `signed_invoice_persist_failed` with `has_signed_invoice=false`.
- 2154 does not create a file.
- Historical rows remain valid with NULL metadata.
- No production WhiteBooks call to backfill INV-076746 (payload was never stored).

## Migration

`2026_09_10_170000_add_e_invoice_signed_invoice_storage.php`  
Additive nullable columns only. `down()` drops those columns. Does not touch `irn` / `ack_no` / `signed_qr`.

## IRN OFF

provider `none`; `NullEInvoiceGateway`; worker false; auto-issue false.
