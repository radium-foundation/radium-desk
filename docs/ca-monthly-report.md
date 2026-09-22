# CA Monthly Report

**Prompt:** `RadiumDesk-P-21-09-07` (contract), `RadiumDesk-P-21-09-23` (export hardening v4.0.105)

## Period filter

- **Date of Invoice:** `statutory_invoices.issued_at`
- Supports monthly / arbitrary inclusive date ranges from the Finance report UI.

## Export contract

- **27 columns** in the exact Owner order (`CaMonthlyReportDefinition::HEADERS`).
- Export is **line-grain**: one row per statutory invoice line with all product detail retained.
- Invoice-level **Shipping** appears on the **first line only** of each invoice.
- **Short/Excess** (`statutory_invoices.rounding`) appears on the **last line only**.

## Preview UI

- **Invoice-grain pagination** (default 50 invoices per page).
- Multi-line invoices render a parent summary row with `+` / `−` expand/collapse.
- Single-line invoices render as a normal row (no expand control) with product name shown under the customer.
- Child rows load with the page (no extra network requests on expand).

## Ordertype

| Label | Rule |
|-------|------|
| Hardware | Invoice contains hardware lines only |
| Service | Invoice contains service lines only (including multiple service lines) |
| Bundled | Invoice contains **both** hardware and service lines |

Classification priority:

1. Commerce `shipping_line_kind = physical_merchandise` (authoritative for commerce invoices)
2. Desk POS / inventory sale channel semantics
3. Desk Service channel / service order source
4. SAC `99xxxx` vs goods HSN fallback

## Cancelled invoices

All cancelled statutory invoices in the selected period are **included**. Original values and `document_type` are preserved. Payment evidence is reported in preflight only; it does not exclude cancelled invoices.

## Document Type

- Source: `statutory_invoices.document_type`
- Human-readable label via `StatutoryInvoiceDocumentType::label()`
- Cancelled tax invoices remain **Tax invoice** unless the statutory record is a credit note.

## Shipping

- Schema: `statutory_invoices.shipping_amount` (decimal, default `0`, immutable after issue)
- Mint hook: `StatutoryInvoiceMintRequest::$shippingAmount` persisted by `StatutoryInvoiceService::mint()`
- **No historical backfill.** Production audited period has zero shipping coverage.
- **Upstream integration still required:** commerce/POS/service mint paths must pass a real pre-tax shipping amount when a source exists. Desk does not infer shipping from AWB, Shiprocket freight, or total deltas.

## Payment evidence (display)

Resolver order for **Payment Mode** column:

1. `payment_method`
2. Service POS `PaymentAllocation` → linked `CustomerPayment.method`
3. `payment_reference` fallback

Preflight still summarizes cancelled payment-evidence categories for CA review.

## Export hardening (v4.0.105)

### Bounded memory

- Line export uses invoice `chunkById()` batches (`ca_monthly_report.invoice_chunk_size`, default 25).
- CSV sync downloads stream rows directly to `php://output`.
- XLSX writes worksheet XML incrementally to a temp file, then zips bounded artifacts.
- The legacy `exportRows()` array helper remains for tests/small fixtures only.

### Sync vs async threshold

- Default `ca_monthly_report.sync_max_lines = 500` (override via `CA_MONTHLY_REPORT_SYNC_MAX_LINES`; benchmarked in `CaMonthlyReportExportBenchmarkTest`: 500 lines ≈262ms / 105MB peak on sqlite).
- Async generation jobs use the `maintenance` queue (`CA_MONTHLY_REPORT_EXPORT_QUEUE`), already consumed by production Supervisor at lowest priority.
- Rationale: production v4.0.104 OOM at 128MB for a full September 2026 month; a single-day export (~388 lines) succeeded.
- Exports above the threshold create a `ca_monthly_report_exports` row and dispatch `GenerateCaMonthlyReportExportJob`.

### Artifact storage

- Disk: `local` private storage (`storage/app/private/ca-monthly-report-exports/`).
- Retention: `ca_monthly_report.retention_hours` (default 72h).
- Cleanup: `php artisan ca-monthly-report:prune-exports --execute` (scheduled daily 03:30).

### Secure download

- Authenticated download: `finance.reports.ca-monthly.exports.download` (owner + `finance.reports.export`).
- Email link fallback: signed `finance.reports.ca-monthly.exports.download.signed` (expires with artifact).

### Email delivery

- Optional recipient on queue/export form.
- Uses existing `NotificationMailSender` + `CaMonthlyReportExportMail`.
- Attachment when `file_size_bytes <= ca_monthly_report.max_email_attachment_bytes` (default 8 MiB); otherwise secure download link.
- Email is queued separately (`SendCaMonthlyReportExportEmailJob`); report is not regenerated on email retry.

### Idempotency

- Duplicate export requests with the same user, date range, and format within `idempotency_window_minutes` (default 10) reuse the latest non-failed export row.
