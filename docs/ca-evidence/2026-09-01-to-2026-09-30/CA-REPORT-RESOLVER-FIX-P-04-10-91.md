# CA Sales Report Resolver Fix — P-04-10-91

**Date:** 2026-10-07  
**Production (current deploy):** v4.1.4 / `ad5047ac` @ `187.127.129.16`  
**Status:** **READY FOR DEPLOYMENT — NOT DEPLOYED**

---

## Summary

Reporting-only fixes for September 2026 CA Sales Report preflight/export:

1. **State column** — shared resolver with evidence-based precedence; resolves all 1,061 previously blank rows on production data (simulation).
2. **Date_of_order** — service orders with `SVC-*` source IDs resolve via `order_number` lookup.
3. **GSTIN Format Status column** — local format validation only (`BuyerGstin::isValid`); no external registration API.
4. **48 GSTIN + no IRN** — investigated read-only; all 48 pass format validation; IRN gap is e-invoice workflow not GSTIN format.

No wallet/refund/cancellation/statutory source data changes.

---

## State resolver precedence (verified)

1. Statutory invoice `billing_address_structured['state']`
2. Linked commerce order structured billing state
3. Linked commerce order `billing_state`
4. Statutory invoice `place_of_supply_state`
5. POS inventory sale structured billing state
6. Service order structured billing state
7. Service order `billing_state` / `place_of_supply_state`

GSTIN-derived state is **not** used.

---

## Production simulation (read-only, patched classes in `/tmp/p91`)

| Metric | Before (legacy) | After (patch) |
|--------|----------------:|--------------:|
| State unresolved | 1,061 | **0** |
| Resolved from legacy set | — | **1,061** |
| Date_of_order missing (8 targets) | 8 (preflight on deployed code) | **0** (patched resolver) |

### Sundrop service invoices (protected)

| Invoice | State (before=after) | Order date (after patch) |
|---------|----------------------|--------------------------|
| INV-0767209 | Tamil Nadu | 2026-09-22 |
| INV-0767215 | Telangana | 2026-09-22 |
| INV-0767220 | Uttar Pradesh | 2026-09-22 |
| INV-0767222 | Odisha | 2026-09-22 |

### P-04-10-89 UAT (cancelled, unchanged)

All four resolve State from `place_of_supply_state` and Date_of_order from service order; remain cancelled.

---

## GSTIN + no IRN (48 invoices)

| Finding | Value |
|---------|------:|
| Count | 48 |
| Format valid (`BuyerGstin::isValid`) | 48 |
| Format invalid | 0 |
| `einvoice_status = permanent_failure` | 29 |
| `einvoice_status = skipped` | 19 |

**External GST registration validation:** Not available — no approved WhiteBooks/GST taxpayer lookup API is bound for registration status (Active/Cancelled/Suspended). Only checksum/format validation exists.

**Column added:** `GSTIN Format Status` — values: `Not present` | `Format valid — registration not verified` | `Format invalid`

---

## Tests

`170` CaMonthly-focused tests: **170 passed** (including 11 new unit tests).

---

## Deployment

Code is **not** on production. September workbook regeneration on production requires owner-authorized deploy of this patch.

Expected post-deploy September preflight:

- `missingStateInvoiceCount`: 0 (or explained residual)
- `missingOrderDateCount`: 0
- Invoice population: 8,859 unchanged
- Taxable/gross/cancelled totals unchanged
