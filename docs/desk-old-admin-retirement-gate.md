# Old Admin retirement / cutover gate

**Ticket:** RadiumDesk-P-05-09-18  
**Date:** 2026-09-05  
**Type:** Read-only investigation. No code, config, DNS, Cloudflare, certificate, queue, invoice, or production-data change.

**Verdict:** **NOT READY — BLOCKERS REMAIN**

Companion prior tickets (re-verified, not assumed): `RadiumDesk-P-31-08-09`, `radiumbox.com-P-05-09-12`, `radiumbox.com-P-05-09-14`.

---

## Production boundaries (this session)

| Project | Repository | Branch / HEAD | Production path | Server | Database | Deploy | vhost / DNS | Integrations | Backup / rollback |
|---|---|---|---|---|---|---|---|---|---|
| Radium Desk | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` `git@github.com:radium-foundation/radium-desk.git` | `main` `363c06be` = `origin/main` | `/var/www/radium-desk` | `srv1910783` / `187.127.129.16` | `radium_desk` @ `127.0.0.1` | KVM rsync (not full `deskd`) | `desk.radiumbox.com` | Cashfree account webhook; `RADIUMBOX_BASE_URL=https://admin.radiumbox.com` | Latest Desk backup `20260905T152234Z` (cloud uploaded). Unused this ticket. |
| RadiumBox storefront | `/Users/ravi/RadiumWebsites/radiumbox.com` | local `launch/real-production` (dirty; **not** used as production SoT) | `/var/www/radiumbox.com` | same KVM8 | `radiumbox_prod` @ `127.0.0.1` | KVM8 app | `https://radiumbox.com` | Own Cashfree PG (`notify` = `https://radiumbox.com/api/payments/cashfree/webhook`) | Not re-run. |
| Old Admin | `/Users/ravi/RadiumWebsites/Admin` `github.com:radium-foundation/Admin.git` | local `spike/admin-p-01-09-02-rdservice-in-invoice-gate` `27f442d9` (source inspect only) | origin tree on AWS `13.234.230.151` `/var/www/admin.radiumbox.com` (**prior tickets**; not re-SSHed this session) | Public CF `admin.radiumbox.com` → `104.21.42.236` / `172.67.212.65` | Origin DB host **UNKNOWN this session** | Not on KVM8 | Public HTTPS **526**. Origin cert `CN=admin.radiumbox.com` LE **expired 2025-06-01** | `GET /api/search/order`; `GET /admin/print/invoice/{id}` | Do not retire. |
| rdservice.net | `/Users/ravi/RadiumWebsites/rdservice.net` | not modified | `/var/www/rdservice.net.prod` | same KVM8 | `rdservice_net_prod` | KVM8 | `https://rdservice.net` | Own Cashfree. Desk lookup API present, token **ABSENT** | Unused. |
| rdservice.in | `/Users/ravi/RadiumWebsites/rdservice.in` | not modified | `/var/www/rdservice.in` | same KVM8 | `rdservice_in_prod` | KVM8 | `https://rdservice.in` | Own Cashfree. Desk ingest URL **EMPTY** | Unused. |
| radiumsign.com | `/Users/ravi/RadiumWebsites/radiumsign.com` | not modified | `/var/www/radiumsign` | same KVM8 | `radiumsign_prod` | KVM8 | `https://radiumsign.com` | No Admin hostname in `.env` | Unused. |
| rdserviceonline.in | `/Users/ravi/RadiumWebsites/rdserviceonline.in` | not modified | `/var/www/rdserviceonline` | same KVM8 | `rdserviceonline` | KVM8 | `https://rdserviceonline.in` | No Admin hostname in `.env` | Unused. |

KVM8 `/var/www` has `beta-admin` only. **No** production `admin.radiumbox.com` vhost on this host. **VERIFIED.**

---

## 1. Code dependencies

