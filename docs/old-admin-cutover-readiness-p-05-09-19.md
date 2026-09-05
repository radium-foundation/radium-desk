# Old Admin cutover readiness — RadiumDesk-P-05-09-19

**Date:** 2026-09-05 21:12 IST  
**Type:** Read-only investigation / readiness gate.  
**Primary ID:** `RadiumDesk-P-05-09-19`  
**Companions:** `radiumbox.com-P-05-09-15`, `RadiumServiceNet-P-05-09-01`, `Admin-P-05-09-02`

No AWS shutdown, SSL repair, DNS/vhost/deploy, production write, invoice mint, sequence init, DLQ flush, migration, Cashfree/seller/UPI change.

---

## Production boundary (this session)

| Project | Repository | Branch / HEAD | Production path | Server | Database | Deploy | DNS / vhost | Integrations | Backup / rollback |
|---|---|---|---|---|---|---|---|---|---|
| Radium Desk | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` `git@github.com:radium-foundation/radium-desk.git` | `main` `b282be39` (local ahead 1 = ledger) | `/var/www/radium-desk` | KVM8 `srv1910783` / `187.127.129.16` | `radium_desk` @ `127.0.0.1` | KVM rsync (not full deskd) | `desk.radiumbox.com` | `RADIUMBOX_ENABLED=true` → `https://admin.radiumbox.com`; RDService token **ABSENT** | Cron 02:00 / 14:00 IST. Unused this ticket. |
| radiumbox.com | `/Users/ravi/RadiumWebsites/radiumbox.com` | local `launch/real-production` `33586b4b` (dirty; not production SoT) | `/var/www/radiumbox.com` | same KVM8 | **`radiumbox_prod`** @ `127.0.0.1` — **only** `/var/www` app using this DB | KVM8 OLS + `schedule:run` | `https://radiumbox.com` | Own Cashfree; Desk ingest pending (11 handoffs) | `/var/backups/radiumbox-prod/` pre-refresh / pre-replace dumps present. Unused. |
| Old Admin | `/Users/ravi/RadiumWebsites/Admin` | local inspect `27f442d9` | AWS `13.234.230.151` `/var/www/admin.radiumbox.com` | Public CF → 526 | Live host **UNKNOWN** (SSH denied). Dump 2026-09-05 11:08:56 = `radiumbox` @ AWS localhost | Filesystem / LiteSpeed | `admin.radiumbox.com` public **526**; origin cert expired 2025-06-01 | `GET /api/search/order`; reprint `GET /admin/print/invoice/{orders.id}`; e-invoice via `media.radiumbox.com` | Do not retire. |
| rdservice.net | `/Users/ravi/RadiumWebsites/rdservice.net` | local WIP not production SoT | `/var/www/rdservice.net.prod` | same KVM8 | `rdservice_net_prod` | named-file overlay | `https://rdservice.net` | Desk lookup API **deployed**, token **ABSENT** | Unused. |
| rdservice.in | `/Users/ravi/RadiumWebsites/rdservice.in` | not modified | `/var/www/rdservice.in` | same KVM8 | `rdservice_in_prod` | KVM8 | `https://rdservice.in` | Desk ingest URL empty | Unused. |

KVM8 has **no** production Admin vhost. AWS SSH (`ubuntu`/`root` @ `13.234.230.151`) **Permission denied**.

---

## A. `radiumbox_prod` reconciliation

**Not the same physical database as AWS Admin.**

| Fact | AWS dump `radiumbox-backup-FINAL-2026-09-05.sql.gz` | KVM8 `radiumbox_prod` 2026-09-05 21:12 IST |
|---|---|---|
| Identity | MariaDB 10.11.13, `Host: localhost`, `Database: radiumbox`, dump completed **2026-09-05 11:08:56**, gzip SHA256 `82b9cab2…` | MariaDB 11.8.8 on `srv1910783`, schema `radiumbox_prod`, 6513.45 MB, **104** tables |
| Tables | 102 | 104 (`desk_order_handoffs`, `cashfree_webhook_events` additive) |
| orders AUTO / MAX | AUTO_INCREMENT **318348** | COUNT 308369, MAX(id) **318368** |
| order_rdservice | AUTO_INCREMENT **3511400** | COUNT 511418, MAX **3511415** |
| users | AUTO_INCREMENT **549478** | COUNT 515942, MAX **549487** |
| invoice | AUTO_INCREMENT **261336** | COUNT 261335, MAX 261335 |
| credit_note | AUTO_INCREMENT **34** | COUNT 31, MAX 33 (ids 1–33, gap 9/24) |

