# Intra-state CGST/SGST IRP compliance (P-23-09-13 / P-23-09-14)

## Authoritative rule (VERIFIED)

NIC IRP knowledge-base errors:

| Code | Requirement |
|---|---|
| **2227** | CGST and SGST amounts must be **equal** for each line item and at invoice header. |
| **2234** | CGST/SGST should equal taxable × rate ÷ 2 with **±₹1 tolerance** on rate identity. |

Production evidence: IRP 2227 on stored splits 48.13/48.12 (tax ₹96.25). Zero submitted invoices with unequal CGST/SGST.

## Invoice-level invariant (P-23-09-14)

`tax_total` remains commerce-authoritative (never rewritten).

1. Each line: `cgst_paise === sgst_paise`.
2. Header `cgst_paise === sum(line cgst_paise)`; header `sgst_paise === sum(line sgst_paise)`.
3. Header `cgst_paise === header sgst_paise`.
4. `header cgst + header sgst` reconciles to `tax_total` within **0 paise** (even tax) or **1 paise** (odd tax) — **not per line**.
5. Multi-line drift does **not** accumulate with line count.

## Algorithm (invoice-level reconciliation)

```
idealHalfPaise(line) = round(taxable_paise × gst% ÷ 200)     // IRP 2234 basis
headerHalfPaise      = intdiv(tax_total_paise + (tax_total_paise % 2), 2)
allocated[line]      = allocateLineHalfPaise(headerHalfPaise, ideals)
cgst = sgst          = fromPaise(allocated[line])
```

`allocateLineHalfPaise` adjusts ideals by ±1 paise deterministically (subtract from last lines when sum(ideals) > header; add from first lines when sum(ideals) < header).

### RD3300 / INV-0767278 shape (future mint)

| | Line 1 | Line 2 | Header |
|---|---|---|---|
| tax | 75.81 | 15.25 | 91.06 |
| cgst/sgst | 37.91 / 37.91 | **7.62 / 7.62** | **45.53 / 45.53** |
| components | | | **91.06** (exact) |

Per-line-only equal-half (587429ba) produced 7.63 + cumulative +₹0.02 header drift.

## Implementation

| Component | Location |
|---|---|
| Allocation rules | `IntraStateCgstSgstRules` |
| Single-line split | `GstSplitService::splitLine()` intra-state branch |
| Multi-line batch split | `GstSplitService::splitIntraStateLines()` |
| Mint batch + paise totals | `StatutoryInvoiceService::applyServiceGstSplit()` / `totals()` |
| Pre-persist validation | `StatutoryInvoiceService::assertIntraStateMintTotals()` |
| IRP readiness guard | `EInvoiceStoredGstGuard` → `IntraStateCgstSgstRules::storedInvoiceReasons()` |

Mapper-only normalization is **prohibited**. PDF/register/IRP all read stored snapshots.

## Historical remediation (NOT performed — owner-authorized only)

| Class | Count | Action |
|---|---|---|
| B2C / e-invoice skipped, unequal CGST/SGST | 66 | No IRP impact; optional CA review only |
| B2B IRP 2227 permanent_failure | 6 | Separate cancel/reissue or governed remediation after owner sign-off |
| INV-0767278 / RD3300 | 1 | Production snapshot unchanged; algorithm prevents recurrence |
| Other permanent_failure (3028, 3039, 3074, 5002, 2240, etc.) | 18 | Unrelated to CGST split; separate data/validation fixes |

Affected 2227 invoices: INV-076775, INV-076780, INV-2767203, INV-2767251, INV-2767279, INV-0767278.
