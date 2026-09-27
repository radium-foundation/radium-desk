# Central Group Identity + Central Wallet — Architecture Discovery

**Prompt ID:** `RadiumDesk-P-25-09-81`  
**Date:** 2026-09-27  
**Mode:** READ-ONLY DISCOVERY / FORENSIC ARCHITECTURE REVIEW  
**Scope:** radiumbox.com, rdservice.in, rdservice.net, radiumsign.com, rdserviceonline.in, radium-desk  
**Status:** Discovery only — no implementation approved

---

## 1. Executive summary

The Radium group operates **six independent spoke applications** plus a **central Desk hub** (`radium-desk`). Each spoke maintains its **own database, customer namespace, and payment flow**. There is **no verified shared Group Customer ID**, **no SSO across sites**, and **no central wallet ledger** today.

**Wallet / stored-value behaviour exists only on two spokes:**

| Spoke | Wallet ledger | Checkout wallet debit | Desk refund credit API | Desk refund reversal API |
|-------|---------------|----------------------|------------------------|--------------------------|
| **radiumbox.com** | `users_wallet` + `users_radium_wallet` | **VERIFIED** (split tender) | **VERIFIED** (source) | **UNKNOWN** prod |
| **rdservice.in** | `users_wallet` | **VERIFIED absent** | **VERIFIED** (source) | **VERIFIED** (source, branch) |
| **rdservice.net** | Schema columns only | **VERIFIED absent** | **VERIFIED absent** | **VERIFIED absent** |
| **radiumsign.com** | **VERIFIED absent** | **VERIFIED absent** | **VERIFIED absent** | **VERIFIED absent** |
| **rdserviceonline.in** | **VERIFIED absent** | **VERIFIED absent** (iframe → rdservice.in) | **VERIFIED absent** | **VERIFIED absent** |
| **radium-desk** | **VERIFIED absent** (no customer wallet table) | N/A | Orchestrates spoke HTTP credit | Orchestrates spoke HTTP reversal |

**Radium Desk** is the **refund workflow owner** (`refund_requests`) and can **automate wallet credits** to radiumbox.com and rdservice.in via server-to-server APIs. Desk does **not** hold customer wallet balances. Statutory cancellation / credit notes are **separate** from wallet credits (feature-flagged `REFUNDS_STATUTORY_ADJUSTMENT_ENABLED`, default off).

The hypothesised target — **Central Group Identity + Central Wallet Ledger + independent spokes + API/event integration + Desk refund integration** — is **INFERRED** as a reasonable direction but **not approved**. This document maps verified current state and gaps.

---

## 2. Scope

### In scope

- Repository verification for six named projects
- Customer identity, wallet/credit, payment/refund, data ownership
- Cross-project dependencies and linking mechanisms
- Security/fraud assessment (architectural)
- A vs B identity model comparison
- Proposed target architecture (evidence-labelled)
- Migration phase considerations (discovery only)

### Out of scope

- Implementation, migration, DB changes, account merging, production changes
- Credential values, tokens, passwords, API keys

### Legend

| Label | Meaning |
|-------|---------|
| **VERIFIED** | Established directly from code, config, migrations, tests, or documented deployment facts in inspected repos |
| **INFERRED** | Logical conclusion requiring owner/production confirmation |
| **UNKNOWN** | Not established from available evidence |

---

## 3. Per-project architecture

Inspection date: **2026-09-27**. Paths under `/Users/ravi/RadiumWebsites/`.

### 3.1 radiumbox.com

| Attribute | Value | Status |
|-----------|-------|--------|
| **Path** | `/Users/ravi/RadiumWebsites/radiumbox.com` | VERIFIED |
| **Remote** | `https://github.com/radium-foundation/radiumbox.com.git` | VERIFIED |
| **Branch** | `feat/desk-catalog-price-sync` | VERIFIED |
| **HEAD SHA** | `116990ababede743647e20c4c3138d1b01dbb1fb` | VERIFIED |
| **Worktree** | Dirty (docs + storefront artifacts; not part of this discovery) | VERIFIED |
| **Framework** | Laravel 10, PHP 8.1+ | VERIFIED — `composer.json` |
| **Frontend** | Next.js 15 storefront + legacy Blade/Vite assets | VERIFIED — `storefront/package.json`, `package.json` |
| **Backend** | Laravel MVC + service layer | VERIFIED |
| **Database** | MySQL (`DB_CONNECTION=mysql`); prod name `radiumbox_prod` | VERIFIED config; prod name INFERRED — `docs/real-launch-architecture.md` |
| **Auth** | Session `web` guard; Laravel UI login/register; optional Google OAuth (`GoogleController`); Sanctum minimal (`GET /api/user`) | VERIFIED |
| **Customer model** | `App\Models\User` → `users` (no separate Customer model) | VERIFIED |
| **Deployment** | KVM8 OpenLiteSpeed path-split: Next.js + Laravel loopback | INFERRED — `storefront/deploy/production-kvm8.sh`, `docs/real-launch-architecture.md` |
| **API** | `/api` prefix; Desk integration under `/api/integrations/v1/*`; Cashfree webhook; storefront catalog API | VERIFIED — `routes/api.php` |

