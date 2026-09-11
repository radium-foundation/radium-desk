# RadiumDesk-P-07-09-222 — Walk-in retail POS Gate 4

## Scope

Complete the existing `/pos/counter` walk-in flow end-to-end without creating a second POS or merging Finance Party.

## Retail POS work already present (verified)

- Counter UI, cart, serials, customer lookup, idempotent `completeSale()`
- Sale-time statutory snapshot (`PosStatutorySnapshot`)
- Post-commit auto-mint (`PosStatutoryInvoiceIssuer` → `issueFromPosSale`)
- UPI intent → bank verify → single `completeSale()`
- Finance Hub manual retry for blocked sales
- P-221 statutory prevention preserved

## Gate 4 additions

- B2C walk-in defaults place of supply from selling branch at snapshot time (mint uses sale snapshot, not later re-resolution)
- POS sale show: GST invoice View / Download / Email / browser Share
- Operator warning when auto-mint fails after sale commit
- Counter UI copy aligned with automatic GST invoice issuance
- Tests: `PosSaleStatutoryInvoiceActionsTest`, updated `PosStatutorySnapshotTest`

## Not merged / not in scope

- `feat/finance-party-master` remains separate (`inventory_customers` is POS customer source)
- Admin customer bulk import unchanged
- Customer 360 WhatsApp API still requires a service-case incident; POS uses Share + Email

## Tests

59 POS-focused feature tests PASS (Gate 4 subset).
