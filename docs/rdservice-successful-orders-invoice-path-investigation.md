# RDService.net successful orders and invoice path

**Ticket:** RadiumDesk-P-31-08-14  
**Date:** 2026-08-31  
**Type:** Read-only investigation. No Desk activation, no deploy, no Admin/RDService.net/schema change, no GSP/NIC/payment-provider call, no invoice generated.  
**Canvas:** [`rdservice-successful-orders-invoice-path.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-RadiumWebsites-radium-service-desk-deploy/canvases/rdservice-successful-orders-invoice-path.canvas.tsx)

**Classification:** **VERIFIED** (observed this ticket), **INFERRED**, **UNKNOWN**.

Related prior work (Desk-side, did not query this production DB): [`rdservice-desk-invoice-flow-investigation.md`](rdservice-desk-invoice-flow-investigation.md), [`rdservice-desk-order-api-integration.md`](rdservice-desk-order-api-integration.md).

---

## Verdict

Live **rdservice.net is up** and has **successful Cashfree-paid orders**. Today’s representative live-path success is **`RD3506770T6a9522b8`** (`orders.id` **315474**, `payment_status=Paid`, RD `status=Processing`, Cashfree `payment_id` present).

That order has **no invoice number, no IRN, no e-waybill**. Generating one would create irreversible commercial/statutory state. **This ticket did not generate an invoice.**

Desk’s RDService.net enrichment remains **OFF**. Admin `GET /api/search/order` remains the live fallback. The Desk order API is **not deployed** on live rdservice.net.

---

## Production identity (verified before SELECT)

| Check | Value |
|-------|--------|
| Project | RDService.net |
| Public URL | `https://rdservice.net` (homepage HTTP 200) |
| Host | `srv1910783` / `187.127.129.16` (same KVM as Desk) |
| SSH user | `ravi` |
| Path | `/var/www/rdservice.net.prod` |
| `APP_ENV` / `APP_URL` | `production` / `https://rdservice.net` |
| Database | MySQL `rdservice_net_prod` @ `127.0.0.1:3306` |
| DB user | `rdservice_net_prod` |
| Cashfree | Live `api.cashfree.com` |
| Desk token on this tree | `DESK_ORDER_API_TOKEN` **unset** |
| WhiteBooks / `app/Services/Einvoice` | **Absent** on production tree |
| Desk order API files | **Absent** on production tree |

Desk production (same host, not modified): `APP_ENV=production`, `DB_DATABASE=radium_desk`, `RDSERVICE_ENABLED` **unset**, `DESK_ORDER_API_TOKEN` **unset**. Runtime previously verified `rdservice.enabled=false`.

**test.rdservice.net** was not modified. Documented as `/var/www/rdservice.net`, DB `rdservice_net`, Cashfree sandbox. Unauthenticated GET `/api/integrations/v1/rd-orders/RD1` returned HTTP **404**. Recorded as a dependency only.

`radiumbox_prod` was **not** queried.

---

## 1. How production orders are created and stored

Production PHP (not the local WIP `PaymentGatewayManager` tree):

```
rdservice_cart
  → POST rd-service/order/genrate/{cart}  OrderController::Store
  → INSERT order_rdservice (status=Pending, website=rdservice.net)
  → CaseFree::createOrder('RD'+id)
       on Cashfree order_already_exists → 'RD'+id+'T'+dechex(time())
  → redirect payment_link
  → GET /rd-order-success and/or POST /cashfree/notify
  → RdPaymentFulfillment (Cashfree order_status=PAID + amount match)
  → OrderController::GenrateOrder
       INSERT orders (payment_status=Paid, ordercode=RD{orders.id}, ordertype=rdservice)
       INSERT order_details (service + optional AMC + duration lines)
       UPDATE order_rdservice.status = Processing
```

**VERIFIED:** `GenrateOrder` does **not** set `invoicecode`, `invoice_date`, or `einvoice_respose`.

Local git WIP (`wip/p04-08-03-einvoice-payment-export-ui`) uses `PaymentGatewayManager` + session checkout. That code is **not** what live `/var/www/rdservice.net.prod` runs.

---

## 2–3. Successful orders exist; representative set

`rdservice_net_prod` is a **shared-legacy copy** (cutover snapshot plus live writes). Website mix on `order_rdservice`:

| website | rows |
|---------|------|
| rdservice.in | 359,976 |
| radiumbox.com | 119,271 |
| rdservice.net | 27,419 |
| direct_buy | 105 |
| **Total** | **506,771** |

**Successful** for this report = `orders.payment_status = 'Paid'` joined to `order_rdservice`.

| Scope | Paid linked | With invoicecode | With `einvoice_respose` | With `e_way_bill` | With Cashfree `payment_id` |
|-------|-------------|------------------|-------------------------|-------------------|----------------------------|
| All websites | 250,304 linked / 264,453 Paid orders | 256,822 | 12,642 | 398 | **2** |
| website=`rdservice.net` | **11,528** | 10,829 | 289 | **0** | **1** |

T-suffix `rdorderid` rows: **9**, all `website=rdservice.net`, **1 Paid** (today).

### Representative identifiers (no customer/payment PII)

| rdorderid | website | RD status | orders.id | Paid | Invoice | IRN | payment_id |
|-----------|---------|-----------|-----------|------|---------|-----|------------|
| **RD3506770T6a9522b8** | rdservice.net | Processing | 315474 | Yes | No | No | Yes |
| RD3506642 | rdservice.in | Completed | 315417 | Yes | INV6796998 | No | No |
| RD3506631 | rdservice.net | Processing | 315406 | Yes | No | No | No |
| RD3506763T6a944b7c | rdservice.net | Failed | — | No | No | No | No |
| RD3500747 | rdservice.in | Refund | 311961 | Yes | No | No | Yes |

There is **no** `order_rdservice.rdorderid = RD3506770` without the T suffix. **VERIFIED.**

Last invoice on this DB: `invoice.id` 259056, **INV6796998**, `service_type=rdservice`, created **2026-08-30**. Last invoiced `orders.id` **315417**. Later Paid rows on this DB (including today’s T-suffix) have no `invoicecode`. Whether Admin continues numbering on `radiumbox_prod` is **UNKNOWN** (other DB, not queried).

`orders.orderdate` is a string; do not use min/max of that column as a date range. `order_rdservice.created_at` spans 2024-05-24 … 2026-08-31.

---

## 4. Desk order-enrichment contract

Desk `RdServiceOrderMapper` + `RdServiceClient` (production Desk v4.0.64, integration **disabled**):

| Desk column | RDService payload | Rule |
|-------------|-------------------|------|
| lookup key | `correlation.rdorderid` / `rd_order.rdorderid` | Exact string match |
| `serial_number` | `serial_no` / snapshot | Fill-missing |
| `product_name` / `device_model` | product / model | Fill-missing |
| `service_history` | `rd_service_name` | Fill-missing |
| `customer_*` | snapshot / userdetails | Identity locks |
| `gst_number` | `gst_no` | Fill-missing |
| `invoice_number` | `order.invoicecode` | Fill-missing; **external copy** |
| `amc_*` | AMC snapshot | Fill-missing |
| `legacy_order_status` / date | RD status / created | Fill-missing |
| payment columns | present on API | **Not written** (Cashfree SoT) |
| address, lines, tax/total | present on API | **Not stored** on Desk |

Endpoint Desk would call if enabled: `GET https://rdservice.net/api/integrations/v1/rd-orders/{rdorderid}` with `Authorization: Bearer {DESK_ORDER_API_TOKEN}`.

**Live GET without token:** HTTP **302** to `https://rdservice.net` (route not present). Production tree has no `app/Services/Integrations` and no API controller. The lookup service exists only in RDService.net git WIP / (intended) test deploy.

---

## 5–9. Invoice generation path, dependencies, sources of truth

### Path that actually numbers invoices

Not rdservice.net checkout. **Admin:**

```
Admin GET genrate/invoice/{orders.id}   GenrateInvoice::Invoice
  → insert invoice (INV/IND… from radium_branch.rd_slug + rd_no+1)
  → increment radium_branch.rd_no
  → UPDATE orders.invoicecode, invoice_date, branch
  → UPDATE order_rdservice.is_requestinvoice = 2
  → if GSTIN and envoice=1:
        GET https://media.radiumbox.com/api/einvoice/{id}/{branch}/einvoice
        → UPDATE orders.einvoice_respose
```

Admin **reprint** (no new number): `GET print/invoice/{id}` → `GenrateInvoice::Print`.

Admin **IRN JSON helper** `GET get/einvoice/irn/{id}/{branch}` builds NIC 1.1 JSON and returns it. It does **not** POST to NIC in that method. **VERIFIED** in Admin repo.

E-waybill: Admin `POST ewaybill/{id}`. **0** `e_way_bill` values on website=`rdservice.net` Paid rows. **VERIFIED.**

WIP WhiteBooks / `order_einvoices` / `invoice_sequences`: in RDService.net git, **not** on production filesystem, **tables absent** on `rdservice_net_prod`.

### rdservice.net my-account invoice

`PaidOrderController::Invoice` would attempt `INS{n}` from table `invoice` if `invoicecode` is null. On production: **not routed**, **Invoice model directory missing**, **invoice views missing**. Not a usable path. **VERIFIED.**

### Dependencies

| Dependency | Role |
|------------|------|
| Cashfree live API | Payment SoT for fulfill |
| `rdservice_net_prod` | Live order/commerce rows |
| Admin (`GenrateInvoice`) | Invoice number authority |
| `radium_branch.rd_no` | Sequence |
| `media.radiumbox.com` | E-invoice/GSP proxy |
| NIC (via media/GSP) | IRN |
| Desk | **Not in this path** |
| WhiteBooks | **Not on production** |

### Sources of truth

| Fact | SoT |
|------|-----|
| Payment success | Cashfree `PAID` |
| Checkout / Cashfree order id | `order_rdservice.rdorderid` (may include `T`) |
| Internal commerce id | `orders.id` / `ordercode` `RD{orders.id}` (different from Cashfree id) |
| Tax invoice number | Admin `invoice.invoice` → `orders.invoicecode` |
| IRN | `orders.einvoice_respose` after media.radiumbox.com |
| Desk operational order | Cashfree webhook → `radium_desk.orders` (separate) |

---

## 6. Do successful RDService.net orders already have invoice / IRN / e-way / payment correlation?

For **website=rdservice.net** Paid (11,528):

- Invoice number: **10,829 yes** / remainder no (includes recent Processing rows and today’s T-suffix success).
- Invoice **record**: `invoice` table has 259,056 rows (all websites/types). Prefixes on Paid `invoicecode`: INS 146,304, INV 96,646, IND 13,381, INM 491.
- GST/IRN response: **289** with `einvoice_respose`; today’s success **no**.
- E-waybill: **0**.
- Payment/provider correlation: Cashfree `payment_id` stored on **1** rdservice.net Paid row (today’s T-suffix). Historical Paid almost never stored `payment_id` on this DB.

`is_requestinvoice`: null 449,303 / `1` 39,435 / `2` 18,033. Admin list treats `1` as invoice-requested; generation sets `2`. **VERIFIED** in Admin `GenrateInvoice`.

---

## 7–8. Can invoice generation be performed for one existing successful order?

**New invoice / IRN for `RD3506770T6a9522b8`:** **NO — STOP.**  
Would consume `radium_branch.rd_no`, write `invoice` + `orders.invoicecode`, and optionally call media.radiumbox.com / GSP. Irreversible commercial/statutory state.

**Documented non-destructive path:** Admin `print/invoice/{id}` for an **already numbered** order (e.g. `orders.id` 315417 / INV6796998). Does not mint a new number. **Not executed this ticket** because:

1. Admin was not changed and not logged into.
2. Admin’s production database vs `rdservice_net_prod` is **UNKNOWN** (do not use another project’s DB).
3. Confirming reprint in Admin would still require a human on Admin UI.

**Prerequisites for a later controlled test**

1. Confirm which database Admin production uses (`radiumbox_prod` vs `rdservice_net_prod`).
2. If reprint: pick an order that **already** has `invoicecode` on **that** database; use `print/invoice/{id}` only.
3. If new invoice is explicitly authorised: use a **B2C** Paid order (no GSTIN) so IRN/GSP is not requested; accept a new INV number; never call `envoice=1` / media.radiumbox.com unless IRN is separately authorised.
4. Do not use today’s T-suffix order as the first IRN experiment.
5. Do not enable Desk `RDSERVICE_ENABLED` as part of an invoice test.

---

## 10. Mismatches (RDService data vs invoice vs Desk)

| Mismatch | Class |
|----------|--------|
| Paid fulfill does not create `invoicecode` | VERIFIED |
| Desk would copy `invoicecode` as if it were Desk’s invoice | VERIFIED (mapper) |
| Customer `RD{id}` vs Cashfree `RD{id}T…` — no non-T row for today’s success; Desk correlation is exact match | VERIFIED |
| Desk API not on live rdservice.net; enabling Desk token would still miss the route | VERIFIED |
| Desk does not store address / HSN / tax split; RDService `orders`/`order_details` do | VERIFIED |
| Historical Paid rows lack Cashfree `payment_id`; Desk payment SoT cannot be filled from RDService | VERIFIED |
| WhiteBooks IRN designed in git, absent on production | VERIFIED |
| Invoice numbering likely still Admin-on-shared-ERP; this DB stopped new codes after 315417 on 2026-08-30 | VERIFIED on this DB; Admin-other-DB = UNKNOWN |
| E-waybill not used for rdservice.net service Paid rows | VERIFIED |
| `rdservice_net_prod` still contains rdservice.in / radiumbox.com history | VERIFIED |

---

## Desk integration (unchanged this ticket)

| Item | State |
|------|--------|
| `RDSERVICE_ENABLED` | Config default **false**; production Desk `.env` key **unset** |
| `DESK_ORDER_API_TOKEN` | Unset on Desk and on rdservice.net.prod |
| RDService lookup | Client present on Desk; **skipped** (disabled + empty token). Live host has **no** Desk API route |
| Admin fallback | `GET /api/search/order` still used |
| Hardware `RDE` / `RIN` | `Order::isHardwareOrderId` → RDService HTTP skipped |
| `INQ-` | `Order::isInquiryOrderId` → RDService HTTP skipped |

---

## This ticket did not

- Enable `RDSERVICE_ENABLED` or set `DESK_ORDER_API_TOKEN`
- Deploy Desk or change order lookup
- Change Admin, RDService.net, DNS, Cloudflare, schemas, or production `.env`
- INSERT/UPDATE/DELETE any production row
- Call Cashfree, GSP, NIC, or media.radiumbox.com except unauthenticated GETs to rdservice.net homepage and the missing Desk API path
- Shut down rdservice.net or modify test.rdservice.net
- Generate or reprint an invoice