**Key config (names only):** `config/cashfree.php`, `config/desk.php`, `config/integrations.php`, `INTEGRATION_WEBSITE_ID=radiumbox.com`

---

### 3.2 rdservice.in

| Attribute | Value | Status |
|-----------|-------|--------|
| **Path** | `/Users/ravi/RadiumWebsites/rdservice.in` | VERIFIED |
| **Remote** | `git@github.com:radium-foundation/rdservice.in.git` | VERIFIED |
| **Branch** | `feat/desk-wallet-refund-reversal` | VERIFIED |
| **HEAD SHA** | `bcb380ac009e4949346ba332d0eb8eef7e969bd9` | VERIFIED |
| **Worktree** | Minor docs dirty state | VERIFIED |
| **Framework** | Laravel 10, PHP 8.1+ | VERIFIED |
| **Frontend** | Blade + Bootstrap/Vite; React 18 Direct Buy island | VERIFIED |
| **Database** | MySQL; target `rdservice_in_prod`; optional `legacy_shared` read-only connection | VERIFIED — `config/database.php`, `config/storefront.php` |
| **Auth** | Session + email verification + Google OAuth; Desk token middleware `desk.integration` | VERIFIED |
| **Customer model** | `App\Models\User` → `users` | VERIFIED |
| **Deployment** | KVM8 documented in project docs | INFERRED |
| **API** | Desk integrations + legacy product/geo APIs + `POST /api/desk/channel-events` | VERIFIED — `routes/api.php` |

---

### 3.3 rdservice.net

| Attribute | Value | Status |
|-----------|-------|--------|
| **Path** | `/Users/ravi/RadiumWebsites/rdservice.net` | VERIFIED |
| **Remote** | `git@github.com:radium-foundation/rdservice.net.git` | VERIFIED |
| **Branch** | `wip/p04-08-03-einvoice-payment-export-ui` | VERIFIED |
| **HEAD SHA** | `c43b9de8f4cda7485968d525a5d1def8de05a945` | VERIFIED |
| **Worktree** | Dirty (docs + blade test) | VERIFIED |
| **Framework** | Laravel 10, PHP 8.1+ | VERIFIED |
| **Frontend** | Blade + static `public/assets` (Vite scaffold unused in views) | VERIFIED |
| **Database** | MySQL (`rdservice_net` / prod `rdservice_net_prod`) | VERIFIED config; prod INFERRED |
| **Auth** | Session + Google OAuth + email verification | VERIFIED |
| **Customer model** | `App\Models\User` → `users`; checkout upserts by email | VERIFIED |
| **API** | Read-only Desk order lookup; HMAC channel outbox to Desk | VERIFIED — **no wallet APIs** |

---

### 3.4 radiumsign.com

| Attribute | Value | Status |
|-----------|-------|--------|
| **Path** | `/Users/ravi/RadiumWebsites/radiumsign.com` | VERIFIED |
| **Remote** | `git@github.com:radium-foundation/radiumsign.com.git` | VERIFIED |
| **Branch** | `production/kvm4-radiumsign` | VERIFIED |
| **HEAD SHA** | `35b289a31e5d541bb00a78b7584f6164ed0f5774` | VERIFIED |
| **Worktree** | `.DS_Store` only dirty | VERIFIED |
| **Framework** | Laravel 8, PHP 8.x | VERIFIED |
| **Frontend** | Blade + jQuery + Alpine.js + Tailwind CLI | VERIFIED |
| **Database** | MySQL `radiumsign_prod` | VERIFIED — `.env.example` |
| **Auth** | Laravel UI; role middleware (`admin`/`user`); **guest checkout** (email upsert) | VERIFIED |
| **Customer model** | `App\Models\User` → `users` | VERIFIED |
| **API** | Open JSON POST routes for Cashfree checkout (mostly unauthenticated) | VERIFIED — `routes/api.php` |

---

### 3.5 rdserviceonline.in

