# Central Wallet Implementation Specification — Desk Hub Platform

**Document type:** Implementation specification (design only — **not an implementation authorization**)  
**Prompt ID:** `RadiumDesk-P-25-09-84`, `RadiumDesk-P-25-09-85`, `RadiumDesk-P-25-09-86`  
**Date:** 2026-09-27 (updated 2026-09-28)  
**Status:** **OWNER APPROVED (R12) — PLATFORM PLANNING; RDIN PILOT + RADIUMBOX EXPANSION**  
**Platform repository:** `radium-desk` (Central Wallet hub)  
**First pilot spoke:** `rdservice.in`  
**Next planned expansion spoke:** `radiumbox.com` (wallet spending at checkout)  
**Companion pilot docs:** `rdservice.in/docs/central-wallet-pilot-charter.md`, `rdservice.in/docs/central-wallet-regression-safety.md`, `rdservice.in/docs/central-wallet-implementation-spec.md` (rdin pilot sections P27-09-03)

---

## Legend

| Label | Meaning |
|-------|---------|
| **VERIFIED** | Discovery, code, or production inspection |
| **INFERRED** | Design proposal requiring confirmation before build |
| **UNKNOWN** | Not established |
| **OWNER APPROVED** | Owner decision recorded in this document (2026-09-27) |
| **OWNER DECISION REQUIRED** | Still open |

**Governance:** R12 (this specification) and R13 (pilot charter) are **OWNER APPROVED** as of 2026-09-28 (`RadiumDesk-P-25-09-86`). Approval completes **Phase 0** and adopts the Phase 1 foundation plan (`docs/central-wallet-phase-1-implementation-plan.md`). Phase 1 **application code/migrations** require an explicit Owner gate (plan §20). This does **not** authorize production cutover, balance migration, RadiumBox checkout, or spoke production integration without subsequent Owner gates.

---

## Formal Owner approvals — governance record

*Append-only governance record. Does not alter prior Owner-approved technical decisions (§ Owner-approved decisions — Core Central Wallet, §T.13).*

| ID | Document | **OWNER APPROVED** | Date | Recording prompt |
|----|----------|-------------------|------|------------------|
| **R13** | Pilot Charter — `rdservice.in/docs/central-wallet-pilot-charter.md` (P27-09-01) | Charter scope, gates, and rdin-first pilot model | 2026-09-28 | `RadiumDesk-P-25-09-86` / companion `P27-09-07` |
| **R12** | Implementation Specification — this document (canonical) + `rdservice.in/docs/central-wallet-implementation-spec.md` (companion) | Technical design, phased plan, decision register | 2026-09-28 | `RadiumDesk-P-25-09-86` / companion `P27-09-07` |

**Phase 1 build authorization:** R12 + R13 approval satisfies **Phase 0** (specification approval). Phase 1 **application implementation** is defined in `docs/central-wallet-phase-1-implementation-plan.md` and requires a **separate Owner gate** before first migration or deployed service (see plan §20).

---

## Platform placement

| Item | Status |
|------|--------|
| Central Wallet platform lives under **Radium Desk hub** (`radium-desk`) | **OWNER APPROVED** |
| Integration pattern | **API-primary** — **OWNER APPROVED** |
| No cross-project database coupling | **VERIFIED** requirement |
| `users_radium_wallet` / Radium Money | **Excluded** from current implementation — **OWNER APPROVED** |

---

## Owner-approved decisions — Core Central Wallet (2026-09-27)

*Supersedes open §R items from `rdservice.in` P27-09-03 where listed below. Does not upgrade **UNKNOWN** or **INFERRED** items to **VERIFIED**.*

