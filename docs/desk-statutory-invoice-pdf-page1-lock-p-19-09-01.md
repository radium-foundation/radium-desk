# Statutory invoice PDF page-1 verification block lock (RadiumDesk-P-19-09-01)

## Problem

`SimplePdfRenderer` used a fixed `FIRST_PAGE_SERIAL_LIMIT = 50`. For hardware invoices with an issued IRN, up to 50 serial numbers could occupy page 1 and push the closing block (QR + e-Invoice Verification + Authorized Signatory) to page 2.

Example class: `INV-0767146` (50 serials + IRN).

## Root cause

`rowsFitWithClosing()` reserves vertical budget for the serial summary and closing block. When the serial summary was too tall, closing was deferred to a later page stream while serials still rendered on page 1.

## Fix (presentation only)

- Resolve first-page serial capacity dynamically from `FIRST_PAGE_BODY_BUDGET` (400pt), line-item height, closing height (including IRN/QR/signatory), and serial-grid height.
- Move overflow serials to Annexure A before deferring the closing block.
- `FIRST_PAGE_SERIAL_LIMIT` remains an absolute ceiling (50), not the default first-page count.

## Rollback

| Item | Value |
|------|-------|
| Last stable renderer SHA (pre-change) | `2d30280c051086c431b9a7ea11fefc5a76b0b03a` |
| Renderer file | `app/Services/StatutoryInvoice/SimplePdfRenderer.php` |
| Production release (at inspect) | `v4.0.88` / `c2b24169` |
| Rollback method | Revert renderer file only; no DB migration |

## Bulk regeneration plan (read-only inventory — NOT executed)

Production DB was **not** queried in this prompt. Methodology for a future approval gate:

1. **Count existing statutory PDFs**
   ```sql
   SELECT COUNT(*) FROM statutory_invoice_documents WHERE status = 'generated' AND path IS NOT NULL;
   ```
   Prior investigation (P-18-09-62) cited ~2380 stored artifacts.

2. **Identify old-layout PDFs**
   - Heuristic: invoices with `e_invoice_records.irn` set AND `serial_numbers` count high enough that old renderer placed verification on page 2.
   - Safer: regenerate all `generated` documents where `e_invoice_records.status = submitted` and hardware serials exist, after spot-checking a sample.

3. **Safe regeneration path**
   - `StatutoryDocumentService::regeneratePresentation($invoice)` — presentation only; no mint, tax, or IRN mutation.
   - Backup each `storage/app/private/statutory-invoices/{id}.pdf` before overwrite.
   - Idempotency key: `sha256(invoice_id + renderer_version + document.checksum)`.

4. **Cannot safely regenerate**
   - Cancelled invoices (skip).
   - Documents with `status = failed` and missing source invoice rows (manual review).
   - Historical invoices where serial allocation changed after issuance (use serial-correction path instead).

5. **Verification per batch**
   - Page 1 contains QR (when signed QR exists), IRN, Ack, signatory.
   - Financial totals unchanged vs invoice row.
   - Serial count and uniqueness preserved.
   - QR decode (zbar) on sample.

6. **Rollback**
   - Restore backed-up PDF blobs from batch backup prefix.
   - Revert renderer deploy if systemic regression.

## Deployment plan (NOT executed)

1. Merge to `main` after regression tests pass (Imagick required).
2. Tagged release after owner approval.
3. Full or surgical `desk deploy` to KVM8 `/var/www/radium-desk`.
4. Post-deploy: generate test hardware B2B invoice with IRN + 50 serials; verify page-1 lock.
5. Bulk regeneration remains a separate approval gate.

## Protected boundaries

- No financial data, IRN, numbering, or logo asset changes.
- No production DB writes or PDF replacements in this prompt.