| Repository | File | Endpoint | Caller | Purpose | Production relevance | Active? | Replacement |
|---|---|---|---|---|---|---|---|
| Desk | `config/radiumbox.php`, `.env.example` | default `https://admin.radiumbox.com` | config | Admin base URL | **Live** `RADIUMBOX_BASE_URL` is this host | **YES** | RDService lookup (not activated) |
| Desk | `app/Services/RadiumBox/RadiumBoxClient.php` | `GET {base}/api/search/order?orderid=` | enrichment / recovery / workspace | Order identity fill | **Live**, `RADIUMBOX_ENABLED=true` | **YES** | `RdServiceClient` `GET /api/integrations/v1/rd-orders/{id}` |
| Desk | `OrderEnrichmentLookupService` | Admin fallback | intake, global search, legacy import, identity repair | RDService-first only when enabled+token | Production flag **off** → Admin-only | **YES** | Same service after activation |
| Desk | `RadiumBoxService` | Admin after optional RDService | Cashfree job / workspace | Fill serial/model/product/service history | **YES** | Same | Same |
| Desk | `RadiumBoxOrderEnrichmentJob` | via client | queue `critical`, 4 tries, backoff 60/300/1800 | Background sync | **YES** — still failing HTTP 526 | **YES** | Activate RDService **and** stop Admin fallback |
| Desk | Finance Hub views | none | operators | Copy says historical Admin INV* are **not** imported | Active UI, no Admin link | N/A | Later read-only import |
| Admin | `routes/api.php` | `GET /api/search/order` | `OrderApiController` | Returns `orders` + `order_rdservice` by `rdorderid` | Origin still serves JSON when TLS is bypassed | **YES** (origin) / **broken** (public CF) | Desk RDService API |
| Admin | `routes/order.php` | `GET /admin/print/invoice/{id}` | `GenrateInvoice::Print` | SELECT-only reprint | Origin 302→login; public 526 | **YES** for humans with Admin login | **None** on Desk/Box |
| Admin | `app/Helper/CaseFree.php` | `route('cashfree.notify')` / `cashfree.callback` | Admin PG helper | Residual Admin Cashfree create | Named routes **not found** in current Admin `routes/` | **UNKNOWN** if still invoked | Box/net/in have own PG |
| Box / net / in / sign / online **app** trees | — | no `admin.radiumbox.com` | — | — | Grep of `app/` **empty** | **NO** runtime call | Already independent |

All rows **VERIFIED** from this worktree + production `.env` key presence, except Admin Cashfree invocation (**UNKNOWN**).

---

## 2. Desk → Old Admin order search (re-verified)

| Item | Value | Class |
|---|---|---|
| Client | `App\Services\RadiumBox\RadiumBoxClient` | VERIFIED |
| Endpoint | `GET https://admin.radiumbox.com/api/search/order?orderid=` | VERIFIED |
| Auth | None. Commented IP allowlist in Admin `OrderApiController` (only `187.127.183.72`) is **not** enforced | VERIFIED |
| Contract | JSON `{status, data: {order, rd_order}}` mapped by `RadiumBoxOrderSearchResponseMapper` | VERIFIED |
| Retry | Job 4 tries; HTTP 5xx/526 retriable; then `failed_jobs` + `radiumbox_sync_status=FAILED` | VERIFIED |
| Queue | `RadiumBoxOrderEnrichmentJob` on critical queue | VERIFIED |
| Recovery | `RADIUMBOX_RECOVERY_ENABLED` env **ABSENT** → config default **true** | VERIFIED |
| RDService replacement | Code exists on Desk + `GET https://rdservice.net/api/integrations/v1/rd-orders/{id}` | VERIFIED |
| Replacement deployed? | Net route is live but `DESK_ORDER_API_TOKEN` **ABSENT** on Desk **and** net. Public GET → **401** | VERIFIED |
| Desk flags | `RDSERVICE_ENABLED` **ABSENT** (defaults false). Token **ABSENT** | VERIFIED |
| Hardware `RDE`/`RIN` and `INQ-` | RDService ineligible; remain Admin-only even after activation | VERIFIED (code) |
| Public Admin API | HTTP **526** | VERIFIED |
| Origin API (insecure TLS) | HTTP **200** JSON for `RD3449705` / `orders.id` 268507 | VERIFIED |