| Former ID | Topic | **OWNER APPROVED** decision |
|-----------|-------|------------------------------|
| R7 | Platform hosting | Central Wallet repository/platform belongs under the **Desk hub** (`radium-desk`) |
| R8 | Integration pattern | **API-primary** architecture |
| R16 | Idempotency record retention | **90 days** |
| R17 | Central Wallet ID format | **UUID v4** |
| R1 | Identity linking | **Automatic wallet-match suggestion** allowed; account linking requires **explicit verified customer confirmation** |
| — | Email equality | **Do not auto-link** solely from email equality (**VERIFIED** policy; Owner reaffirmed) |
| R2 | Dual-verified overlaps | **11,212** dual-verified candidates may use **expedited verification** with **explicit customer confirmation** (not auto-link) |
| — | Customer wallet UX (linking) | Automatic suggestion → **“Connect Wallet”** → verification → **explicit confirmation** → connected |
| R3 | Shadow reconciliation | **Per-event** plus **bounded daily** reconciliation; **asynchronous/queued**, **batched**, **rate-limited**; prefer heavier reconciliation during **low-traffic/night hours** |
| R4 | Financial tolerance | **₹0 unexplained difference**; temporary timing differences permitted **only** for demonstrably **in-flight** transactions and must **resolve within the reconciliation window** |
| — | rdin authority (pilot/shadow) | Existing **rdservice.in** `users_wallet` remains **authoritative** during pilot/shadow |
| R15 | Shadow/parallel minimum duration | **30 days** minimum; **extend if required** |
| Cutover | Automation / authority | **No automatic cutover**; **Owner retains final written cutover authority** |
| R6 | Balance migration (general) | **No automatic migration** of existing wallet balances |
| R9 | First customer-visible pilot (rdin) | **Desk refund + wallet balance display** |
| R10 | Expansion | **Site-by-site**; **rdservice.in** first pilot; **radiumbox.com** next planned expansion |
| R11 | Mobile app | **Future scope** |
| R14 | Radium Money | **Future scope**; **excluded** from current Central Wallet pilot/implementation |
| R12 | Implementation specification sign-off | **OWNER APPROVED** 2026-09-28 — see Formal Owner approvals |
| R13 | Pilot charter sign-off | **OWNER APPROVED** 2026-09-28 — see Formal Owner approvals |

### Remaining open — Core (not Owner-approved in this record)

| Topic | Status |
|-------|--------|
| R5 — named cutover role / signatory identity | **OWNER DECISION REQUIRED** (process: written authority is approved; named role is not) |
| R1 — specific verification mechanism (M1/M2/M3 combination) | **OWNER DECISION REQUIRED** (ceremony outcomes approved; provider/mechanism not fixed) |
| OTP/email provider | **UNKNOWN** |
| Final API host/URL paths | **UNKNOWN** |
| Refund messaging copy (rdin + box) | **OWNER DECISION REQUIRED** |

---

## Executive architecture (updated)

```
┌─────────────────────────────────────────────────────────────────────────┐
│              CENTRAL WALLET PLATFORM (radium-desk — future build)        │
│  CWID (UUID v4) │ Link registry │ Authoritative ledger │ Idempotency    │
│  Checkout: RESERVE → COMMIT / RELEASE APIs (radiumbox.com expansion)   │
└───────────────────────────────┬─────────────────────────────────────────┘
                                │ API-primary only
          ┌─────────────────────┼─────────────────────┐
          ▼                     ▼                     ▼
   rdservice.in           radiumbox.com          (future spokes)
   users_wallet            users_wallet
   refund credits          refund credits +
   (pilot)                 CHECKOUT SPEND (expansion)
                           users_radium_wallet → OUT OF SCOPE
```

**VERIFIED today:** rdin local `users_wallet` authoritative during pilot/shadow (**OWNER APPROVED** until cutover). radiumbox.com checkout debits `users_wallet` locally via pending→success pattern today; central checkout expansion **must not** use “local subtract then call central afterward.”

**OWNER APPROVED architectural principle (radiumbox.com expansion):** Central Wallet is the **authority** for future RadiumBox **wallet-spending** transactions (reserve/commit ledger), replacing local-first debit for flagged/cutover cohorts only after gates pass.

---

## T. RadiumBox Wallet Spending / Checkout

*Primary future customer use case: verified Central Wallet holder uses balance on radiumbox.com checkout.*

### T.1 Scope and non-goals

