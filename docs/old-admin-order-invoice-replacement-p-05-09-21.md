# Old Admin replacement — order lookup + historical reprint — RadiumDesk-P-05-09-21

**Date:** 2026-09-05  
**Primary ID:** `RadiumDesk-P-05-09-21`  
**Companions:** `rdservice.in-P05-09-01`, `radiumbox.com-P-05-09-17`, `RadiumServiceNet-P-05-09-03`

Remove Desk’s live dependency on `https://admin.radiumbox.com`. Route authenticated lookups to owning spoke APIs. Provide read-only historical INV* reprint. No mint, remint, IRN, payment, or DLQ flush.

---

## Routing policy

Desk never silently routes to dead Admin. `RADIUMBOX_ADMIN_FALLBACK_ENABLED` defaults **false**.

| Order class | Authoritative source | API | Status after this ticket |
|---|---|---|---|
| RD/RA `website=rdservice.net` | `rdservice_net_prod` | Existing net `GET /api/integrations/v1/rd-orders/{id}` | **SUPPORTED** (already activated P-05-09-20) |
| RD* `rdservice.in` (incl. post-cutover) | `rdservice_in_prod` | New in API, same path | **SUPPORTED** in code; production overlay **NOT deployed** |
| RIN* direct buy | `rdservice_in_prod` | Same in API (allow `direct_buy` for RIN) | **SUPPORTED** in code; production overlay **NOT deployed** |
| RD*/RDE* Box + historical copies | `radiumbox_prod` | New Box `rd-orders` + `historical-invoices` | **SUPPORTED** in code; production overlay **NOT deployed** |
| INQ | Desk | Local only | **SUPPORTED** (no remote) |
| Unknown / malformed | — | 400 / not found | **UNSUPPORTED** — not Admin |
| Source timeout / 5xx | owning spoke | retriable | **BLOCKED** until that API recovers |

Fan-out for `RD*`: net → in → Box. First usable 200 wins. Net allow-list stays `rdservice.net` only (`RadiumServiceNet-P-05-09-03`).

---

## Historical invoice reprint

| Capability | Status | Evidence |
|---|---|---|
| Exact historical number (`INV6745886`) | Implemented | Box API + Desk Finance reprint; number not reminted |
| Order linkage | Implemented | `orders_id` / `ordercode` / `rdorderid` on payload |
| Customer / lines / totals | Implemented | Box reprint payload; lite fallback from order API |
| Auth | Implemented | Desk `auth` + `finance.invoices.view`; spoke Bearer fail-closed |
| Invalid / statutory `INV-*` | Implemented | 422 statutory; 400 malformed |
| Paid without invoice | Implemented | 409 `paid_without_invoice`; no mint |
| Cancelled / unpaid | Implemented | 409 `cancelled_or_unpaid`; no mint |
| IRN display | Partial | Box returns IRN/Ack/QR when `einvoice_respose` exists; not generated |

---

## Production env (not applied this ticket)

Desk:

- `RADIUMBOX_ENABLED=false`
- `RADIUMBOX_BASE_URL=`
- `RADIUMBOX_ADMIN_FALLBACK_ENABLED=false`
- Keep `RDSERVICE_ENABLED=true` + existing `DESK_ORDER_API_TOKEN`
- `RDSERVICE_IN_LOOKUP_ENABLED=true` after in overlay
- `RADIUMBOX_STOREFRONT_LOOKUP_ENABLED=true` after Box overlay
- Loopback Host remains valid (`RDSERVICE_HOST=rdservice.net`)

Spokes: set the **same** `DESK_ORDER_API_TOKEN` (or dedicated override). Empty token fails closed.

---

## Pending / failed order mapping (no mass replay)

| Record class | Action this ticket | Later condition |
|---|---|---|
| Eligible net `RD*` | Already use net API | None |
| `RD*` not in net (in / Box) | 404 until sibling APIs are live | Replay **one** order after spoke 200 is proven; do not flush DLQ |
| Admin HTTP 526 `failed_jobs` | **Not** retried | Only after fallback is off **and** the owning API returns 200 for that id |
| Paid without `invoicecode` | Visible as `paid_without_invoice` | Separate mint ticket |
| Desk handoffs pending | Unchanged | Not this ticket |

---

## Tests (local)

- Desk focused routing + reprint + existing lookup/enrichment: **64 passed / 237 assertions**
- rdservice.in `RdOrderDeskApiTest`: **8 passed**
- radiumbox.com `DeskOrderLookupApiTest`: **7 passed** (2 pre-existing `.env` missing warnings)
- Pint on dirty PHP; `php -l` on key Desk files

Not committed. Not pushed. Not deployed.
