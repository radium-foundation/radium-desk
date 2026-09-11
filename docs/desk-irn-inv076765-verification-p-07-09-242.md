# INV-076765 production IRN + QR verification — P-07-09-242

**Prompt ID:** RadiumDesk-P-07-09-242  
**Date:** 2026-09-11  
**Branch:** `feat/irn-foundation-phase-a`  
**HEAD:** `5ebf50bf` (no application code change)

## Provider safety (Get-IRN only, not GENERATE)

`GETIRNBYDOCDETAILS` for `INV-076765` / `11/09/2026`: HTTP mapped outcome `irn_not_found`, errorCode **2154**. Local e-invoice row remained `skipped` (recovery was not persisted). No correlation ID.

## Requeue

Reused existing outbox `713465` / `statutory-irn:1441` (completed → pending). E-invoice `skipped` → `queued`. No second dispatch row. Normal `outbox:process` processed it (attempts 1 → 2).

## Result

| Field | Value |
|-------|--------|
| IRN | `3296efd815e08b791bbd6517a11ffc0962ac342d72319ea4350f71ef2cf24fe6` |
| Ack No. | `172621154195600` |
| Ack Date | 2026-09-11 17:12:00 IST |
| Signed QR SHA-256 | `d2ecef26ea464408765de6dbe9d2063f5208a812581c2ef7a5459dc80c9a0195` |
| E-invoice status | `submitted` |
| PDF generated_at | 2026-09-11 17:12:03 (was 16:35:04) via `finalizeAfterIrn` |
| QR decode SHA | matches stored Signed QR |

Financial values, serials, and sale totals unchanged. Line `uqc` snapshot remains NULL (not backfilled); GENERATE used catalog `PCS`. Future mints snapshot catalog UQC.

No `regeneratePresentation()`. No new sale. No second GENERATE.