**What must change before Admin can disappear**

1. Activate Desk RDService (token on **both** apps, `RDSERVICE_ENABLED=true`, cache rebuild, soak).
2. Explicitly remove Admin fallback from `OrderEnrichmentLookupService` + `RadiumBoxService`.
3. Hardware + inquiry strategy (new source or Desk-native-only Owner decision).
4. Do **not** treat TLS repair of Admin as the retirement path; it only unblocks the current incident.

---

## 3. Historical invoice reprint

`GET /admin/print/invoice/{id}` → `GenrateInvoice::Print`.

| Question | Finding | Class |
|---|---|---|
| What is `{id}`? | `orders.id` (not `invoicecode`, not RD id). Example: `268507` → `INV6745886` | VERIFIED |
| How assembled? | SELECT `orders`, `order_details`, `users`, `order_rdservice.rdorderid`, `orders.einvoice_respose`, `radium_branch`, `product_stock_log`, `orders_payment` (credit) | VERIFIED (Admin source) |
| Admin-only tables? | Same table names exist on KVM8 `radiumbox_prod`: `invoice` 261335 rows; `orders` 308366 / 261698 with `invoicecode`; `credit_note` 31; `radium_branch` 6. Sample `268507`/`INV6745886` **present** | VERIFIED |
| Does Box expose a reprint route? | No `print/invoice` in Box `routes/` | VERIFIED |
| Does Desk have an equivalent? | Finance Hub PDF is for **Desk-minted** `statutory_invoices` only. Count **0**. UI says Admin INV* are not imported | VERIFIED |
| Production links to Admin reprint? | No Desk/Box blade link to `admin.radiumbox.com` print URL. Operators still need Admin login (docs + origin 302→`/login`) | VERIFIED / INFERRED (human workflow) |
| Numbers immutable? | Yes. Desk config/docs: do not remint Admin INV* | VERIFIED |
| Import vs live read? | Replacement can **read** existing Box/Admin-shaped rows; must **not** regenerate numbers. Still needs a new renderer + auth + Owner decision which DB is SoT | INFERRED |

Public reprint URL is **526**. Origin reprint is **302 login**. No invoice was opened or regenerated.

Admin origin DB host was **not** re-read (no AWS SSH this session). Same invoice row exists on KVM8 `radiumbox_prod` **and** via origin API. Whether those are one physical database is **UNKNOWN**.

---

## 4. Old Admin data dependency

Needed for reprint / numbering history:

- `orders` (`invoicecode`, `invoice_date`, `einvoice_respose`, `userdetails`, …)
- `order_details`
- `invoice` (allocator / historical numbers)
- `radium_branch` (`rd_slug` / `rd_no` — Admin mint path)
- `order_rdservice`
- `orders_payment`
- `product_stock_log`
- `users`
- `credit_note` / `credit_note_order_details`

RadiumBox storefront **still uses** `radiumbox_prod` for live commerce (wallet, orders, Cashfree fulfill). Those tables must **not** be dropped with Admin.

Admin origin may still use a **separate** AWS database. **Do not archive or delete either copy.** UNKNOWN data stays preserved.

Desk `statutory_invoices` is a **new** series from 2026-09-01 and is not a substitute for historical Admin INV*.

---

## 5. Cashfree / payment

