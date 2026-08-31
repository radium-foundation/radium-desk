# RDService.net RA namespace compatibility (Desk)

**Ticket:** RadiumDesk-P-31-08-15  
**Date:** 2026-08-31  
**Supplied prompt id:** RadiumDesk-P-31-08-13 (already used on the ledger for the v4.0.64 release; this work is appended as 15)

Desk-side only. Historical RD and RD T-suffix order ids are not renamed, backfilled, or rewritten. `RDSERVICE_ENABLED` remains default `false`. RDService.net, Admin, invoices, IRN/e-waybill, production DB, and DNS were not modified.

## Behaviour

| Lookup | RDService eligibility | Correlation |
|--------|----------------------|-------------|
| `RA3506771` | Eligible when RDService is configured | Accept only RA-namespaced stored identifiers; numeric `order_rdservice.id` fallback only when stored provider id is RA |
| `RA3506771T6a9522b8` | Eligible | Exact provider / Cashfree id |
| `RD3506000` | Unchanged | Exact stored id |
| `RD3506770T6a9522b8` | Unchanged | Exact provider id |
| `RA3506771` vs stored `RD3506771` / `RD3506771T…` | Request may be sent | Payload rejected as not found; Admin fallback unchanged |
| `RDE…` / `RIN…` | Ineligible | No RDService HTTP |
| `INQ-…` | Ineligible | No RDService HTTP |

Identifiers stay distinct: `customer_order_id`, `cashfree_order_id`, stored provider `rdorderid`. Desk still calls `GET /api/integrations/v1/rd-orders/{order_reference}`.

## Not done

- Production activation (`RDSERVICE_ENABLED`, `DESK_ORDER_API_TOKEN`)
- Deploy, Admin changes, invoice/IRN generation, historical id migration