| Attribute | Value | Status |
|-----------|-------|--------|
| **Path** | `/Users/ravi/RadiumWebsites/rdserviceonline.in` | VERIFIED |
| **Remote** | `git@github.com:radium-foundation/radiumsign.com.git` → **should be** `rdserviceonline.in` | VERIFIED remote from git |
| **Branch** | `main` | VERIFIED |
| **HEAD SHA** | `0af0cb2b8f6b047f481275ea16060cbdcf6e4f46` | VERIFIED |
| **Worktree** | Clean | VERIFIED |
| **Framework** | Laravel 11, PHP 8.2+ | VERIFIED |
| **Frontend** | Blade + Tailwind/Vite; Markdown content | VERIFIED |
| **Database** | SQLite default in `.env.example`; no commerce tables | VERIFIED |
| **Auth** | **None** on public routes | VERIFIED |
| **Customer model** | Stock `User` model unused for commerce | VERIFIED |
| **Payments** | iframe → `RDSERVICE_IN_CHECKOUT_URL` (default `rdservice.in/api/rd-service-form`) | VERIFIED — `config/services.php` |
| **API** | Web-only + `/health`; no `routes/api.php` | VERIFIED |

**Note:** Documented order prefix `RE` for rdserviceonline.in exists in sibling docs only; **not implemented** in this repo (INFERRED future).

---

### 3.6 radium-desk

| Attribute | Value | Status |
|-----------|-------|--------|
| **Path** | `/Users/ravi/RadiumWebsites/radium-desk` | VERIFIED |
| **Remote** | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| **Branch** | `feat/refund-statutory-adjustment-p-25-09-79` | VERIFIED |
| **HEAD SHA** | `cfce331915d90d28de2709dc8d03b11aad51f555` | VERIFIED |
| **Worktree** | Clean | VERIFIED |
| **Framework** | Laravel 13, PHP 8.3 | VERIFIED |
| **Frontend** | Vite + Blade (Breeze auth) | VERIFIED |
| **Database** | MySQL production (`radium_desk`); sqlite default in config | VERIFIED config; prod INFERRED |
| **Auth** | Session (Breeze); Spatie permissions | VERIFIED |
| **Customer model** | **No unified customer table** — denormalised on `orders` / `commerce_orders`; POS uses `inventory_customers` | VERIFIED |
| **Deployment** | KVM via `tools/desk deploy`; `release.json` authoritative | VERIFIED — `docs/release-workflow.md` |
| **API** | Inbound channel ingest HMAC; Cashfree webhook; refund ops are **web session routes** not public REST | VERIFIED |

**Production deploy state (from ledger, not re-verified live):** v4.0.151 with `refund_statutory_adjustments` migration; `REFUNDS_STATUTORY_ADJUSTMENT_ENABLED` remains OFF (INFERRED from P-25-09-80).

---

## 4. Customer identity comparison

| Project | Customer model | Customer ID | Login | Email identity | Phone identity | SSO | Guest checkout | Notes |
|---------|----------------|-------------|-------|----------------|----------------|-----|----------------|-------|
| **radiumbox.com** | `users` | `users.id` (local bigint) | Email/password + Google OAuth | `users.email` (unique) | `users.phone` | Google OAuth optional | Guest cart; checkout auth-bound | Sanctum sparse |
| **rdservice.in** | `users` | `users.id` | Email/password + verify + Google | `users.email` | `users.phone` | Google OAuth | Checkout upserts user by email | Wallet owner = `order_rdservice.userid` |
| **rdservice.net** | `users` | `users.id` | Email/password + verify + Google | `users.email` | `users.phone` | Google OAuth | Upsert + `Auth::login` at checkout | No guest-only path |
| **radiumsign.com** | `users` | `users.id` | Laravel UI (+ role) | `users.email` | `users.phone` | `google_id` field; no Socialite controller found | **Guest checkout** (email upsert) | Admin role middleware |
| **rdserviceonline.in** | `users` (unused) | N/A for commerce | None | N/A | N/A | None | Commerce delegated to rdservice.in iframe | Marketing site only |
| **radium-desk** | `orders.customer_*` / `commerce_orders.customer_*` | `orders.customer_id` nullable sparse; no group ID | Staff Breeze login only | `customer_email` on orders | `customer_phone` | None for customers | N/A | POS: `inventory_customers` separate |

### Identity confidence mechanisms today

| Mechanism | Status |
|-----------|--------|
| Shared Group Customer ID | **VERIFIED absent** |
| Cross-site SSO | **VERIFIED absent** |
| Email as cross-site join key | **INFERRED only** — each site has independent `users` tables; **email match ≠ safe identity match** |
| Phone as cross-site join key | **VERIFIED absent** as linking mechanism |
| Order prefix → owner mapping | **VERIFIED** — `radium-desk/app/Support/BusinessOrderId.php` |
| Desk order lookup by business ID | **VERIFIED** — spoke `GET /api/integrations/v1/rd-orders/{id}` |
| Account linking / import / sync jobs | **VERIFIED absent** across spokes |
| Mobile app identity | **UNKNOWN** — marketing copy references mobile apps; no dedicated mobile auth surface found in spoke repos |
| KYC / verification beyond email verify | **VERIFIED absent** (email verification on RD sites only) |

