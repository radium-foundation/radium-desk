# Statutory eligibility billable-line contract and deferred GST split (P-23-09-12)

## Eligibility / mint contract (implemented)

Commerce statutory eligibility and mint now share a canonical billable-line set via
`StatutoryInvoiceCommerceBillableLines`, which delegates to
`StatutoryInvoiceCommerceLinePresentation::includesOnStatutoryInvoice()`.

Invariant:

- `evaluateOrder()` is eligible only when at least one billable line exists.
- `serviceGstSplitErrors()` validates GST split on billable lines only.
- `issueFromCommerceOrder()` retains a defensive mint-time filter on the same presentation rules.

`order_value > 0` is not used as an eligibility gate.

## Deferred CGST/SGST architecture (not implemented)

Odd-paise intra-state tax totals (for example ₹96.25 → CGST ₹48.13 / SGST ₹48.12) expose a
tension between sum reconciliation and IRP error 2227 (CGST must equal SGST).

Before changing financial semantics:

1. An authoritative GST/IRP rule for odd-paise intra-state splits must be confirmed.
2. IRP mapper-only normalization is prohibited — it would diverge PDF/register snapshots from IRP payloads.
3. Issued statutory invoice financial columns are immutable for routine operations.
4. Future GST calculation changes apply only at mint time via `GstSplitService` (or successor), not on read paths.
5. Historical remediation (cancel/reissue, governed exception workflow) requires a separate authorized prompt.

No CA or NIC approval is claimed in this document.