Application using KVM8 schema: **only** `/var/www/radiumbox.com`. Desk / net / in / sign use their own DBs.

Sample reconciliation (historical Admin reprint example):

| Key | AWS origin API (insecure TLS) | KVM8 `radiumbox_prod` | `rdservice_net_prod` |
|---|---|---|---|
| `RD3449705` | 200, website `rdservice.net`, Completed | same | same |
| `orders.id` | 268507 | 268507 | 268507 |
| `invoicecode` | `INV6745886` | `INV6745886` | `INV6745886` |
| payment | Paid | Paid | Paid |

Newest KVM8 orders `318348`–`318368` are **post-dump Box/RDE activity** and cannot exist in the 11:08 AWS dump. Origin API 404 for `RA3506774`, `RD3511924`, `RD3506770T6a9522b8` — AWS Admin no longer sees post-split net/in identifiers.

`order_rdservice` on KVM8 is **not** current for all websites:

- `rdservice.net` last created **2026-08-30 17:35:22** (net lives in `rdservice_net_prod`)
- `rdservice.in` last created **2026-09-05 05:09:18** (`rdservice_in_prod` now MAX id **3511970**)
- `radiumbox.com` live through **2026-09-05 20:39:40**

**FINAL `radiumbox_prod` DATABASE: UNKNOWN**

Not PASS: AWS live counts/MAX(id) were not queried (SSH denied). Not FAIL on history: dump AUTO_INCREMENT + matched `RD3449705`/`INV6745886` show the 11:08 AWS snapshot is present, and KVM8 is a **superset** for Box writes. It is **not** the sole current order/invoice store.

---

## B–C. Order-search + Desk 360

| Item | Finding |
|---|---|
| Current Desk path | `RadiumBoxClient` → `GET https://admin.radiumbox.com/api/search/order?orderid=` — **live**, public **526** |
| Replacement | `GET https://rdservice.net/api/integrations/v1/rd-orders/{id}` Bearer `DESK_ORDER_API_TOKEN` |
| Net route | **Deployed** (public 401 JSON) |
| Token Desk | **KEY_ABSENT** |
| Token net | **KEY_ABSENT** → fail-closed 401 |
| Data source | `rdservice_net_prod`, `allowed_websites` default `rdservice.net` only |
| `RD3449705` in net DB | **YES** (same invoice). Authenticated GET **not performed** (no token) |
| Hardware `RDE`/`RIN`/`INQ` | Excluded by net API; remain Admin-only |
| `radiumbox.com` / `rdservice.in` IDs | **Not** in net allow-list |
| Fallback | Already in `OrderEnrichmentLookupService` (replacement first, Admin on skip/401/404). **Do not remove.** |

Desk 360 reads **Desk `orders` columns**, not a live Admin reprint. Intake preview maps Admin/`rd_order` + `order.invoicecode` → serial, model, customer, GST, invoice, years, AMC, legacy status/date.

`RD3449705` already on Desk (`orders.id` 11050, `SYNCED`): serial/product/customer/payment present; **`invoice_number` NULL**; no GST/service history/legacy status. Historical `INV6745886` is therefore **not** on the 360 row today.

**ORDER-SEARCH REPLACEMENT: BLOCKED** (token absent; coverage is net-website only).  
**DESK 360 HISTORICAL ORDER LOOKUP: BLOCKED** (same; invoice field not on the known synced row).

---

## D. 1 September 2026+ invoice backlog (do not mint)

Use `created_at` (mixed `orderdate` formats make string filters wrong).

### KVM8 `radiumbox_prod` (`created_at >= 2026-09-01`)