| App | Notify / return | Independent of Admin? |
|---|---|---|
| radiumbox.com | `https://radiumbox.com/api/payments/cashfree/webhook` (tests + `CashfreePublicUrl`) | **YES** — VERIFIED code + live `CASHFREE_*` on Box `.env`; no Admin URL keys |
| rdservice.net / rdservice.in | Own `CASHFREE_URL` / app id / secret | **YES** — VERIFIED keys; no Admin hostname |
| Desk | Own Cashfree ingest (payment SoT) | **YES** — VERIFIED architecture; enrichment is post-payment |
| Admin `CaseFree::createOrder` | `route('cashfree.notify')` + `cashfree.callback` | Residual **code**. Named routes **not** in current Admin `routes/*.php`. Whether Cashfree merchant dashboard still has an Admin URL: **UNKNOWN** (dashboard not opened) |

Automated storefront payment does **not** require Old Admin. Retirement does **not** require a Cashfree config change **unless** Owner finds a leftover merchant notify URL pointing at `admin.radiumbox.com` (those would already be broken by 526).

Wallet: Desk has no wallet API. Box storefront has `users_wallet` / `users_radium_wallet` on `radiumbox_prod`. Prior Desk audit treated wallet ops as Admin UI. Live ops wallet **host** (Admin vs Box my-account) is **UNKNOWN** and is a retirement risk if humans still use Admin for credits/debits.

---

## 6. Cross-project

| Project | Depends on Old Admin HTTP? |
|---|---|
| Radium Desk | **YES** — live enrichment |
| radiumbox.com | **NO** app call. Shares invoice-shaped **data** on `radiumbox_prod` |
| rdservice.in | **NO** app call. Historical invoices were allocated by Admin; new Desk ingest still disabled |
| rdservice.net | **NO** app call. Lookup API is the intended replacement; token unset |
| radiumsign.com | **NO** |
| rdserviceonline.in | **NO** |

---

## 7. Infrastructure / hostname

| Check | Result | Class |
|---|---|---|
| Public DNS | Cloudflare anycast `104.21.42.236`, `172.67.212.65` | VERIFIED |
| Public HTTPS `/`, `/login`, `/api/search/order`, `/admin/print/invoice/268507` | **526**, `server: cloudflare` | VERIFIED |
| Origin `13.234.230.151` SNI `admin.radiumbox.com` | Insecure login **200**; strict verify **fail** (expired cert) | VERIFIED |
| Origin cert | LE E5, `notAfter=Jun 1 2025` | VERIFIED |
| Origin API insecure | **200** JSON | VERIFIED |
| Origin reprint unauthenticated | **302** `/login` | VERIFIED |
| KVM8 Admin vhost | Absent (beta-admin only) | VERIFIED |
| Other hostnames on same origin | Prior tickets listed leftover AWS OLS maps. **Not re-enumerated this session** | UNKNOWN |
| 526 repair | **Not performed** (forbidden) | — |

---

## 8. Desk DLQ / failure state (no retry)

| Metric | Value | Class |
|---|---|---|
| `failed_jobs` | **42** | VERIFIED |
| HTTP 526 | **40** | VERIFIED |
| Timeouts (`cURL error 28` / timed out) | **2** (oldest `2026-08-10 15:19:29`) | VERIFIED |
| Newest failure | `2026-09-05 20:21:14` `RadiumBoxOrderEnrichmentJob` HTTP 526 | VERIFIED |
| Still generating? | **YES** — count rose vs Box P-05-09-14 (23). Recovery still touching PENDING rows (`RD3511924`, `RDE318360`, …) at 21:00 IST | VERIFIED |
| `orders.radiumbox_sync_status` | FAILED **25**, PENDING **2**, SYNCED 42095, NOT_SYNCED 8554 | VERIFIED (snapshot) |
| `jobs` pending | **0** | VERIFIED |
| Unrelated historical | 2 Aug-10 timeouts | VERIFIED |

No job was retried, flushed, deleted, or acknowledged.

---

## 9. Retirement gate

**OLD ADMIN RETIREMENT GATE: NOT READY**

### BLOCKERS