| In scope (design) | Out of scope (this implementation wave) |
|-------------------|-------------------------------------------|
| Central Wallet balance lookup for linked radiumbox.com customers | **Implementing** checkout code changes |
| Reserve/commit debit authorization model | Migrating existing radiumbox.com balances |
| API contract radiumbox ↔ central | Including `users_radium_wallet` / Radium Money |
| Reconciliation, audit, refund/reversal design | Deploying to production |
| Regression protection for existing Cashfree + local wallet | Removing existing local wallet without Owner gate |

### T.2 VERIFIED — existing radiumbox.com wallet / checkout baseline

| Item | Evidence |
|------|----------|
| Ledger | `users_wallet` — 2,555 rows; checkout + Desk credits | Production discovery P-25-09-82 |
| Checkout wallet debit | `PaymentController::PaymentLink` — `payment_type=wallet` | Code + discovery |
| Pending debit pattern | `Wallet::create` with `status=pending`, `debit=amount`, linked on `orders.wallet_id` | **VERIFIED** `PaymentController.php` |
| Fulfillment | `PaidOrderFulfillmentService` sets wallet row `status=success` when order paid | **VERIFIED** |
| Balance at checkout | `SUM(credit success) - SUM(debit success)` — **pending debits not in availability sum** | **VERIFIED** `PaymentController` |
| Split tender | Wallet portion + Cashfree for remainder via `HandlePaymentGatewayController` | **VERIFIED** |
| Pending production debits | **292** `users_wallet` rows `status=pending` with debit > 0 | Production count |
| Desk refund credit API | `POST /api/integrations/v1/wallet-refunds` deployed | **VERIFIED** |
| Desk ledger read API | `GET /api/integrations/v1/wallet-ledger?customer_email=` | **VERIFIED** |
| Reversal API | **Absent** on radiumbox.com production | **VERIFIED** |
| Checkout idempotency key | **No separate idempotency key documented** for checkout wallet debit | **INFERRED gap** |
| `users_radium_wallet` | Separate ledger; max ₹200; **55** orders `wallet_type=radium_money` | **VERIFIED** — **excluded** from Central Wallet |
| Guest checkout | Guest cart; email upsert | **VERIFIED** discovery |
| Cached `users.wallet_amount` | 435 non-zero; can drift | **VERIFIED** |

### T.3 INFERRED — gaps for central checkout

| Gap | Impact |
|-----|--------|
| No global idempotency on checkout wallet debit | Duplicate POST risk on retry/double-click |
| Pending debit without central reservation TTL | Abandoned checkout locks funds locally until cleanup |
| No reversal API on box | Central refund of checkout debit needs compensating credit path or box API deploy |
| Email fallback on Desk credit (box) | Wrong-wallet risk if order userid missing — **VERIFIED** mechanism |

### T.4 UNKNOWN

| Item | Notes |
|------|-------|
| Pending debit cleanup / expiry job on radiumbox.com | Not verified in this planning pass |
| Exact Cashfree webhook race ordering vs wallet pending flip | Needs radiumbox.com payment test spec |
| Desk retry on wallet credit timeout | Regression baseline UNKNOWN |

### T.5 Required capabilities (analysis checklist)

| Capability | Requirement | Status |
|------------|-------------|--------|
| **Balance lookup** | Return spendable balance for `(site_code, local_user_id)` or `central_wallet_id` | **INFERRED** API design §T.6 |
| **Wallet availability** | Exclude reserved amounts; currency INR; 2dp | **INFERRED** |
| **Debit authorization** | Two-phase reserve before order commit | **INFERRED** §T.8 |
| **Debit idempotency** | Unique per checkout attempt / order reference | **INFERRED** — required to fix VERIFIED gap |
| **Order sequencing** | Reserve → create/update order → pay remainder → commit | **INFERRED** §T.7 |
| **Payment/order races** | Row locks + idempotency + reservation TTL | **INFERRED** |
| **Cancellation/expiry** | Release reservation; no commit | **INFERRED** |
| **Refund** | Credit central ledger; project to local; Desk path separate | **INFERRED** |
| **Reversal** | Compensating entry; requires box reversal API or central-only with projection rules | **UNKNOWN** until box reversal deployed |
| **Reconciliation** | ₹0 tolerance; map order ↔ reservation ↔ ledger entry | **OWNER APPROVED** tolerance |
| **Audit trail** | correlation_id, reservation_id, ordercode, CWID | **INFERRED** |
| **Identity/linking** | Active `wallet_account_link`; no email auto-link | **OWNER APPROVED** |