---

## 5. Existing wallet / credit comparison

### 5.1 radiumbox.com — dual ledger (financial / stored-value)

| Aspect | Detail | Status |
|--------|--------|--------|
| **Tables** | `users_wallet` (store wallet), `users_radium_wallet` (Radium Money, max ₹200 at checkout) | VERIFIED |
| **Models** | `App\Models\User\Wallet`, `App\Models\User\RadiumWallet` | VERIFIED |
| **Balance** | `SUM(credit) - SUM(debit)` where `status=success` (pending debits included in availability calc at checkout) | VERIFIED — `PaymentController`, `DeskWalletLedgerService` |
| **Cached balance** | `users.wallet_amount` optional column | VERIFIED |
| **Checkout debit** | `PaymentController::PaymentLink` — pending debit row → Cashfree remainder → `PaidOrderFulfillmentService` flips to success | VERIFIED |
| **Desk credit** | `POST /api/integrations/v1/wallet-refunds` → `DeskWalletRefundCreditService` | VERIFIED |
| **Idempotency** | Unique `desk_refund_reference` on `users_wallet` | VERIFIED — migration `2026_09_12_200000_add_desk_refund_reference_to_users_wallet_table.php` |
| **Ledger read API** | `GET /api/integrations/v1/wallet-ledger?customer_email=` | VERIFIED |
| **Reversal API** | Not found in radiumbox.com source (unlike rdservice.in) | VERIFIED absent in repo |
| **Classification** | **Financial / stored-value** at checkout; **refund-credit** via Desk | VERIFIED |
| **Historical writer** | Old Admin (`Admin/app/Http/Controllers/Customer/WalletController.php`) credited wallets | INFERRED — ledger docs P-08-09-07; Admin not in six-project scope |

### 5.2 rdservice.in — refund-credit ledger (no checkout wallet)

| Aspect | Detail | Status |
|--------|--------|--------|
| **Table** | `users_wallet` | VERIFIED |
| **Model** | `App\Models\User\Wallet` | VERIFIED |
| **Balance** | `SUM(credit success/pending) - SUM(debit success)`; dashboard display | VERIFIED — `HomeController` |
| **Checkout wallet** | **Not implemented** — Cashfree only | VERIFIED |
| **Desk credit** | `POST /api/integrations/v1/wallet-refunds` → `DeskWalletRefundCreditService` | VERIFIED |
| **Desk reversal** | `POST /api/integrations/v1/wallet-refund-reversals` → `DeskWalletRefundReversalService` | VERIFIED (current branch) |
| **Idempotency** | Unique `(source_system, desk_refund_reference)`; reversal keys on separate columns | VERIFIED — migrations `2026_09_16_*`, `2026_09_17_*` |
| **Amount guard** | Credit ≤ `order_rdservice.paid_amount`; reversal must match original; balance check before debit | VERIFIED |
| **Ledger read API** | **Absent** (unlike radiumbox.com) | VERIFIED |
| **Classification** | **Refund-credit** primary; not checkout stored-value | VERIFIED |
| **Production deployment** | Docs indicate staged gates; live state **UNKNOWN** | UNKNOWN |

### 5.3 rdservice.net — schema legacy only

| Aspect | Detail | Status |
|--------|--------|--------|
| **Wallet columns** | `orders.wallet_id`, `wallet_amount`, `wallet_type` | VERIFIED schema only |
| **Application logic** | Zero wallet references in `app/` | VERIFIED |
| **orders_payment** | Exists in prod per docs; not written by app fulfillment | INFERRED |
| **Classification** | **Unknown / inactive** | VERIFIED |

### 5.4 radiumsign.com — none

**VERIFIED absent** — no wallet models, migrations, or routes.

### 5.5 rdserviceonline.in — none

**VERIFIED absent** — payments delegated via iframe.

### 5.6 radium-desk — orchestration only

| Aspect | Detail | Status |
|--------|--------|--------|
| **Customer wallet storage** | **None** | VERIFIED |
| **Refund workflow** | `refund_requests` table | VERIFIED |
| **Wallet execution** | `WalletRefundExecutor` → HTTP clients to spokes | VERIFIED |
| **Wallet tender at ingest** | `commerce_orders.wallet_tender_amount` (fact only) | VERIFIED |
| **Company GL** | `finance_journals` — posts refund expense; **does not track customer wallet** | VERIFIED — `RefundJournalService` always credits bank clearing |
| **C360 wallet tab** | Read-only via `RadiumBoxWalletLedgerClient` | VERIFIED — rdservice.in ledger in C360 **UNKNOWN** |

