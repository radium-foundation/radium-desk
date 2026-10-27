# P-27-21 — Production recovery runbook: INV-0767631 (id 14910)

**ChatGPT prompt ID:** P-27-21  
**Status:** **NOT RESOLVED** until authorized IRN retry succeeds with verified IRN + tax totals.

## Current production snapshot (read-only KVM, 2026-10-27)

| Field | Value |
|-------|--------|
| `statutory_invoices.id` | **14910** |
| `invoice_number` | **INV-0767631** |
| `status` | `issued` |
| `shipping_amount` | ₹1500.00 |
| `taxable_value` | ₹31144.08 (product lines only on header) |
| `tax_total` / `igst` | ₹5875.93 |
| `invoice_value` | ₹38520.00 |
| Line items persisted | **1** (hardware); shipping header-only |
| `EInvoiceStoredGstGuard` | `line_header_gst_mismatch`, `invoice_total_mismatch` |
| IRN | **none** (`e_invoice_records` skipped) |

## Root cause (code)

Pre-fix mint stored shipping in **header** totals without a matching statutory **line** or IRN synthetic line reconciliation → e-Invoice blocked.

## Fix strategy (post-deploy Workstream A)

1. **Deploy** Workstream A allowlist to `/var/www/radium-desk` (no config change required if incidental HSN acceptable).
2. **Do not** mutate invoice row **14910** for the standard path — legacy compat builds IRN shipping line from header snapshot + product HSN.
3. Optional env (only if CA rejects incidental): `STATUTORY_POS_RETAIL_SHIPPING_HSN_SAC` = confirmed code.

## Preflight (Owner)

- [ ] RIP / DB backup tag recorded (e.g. `RIP-*-radium-desk-pre-p27-21-shipping-einvoice`).
- [ ] Deployed commit SHA matches release candidate.
- [ ] `php artisan` read-only: re-run guard on id **14910** — expect **empty** `missingReasons` after deploy.
- [ ] No concurrent edit to sale **109** / invoice **14910**.

## IRN retry (Finance Hub — authorized only)

1. Open statutory invoice **INV-0767631** in Finance Hub.
2. Trigger **e-Invoice generate** (idempotent outbox — safe to retry if prior attempt failed closed).
3. On success: verify **IRN**, **AckNo**, signed QR, and IRP **taxable_value** ≈ **32644.08** (31144.08 + 1500.00 shipping).
4. On failure: capture `e_invoice_records` error payload; **stop** — do not hand-edit totals.

## Rollback

- Redeploy previous Desk release SHA (pre Workstream A).
- IRN if partially issued: follow statutory cancellation runbook (separate Owner gate — **not** part of this hotfix).

## Idempotency

- Mint for sale **109** already exists — retry is **e-Invoice only**, not re-mint.
- Outbox writer must not duplicate posted IRN (existing eligibility checks).

**Production retry executed:** **NO — Not performed.**