1. **Desk live order-search still calls Admin**  
   Evidence: production `RADIUMBOX_BASE_URL=https://admin.radiumbox.com`, `RADIUMBOX_ENABLED=true`, RDService off, DLQ 526.  
   Why: retiring Admin breaks enrichment, intake fallback, legacy import, identity repair, hardware/INQ lookup.  
   Replacement: activate RDService + remove Admin fallback + hardware/INQ decision.  
   Code **yes**. Production env/deploy **yes**. Owner approval **yes**. Data migration **no** (on-demand lookup).

2. **Historical GST reprint has no Desk/Box replacement**  
   Evidence: only Admin `Print($id)`; Finance Hub statutory count 0; Box has data but no reprint route.  
   Why: CA/ops cannot reprint ~261k Admin INV* if Admin is gone.  
   Replacement: read-only reprint (or import) that **keeps** `invoicecode`.  
   Code **yes**. Possible read of existing `radiumbox_prod` / Admin DB. Deploy **yes**. Owner decision on SoT DB **yes**. Remint **no**.

3. **Admin origin TLS is expired; public hostname is 526**  
   Evidence: cert expired 2025-06-01; CF 526; Desk still targeting the public hostname.  
   Why: this is the current outage, not a retirement. Do **not** confuse “broken Admin” with “retired Admin”.  
   Replacement: either repair origin TLS **or** point Desk at a replacement **before** turning Admin off.  
   Infra/Owner **yes**. This ticket must not repair it.

4. **Hardware `RDE`/`RIN` (and `INQ-`) have no non-Admin source**  
   Evidence: `RdServiceClient::isEligible` skips them; live `RDE318360` is in the PENDING sync set.  
   Code + Owner decision **yes**.

### NON-BLOCKING CLEANUP

- Remove default `https://admin.radiumbox.com` from Desk `.env.example` / config **after** fallback is gone.
- Confirm leftover Admin `CaseFree` named routes / Cashfree merchant URLs (dashboard review).
- Archive Admin source repo once no caller remains.
- Optional read-only Finance Hub import of Admin INV* for CA completeness (later).
- Retry **only 526** DLQ jobs after a replacement or TLS fix — not this ticket.

### UNKNOWN / FURTHER INVESTIGATION

- Admin origin `DB_HOST` / whether it is the same physical DB as KVM8 `radiumbox_prod`.
- Whether Cashfree merchant dashboard still lists `admin.radiumbox.com`.
- Whether finance/ops still use Admin UI for wallet debit/credit.
- Full AWS OLS vhost list besides `admin.radiumbox.com`.
- Whether any human bookmark/SOP still requires Admin login for reprint (INFERRED yes).

---

## 10. Minimum safe future sequence (do not execute)

1. **VERIFY** RDService lookup on a non-production token path; confirm net API contract vs Admin mapper fields.
2. **IMPLEMENT** Desk activation + soak with Admin fallback **still on**.
3. **IMPLEMENT** hardware/INQ policy.
4. **IMPLEMENT** historical reprint (read-only) against the Owner-chosen SoT; never remint.
5. **TEST** enrichment, intake, repair, reprint of a known INV* (e.g. `INV6745886`) without Admin HTTP.
6. **DEPLOY** Desk (+ reprint host if not Desk).
7. **VERIFY** production: RDService 200 as normal path; Admin volume residual.
8. **VERIFY** reprint without Admin login.
9. **VERIFY** Cashfree merchant URLs have no Admin host.
10. **FREEZE** Desk Admin fallback (`RADIUMBOX_ENABLED=false` or remove client).
11. **FINAL SCAN** of all RadiumWebsites repos + production `.env` for `admin.radiumbox.com`.
12. **THEN** retire Admin hostname/origin — separate Owner-approved infra ticket.

Do **not** start at TLS repair or DNS cut. Do **not** delete `radiumbox_prod` invoice tables with the Admin app.