### 5.7 Ledger semantics summary

| Site | Immutable append-only ledger | Balance derived from ledger | Separate credit/debit rows | Expiry | Promotional vs refund |
|------|------------------------------|----------------------------|---------------------------|--------|----------------------|
| radiumbox.com | INFERRED (updates status on pending debits) | VERIFIED | VERIFIED | UNKNOWN | Radium Money capped ₹200; no cashback found |
| rdservice.in | VERIFIED (reversal = new debit row) | VERIFIED | VERIFIED | UNKNOWN | Desk `source_system=radium_desk` only in app |
| Others | N/A | N/A | N/A | N/A | N/A |

---

## 6. Payment / refund comparison

| Project | Payment provider | Order IDs | Refund in spoke code | Desk wallet refund | Cashfree webhook | Idempotency |
|---------|------------------|-----------|---------------------|-------------------|------------------|-------------|
| **radiumbox.com** | Cashfree primary; PayU legacy optional | `RBX*` gateway namespace; `RDE*` hardware | No Cashfree refund API | Inbound credit API | `POST /api/payments/cashfree/webhook` | `cashfree_webhook_events`; paid fulfillment service |
| **rdservice.in** | Cashfree | `RD*` / `RIN*` / `RDP*` | No Cashfree refund | Credit + reversal APIs | `cashfree/notify` | `paid_order_idempotency` |
| **rdservice.net** | Cashfree | `RN*` / `RA*` / `RNP*` | None automated | **Blocked** at Desk approval | `POST cashfree/notify` | Fulfillment idempotency in services |
| **radiumsign.com** | Cashfree | `RS*` / `RDS*` / `RSP*` | Policy page only | Not configured | Product-specific callbacks | UNKNOWN |
| **rdserviceonline.in** | None local | N/A | Delegated | N/A | N/A | N/A |
| **radium-desk** | Cashfree (intake/webhook) | Parses owner via `BusinessOrderId` | **Manual** `ManualRefundExecutor` for Cashfree/bank/UPI | `WalletRefundExecutor` for 2 spokes | `POST /api/webhooks/cashfree` | `refund_requests` workflow + reference validators |

### Radium Desk refund → statutory interaction

| Component | Status |
|-----------|--------|
| Refund completion | `RefundRequestService` → `RefundExecutorResolver` | VERIFIED |
| Statutory cancel on refund | `RefundStatutoryAdjustmentService` via outbox when flag ON | VERIFIED (source); prod flag **UNKNOWN** |
| Credit note mint | `StatutoryInvoiceCreditNoteService` inside cancellation orchestrator | VERIFIED |
| Wallet credit ≠ CN | Separate systems by design | VERIFIED — P-25-09-78 analysis |
| Cashfree automated refund from Desk | **VERIFIED absent** | VERIFIED |

### Desk refund integration surface (future central wallet)

```
Desk refunds.complete (wallet method)
  → WalletRefundExecutor
    → RdServiceInWalletRefundClient | RadiumBoxWalletRefundClient
      → POST spoke /api/integrations/v1/wallet-refunds
        → DeskWalletRefundCreditService::credit()
          → INSERT users_wallet (credit, desk_refund_reference)
```

**Auth:** Bearer `DESK_ORDER_API_TOKEN` / `X-Desk-Token` via `AuthenticateDeskIntegration` middleware.

---

## 7. Database / data ownership

| Data domain | Owner DB (per project) | Cross-DB access |
|-------------|------------------------|-----------------|
| **Customers** | Each spoke `users` table in own MySQL schema | **VERIFIED absent** at runtime |
| **Orders** | Spoke `orders` / `order_rdservice` / `order_radiumsign` | Desk holds copies via ingest (`commerce_orders`) |
| **Payments** | Spoke + Cashfree PG | Desk Cashfree webhook creates `orders` for service path |
| **Wallet balances** | radiumbox.com + rdservice.in `users_wallet` only | Desk reads radiumbox ledger via HTTP only |
| **Refunds** | Desk `refund_requests` authoritative for ops | Spoke wallet rows are payout destination |
| **Invoices / IRN** | Desk `statutory_invoices` (mint authority) | Spokes display via channel events / document fetch |
| **Shared Redis** | Config present on some spokes; production usage | **UNKNOWN** |
| **Legacy shared dump** | rdservice.in `legacy_shared` connection optional | VERIFIED config; not default runtime |

### Cross-project APIs / events (verified)

