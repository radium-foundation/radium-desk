# Intra-state CGST/SGST IRP compliance (P-23-09-13)

## Authoritative rule (VERIFIED)

NIC IRP knowledge-base errors:

| Code | Requirement |
|---|---|
| **2227** | CGST and SGST amounts must be **equal** for each line item. |
| **2234** | CGST/SGST should equal taxable × rate ÷ 2 with **±₹1 tolerance** on rate identity. |

Production evidence: IRP 2227 on stored splits 48.13/48.12 (tax ₹96.25). Zero submitted invoices with unequal CGST/SGST.

## Project inference (IMPLEMENTED)

When `tax_total` has **odd paise**, equal half-rounding is required:

```
cgst = sgst = round(tax_total ÷ 2, 2)
```

- `tax_total` remains commerce-authoritative (not rewritten).
- `cgst + sgst` may exceed `tax_total` by **1 paise per intra-state line**.
- Multi-line headers sum equal line halves in paise; header `cgst === sgst` even when old unequal-per-line splits produced header drift (INV-0767278).

## Unresolved ambiguity

Whether IRP invoice-level `ValDtls` tolerates multi-line cumulative component drift >1 paisa has not been independently verified beyond per-line 2234 wording. Desk allows drift up to the sum of per-line odd-paise drifts at guard time.

## Implementation

| Component | Location |
|---|---|
| Equal-half allocation | `IntraStateCgstSgstRules` |
| Mint-time split | `GstSplitService::splitLine()` intra-state branch |
| IRP readiness guard | `EInvoiceStoredGstGuard` |

Mapper-only normalization is **prohibited**. PDF/register/IRP all read stored snapshots.

## Historical remediation (NOT performed — owner-authorized only)

| Class | Count | Action |
|---|---|---|
| B2C / e-invoice skipped, unequal CGST/SGST | 66 | No IRP impact; optional CA review only |
| B2B IRP 2227 permanent_failure | 6 | Separate cancel/reissue or governed remediation after owner sign-off |
| INV-0767278 / RD3300 | 1 | Multi-line odd-paise; equal-half fix prevents recurrence; existing invoice unchanged |
| Other permanent_failure (3028, 3039, 3074, 5002, 2240, etc.) | 18 | Unrelated to CGST split; separate data/validation fixes |

Affected 2227 invoices: INV-076775, INV-076780, INV-2767203, INV-2767251, INV-2767279, INV-0767278.
