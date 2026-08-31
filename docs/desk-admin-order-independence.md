# Desk independence from Admin `GET /api/search/order`

**Ticket:** RadiumDesk-P-31-08-09  
**Date:** 2026-08-31  
**Canvas:** [`desk-admin-order-independence.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-RadiumWebsites-radium-desk/canvases/desk-admin-order-independence.canvas.tsx)

Desk-side only. Admin, RDService.net, production `.env`, DNS, Cloudflare, Cashfree, and `rdservice_net_prod` were not modified. No deploy. No production DB change. Customer 360 WIP was left uncommitted.

Paired prior work: [`rdservice-desk-order-api-integration.md`](rdservice-desk-order-api-integration.md), [`rdservice-production-activation-readiness.md`](rdservice-production-activation-readiness.md), [`rdservice-first-enrichment-preference.md`](rdservice-first-enrichment-preference.md).

---

## Inspection (before change)

| Item | Value |
|------|--------|
| Repository | `/Users/ravi/RadiumWebsites/radium-desk` |
| Starting branch | `feat/rdservice-order-api-enrichment` (same SHA as `origin/main`) |
| Implementation branch | `feat/desk-admin-order-independence` |
| HEAD at inspect | `a8af91e0628ae73d4c90826145ceb0252accebfd` — `docs(release): add v4.0.63 changelog` |
| Remote | `git@github.com:radium-foundation/radium-desk.git` |
| Second worktree | `/Users/ravi/RadiumWebsites/radium-service-desk-deploy` on `main` @ same SHA |
| Worktree at inspect | Unrelated Customer 360 WIP plus uncommitted RDService default-off files. C360 left untouched. |

Production constraint held: do not disable Admin `/api/search/order`, do not add an Admin token, do not enable the IP allowlist, do not set `DESK_ORDER_API_TOKEN`, do not set `RDSERVICE_ENABLED=true`, do not modify Admin, do not deploy, do not alter production DB.

---

## Preference order (unchanged architecture)

```
Desk-native persisted data (fill-missing / identity locks / local search hit)
  → RDService GET if RDSERVICE_ENABLED=true, token present, HTTPS, RD-eligible
  → Admin GET /api/search/order if RDService skip / 401 / 404 / malformed / incomplete
  → unresolved (no destructive write)
```

Production default remains Admin-compatible: `RDSERVICE_ENABLED` defaults to **false**, and an empty `DESK_ORDER_API_TOKEN` still skips RDService HTTP.

---

## 1. Every Admin-dependent Desk caller

Direct HTTP client: `RadiumBoxClient` `GET {RADIUMBOX_BASE_URL}/api/search/order?orderid=`.

| Caller | Path | Before this ticket | After this ticket |
|--------|------|--------------------|-------------------|
| `RadiumBoxService` workspace + background enrichment | Cashfree job, auto-sync, backfill, recovery, order workspace | Already Desk → RDService → Admin | Unchanged |
| `LegacyOrderLookupService` | Intake search + global-search fallback when Desk misses | Admin only | Shared lookup (RDService when enabled, else Admin) |
| `LegacyOrderImportService` | One-click / confirmed legacy import | Admin only | Shared lookup |
| `CustomerIntakeService::createLegacyFromRadiumBox` | Intake create when order is not in Desk | Admin only | Shared lookup |
| `OrderIdentityRepairService` | `orders:repair-identity` | Admin only | Shared lookup (background retry semantics) |
| Global search (`SearchController`) | Desk `GlobalSearchService` first; Admin only via intake fallback | Admin via lookup | Same chain, now RDService-capable |
| Hardware `RDE` / `RIN` | Any of the above | Admin; RDService ineligible | Still Admin; RDService HTTP skipped |
| Inquiry `INQ-` | Any of the above | Admin; RDService ineligible | Still Admin; RDService HTTP skipped |

`RadiumBoxService` was **not** refactored onto the new lookup. It already persists RDService then optionally Admin for remaining identity fields. Changing that path would risk Cashfree enrichment.

---

## 2. Fields already persisted in `radium_desk`

Desk `orders` already stores the enrichment contract used by Admin and RDService mappers:

| Column | Role | Typical source |
|--------|------|----------------|
| `order_id` | Correlation key | Cashfree / intake / import |
| `serial_number`, `product_name`, `device_model` | Device identity | Cashfree tags, RDService, Admin, agent correction |
| `service_history` | RD service years/name | Same |
| `customer_name/email/phone` (+ lock columns) | Identity (lock-aware) | Cashfree, RDService, Admin, agent |
| `gst_number`, `invoice_number`, `purchase_year` | Commercial | RDService / Admin / legacy import |
| `amc_status`, `amc_year`, `amc_details` | AMC | RDService / Admin / legacy import |
| `legacy_order_status`, `legacy_order_date`, `legacy_*` | Legacy import metadata | Admin / RDService import |
| `cashfree_payment_id`, `payment_*`, `gateway_*`, `bank_reference`, `transaction_id` | Payment | **Cashfree only** |

Not stored, and not required to drop Admin: billing address, tax/total besides `payment_amount`, full RD history blob, order lines.

---

## 3. Fields that require historical import

Do **not** bulk-import in this ticket.

| Gap | How it appears | Safe strategy |
|-----|----------------|---------------|
| Paid Cashfree RD order with incomplete tags and never-enriched GST/invoice/AMC | Desk row exists; commercial columns empty | Existing fill-missing job / identity repair / backfill. After activation, RDService-first. Admin remains fallback. |
| RD order that never entered Desk | Agent search miss | On-demand intake / legacy import (now RDService-capable when enabled). No warehouse dump. |
| Hardware `RDE`/`RIN` commercial facts | Desk row may exist without Admin facts | Stay on Admin until a hardware source exists. |
| `INQ-` | Desk-created | No historical import. Remote lookup is vestigial. |
| Address / tax / lines | No Desk column | Out of scope. |

Empty GST/invoice is **not** treated as “no GST/invoice exists.” Fill-missing only.

---

## 4. Hardware RDE/RIN strategy

RDService `GET /api/integrations/v1/rd-orders/{id}` accepts `^RD[0-9A-Za-z]{1,61}$` only. `RDE` / `RIN` are ineligible (`Order::isHardwareOrderId`).

**Keep Admin** for hardware enrichment, intake preview, import, and repair.

Later (not this ticket): a dedicated hardware source, or treat hardware as Desk-native-only if operators already enter serial/model at create time. Removing Admin for hardware without a replacement would drop live serial/model fill.

---

## 5. INQ strategy

`INQ-` orders are created by Desk (incoming email, missed call, new-contact intake). They are not RDService records.

Today production still calls Admin for INQ enrichment/search misses. That is likely a no-op 404, but skipping Admin would be a behavior change.

**This ticket keeps Admin for INQ.** A later explicit ticket may skip all remote lookup for `INQ-` after confirming Admin never returns useful INQ payloads.

---

## 6. Legacy import strategy

Legacy import remains **on-demand**, one order at a time:

1. Desk hit → no remote call.
2. Desk miss + RD-eligible + RDService enabled/configured → RDService preview/import.
3. Otherwise Admin `/api/search/order`.
4. Duplicate `order_id` or serial still rejected.

No bulk historical pull from Admin or RDService. `legacy_source` stays `radiumbox` so existing audit/UI meaning does not change.

---

## 7. Intake / identity-repair / global-search dependencies

| Flow | Desk-native | Remote |
|------|-------------|--------|
| Intake search | Local order / phone / serial match | Shared lookup only on miss + order id |
| Intake `createLegacyFromRadiumBox` | Existing Desk row short-circuits | Shared lookup for identity fields; stub order still created if both remotes miss (same as today) |
| Global search | `GlobalSearchService` on Desk cases | Intake fallback uses the same lookup |
| Identity repair | Skip when local identity already valid | Shared background lookup; RDService 429/5xx/timeout retry without Admin on that attempt |

---

## 8. RDService readiness without activation

Code is ready. Production must not be activated here.

| Gate | State |
|------|--------|
| `RDSERVICE_ENABLED` default | **false** |
| `DESK_ORDER_API_TOKEN` | Empty; PHPUnit forces empty |
| HTTPS-only host | Unchanged |
| Admin fallback | Required |
| Cashfree payment SoT | Unchanged |
| `rdservice_net_prod` | Not a Desk connection |

Activation remains a later explicit ops step: set flag + verified token on KVM `.env`, then `./tools/desk cache`. Do not write the token in this ticket.

---

## 9. Exact criteria for removing Admin fallback

All of the following must be true **and** approved in a later ticket. None are true today.

1. Production is running this code with `RDSERVICE_ENABLED=true` and a verified token for a measured soak period.
2. Live RD enrichment (Cashfree job + workspace) shows RDService 200 as the normal path; Admin volume is residual.
3. Intake, global-search fallback, legacy import, and identity repair show the same RDService-first pattern in production logs.
4. Hardware `RDE`/`RIN` have a non-Admin source **or** an explicit decision that Desk-native hardware identity is sufficient.
5. `INQ-` remote lookup is removed (Desk-only) after confirming Admin never returns useful INQ data.
6. Historical commercial gaps (GST/invoice/AMC) are either filled on existing Desk rows or accepted as unknown — empty remote must not overwrite.
7. Admin `/api/search/order` may stay deployed on Admin; Desk simply stops calling it. Do not token-gate or IP-allowlist that endpoint from this repo.
8. Changelog + production verification of payment ingest, Ready Queue, intake, and repair.

Until then, Admin fallback stays.

---

## What changed in this ticket

| File | Change |
|------|--------|
| `config/rdservice.php`, `.env.example`, `RdServiceClient` | Production default `RDSERVICE_ENABLED=false` |
| `OrderEnrichmentLookupService` | Shared Desk-compatible lookup: RDService when eligible, else Admin |
| Legacy lookup/import, intake create, identity repair | Use the shared lookup |
| Tests | Default-off, RDService-first for remaining callers, hardware/INQ Admin, timeout split (interactive vs background) |

`RadiumBoxClient` remains the Admin HTTP client. It is not removed.

---

## Production behavior preserved

- Deploying this branch without setting the token or flag keeps today's Admin enrichment, intake, import, repair, and search-fallback path.
- Empty token still skips RDService even if someone later sets the flag without a secret.
- `/api/search/order` is not disabled and is not token-gated.
- Cashfree remains payment source of truth.
- Fill-missing and identity locks unchanged.
- Hardware and INQ still use Admin.
- Admin, Cashfree, wallet, invoice/tax, DNS, Cloudflare, and production DB were not touched.

---

## Tests

Targeted: **114 passed**, 598 assertions.

- `tests/Unit/OrderLookup` + `tests/Feature/OrderLookup`
- `tests/Unit/RdService` + `tests/Feature/RdService`
- `tests/Feature/RadiumBox/RadiumBoxOrderEnrichmentTest`
- `tests/Feature/LegacyOrder/LegacyOrderBridgeTest`
- `tests/Feature/GlobalSearchIntakeFallbackTest`
- `tests/Feature/OrderIdentityRepairCommandTest`

Pint on intended PHP files. PHP syntax (`php -l`) on new/changed services. PHPStan/Larastan not configured.

---

## This ticket did not

- Commit Customer 360 WIP
- Push to production, tag, or `deskd`
- Activate RDService production credentials
- Modify Admin, Cashfree, wallet, invoice/tax, DNS, Cloudflare, or production DB
- Disable `/api/search/order`
- Add an Admin API token requirement
- Enable the IP allowlist
- Remove Admin fallback