| Direction | Mechanism | Projects |
|-----------|-----------|----------|
| Spoke → Desk | HMAC `POST /api/v1/channel-orders` | box, rdservice.in, rdservice.net, radiumsign |
| Desk → Spoke | Bearer order lookup, wallet credit/reversal | box, rdservice.in |
| Desk → Spoke | Invoice/fulfilment callbacks (box) | radiumbox.com |
| Desk → Spoke | Channel events inbound | rdservice.in |
| Spoke → Spoke | **VERIFIED absent** | — |

---

## 8. Cross-site customer linking

**VERIFIED:** No shared customer ID, SSO, account linking API, or synchronisation job connects:

```
radiumbox users.id  ↔  rdservice.in users.id  ↔  rdservice.net users.id
       ↔  radiumsign users.id  ↔  rdserviceonline (no commerce users)
       ↔  Desk (no unified customer registry)
```

**INFERRED weak correlates (not identity guarantees):**

- Email address on checkout / refund (`customer_email` fallback in `DeskWalletRefundCreditService` on radiumbox.com)
- Phone on order rows (Desk `orders.customer_phone`)
- Business order ID prefix → owning site (`BusinessOrderId`)

**VERIFIED:** Desk does not treat equal emails across sites as one customer for wallet purposes; wallet credit resolves via **order ownership** → `userid` on spoke.

---

## 9. Security / fraud assessment (architectural)

| Risk | Current state | Severity for central wallet |
|------|---------------|----------------------------|
| **Duplicate identities** | Independent `users` per site; same email can exist N times | High — core problem |
| **Account takeover** | Per-site session auth; Google OAuth per site | Medium — no cross-site session |
| **Wallet replay / duplicate credit** | `desk_refund_reference` unique constraints on box + rdservice.in | Mitigated on those spokes |
| **Duplicate debit** | Checkout pending→success pattern on box; no spoke-side idempotency key for checkout wallet debit documented | Medium |
| **Forged wallet transaction** | Desk integration requires bearer token; fail-closed if empty | Mitigated for API path |
| **Client-side balance manipulation** | Balance computed server-side from ledger | Mitigated |
| **Cross-site authorization** | No shared auth token | Low today; design risk if added without care |
| **Webhook replay** | Cashfree signature verification; webhook event tables on box | Partially mitigated |
| **Race conditions** | DB transactions in credit services; `paid_order_idempotency` on rdservice.in | Partially mitigated |
| **Refund duplication** | Desk workflow + idempotent credit reference | Partially mitigated |
| **Stale cached balance** | `users.wallet_amount` denormalised; refreshed on credit | Medium |
| **Inconsistent customer mapping** | Email fallback on box if order userid missing | Medium |
| **Privilege escalation** | Desk Spatie permissions on refund execute | Staff-side risk |
| **Direct DB access** | Admin historical wallet writer | INFERRED legacy risk |
| **Missing idempotency** | radiumbox.com lacks reversal API; rdservice.net has no wallet | Gap |
| **Negative balance** | rdservice.in reversal fails closed if insufficient balance | VERIFIED |

---

## 10. A vs B decision analysis

### Model A — Shared Group Customer Identity

One Group Customer ID; one login; site-specific profiles linked to same identity.

| Dimension | Assessment |
|-----------|------------|
| **Migration** | High effort — millions of legacy `users` rows across schemas |
| **Existing customers** | Massive duplicate email/phone collisions likely |
| **Duplicate accounts** | Must merge or link with explicit confidence scoring |
| **Security** | Single auth surface — higher blast radius if compromised |
| **Wallet ownership** | Clear — one wallet per group customer |
| **Reconciliation** | Simpler long-term |
| **Refunds** | Desk credits one wallet regardless of originating site |
| **Fraud** | Centralised controls possible; centralised attack target |
| **Mobile app** | Natural fit if one identity |
| **New site onboarding** | Register once in group IdP |
| **Operational complexity** | High upfront; lower steady-state |
| **Failure isolation** | Identity outage affects all sites |
| **Maintainability** | Good if built well |
| **Independence** | Reduces per-spoke autonomy |

**Dependencies:** Central IdP, account linking rules, owner decisions on collision policy  
**Risks:** Wrong merges, checkout downtime during migration  
**UNKNOWNs:** Duplicate email cardinality; mobile app auth requirements

### Model B — Separate Wallet Identity linked to each website account

Each site keeps local `users.id`; central wallet maps N local accounts → 1 wallet via explicit links.