### T.6 Proposed API contract — RadiumBox ↔ Central Wallet

*Logical endpoints; final URL host/path **UNKNOWN** until platform deployment. Authentication pattern extends **VERIFIED** `desk.integration` (Bearer / `X-Desk-Token` style) with **spoke-scoped** credentials for radiumbox.com.*

#### T.6.1 Authentication

| Caller | Credential | Classification |
|--------|------------|----------------|
| radiumbox.com → Central Wallet | Service bearer token (per-site) | **INFERRED** |
| Customer browser | Never holds central service token; spoke session only | **INFERRED** |

Fail-closed if token empty (**VERIFIED** pattern on existing Desk integrations).

#### T.6.2 `GET /v1/wallets/{central_wallet_id}/balance`

| Field | Rule |
|-------|------|
| Caller | radiumbox.com (authenticated) |
| Response | `available_balance`, `reserved_balance`, `currency=INR` |
| Preconditions | Active link `(site_code=radiumbox.com, local_user_id)` |

#### T.6.3 `POST /v1/wallet-reservations` (RESERVE)

| Field | Required | Notes |
|-------|----------|-------|
| `idempotency_key` | yes | Unique per checkout attempt; e.g. `box-checkout:{session_id}:{attempt}` |
| `central_wallet_id` | yes | UUID v4 |
| `site_code` | yes | `radiumbox.com` |
| `local_user_id` | yes | `users.id` |
| `order_reference` | yes | **`RADBOX:<BusinessOrderId>`** — namespaced existing RadiumBox BusinessOrderId (**OWNER APPROVED** R-CW-03) |
| `amount` | yes | Positive INR ≤ available |
| `currency` | yes | `INR` |
| `checkout_session_id` | yes | Opaque spoke session identifier |
| `expires_at` | set by central | Default **15 minutes** from reserve (**OWNER APPROVED** R-CW-01) |

**Success (201 / 200 duplicate):**

```json
{
  "status": 201,
  "data": {
    "reservation_id": "…",
    "central_wallet_id": "…",
    "amount": "499.00",
    "currency": "INR",
    "state": "reserved",
    "available_balance_after_reserve": "0.00",
    "expires_at": "2026-09-27T12:00:00Z",
    "idempotency_key": "box-checkout:…"
  }
}
```

**Failure:** 401, 404 (no link), 422 (insufficient funds), 409 (idempotency conflict different amount).

**Retry:** Safe on same `idempotency_key` → return existing reservation.

**Timeout:** Spoke must not commit locally on ambiguous timeout; reconcile by `idempotency_key` lookup before retry.

#### T.6.4 `POST /v1/wallet-reservations/{reservation_id}/commit` (COMMIT)

| Field | Required | Notes |
|-------|----------|-------|
| `idempotency_key` | yes | e.g. `box-commit:{order_id}` |
| `order_reference` | yes | Final paid order identifier |
| `order_paid_at` | yes | ISO timestamp |

Creates immutable **posted debit** ledger entry linked to reservation. Idempotent on commit key.

**Precondition:** Reservation `state=reserved`, not expired, amount matches.

#### T.6.5 `POST /v1/wallet-reservations/{reservation_id}/release` (RELEASE)

| Field | Required | Notes |
|-------|----------|-------|
| `idempotency_key` | yes | |
| `reason` | yes | `checkout_abandoned` \| `payment_failed` \| `order_cancelled` \| `expired` |

Returns reserved funds to available balance. Idempotent.

#### T.6.6 `GET /v1/wallet-reservations/{reservation_id}`

