# RDService.net Desk order API integration

**Ticket:** RadiumDesk-P-30-08-08  
**Date:** 2026-08-30  
**Canvas:** [`rdservice-desk-order-api-integration.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-RadiumWebsites-radium-desk/canvases/rdservice-desk-order-api-integration.canvas.tsx)

Desk-side only. RDService.net, Admin, radiumbox_prod, AWS/DNS/Cloudflare, and `rdservice_net_prod` were not modified. Desk continues to use `radium_desk` only.

---

## Inspection (before change)

| Item | Value |
|------|--------|
| Repository | `/Users/ravi/RadiumWebsites/radium-desk` |
| Implementation branch | `feat/rdservice-order-api-enrichment` (from `origin/main`) |
| Base HEAD | `eb54563da3191bbaeab30cd77e414206fc99ffa5` |
| Remote | `git@github.com:radium-foundation/radium-desk.git` |
| Worktree at inspect | `fix/bonvoice-webhook-lifecycle`, untracked Bonvoice docs only |

### Current Cashfree webhook

- Public `POST /api/webhooks/cashfree` → `CashfreeWebhookController` → `CashfreeWebhookProcessorService`.
- Signature verification optional (`CASHFREE_VERIFY_SIGNATURE`).
- `PAYMENT_SUCCESS` persists one `orders` row + service case, then drains a scoped outbox aggregate (`automation_monitor`, optional `dashboard_broadcast`, `radiumbox_enrichment`).
- Payment acknowledgement stays in-process; enrichment is a queued `RadiumBoxOrderEnrichmentJob` on the critical queue (`tries=4`, backoff 60/300/1800, `ShouldBeUnique` per order).

### Desk order create/update

- New paid orders: `orders.order_id` = Cashfree `data.order.order_id` (e.g. `RD3000003`).
- Duplicate Cashfree payment: `cashfree_payment_id` unique + processed webhook sibling → no second order.
- Duplicate business id: `orders.order_id` unique → `linkPaymentToExistingOrder` (fill-missing payment columns only).
- Optional Cashfree `order_tags` (`serial_no`, `product_name`, `rd_service_name`) applied at ingest.

### Admin (RadiumBox) lookup

- HTTP `GET {RADIUMBOX_BASE_URL}/api/search/order?orderid=` (default `https://admin.radiumbox.com`).
- Mapper: `RadiumBoxOrderSearchResponseMapper` (`data.rd_order` / `data.order`).
- Live persist (fill-missing): serial, device/product, service history, customer identity.
- Legacy import additionally writes GST, invoice, AMC, order date/status.

### Desk schema involved

`orders` (no new tables/columns in this ticket):

| Column | Role |
|--------|------|
| `order_id` | Correlation key (Cashfree order id = RD order id) |
| `cashfree_payment_id` | Payment idempotency (unique) |
| `payment_amount`, `payment_method`, `payment_date`, `bank_reference`, `gateway_*` | Cashfree SoT — enrichment must not write |
| `serial_number`, `product_name`, `device_model`, `service_history` | Identity completeness |
| `customer_name/email/phone` | Identity (lock-aware) |
| `gst_number`, `invoice_number`, `purchase_year`, `amc_*`, `legacy_order_status`, `legacy_order_date` | Commercial / RD status fill-missing |
| `radiumbox_sync_*` | Existing enrichment job status |

No address column exists. Amount/tax/total besides `payment_amount` are not stored. No `order_history` table.

### Idempotency already present

- `orders.order_id` unique
- `orders.cashfree_payment_id` unique
- Webhook: existing incident for `cf_payment_id` → mark processed, skip create
- Outbox `idempotency_key` per deferred operation + incident
- Enrichment job `ShouldBeUnique` on order id

### Correlation field

**Yes.** `orders.order_id` is already Cashfree `data.order.order_id` / RD `rdorderid` (example `RD3000003`).

---

## Design (smallest safe change)

Prefer RDService.net on the **existing** Cashfree paid enrichment job. Do not add a second payment processor, queue, webhook, or public endpoint.

```
Cashfree PAYMENT_SUCCESS
  → existing webhook (unchanged payment path)
  → one Desk order (order_id = RDxxxx)
  → existing radiumbox_enrichment outbox + RadiumBoxOrderEnrichmentJob
        → GET https://rdservice.net/api/integrations/v1/rd-orders/RDxxxx
              Authorization: Bearer {DESK_ORDER_API_TOKEN}
        → fill-missing on the same orders row
        → if still incomplete or RDService 404/401/malformed → existing Admin lookup
        → if RDService 429/5xx/timeout → retry job (payment already committed)
```

**Case B (RDService-first):** RDService API is read-only and Desk has no RDService webhook. Supported only when a Desk order already exists (`orders.order_id`); Cashfree later links payment via `linkPaymentToExistingOrder`. No pending-order factory from RDService.

**Legacy:** RDService 404 → Admin `/api/search/order` unchanged. Admin code not removed.

**Tag-complete bypass:** unchanged. Complete Cashfree `order_tags` still skip the enrichment job (Ready Queue). RDService runs when that job already would (incomplete tags, manual, recovery).

---

## Field mapping (Desk columns only)

| RDService | Desk column | Rule |
|-----------|-------------|------|
| `correlation.rdorderid` / `rd_order.rdorderid` | lookup key vs `orders.order_id` | Must match; mismatch → treat as not found |
| `rd_order.serial_no` / `snapshot.serial_number` | `serial_number` | Fill-missing; duplicate serial skipped |
| `rd_order.product_name` / snapshot model | `product_name`, `device_model` | Fill-missing |
| `rd_order.rd_service_name` / snapshot.rd_service | `service_history` | Fill-missing |
| userdetails / snapshot customer | `customer_*` | Existing identity protection |
| `gst_no` / snapshot.gst_number | `gst_number` | Fill-missing |
| `order.invoicecode` / snapshot.invoice_number | `invoice_number` | Fill-missing |
| AMC service | `amc_status`, `amc_details` | Fill-missing |
| `rd_order.status` / snapshot.rd_order_status | `legacy_order_status` | Fill-missing |
| `created_at` / `orderdate` | `legacy_order_date`, `purchase_year` | Fill-missing |
| Cashfree payment ids/amounts from RDService | **not written** | Cashfree webhook remains SoT |
| address, lines, full history blob, tax/total | **not stored** | No matching required columns |

---

## Security

- `DESK_ORDER_API_TOKEN` via `config/rdservice.php` / `.env` (`env('DESK_ORDER_API_TOKEN')`). Never hard-coded.
- Token never logged (401 logs status + order id only; exception messages redact token).
- HTTPS-only `RDSERVICE_BASE_URL` (default `https://rdservice.net`). HTTP or empty host → skip, no request.
- Host is config-only. Path is fixed. Order id validated (`^RD[0-9A-Za-z]{1,61}$`) and hardware/inquiry ids skipped.
- Timeouts: connect 3s, read 8s.
- No new public Desk endpoints.
- No `rdservice_net_prod` (or any second) database connection.

HTTP status handling:

| Status | Behavior |
|--------|----------|
| 200 | Map + fill-missing |
| 401 | Log without secrets; Admin fallback; no RDService retry |
| 404 | Admin fallback (legacy) |
| 429 / 5xx / timeout | Retry existing job; do not call Admin on that attempt |
| Malformed 200 | Admin fallback |

---

## Tests run

| Suite | Result |
|-------|--------|
| `tests/Unit/RdService` + `tests/Feature/RdService` | 33 passed |
| Cashfree processor, existing-order link, first-enrichment bypass, paid RadiumBox, RDService | 55 passed |
| `CashfreeOrderTagsIngestTest` + `CashfreeWebhookTest` | 14 passed |
| Pint (changed PHP) | passed |
| PHP syntax (`php -l` on new/changed services) | passed |
| Static analysis | not configured (no PHPStan/Larastan) |

Coverage: Cashfree-first, RDService-first (existing Desk row), duplicate webhook, duplicate RDService, race, 401/404/429/5xx/malformed, legacy Admin fallback, no `rdservice_net_prod` connection, no duplicate order, payment fields preserved, secrets not logged.

Pre-existing (also fails on `origin/main` with original `RadiumBoxService`): `RadiumBoxOrderEnrichmentTest::test_existing_local_serial_and_device_model_are_not_overwritten` (`Http::assertNothingSent` while service history is empty). Not introduced by this change.

---

## Production activation (not done here)

Set on Desk only (do not change RDService.net in this ticket):

```
RDSERVICE_ENABLED=true
RDSERVICE_BASE_URL=https://rdservice.net
DESK_ORDER_API_TOKEN=<same token RDService.net uses>
```

PHPUnit forces `RDSERVICE_ENABLED=false` and an empty token so existing suites cannot leak `.env` credentials or call RDService.

---

## Remaining risks

- Complete Cashfree `order_tags` still bypass the enrichment job; GST/AMC from RDService for those orders wait until a later job (manual/recovery) if identity is incomplete again.
- RDService 404 immediately after payment (write race) falls back to Admin; if Admin also 404, existing `order_not_found` sync behavior applies until recovery.
- Invalid token: 401 then Admin. Ops must rotate `DESK_ORDER_API_TOKEN` on both sides.
- No RDService-first order create without a pre-existing Desk row (no new ingress).
- Not deployed; production env token not set by this ticket.
