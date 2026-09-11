# POS counter cart + customer lookup fix (P-07-09-230)

## Production baseline

- Repository: `radium-desk-irn-foundation`
- Branch: `feat/irn-foundation-phase-a`
- Deployed Gate 4 overlay: P-225 / browser E2E P-229 on `desk.radiumbox.com`
- Customer source of truth: `inventory_customers` (Finance Party **not** deployed)

## Branch comparison

| Area | `feat/irn-foundation-phase-a` (production) | `feat/walk-in-pos-production` (not deployed) |
|---|---|---|
| Customer master | `inventory_customers` | Finance Party bridge + extra snapshot columns |
| POS completion | Gate 4 statutory auto-issue on existing stack | `PosWalkInCompletionService` rewrite |
| Risk if merged blindly | — | Would regress Gate 4 routes, duplicate customer architecture, unrelated Finance Party migration |

**Decision:** fix cart + lookup on the production baseline only. Do **not** merge `feat/walk-in-pos-production`.

## Root causes

### Multiple serial rows

`addSerializedItem()` in `resources/views/pos/counter/create.blade.php` always `cart.push()`ed a new row. Non-serialized products already merged via `addQuantityItem()`.

### Customer lookup

1. UI waited for **8+ phone digits** before any lookup.
2. API required **exact phone** match (`where phone = ?`).
3. No name/partial search.
4. Even on exact match, billing city/state/PIN/POS were not populated from the latest sale snapshot.

## Fixes

- Merge serialized units into one cart line per product/variant; `qty = serials.length`.
- Per-serial remove chips decrement qty or remove the line.
- `PosCustomerLookupService` with partial search + full resolve payload from latest completed sale.
- Routes: `pos.customers.search`, `pos.customers.show`; enhanced `pos.customers.lookup`.

## Existing test sales (do not delete)

From Gate 4 overlays P-225 / P-229:

| Sale | Invoice | Classification |
|---|---|---|
| POS-000004 | INV-076751 | Controlled B2C Gate 4 CLI/browser test |
| POS-000005 | INV-076752 | Controlled B2B Gate 4 test with IRN |
| POS-000006 | INV-076753 | Controlled UQC mint-path test |

**Cleanup performed:** none. Use supported sale cancel/return + statutory credit-note policy if ever required; do not truncate rows.

## Deployment plan (not executed here)

Named-file overlay only:

- `app/Services/Pos/PosCustomerLookupService.php`
- `app/Http/Controllers/Pos/CounterController.php`
- `resources/views/pos/counter/create.blade.php`
- `routes/web.php` (POS customer search/show routes only)

Verify `/pos/counter`, `/pos/sales`, Ready Queue, Hardware workspace after overlay.
