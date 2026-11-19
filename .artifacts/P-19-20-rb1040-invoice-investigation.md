# P-19-20 — RB1040 statutory invoice investigation & fix

| Field | Value |
|---|---|
| Global ID | **P-19-20** |
| Cursor ledger ID | **RadiumDesk-P-19-20** |
| Order | **RB1040** (Desk support pk **66448**, service case **SC67554**) |
| Production changes | **NO** |
| Desk prod runtime (P-19-18) | **v4.1.94** / `b6b39f081` |

## Root cause (VERIFIED)

1. **Invoice generation was triggered** — outbox `statutory-service-mint:order:66448` on event `service_reference_completed` (**P-27-83**, **P-01-05**).
2. **Generation failed after payment** — permanent mint failure before commerce snapshot or statutory invoice row.
3. **Exact failure** — `RadiumBox service lookup response is missing service_commerce.` from `RadiumBoxServiceCommerceLookupMapper` during `RadiumBoxServiceCommerceSnapshotService::ensureForSupportOrder()`.
4. **Not** duplicate protection, GST validation, or missing invoice link — **0** `commerce_orders`, **0** `statutory_invoices` for RB1040 (**VERIFIED** prior read-only SQL).

**Upstream contract regression:** RadiumBox `GET /api/integrations/v1/rd-orders/{RB*}` returned HTTP **200** without `data.service_commerce` (merged fix **PR #102** on `radiumbox.com`; production deploy state **UNKNOWN** in this session without live G4 call).

## Code defect (Desk)

- Fail-closed mapper threw on absent `service_commerce` with **no** spoke overlay.
- Classifier treated the error as **permanent** (`unclassified_failure`), leaving outbox **Failed** and blocking automatic retry after Box contract recovery.

## Fix (this PR)

| File | Change |
|---|---|
| `RadiumBoxServiceCommerceSpokeOverlay.php` | Synthesize `service_commerce` from `data.rd_order` + `data.snapshot` when block absent (RB1040-shaped amounts/states). |
| `RadiumBoxServiceCommerceLookupMapper.php` | Invoke overlay before throwing. |
| `ServiceStatutoryInvoiceMintFailureClassifier.php` | Classify missing `service_commerce` as **temporary** (`missing_service_commerce_upstream`) for durable outbox retry. |
| Tests | Unit + feature RB1040 overlay coverage. |

## Idempotency (VERIFIED in tests)

- Repeated `ensureForSupportOrder()` unchanged (existing idempotency test suite).
- Overlay does not alter mint idempotency keys.

## Production record status (VERIFIED historical)

| Entity | Status |
|---|---|
| Desk order **66448** / **RB1040** | Paid, workflow complete, **no** invoice |
| `commerce_orders` | **0** |
| `statutory_invoices` | **0** |
| Outbox | **Failed**, last_error missing `service_commerce` |

## Safe repair procedure (requires separate Owner authorization)

1. **G4:** Verify live `GET …/rd-orders/RB1040` returns `data.service_commerce` **or** extended `rd_order` statutory fields (amount, tax_amount, state, serial, service name).
2. **Deploy:** Desk build including this fix **and** confirm Box **PR #102** overlay on production (if G4 still absent).
3. **Requeue:** Single `ServiceStatutoryInvoiceIssuanceCoordinator::attempt` for pk **66448**, trigger `invoice_retry` — **not** performed in P-19-20.
4. **Post-check:** One `statutory_invoices` row, commerce `invoiced`, outbox `completed`, PDF generated; **no** duplicate invoice number.

## Findings summary

| Finding | Class |
|---|---|
| Mint triggered, failed on spoke contract | **VERIFIED** |
| Root exception message | **VERIFIED** |
| Box `service_commerce` regression / deploy gap | **INFERRED** from prior G4 + code parity |
| Live prod API shape at investigation time | **UNKNOWN** (no prod HTTP in this task) |