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

## CGST/SGST architecture (implemented in P-23-09-13)

See `docs/desk-intra-state-cgst-sgst-irp-p-23-09-13.md` for the NIC IRP rule, equal-half
allocation, guard behaviour, and historical remediation classification.

Mapper-only normalization remains prohibited. Issued invoice snapshots are immutable for
routine operations.
