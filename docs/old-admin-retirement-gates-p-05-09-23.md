# Old Admin retirement gates — RadiumDesk-P-05-09-23

**Date:** 2026-09-05  
**HEAD at inspect:** `9fe0f24f5646dcf699af40b8c9eb334f8a27f65d`  
**Branch:** `main` (clean, matches `origin/main`)

No application change. No DNS/AWS/Admin/Cashfree mutation. No DLQ flush.

---

## Owner-browser reprint

**NO — Owner-browser reprint not performed.**

This session has no browser-automation tools and no Owner production session. Unauthenticated `GET https://desk.radiumbox.com/finance/invoices/historical` and `/print` remain 302 `/login` (P-05-09-22). Service-layer reprint of `INV6745886` was already verified then; it is not a substitute for the Owner-browser gate.

---

## Cashfree merchant dashboard

**UNKNOWN — Dashboard/configuration could not be inspected.**

No documented/authorized Cashfree merchant-dashboard session exists in this environment. Dashboard was not opened. Credentials were not used. No payment was created.

### App-side notify/return hosts (not dashboard)

Read-only artisan `route()` / `CashfreePublicUrl` on KVM8. These are what **new** create-order calls send. They are **not** the merchant-dashboard webhook list.

| App | Notify | Return |
|---|---|---|
| rdservice.in | `https://rdservice.in/cashfree/notify` | `https://rdservice.in/cashfree/callback` |
| rdservice.net | `https://rdservice.net/cashfree/notify` | `https://rdservice.net/rd-order-success` |
| radiumbox.com | `https://radiumbox.com/api/payments/cashfree/webhook` | apex rewrite via `CashfreePublicUrl` |
| Desk | No PG create-order notify URL in `.env` | `APP_URL=https://desk.radiumbox.com` |

No production `.env` URL value contains `admin.radiumbox.com` or `13.234.230.151`.

Archived Old Admin `CaseFree.php` still used `route('cashfree.notify')` / `cashfree.callback`. If Admin `APP_URL` was `https://admin.radiumbox.com`, those historical merchant-dashboard rows **may** still exist. That is **INFERRED**, not dashboard-verified.

---

## Production safety (re-checked)

| Check | Result |
|---|---|
| `RADIUMBOX_ENABLED` | `false` |
| `RADIUMBOX_BASE_URL` | empty |
| `RADIUMBOX_ADMIN_FALLBACK_ENABLED` | `false` |
| `admin.radiumbox.com` after 23:46 IST | 0 new Desk log lines (last hit 23:36:59) |
| `failed_jobs` | **57** — untouched |
| `statutory_invoices` | **0** |

---

## Gate

**CONDITIONAL.** Owner-browser reprint and Cashfree dashboard remain open. Do not prepare the Old Admin retirement execution plan until both are VERIFIED clean.