Status polling after timeout. **INFERRED** required for safe retry.

#### T.6.7 Refund / reversal (checkout)

| Event | **INFERRED** central behaviour |
|-------|----------------------------------|
| Order refunded to wallet | Credit ledger entry linked to original debit `ledger_entry_id` |
| Order cancelled before commit | RELEASE only |
| Order cancelled after commit | Credit (refund) entry; Desk may orchestrate separately |
| Desk refund-revoke | Use existing Desk refund reference semantics when applicable |

**OWNER APPROVED gate (R-CW-05):** RadiumBox **wallet reversal capability must be available and verified** before enabling Central Wallet wallet checkout on RadiumBox. **VERIFIED today:** reversal API is **absent** on radiumbox.com production — checkout enablement is **blocked** until reversal is deployed and verified (implementation **not** authorized by this document).

**Reference identity separation (R-CW-03 — OWNER APPROVED):** Keep distinct:

- `order_reference` — `RADBOX:<BusinessOrderId>`
- Central Wallet **transaction / ledger entry ID** (central-assigned)
- **Idempotency key** (caller-assigned per operation)

### T.7 Lifecycle (target — **OWNER APPROVED**)

**Preferred sequence (R-CW-02):**

```
CHECKOUT START
  → Customer authenticated on radiumbox.com
  → Resolve active wallet_account_link → central_wallet_id (UUID v4)
  → GET balance (central) — display available

WALLET SELECTED
  1. POST RESERVE (central) — idempotent; TTL 15 minutes (R-CW-01)
  2. Create order / payment session (radiumbox.com — local DB only)
  3. Customer completes Cashfree payment (split tender: wallet reserved + Cashfree remainder)
  4. Confirm Cashfree payment server-side (radiumbox.com)
  5. POST COMMIT (central) — idempotent
  6. Finalize / fulfill order (radiumbox.com)

POST-COMMIT
  → Central posts immutable ledger debit (authority)
  → radiumbox adapter may write local users_wallet projection (non-authoritative during transition)
```

**Failure paths (OWNER APPROVED):**

| Condition | Action |
|-----------|--------|
| Cancel / abandon / **reservation expiry** (15m) | **RELEASE** reservation safely (R-CW-01, R-CW-07) |
| Cashfree payment failure | **RELEASE** reservation |
| Central Wallet **unavailable** at checkout | **Fail-closed** — no wallet debit, no wallet-funded completion (R-CW-04) |
| Cashfree succeeds but Central Wallet **commit temporarily fails** | Enter **recoverable pending/reconciliation state**; **never** silently mark order paid without wallet commit (R-CW-02) |
| Commit vs order-finalization inconsistency | Reconciliation + controlled recovery |
| Expiry vs commit race | Reconciliation — not duplicate release/debit (R-CW-07) |

**Forbidden flow:** radiumbox inserts local `users_wallet` pending debit **first**, then calls central (**no local-first wallet debit**).

**Parallel-run / rollout (R-CW-06 — OWNER APPROVED):** Central Wallet checkout on RadiumBox launches behind a **feature flag**, beginning with a **controlled cohort**; expand only after reconciliation and operational validation; **Owner approval** required at major rollout/cutover gates. Non-flagged users retain **VERIFIED** existing local wallet checkout path until cutover.

### T.8 Transaction model selection

| Model | Fit | Verdict |
|-------|-----|---------|
| **Reserve / commit** (hold → capture) | Matches existing **pending→success** semantics; prevents spend during checkout; supports expiry | **SELECTED — OWNER APPROVED** (aligned with R-CW-02 and no local-first debit) |
| Authorize / capture | Equivalent to reserve/commit in payment terminology | Acceptable alias |
| Immediate debit + reversal | Poor for abandoned checkout and races; 292 pending rows show operational pain | **Reject** for central |
| Local debit then central sync | **Explicitly forbidden** by Owner critical principle | **Reject** |

**Tradeoffs (reserve/commit):**