| Dimension | Assessment |
|-----------|------------|
| **Migration** | Lower — spokes unchanged initially |
| **Existing customers** | No forced merge |
| **Duplicate accounts** | Visible as multiple links; customer chooses linking |
| **Security** | Spoke breaches don't expose central wallet session |
| **Wallet ownership** | Central ledger; links are mapping layer |
| **Reconciliation** | Harder — must trace link + local userid |
| **Refunds** | Desk must resolve link table, not just order prefix |
| **Fraud** | Linking attacks (claim another email's wallet) |
| **Mobile app** | Needs link to group wallet explicitly |
| **New site onboarding** | Add new link type |
| **Operational complexity** | Higher steady-state |
| **Failure isolation** | Better per spoke |
| **Maintainability** | More moving parts |
| **Independence** | Preserves spoke autonomy |

**Dependencies:** Linking ceremony, verification, Desk resolver changes  
**Risks:** Orphan wallets, weak linking confidence  
**UNKNOWNs:** Customer UX tolerance for linking

### Evidence-based lean

Given **VERIFIED** independent databases and **VERIFIED** Desk wallet credit already resolving via **order_id → local userid**, **Model B (phased)** aligns with current integration seams. **Model A** is a longer-term UX goal requiring owner investment in identity collision policy. **Neither is approved.**

---

## 11. Proposed target architecture (hypothesis — not approved)

```
                    ┌─────────────────────────────┐
                    │   Central Group Identity    │  INFERRED
                    │   (Group Customer ID)       │
                    └──────────────┬──────────────┘
                                   │
                    ┌──────────────▼──────────────┐
                    │   Central Wallet Ledger     │  INFERRED
                    │   (immutable entries)       │
                    └──────────────┬──────────────┘
           ┌───────────────────────┼───────────────────────┐
           │                       │                       │
    ┌──────▼──────┐         ┌──────▼──────┐         ┌──────▼──────┐
    │ radiumbox   │         │ rdservice.in│         │ other spokes│
    │ (local user)│         │ (local user)│         │             │
    └─────────────┘         └─────────────┘         └─────────────┘
                                   │
                    ┌──────────────▼──────────────┐
                    │      Radium Desk          │  VERIFIED hub today
                    │  refunds + statutory      │
                    └───────────────────────────┘
```

### Requirements matrix

| Component | Label |
|-----------|-------|
| Immutable central ledger with idempotency keys | INFERRED requirement |
| Desk as refund orchestrator | VERIFIED current state |
| Spoke independence (separate DB) | VERIFIED current state |
| Single sign-on across 6 sites | UNKNOWN owner requirement |
| Replace `users_wallet` on box + rdservice.in | INFERRED migration |
| rdservice.net wallet support | UNKNOWN |
| radiumsign / rdserviceonline wallet | UNKNOWN |
| API vs event integration | UNKNOWN — today is synchronous HTTP |
| Service-to-service auth pattern | VERIFIED — bearer/HMAC exists to copy |

---

## 12. Migration considerations (discovery only)

| Phase | Scope | Protected functionality |
|-------|-------|-------------------------|
| **0 — Discovery** | This document | All production |
| **1 — Central identity** | Group ID service | Existing logins must work |
| **2 — Wallet ledger** | New ledger service | Existing `users_wallet` untouched |
| **3 — Account linking** | Explicit link table | No auto-merge by email |
| **4 — Read-only integration** | Display central balance | Checkout still uses local wallet |
| **5 — Controlled credit** | Desk → central wallet | Parallel credit to old ledger |
| **6 — Controlled debit** | Checkout tender from central | Rollback to local ledger |
| **7 — Refunds** | Desk credits central only | Preserve `desk_refund_reference` |
| **8 — Cross-site rollout** | Per-spoke cutover | Order prefix routing unchanged |
| **9 — Mobile app** | UNKNOWN requirements | — |

### Major risks

- Double-credit during parallel run (local + central)
- Balance drift between `users.wallet_amount` cache and ledger
- Refund referencing wrong wallet during cutover
- Customer communication for linking

### Rollback

- Feature flags per spoke (pattern exists: `RADIUMBOX_WALLET_REFUND_CREDIT_ENABLED`, etc.)
- Desk executor routing by owner + flag
- Keep local `users_wallet` read-only archive post-migration

### Reconciliation

- Sep 2026 Desk wallet forensic work (P-25-09-66–68) shows production reconciliation is non-trivial
- Require per-customer ledger tie-out before cutover

---

## 13. Risks

1. **Assuming email = same customer** across sites (VERIFIED unsafe)
2. **Production wallet API deployment state UNKNOWN** on rdservice.in
3. **radiumbox.com lacks reversal API** — Desk revoke path incomplete for box
4. **Two parallel wallet ledgers** on radiumbox.com (`users_wallet` vs `users_radium_wallet`)
5. **Historical Admin wallet writes** outside modern Desk idempotency
6. **Finance GL does not reflect wallet method** — reporting gap
7. **rdserviceonline.in** not in Desk `BusinessOrderId` — future scope unclear

---

## 14. Open questions

1. Owner approval for central wallet vs per-site wallets long-term?
2. Should rdservice.net ever support wallet refunds (currently blocked)?
3. Production state: are `wallet-refunds` APIs live on KVM8 for box and rdservice.in?
4. Mobile app identity requirements?
5. Is `users_radium_wallet` in scope for centralisation or deprecated?
6. Role of Old Admin (`Admin/`) in future wallet writes?
7. rdserviceonline.in: separate commerce or permanent iframe to rdservice.in?
8. Redis shared infrastructure — any cross-project cache?

---

## 15. Owner decisions required

| # | Decision |
|---|----------|
| 1 | Model A vs Model B (or hybrid phasing) |
| 2 | Single balance vs per-site sub-balances |
| 3 | Account linking verification level (email OTP, KYC, etc.) |
| 4 | Migrate historical `users_wallet` balances or snapshot forward-only |
| 5 | Include `users_radium_wallet` in scope |
| 6 | rdservice.net wallet roadmap |
| 7 | Desk as sole credit writer vs spokes self-crediting |
| 8 | Target timeline and pilot site (likely radiumbox.com + rdservice.in) |

---

## 16. Recommended next discovery step

1. **Production verification gate (read-only):** Confirm live state of wallet-refund APIs, feature flags, and Sep 2026 wallet row counts on radiumbox.com + rdservice.in production DBs (SELECT only).
2. **Duplicate identity sampling:** Read-only query cardinality of email collisions across spoke DBs (counts only, no PII export).
3. **Owner workshop:** Decide Model A/B and pilot scope before any central repository creation.

---

## Appendix A — Key evidence index

### radiumbox.com

- `app/Models/User.php`, `app/Models/User/Wallet.php`, `app/Models/User/RadiumWallet.php`
- `app/Http/Controllers/Orders/PaymentController.php` — checkout wallet debit
- `app/Services/Integrations/DeskWalletRefundCreditService.php`
- `app/Services/Integrations/DeskWalletLedgerService.php`
- `routes/api.php` — integration routes
- `database/migrations/2026_09_12_200000_add_desk_refund_reference_to_users_wallet_table.php`

### rdservice.in

- `app/Services/Integrations/DeskWalletRefundCreditService.php`
- `app/Services/Integrations/DeskWalletRefundReversalService.php`
- `app/Http/Controllers/Api/Integrations/DeskWalletRefundController.php`
- `database/migrations/2026_09_16_110000_add_desk_refund_uniqueness_to_users_wallet.php`
- `docs/RDSERVICE-IN-DESK-ARCHITECTURE-CONTRACT.md`

### rdservice.net

- `database/migrations/2026_08_23_101000_create_orders_table.php` — wallet columns
- `app/Services/Desk/DeskChannelClient.php`
- `docs/RDSERVICE-NET-INVOICE-TO-CENTRAL-ENGINE-INVESTIGATION.md`

### radiumsign.com

- `app/Http/Controllers/PaymentController.php`
- `app/Support/RadiumSignOrderReference.php`
- `docs/RADIUMSIGN-P-01-09-03-INVOICE-TO-CENTRAL-ENGINE.md`

### rdserviceonline.in

- `config/services.php` — `RDSERVICE_IN_CHECKOUT_URL`
- `resources/views/components/marketing/payment-wrapper.blade.php`

### radium-desk

- `app/Support/BusinessOrderId.php`
- `app/Services/Refunds/WalletRefundExecutor.php`
- `app/Services/Refunds/WalletRefundDestinationResolver.php`
- `app/Services/RdService/RdServiceInWalletRefundClient.php`
- `app/Services/RadiumBox/RadiumBoxWalletRefundClient.php`
- `app/Services/Refunds/RefundStatutoryAdjustmentService.php`
- `docs/finance-architecture-audit.md`
- `docs/sc28430-refund-service-investigation.md`

### Historical (out of six-project scope, relevant to wallet writes)

- `Admin/app/Http/Controllers/Customer/WalletController.php`
- `Admin/app/Models/Customer/Wallet.php`

---

## Appendix B — Inspection SHAs

| Project | SHA at inspection |
|---------|-------------------|
| radiumbox.com | `116990ababede743647e20c4c3138d1b01dbb1fb` |
| rdservice.in | `bcb380ac009e4949346ba332d0eb8eef7e969bd9` |
| rdservice.net | `c43b9de8f4cda7485968d525a5d1def8de05a945` |
| radiumsign.com | `35b289a31e5d541bb00a78b7584f6164ed0f5774` |
| rdserviceonline.in | `0af0cb2b8f6b047f481275ea16060cbdcf6e4f46` |
| radium-desk | `cfce331915d90d28de2709dc8d03b11aad51f555` |

---

*End of discovery document. No application code, production data, or infrastructure was modified during this inspection.*