- Orders: **2021**
- Paid + invoice (Admin/historical — **do not remint**): **1342** (`rdservice.in` INV 1239, Box INV 44, hardware IND 56, INM 3)
- Paid + **no** invoice (Desk candidates): **528** — `rdservice.in` 516 (65 B2B), `radiumbox.com` 10 (6 B2B), RDE 2
- Refund: 7 (6 already invoiced)
- reject/cancelled-like: 31
- Paid B2B / B2C (all paid): 85 / 1785
- Paid-no-invoice line types: Digital **505** orders, Physical 1, unknown 22

`radiumbox_prod` is **stale for rdservice.in**. Prefer `rdservice_in_prod`:

### `rdservice_in_prod`

- Paid since Sept 1: **2098** (Completed 1233, Pending 859, Refund 6)
- Already invoiced: **1239** — **do not remint**
- Paid no invoice: **859** (authoritative in-spoke candidates)
- B2B among paid: **79**

### `rdservice_net_prod`

- Sept 1+ `order_rdservice`: **152 Pending**, **0 Paid** by `created_at`
- No `order_einvoices` table

### Desk `radium_desk`

- `statutory_invoices` **0**, `invoice_sequences` **0**, `commerce_orders` **0**
- Desk orders since Sept 1: 2193 (operational, not statutory)

Issuer for later mint: Delhi/Mumbai location series. Place of Supply / GSTIN must be taken from the **spoke row** at generation time, not invented here.

---

## E. Numbering

Owner rule is already in live `config/statutory_invoices.php` (`location_series.enabled`, first numbers `INV-07671` / `INV-27671`, scope `2026-09-01`). Sequences **uninitialized** (0 rows). Admin history has **0** `INV-0767*` / `INV-2767*`. Seller legal name + Delhi/Mumbai GSTIN **PRESENT**; auto-issue / worker mint **OFF**. Safe to generate later **after** an Owner-approved mint ticket. **Not initialized this ticket.**

---

## F. E-invoicing

Production path (Old Admin): `InvoiceController::gen_einvoice` → `GET https://media.radiumbox.com/api/einvoice/{id}/{branch}/einvoice` → persist `orders.einvoice_respose`. **This GET must not be called with a real id in this ticket.**

- `media.radiumbox.com` homepage **200** (Hostinger). `/api/einvoice` without id **404**.
- KVM8 has **13140** populated `einvoice_respose` rows.
- Latest inspected row `orders.id` 318338 / `IND671904` / invoice_date **2026-09-05 11:23:03**: JSON `status_cd` present; `data.Status=ACT`; **64-char Irn**; AckNo (len 15); AckDt; SignedInvoice; SignedQRCode. **No IRN value printed.**
- Public Admin UI **526** — operators cannot mint from the hostname Desk still uses.
- Desk: `STATUTORY_EINVOICE_PROVIDER` default `none`; `NullEInvoiceGateway`; **0** statutory invoices.

**E-INVOICING: NOT PROVEN** for a new live submit. Last persisted ACT acknowledgement exists (this morning). Owner action required for a controlled non-minting Get-IRN or an approved test submit. Desk path is not operational.

---

## G. Future invoice policy

Still required after the September backlog. Do not choose: staff-manual / customer-request / automatic / hybrid. Engine already supports manual Finance Hub issue with auto-issue OFF.

---

## H. Old Admin / AWS retirement

| Dependency | Class |
|---|---|
| Desk order search | **VERIFIED** live Admin; replacement blocked |
| Historical reprint | **VERIFIED** Admin-only `Print($id)`; Finance Hub 0 |
| Hardware RDE/RIN/INQ | **VERIFIED** no net API |
| Cashfree storefronts | **VERIFIED** independent of Admin HTTP |
| Cashfree merchant leftover Admin URL | **UNKNOWN** (dashboard not opened) |
| AWS DB | **VERIFIED** dump `radiumbox` localhost; live host **UNKNOWN** |
| EC2 `13.234.230.151` | **VERIFIED** origin Admin up; SSH denied |
| media.radiumbox.com e-invoice/drivers | **VERIFIED** live; keep |
| DNS/certs/vhosts | **VERIFIED** public 526; origin cert expired |
| Cron/queue Desk | **VERIFIED** schedule + supervisor; DLQ 42 (40×526), no flush |
| Wallet Admin UI | **UNKNOWN** |

**OLD ADMIN RETIREMENT: NOT READY**  
**AWS EC2 SHUTDOWN: NOT READY**