- (+) Prevents double-spend across concurrent sessions
- (+) Clean cancel/expiry without compensating entries
- (+) Central balance authoritative before commit
- (-) Requires reservation TTL job and reconciliation for stuck `reserved`
- (-) Extra API round-trips (mitigated by async where safe)

### T.9 Reconciliation — RadiumBox wallet spending

| Check | Frequency | Tolerance |
|-------|-----------|-----------|
| `reserved` + `posted` central entries ↔ radiumbox `orders.wallet_*` | Per-event + daily batch | **₹0** unexplained (**OWNER APPROVED**) |
| Commit without matching paid order | Per-event | **Block P1** |
| Order paid with wallet but no commit | Per-event | **Block P1** |
| Duplicate commit same idempotency | Per-event | **Block P1** |
| Expired reservation still holding funds | Per-event + daily | **Auto-release** server-side, idempotent, auditable, race-safe (R-CW-07); expiry/commit races → reconciliation |
| In-flight timing lag (reserve/commit/Cashfree) | Per-event + daily window | Allowed only if demonstrably in-flight; must resolve within reconciliation window (R4) |
| Local `users_wallet` projection ↔ central ledger (flagged accounts) | Daily night batch | **₹0** |

### T.10 Existing RadiumBox balance handling (migration)

| Phase | `users_wallet` on radiumbox.com | Central Wallet |
|-------|--------------------------------|----------------|
| Pilot (rdin) | **Authoritative** (refund only) | Shadow — no box checkout |
| Pre-cutover box | **Authoritative** for checkout | Shadow projection of new Desk credits only |
| Parallel-run box (flagged users) | Authoritative for legacy users | Reserve/commit for flagged; reconcile |
| Post-cutover box | **Projection/cache** | **Authoritative** for new debits/credits |
| Historical balances | **Remain local** | **No automatic migration** (R6, **R-CW-08**) |

**OWNER APPROVED (R-CW-08):** Do **not** automatically migrate existing RadiumBox `users_wallet` balances. Balances remain **local** until a **separate Owner migration decision**. Investigate balance **provenance** first. The **292** verified pending RadiumBox wallet debits must be included in that analysis. Unknown/unresolved balances are **preserved**, not guessed or migrated.

### T.11 Customer UX (radiumbox.com)

| State | Behaviour |
|-------|-----------|
| **Wallet visible** | Customer logged in; active `wallet_account_link`; central balance > 0; checkout feature flag on |
| **Suggestion** | Email match may suggest linking — **OWNER APPROVED**; requires explicit confirmation |
| **Unavailable** | No link, suspended CWID, flag off, or central unreachable → wallet tender **unavailable**; **fail-closed** — no wallet debit without confirmed authorization/commit (R-CW-04) |
| **Insufficient balance** | Reserve returns 422; message “Insufficient wallet balance” (mirror **VERIFIED** local string) |
| **Linking** | Expedited flow for dual-verified candidates with explicit confirm — **OWNER APPROVED** |
| **Success** | Show wallet amount applied + Cashfree remainder if split |
| **Failure** | Release reservation; order not wallet-debited; Cashfree-only retry allowed |
| **Refund messaging** | “Refund credited to your Radium Wallet” — copy **OWNER DECISION REQUIRED** |

### T.12 Regression requirements — radiumbox.com

**PRESERVED until Owner-authorized cutover:**

| Protected behaviour | Classification |
|--------------------|----------------|
| Cashfree checkout for non-wallet and split-tender remainder | **VERIFIED** |
| Existing `PaymentController` wallet + radium_money paths | **VERIFIED** |
| `users_radium_wallet` checkout (≤₹200) unchanged | **VERIFIED** — separate from Central Wallet |
| Desk `wallet-refunds` credit API | **VERIFIED** |
| `GET wallet-ledger` | **VERIFIED** |
| Pending→success wallet debit on paid order | **VERIFIED** |

**Mandatory before central checkout flag:**

