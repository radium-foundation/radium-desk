# Cashfree retirement gate — webhook / notify verification

**Ticket:** RadiumDesk-P-06-09-01  
**Date:** 2026-09-06  
**Type:** Read-only investigation. No application, Cashfree, DNS, Cloudflare, AWS, database, queue, or payment change.

**Old Admin dependency (Cashfree server-side notifications):** **UNKNOWN**

Merchant Dashboard was not authenticated. Application-side evidence is recorded below and is **not** a PASS.

---

## Phase 1 — repository

| Item | Value |
|---|---|
| Repository | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` · `git@github.com:radium-foundation/radium-desk.git` |
| Branch | `main` tracking `origin/main` |
| HEAD at session open | `83e24ef7951ee781711155704f64d7eca9ecd025` |
| HEAD before this ticket’s commit | `0b8f144d1df6f377b3d929284dde85a7e9469fc5` (unrelated historical-print commit landed during this session) |
| Remote HEAD at session open | `origin/main` = `83e24ef7` |
| Prompt ID | `RadiumDesk-P-06-09-01` (next unused after `RadiumDesk-P-05-09-29`) |

No application code was modified.

---

## Phase 2 — application-side Cashfree destinations

Classification: **VERIFIED** unless marked otherwise.

Server-to-server notification is separate from customer browser return.

| Environment / Setting | Exact configured URL | Owner / Application | Old Admin dependency? | Evidence |
|---|---|---|---|---|
| rdservice.in create-order `notify_url` | `https://rdservice.in/cashfree/notify` | rdservice.in | **NO** in current app/create-order path | Live `route('cashfree.notify')` on KVM8 `/var/www/rdservice.in`; `CaseFree.php`; `routes/web.php`; public GET **200** `OK` |
| rdservice.in create-order `return_url` | `https://rdservice.in/cashfree/callback?order_id={order_id}&order_token={order_token}` | rdservice.in | **NO** | Live `route('cashfree.callback')`; customer browser return; public GET without `order_id` **302** `/home` |
| rdservice.net create-order `notify_url` | `https://rdservice.net/cashfree/notify` | rdservice.net | **NO** in current app/create-order path | Live `route('cashfree.notify')` on `/var/www/rdservice.net.prod`; POST-only in source; public GET **302** apex (not Admin) |
| rdservice.net create-order `return_url` | `https://rdservice.net/rd-order-success?order_id={order_id}` | rdservice.net | **NO** | Live `route('cashfree.callback')`; customer browser return; public GET without `order_id` **302** `/home` |
| radiumbox.com create-order `notify_url` | `https://radiumbox.com/api/payments/cashfree/webhook` | radiumbox.com | **NO** | Live `CashfreePublicUrl::to(...)` on `/var/www/radiumbox.com`; public GET **405** (POST-only) |
| radiumbox.com ecom `return_url` | `https://radiumbox.com/orders/payment/online/success?order_id={order_id}` | radiumbox.com | **NO** | `PaymentController` + `CashfreePublicUrl::rewrite` |
| radiumbox.com RD `return_url` | `https://radiumbox.com/rdservice/checkout?order_id={order_id}` | radiumbox.com | **NO** | `CashfreePublicUrl::rewrite(url('rdservice/checkout'))` |
| Desk account webhook ingest | `https://desk.radiumbox.com/api/webhooks/cashfree` | Radium Desk | **NO** as an Admin host | Live `route('webhooks.cashfree')`; public GET **405**; this is the Desk inbound account webhook, **not** a create-order notify_url in Desk `.env` |
| Old Admin named `cashfree.notify` / `cashfree.callback` | Would have been `APP_URL` + those paths **if the routes existed** | Old Admin residual helper | **UNKNOWN** whether Merchant Dashboard still stores any such URL | `Admin/app/Helper/CaseFree.php` and AWS snapshot copy still call `route(...)`. **No** matching routes in current Admin or AWS-snapshot `routes/*.php`. **No** `Cashfree*Controller` in Admin. `createOrder` has **no** callers. AWS snapshot `.env` `APP_URL=http://localhost` (**snapshot only**, not live dashboard proof). Public `https://admin.radiumbox.com/cashfree/notify` and `/callback` **GET timed out** (0 bytes) |

### Production `.env` URL scan (values, no secrets)

KVM8 `/var/www/{radium-desk,rdservice.in,rdservice.net.prod,radiumbox.com}/.env`:

| App | `APP_URL` | `CASHFREE_URL` / flags | `admin.radiumbox.com` or `13.234.230.151` in any `.env` URL value |
|---|---|---|---|
| Desk | `https://desk.radiumbox.com` | no PG create-order URL | **NONE — VERIFIED** |
| rdservice.in | `https://rdservice.in` | `https://api.cashfree.com/pg/orders` | **NONE — VERIFIED** |
| rdservice.net | `https://rdservice.net` | `https://api.cashfree.com/pg/orders` | **NONE — VERIFIED** |
| radiumbox.com | `https://radiumbox.com` | live PG `https://api.cashfree.com/pg/orders`; `CASHFREE_LIVE_ENABLED=true` | **NONE — VERIFIED** |

Desk flags this session: `RADIUMBOX_ENABLED=false`, `RADIUMBOX_BASE_URL` empty, `RADIUMBOX_ADMIN_FALLBACK_ENABLED=false`.

Application `app/` trees for Desk, rdservice.in, rdservice.net, and radiumbox.com contain **no** `admin.radiumbox.com` or `13.234.230.151` Cashfree destination.

These facts **do not** prove Merchant Dashboard configuration.

---

## Phase 3 / 4 — Cashfree Merchant Dashboard

**Cashfree Dashboard Verification: UNKNOWN — authenticated dashboard access unavailable.**

| Check | Result |
|---|---|
| Chrome running | YES |
| Existing Cashfree / merchant tab | NO |
| Opened `https://merchant.cashfree.com/` read-only | Landed on **Email Login** · `https://merchant.cashfree.com/auth/login` |
| Login / password reset / credential use | **Not performed** |
| Dashboard webhook / notify / return pages | **Not opened** (login wall) |
| Cashfree configuration change | **Not performed** |
| Test payment / diagnostic order | **Not performed** |

The login tab opened for this probe was closed without submitting the form.

Cashfree documents that account-level webhook endpoints live under Payment Gateway → Developers → Webhooks, distinct from per-order `order_meta.notify_url` / `return_url`. That page was not visible.

---

## Phase 5 — retirement decision

**UNKNOWN**

Do not retire Old Admin from the Cashfree integration perspective.

A PASS requires verified Merchant Dashboard evidence that no active server-side webhook/notify/payment-notification destination points at Old Admin (`admin.radiumbox.com`, `13.234.230.151`, or an equivalent Admin route). That evidence was not obtained.

Application-side create-order hosts currently point at rdservice.in / rdservice.net / radiumbox.com / Desk. That is necessary context, not a substitute for the dashboard list.

---

## Remaining blocker

Owner (or an already-authenticated Merchant Dashboard session) must open **Payment Gateway → Developers → Webhooks** (and any Payouts / Payment Links / Subscriptions webhook pages that exist on the live MID) and confirm no destination uses Old Admin. Do not change those URLs in that review unless a later ticket explicitly authorizes it.
