# CA Monthly Report

**Prompts:** `RadiumDesk-P-21-09-07` (original contract), `RadiumDesk-P-21-09-23` (export hardening v4.0.105), `radiumbox.com-P-22-09-09` (invoice-level register redesign), `RadiumDesk-P-22-09-28` (documentation alignment)

**Template version:** `CaMonthlyReportDefinition::TEMPLATE_VERSION` = `2026-09-22`

## Period filter

- **Date of Invoice:** `statutory_invoices.issued_at`
- Supports monthly / arbitrary inclusive date ranges from the Finance report UI.

## Export grain

- **One visible parent row per invoice** in CSV and XLSX primary register rows.
- **XLSX** additionally emits **hidden, expandable child rows** for non-zero line-item detail (see [Expandable line detail](#expandable-line-detail)).
- **CSV** contains **parent invoice rows only** (no child line detail).
- `countExportLines()`, sync/async thresholds, and `row_count` on export artifacts count **invoices**, not line items.

## Parent invoice columns (21)

Exact order from `CaMonthlyReportDefinition::HEADERS`:

| # | Column |
|---|--------|
| 1 | Branch |
| 2 | Invoice Date |
| 3 | Invoice No. |
| 4 | Order ID |
| 5 | Order Type |
| 6 | Customer Name |
| 7 | GSTIN |
| 8 | State |
| 9 | Place of Supply |
| 10 | eWay Bill |
| 11 | HSN/SAC |
| 12 | Taxable Amount |
| 13 | Shipping |
| 14 | IGST |
| 15 | CGST |
| 16 | SGST |
| 17 | Short/Excess |
| 18 | Invoice Total |
| 19 | IRN Number |
| 20 | Acknowledgement |
| 21 | Payment Mode |

### Removed from export

- **Status** — not exported.
- **Document Type** — not exported.

## Expandable detail columns (9)

Used in XLSX child rows (`CaMonthlyReportDefinition::DETAIL_HEADERS`):

| # | Column |
|---|--------|
| 1 | Product / Service |
| 2 | Quantity |
| 3 | HSN/SAC |
| 4 | Taxable Amount |
| 5 | Shipping |
| 6 | IGST |
| 7 | CGST |
| 8 | SGST |
| 9 | Line Total |

### Expandable line detail

- Child rows are written only for **exportable** (non-zero-value) line items.
- A group is **expandable** when an invoice has **more than one** exportable line.
- XLSX uses Excel **outline grouping**: child rows have `outlineLevel="1"` and are **hidden initially** (`hidden="1"`). Users expand via Excel’s native outline controls.
- **Shipping** on child rows appears on the **first exportable line only** (invoice-level `shipping_amount`); other lines show blank shipping.
- Child rows are **detail only**; they do not duplicate invoice totals in the primary register.

## Zero-value line exclusion

`CaMonthlyReportLineValuePolicy` excludes a line from exportable detail when **all** of the following are zero (to 2 dp):

- `taxable_value`
- `igst`, `cgst`, `sgst`
- `line_total`

Example: `RD Technical Support — included` with zero financial value is omitted from expandable detail. Parent invoice totals are unaffected.

## Parent totals (authoritative invoice fields)

Parent-row amounts come from **`statutory_invoices` invoice-level fields**, not from summing line items:

| Export column | Source |
|---------------|--------|
| Taxable Amount | `taxable_value` |
| Shipping | `shipping_amount` |
| IGST / CGST / SGST | `igst` / `cgst` / `sgst` |
| Short/Excess | `rounding` |
| Invoice Total | `invoice_value` |

**Taxable Amount** and **Invoice Total** remain distinct (e.g. taxable ₹669.47 vs invoice total ₹789.98 when shipping/tax/rounding apply).

Parent **HSN/SAC** combines unique codes from exportable lines (comma-separated). **eWay Bill** remains blank without an authoritative source.

## Branch

Resolver: `CaMonthlyReportBranchResolver`.

Resolution order (first non-empty wins):

1. `statutory_invoices.branch_id` → related `InventoryBranch` name or code
2. Linked `inventory_sale` branch name or code (POS)
3. Related `commerce_orders.branch_code` → `InventoryBranch` lookup by code (falls back to raw `branch_code` when no branch row exists)

**No inference** from customer state, GSTIN, place of supply, or other indirect fields. Branch is blank when no authoritative source exists.

## Order Type

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

## Payment Mode

Resolver: `CaMonthlyReportPaymentEvidenceResolver::resolvePaymentModeDisplay()`.

Resolution order (first non-empty, normalized label wins):

1. Verified `HardwareFulfilmentPaymentEvidence.payment_method` (by source / commerce order)
2. Linked support `orders.payment_method`
3. `commerce_orders.payment_method`
4. `PaymentAllocation` → linked `CustomerPayment.method`
5. `statutory_invoices.payment_method` (invoice snapshot)

**Payment providers are not payment instruments.** Provider aliases such as `cashfree`, `payu`, and `razorpay` are filtered and do not appear as Payment Mode. Unknown/unavailable remains blank rather than mislabeled.

Preflight still summarizes cancelled payment-evidence categories for CA review.

## Cancelled invoices

All cancelled statutory invoices in the selected period are **included**. Original invoice-level values are preserved. Payment evidence is reported in preflight only; it does not exclude cancelled invoices.

## Shipping (source data)

- Schema: `statutory_invoices.shipping_amount` (decimal, default `0`, immutable after issue)
- Mint hook: `StatutoryInvoiceMintRequest::$shippingAmount` persisted by `StatutoryInvoiceService::mint()`
- **No historical backfill.** Production audited period may have zero shipping coverage.
- **Upstream integration still required:** commerce/POS/service mint paths must pass a real pre-tax shipping amount when a source exists. Desk does not infer shipping from AWB, Shiprocket freight, or total deltas.

## Preflight reconciliation

Preflight runs at **invoice grain**:

- Totals accumulate from parent export rows (`taxable_value`, shipping, taxes, rounding, `invoice_value`).
- A **non-reconciling invoice** is counted when
  `taxable + shipping + IGST + CGST + SGST + Short/Excess` ≠ `Invoice Total` (tolerance 0.01).
- Zero-value excluded lines increment `excludedZeroValueLineCount`; exportable line counts apply to detail/preflight line metrics only.

## Preview UI

- **Invoice-grain pagination** (default 50 invoices per page).
- Multi-line invoices render a parent summary row with `+` / `−` expand/collapse.
- Single-line invoices render as a normal row (no expand control) with product name shown under the customer.
- Child rows load with the page (no extra network requests on expand).
- Preview columns align with the invoice-level export contract (Status and Document Type not shown).

## XLSX workbook layout

Sheet name: `CA Monthly Report`.

| Row | Content |
|-----|---------|
| 1 | Report title (`CA Monthly Report`) |
| 2 | Reporting period + generated timestamp (`CaMonthlyReportWorkbookMeta`) |
| 3 | Parent column headers (frozen below this row) |
| 4+ | Parent invoice rows, with hidden grouped child rows where applicable |
| Last | Blank separator + summary block (invoice count, taxable/shipping/tax totals, invoice grand total) |

Excel usability features implemented in `CaMonthlyReportXlsxStreamWriter`:

- Frozen header row (pane below row 3)
- AutoFilter on header row
- Column widths tuned for key columns
- INR-style two-decimal money formatting for numeric cells
- Landscape page setup (`orientation="landscape"`, fit to width)
- Outline summary above detail (`summaryBelow="0"`)

## Export hardening (v4.0.105+)

### Bounded memory

- Export uses invoice `chunkById()` batches (`ca_monthly_report.invoice_chunk_size`, default 25).
- CSV sync downloads stream parent rows directly to `php://output`.
- XLSX writes worksheet XML incrementally to a temp file, then zips bounded artifacts.
- The legacy `exportRows()` array helper remains for tests/small fixtures only.

### Sync vs async threshold

- Default `ca_monthly_report.sync_max_lines = 500` (override via `CA_MONTHLY_REPORT_SYNC_MAX_LINES`; benchmarked in `CaMonthlyReportExportBenchmarkTest` against **invoice count**).
- Async generation jobs use the `maintenance` queue (`CA_MONTHLY_REPORT_EXPORT_QUEUE`), already consumed by production Supervisor at lowest priority.
- Rationale: production v4.0.104 OOM at 128MB for a full September 2026 month; chunked preflight/export avoids loading entire periods in memory.
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

## Key implementation classes

| Concern | Class |
|---------|-------|
| Column contract | `CaMonthlyReportDefinition` |
| Parent + detail row build | `CaMonthlyReportInvoiceExportBuilder` |
| Branch | `CaMonthlyReportBranchResolver` |
| Payment mode | `CaMonthlyReportPaymentEvidenceResolver` |
| Zero-value filter | `CaMonthlyReportLineValuePolicy` |
| Read model / preflight | `CaMonthlyStatutoryLineReadModel` |
| CSV / XLSX generation | `CaMonthlyReportExportGenerator`, `CaMonthlyReportXlsxStreamWriter` |