- radiumbox.com regression suite for PaymentController + PaidOrderFulfillmentService
- No duplicate debit under replayed `PaymentLink` POST (new test **INFERRED** required)
- Cashfree webhook + wallet commit ordering tests (**INFERRED**)
- Central Wallet integration tests (reserve/commit/release) in isolation

### T.13 Owner-approved RadiumBox checkout decisions (R-CW-01–R-CW-08)

| ID | **OWNER APPROVED** decision |
|----|-----------------------------|
| **R-CW-01** | Reservation TTL = **15 minutes**. Expiry must **release** the reservation safely. |
| **R-CW-02** | Split-tender sequence: **(1)** Reserve wallet amount → **(2)** Create order/payment session → **(3)** Customer completes Cashfree → **(4)** Confirm Cashfree **server-side** → **(5)** Commit Central Wallet reservation → **(6)** Finalize/fulfill order. If Cashfree succeeds but commit temporarily fails: **recoverable pending/reconciliation state**; **never** silently mark order paid without wallet commit. |
| **R-CW-03** | `order_reference` = **`RADBOX:<BusinessOrderId>`**. Separate: business order reference, Central Wallet transaction ID, idempotency key. |
| **R-CW-04** | **Fail-closed** when Central Wallet unavailable. No wallet debit or wallet-funded order completion without confirmed authorization/commit. Recoverable async states allowed for **transient** failures. |
| **R-CW-05** | RadiumBox **wallet reversal capability must be available and verified** before enabling Central Wallet wallet checkout. (**VERIFIED:** reversal API absent today — gate blocked; API implementation **not** in scope of this doc task.) |
| **R-CW-06** | Checkout behind **feature flag**; start with **controlled cohort**; expand after reconciliation + ops validation; **Owner approval** at major rollout/cutover gates. |
| **R-CW-07** | Expired reservations **auto-released** server-side; **idempotent**, **auditable**, **race-safe**; expiry/commit races → **reconciliation** (not duplicate release/debit). |
| **R-CW-08** | **No automatic migration** of existing RadiumBox balances. Remain local until **separate migration decision**. Provenance investigation first; include **292** pending debits; preserve unknown/unresolved balances. |

### T.14 Remaining open — RadiumBox checkout

| Topic | Status |
|-------|--------|
| Controlled cohort selection criteria (which accounts first) | **OWNER DECISION REQUIRED** |
| Separate migration decision for historical box balances (after provenance) | **OWNER DECISION REQUIRED** (R-CW-08 defers, does not approve migration) |
| Refund customer messaging copy | **OWNER DECISION REQUIRED** |
| Pending debit cleanup job on legacy radiumbox.com path | **UNKNOWN** |
| Cashfree webhook vs commit ordering test evidence | **UNKNOWN** |

### T.15 Implementation phase placement

| Phase | RadiumBox checkout |
|-------|-------------------|
| 0–7 (rdin pilot) | **No checkout spend**; design only (this section) |
| **8-pre** | Deploy + verify RadiumBox **reversal API** (R-CW-05 gate) — **not authorized here** |
| **8a** | Shadow: central balance + reserve/commit in staging; no customer flag |
| **8b** | Feature-flag **controlled cohort**; parallel-run reconciliation |
| **8c** | Owner **written** cutover; central authoritative for wallet spending debits |
| **8d** | Expand cohort / full radiumbox.com; site-by-site expansion continues |

**Does not start until:** rdin pilot gates complete; **R-CW-05** reversal gate satisfied; radiumbox regression baseline documented.

---

## Document control

| Field | Value |
|-------|-------|
| Version | 0.4 (R12/R13 formal approval + Phase 1 plan) |
| Prompt ID | `RadiumDesk-P-25-09-84` … `RadiumDesk-P-25-09-86` |
| Phase 1 implementation plan | `radium-desk/docs/central-wallet-phase-1-implementation-plan.md` |
| Pilot charter (R13) | `rdservice.in/docs/central-wallet-pilot-charter.md` |
| rdin pilot detail | `rdservice.in/docs/central-wallet-implementation-spec.md` (P27-09-03) |

---

*Planning only. No implementation, deployment, or production financial movement authorized.*
