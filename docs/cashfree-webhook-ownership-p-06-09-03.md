# Cashfree webhook ownership — Old Admin → Radium Desk

**Ticket:** RadiumDesk-P-06-09-03  
**Date:** 2026-09-06  
**Type:** Read-only. No application, Cashfree, DNS, Cloudflare, AWS, database, queue, or payment change.

**Retirement gate (single account webhook transferred to Desk):** **PASS — verified from Cashfree Merchant Dashboard**

Recorded on `RadiumDesk-P-06-09-04` from authenticated Merchant Dashboard screenshots. `P-06-09-03` left this gate **UNKNOWN** because the dashboard was login-walled in that session.

Companion: `docs/cashfree-retirement-gate-p-06-09-01.md`. That ticket listed all notify/return hosts. This file covers only whether **Cashfree’s single account webhook** now points at Desk instead of Old Admin.

This **does not** retire Old Admin. It closes the Cashfree account-webhook gate only.

---

## Phase 1 — repository

| Item | Value |
|---|---|
| Repository | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` · `git@github.com:radium-foundation/radium-desk.git` |
| Branch | `main` = `origin/main` |
| HEAD at inspect | `6816dee5abc8a567088c3e1d819d379fbf7cfb14` |
| Worktree | dirty ledger only (this ticket + sibling `P-06-09-02` row) |
| Prompt ID | `RadiumDesk-P-06-09-03` (next unused after `P-06-09-02`) |

No application code was modified.

---

## Phase 2 — current Desk webhook

| Item | Finding | Class |
|---|---|---|
| Exact endpoint | `POST https://desk.radiumbox.com/api/webhooks/cashfree` | **VERIFIED** |
| Route | `routes/api.php` `webhooks.cashfree` → `CashfreeWebhookController::handle` | **VERIFIED** |
| Owner | Radium Desk | **VERIFIED** |
| Auth / signature | Optional HMAC: `x-webhook-signature` + `x-webhook-timestamp` over raw body; secret is `CASHFREE_CLIENT_SECRET` (not printed). Missing headers → 400; bad signature → 401. | **VERIFIED** source |
| Production verify flag | `CASHFREE_VERIFY_SIGNATURE=true`; secret **SET**; `CASHFREE_APP_ID` **ABSENT** (Desk does not create PG orders) | **VERIFIED** live `.env` keys only |
| Live receipt | `cashfree_webhook_logs` total **63324**; last 24h **692**, all type `PAYMENT_SUCCESS_WEBHOOK`; latest id **63324** at `2026-09-06 00:30:02` status `processed`, version `2025-01-01`. First row `2026-06-28 14:54:22`. | **VERIFIED** SELECT counts/metadata only |
| Intended role | Desk is webhook-inbound only. `CashfreeWebhookProcessorService` creates Desk orders/cases from `PAYMENT_SUCCESS_WEBHOOK`. That is the account-webhook replacement pattern. | **VERIFIED** architecture; **INFERRED** that it was meant to replace a prior Admin dashboard webhook |

Customer return/callback URLs are out of scope for this gate.

---

## Phase 3 — historical Old Admin webhook

| Item | Finding | Class |
|---|---|---|
| Named notify in helper | `CaseFree::createOrder` still sets `notify_url` = `route('cashfree.notify')` and `return_url` = `route('cashfree.callback')?...` in current Admin and the AWS snapshot tree | **VERIFIED** residual source |
| Registered Admin route | **None** in current Admin `routes/*`, AWS snapshot `routes/*`, or historical `git grep` of Admin `routes` for `cashfree.notify` / `cashfree/notify` / `CashfreeController` | **VERIFIED** absence |
| Admin webhook controller | **No** `Cashfree*Controller` in Admin or the AWS snapshot | **VERIFIED** |
| Callers of `createOrder` | **None** outside `CaseFree.php` | **VERIFIED** |
| Snapshot `APP_URL` | `http://localhost` (snapshot file only) | **VERIFIED** snapshot; **not** live dashboard proof |
| Exact historical dashboard URL | Not present in surviving Admin config as a static webhook URL | **UNKNOWN** |
| Corresponds to “the single Cashfree webhook”? | Admin helper used **per-order** `order_meta.notify_url`, which is not the same object as Merchant Dashboard **account** webhook endpoints. Whether a dashboard row once pointed at `admin.radiumbox.com/...` is not in source. | **INFERRED** they are different; dashboard history **UNKNOWN** |

P-06-09-03: do not treat the missing Admin route as dashboard proof. P-06-09-04 dashboard configuration supersedes that caution for the **active** account webhook only.

---

## Phase 4 / 5 — Merchant Dashboard

### P-06-09-03 session (superseded)

That session: **UNKNOWN — dashboard configuration could not be directly inspected.** Chrome had no Cashfree tabs. Login was not attempted.

### P-06-09-04 — authenticated dashboard screenshots

**Cashfree Dashboard Verification: VERIFIED** from Owner-supplied authenticated Merchant Dashboard screenshots. This ticket did not open Cashfree, change any setting, or send a test webhook.

| Item | Finding |
|---|---|
| Cashfree environment | Live Merchant Dashboard account-webhook configuration (success-payment) |
| Configured webhook URL | `https://desk.radiumbox.com/api/webhooks/cashfree` |
| Webhook version | `2025-01-01` |
| Policy | `DEFAULT` |
| Event | `success payment` |
| Current owner | Radium Desk |
| Matches Desk endpoint? | **YES** |
| Points to Old Admin? | **NO** — the configured active success-payment webhook is the Desk URL |
| Delivery | Cashfree delivery to Desk returns HTTP **200** |
| Domain Health (active Desk webhook) | `Good 100%` |
| Domain Health (older RadiumBox endpoints) | `Severe 0%` — historical/delivery-health rows only; **not** the configured active success-payment webhook |

Account-level webhook **configuration** is now **VERIFIED**. Domain Health may still list older RadiumBox endpoints as failed/severe delivery history. Those rows are not the active configured destination and must not be treated as a second live account webhook.

No secrets, signing keys, or dashboard credentials are recorded here.

---

## Phase 6 — gate

**PASS — verified from Cashfree Merchant Dashboard**

The single configured Cashfree account-level success-payment webhook points at `https://desk.radiumbox.com/api/webhooks/cashfree` and does not point at Old Admin.

Do not retire Old Admin on this ticket. Other Old Admin gates (DNS, origin, reprint, leftover helpers, Domain Health history) remain separate.
