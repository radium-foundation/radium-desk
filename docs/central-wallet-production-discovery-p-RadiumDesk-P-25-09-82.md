# Central Wallet — Phase 2 Production Discovery

**Prompt ID:** `RadiumDesk-P-25-09-82`  
**Date:** 2026-09-27  
**Mode:** READ-ONLY DISCOVERY GATE  
**Companion:** `docs/central-wallet-architecture-discovery-p-RadiumDesk-P-25-09-81.md`  
**Status:** Discovery only — no implementation approved

---

## 1. Executive summary

Phase 2 establishes **verified production facts** for the two wallet-capable spokes and Desk orchestration. No wallet credits, refunds, schema changes, or infrastructure modifications were performed.

### Headline findings

| Finding | Status |
|---------|--------|
| **Wallet-refund API deployed on radiumbox.com** | **VERIFIED** — route live; unauthenticated POST returns HTTP 401 |
| **Wallet-refund + reversal APIs deployed on rdservice.in** | **VERIFIED** — both routes live; unauthenticated POST returns HTTP 401 |
| **Desk automated wallet credit enabled for both spokes** | **VERIFIED** — `RADIUMBOX_WALLET_REFUND_CREDIT_ENABLED=true`, `RDSERVICE_IN_WALLET_REFUND_CREDIT_ENABLED=true` |
| **Desk wallet reversal enabled for rdservice.in only** | **VERIFIED** — `RDSERVICE_IN_WALLET_REFUND_REVERSAL_ENABLED=true`; radiumbox reversal **false** |
| **Desk can reach spoke integration APIs** | **VERIFIED** — authenticated GET order lookup returns HTTP 400 (invalid probe ID), not connection/401 failure |
| **Production wallet credits have occurred** | **VERIFIED** — box: 4 `desk_refund_reference` credits; rdservice.in: 51 `source_system=radium_desk` credits, 2 reversal debits |
| **Within-DB email duplicates** | **VERIFIED zero** — unique email index on both production DBs |
| **Cross-DB email overlap count** | **UNKNOWN** — DB users lack cross-schema SELECT permission |
| **`users_radium_wallet` is production-active** | **VERIFIED** — ledger row as recent as 2026-09-27; 55 orders use `wallet_type=radium_money` |
| **Central wallet / group identity** | **NOT APPROVED** — architectural hypothesis only |

**Pilot architecture implication (INFERRED, not decided):** Option 3 (Central Wallet ID linked to local accounts now; Group Identity/SSO later) aligns best with verified production seams, but **Owner decision required**.

---

## 2. Scope and legend

### In scope

- Production deployment verification (routes, flags, reachability)
- Wallet data model on radiumbox.com and rdservice.in (schema + counts)
- Count-only identity collision analysis (no PII)
- Refund-credit flow trace (read-only)
- `users_radium_wallet` investigation
- Pilot option comparison (no selection)

### Out of scope

- Wallet writes, refunds, migrations, code changes
- Credential values, email addresses, customer names

| Label | Meaning |
|-------|---------|
| **VERIFIED** | Established from production inspection, DB counts, or matching source/deploy evidence |
| **INFERRED** | Logical conclusion requiring confirmation |
| **UNKNOWN** | Not established |

---

## 3. Repository / environment verification

Inspection: **2026-09-27**

| Project | Path | Remote | Branch | HEAD SHA | Worktree |
|---------|------|--------|--------|----------|----------|
| radiumbox.com | `/Users/ravi/RadiumWebsites/radiumbox.com` | `radium-foundation/radiumbox.com` | `feat/desk-catalog-price-sync` | `116990ab` | Dirty (unrelated local files) |
| rdservice.in | `/Users/ravi/RadiumWebsites/rdservice.in` | `radium-foundation/rdservice.in` | `feat/desk-wallet-refund-reversal` | `bcb380ac` | Dirty (docs only) |
| rdservice.net | `/Users/ravi/RadiumWebsites/rdservice.net` | `radium-foundation/rdservice.net` | `wip/p04-08-03-einvoice-payment-export-ui` | `c43b9de8` | Dirty (unrelated) |
| radiumsign.com | `/Users/ravi/RadiumWebsites/radiumsign.com` | `radium-foundation/radiumsign.com` | `production/kvm4-radiumsign` | `35b289a3` | `.DS_Store` only |
| rdserviceonline.in | `/Users/ravi/RadiumWebsites/rdserviceonline.in` | `radium-foundation/rdserviceonline.in` | `main` | `0af0cb2b` | Clean |
| radium-desk | `/Users/ravi/RadiumWebsites/radium-desk` | `radium-foundation/radium-desk` | `feat/refund-statutory-adjustment-p-25-09-79` | `35a74710` | Clean |

### Production host (wallet verification)

| Item | Value | Status |
|------|-------|--------|
| Host | `ravi@187.127.129.16` (KVM8) | VERIFIED SSH |
| App paths | `/var/www/radiumbox.com`, `/var/www/rdservice.in`, `/var/www/radium-desk` | VERIFIED |
| PHP (Desk) | 8.4.24 via `/usr/local/lsws/lsphp84/bin/php` | VERIFIED |
| Desk Laravel | 13.17.0, environment `production` | VERIFIED `artisan about` |
| Production DB: box | `radiumbox_prod` | VERIFIED |
| Production DB: rdservice.in | `rdservice_in_prod` | VERIFIED |

Production deploy trees are **not git worktrees** (no `.git` in app paths). File integrity verified via SHA-256 match between production and local workspace for wallet service files (see §4).

---

## 4. Production wallet API verification

### 4.1 radiumbox.com

| Item | Finding | Status |
|------|---------|--------|
| **Credit route** | `POST /api/integrations/v1/wallet-refunds` | VERIFIED — `routes/api.php` on production |
| **Ledger read route** | `GET /api/integrations/v1/wallet-ledger` | VERIFIED |
| **Reversal route** | Not present in production `routes/api.php` | VERIFIED absent |
| **Controller** | `App\Http\Controllers\Api\Integrations\DeskWalletRefundController` | VERIFIED on disk |
| **Service** | `App\Services\Integrations\DeskWalletRefundCreditService` | VERIFIED |
| **Auth mechanism** | Bearer `Authorization` or `X-Desk-Token` vs `DESK_ORDER_API_TOKEN` → `config('integrations.desk.token')` | VERIFIED — `AuthenticateDeskIntegration` |
| **Fail-closed** | Empty/missing token → HTTP 401 JSON | VERIFIED — curl probe without auth |
| **Ledger GET without auth** | HTTP 401 | VERIFIED |
| **Spoke token configured** | `integrations.desk.token_set=true` | VERIFIED (boolean only) |
| **Desk credit flag** | `radiumbox.wallet_refund_credit_enabled=true` | VERIFIED |
| **Desk reversal flag** | `radiumbox.wallet_refund_reversal_enabled=false` | VERIFIED |
| **Desk spoke config** | `order_lookup.spokes.radiumbox_com`: enabled, base_url_set, token_set all true | VERIFIED |
| **Desk → spoke reachability** | GET `/api/integrations/v1/rd-orders/INVALID_PROBE_ID` → HTTP 400 | VERIFIED (auth accepted; invalid ID) |
| **Intended for production** | Yes — live routes, flags ON, historical credits in DB | VERIFIED |
| **Implementation version** | Production SHA-256 matches local workspace for `DeskWalletRefundCreditService.php` | VERIFIED |

**Production file SHA-256 (matches local repo):**

- `DeskWalletRefundController.php`: `379ca169…`
- `DeskWalletRefundCreditService.php`: `27bebe5d…`

**No wallet credit/refund POST was executed during this discovery.**

### 4.2 rdservice.in

| Item | Finding | Status |
|------|---------|--------|
| **Credit route** | `POST /api/integrations/v1/wallet-refunds` | VERIFIED |
| **Reversal route** | `POST /api/integrations/v1/wallet-refund-reversals` | VERIFIED |
| **Controllers** | `DeskWalletRefundController`, `DeskWalletRefundReversalController` | VERIFIED on disk |
| **Services** | `DeskWalletRefundCreditService`, `DeskWalletRefundReversalService` | VERIFIED |
| **Auth mechanism** | Same Bearer / `X-Desk-Token` pattern | VERIFIED |
| **Unauthenticated POST** | HTTP 401 on both credit and reversal routes | VERIFIED |
| **Spoke token configured** | `integrations.desk.token_set=true` | VERIFIED |
| **Desk credit flag** | `rdservice_in.wallet_refund_credit_enabled=true` | VERIFIED |
| **Desk reversal flag** | `rdservice_in.wallet_refund_reversal_enabled=true` | VERIFIED |
| **Desk spoke config** | `order_lookup.spokes.rdservice_in`: enabled, base_url_set, token_set all true | VERIFIED |
| **Desk → spoke reachability** | GET invalid order → HTTP 400 | VERIFIED |
| **Migrations applied** | `2026_09_16_110000_add_desk_refund_uniqueness_to_users_wallet`, `2026_09_17_120000_add_desk_wallet_refund_reversal_columns_to_users_wallet` | VERIFIED in `migrations` table |
| **Implementation version** | Production SHA-256 matches local workspace for credit + reversal services | VERIFIED |

**Production file SHA-256 (matches local repo):**

- `DeskWalletRefundCreditService.php`: `fed2578a…`
- `DeskWalletRefundReversalService.php`: `4f56b56d…`

### 4.3 Feature-flag summary (Desk production)

| Flag | Value | Status |
|------|-------|--------|
| `RADIUMBOX_WALLET_REFUND_CREDIT_ENABLED` | true | VERIFIED |
| `RADIUMBOX_WALLET_REFUND_REVERSAL_ENABLED` | false | VERIFIED |
| `RDSERVICE_IN_WALLET_REFUND_CREDIT_ENABLED` | true | VERIFIED |
| `RDSERVICE_IN_WALLET_REFUND_REVERSAL_ENABLED` | true | VERIFIED |

**INFERRED:** Desk can automate wallet **credits** to both spokes in production. Desk can automate wallet **reversals** only to rdservice.in until radiumbox.com deploys a matching reversal endpoint and Desk enables `RADIUMBOX_WALLET_REFUND_REVERSAL_ENABLED`.

---

## 5. Wallet data model verification

### 5.1 radiumbox.com (`radiumbox_prod`)

#### Tables

| Table | Rows (total) | Credit rows | Debit rows | Desk-specific |
|-------|--------------|-------------|------------|---------------|
| `users_wallet` | 2,555 | 1,335 | 949 | 4 rows with `desk_refund_reference` |
| `users_radium_wallet` | 172 | 6 | 4 | No `desk_refund_reference` column |

#### `users_wallet` schema (production-verified columns)

| Column / mechanism | Present | Notes |
|--------------------|---------|-------|
| `userid`, `credit`, `debit`, `status`, `message`, `orderid`, `txnid` | VERIFIED | Core ledger |
| `desk_refund_reference` | VERIFIED | Unique index; Desk idempotency key |
| `source_system` | **absent** | Unlike rdservice.in |
| Reversal idempotency columns | **absent** | No production reversal API |
| `admin_id` | INFERRED | Historical Admin writes |

#### Balance semantics

| Mechanism | Detail | Status |
|-----------|--------|--------|
| **Ledger SoT** | `SUM(credit) - SUM(debit)` where `status=success` (credits); checkout also uses pending debits | VERIFIED — `PaymentController`, `DeskWalletRefundCreditService` |
| **Cached balance** | `users.wallet_amount` — 435 users with non-zero value | VERIFIED column + counts |
| **Cache refresh** | Updated on Desk credit via `refreshCachedWalletBalance()` | VERIFIED — source |
| **Pending debits** | 292 `users_wallet` rows `status=pending` with debit > 0 | VERIFIED production count |

#### Idempotency

| Key | Mechanism | Status |
|-----|-----------|--------|
| `desk_refund_reference` | Unique; `lockForUpdate` + duplicate check before insert | VERIFIED |
| Checkout wallet debit | No separate idempotency key documented | INFERRED gap |

#### Reversal

| Item | Status |
|------|--------|
| API endpoint | **VERIFIED absent** on radiumbox.com production |
| Desk flag | **VERIFIED false** |

### 5.2 rdservice.in (`rdservice_in_prod`)

#### Tables

| Table | Rows | Credit | Debit | Desk |
|-------|------|--------|-------|------|
| `users_wallet` | 1,466 | 934 | 506 | 51 desk credits; 2 desk reversal debits |
| `users_radium_wallet` | **absent** | — | — | — |

#### `users_wallet` schema (production-verified)

| Column / mechanism | Present |
|--------------------|---------|
| `desk_refund_reference` | VERIFIED |
| `source_system` | VERIFIED (`radium_desk` on 53 rows) |
| `desk_reversal_idempotency_key` | VERIFIED |
| `reversed_desk_refund_reference` | VERIFIED |
| Unique indexes | 7 index groups VERIFIED (`SHOW INDEX`) |

#### Balance semantics

| Mechanism | Detail | Status |
|-----------|--------|--------|
| **Ledger SoT** | `SUM(credit success/pending) - SUM(debit success)` on dashboard | VERIFIED |
| **Cached balance** | `users.wallet_amount` — 242 non-zero | VERIFIED |
| **Checkout wallet debit** | Not implemented | VERIFIED absent in app |

#### Reversal

| Item | Status |
|------|--------|
| API deployed | VERIFIED |
| Production usage | VERIFIED — 2 reversal debit rows |
| Fail-closed insufficient balance | VERIFIED — source `DeskWalletRefundReversalService` |
| Original credit row preserved; reversal = new debit row | VERIFIED |

### 5.3 Previously identified concerns

| Concern | Finding | Status |
|---------|---------|--------|
| **1. `users_radium_wallet`** | See §9 — active, separate ledger, Admin + checkout writes | VERIFIED |
| **2. `users_wallet`** | Primary stored-value/refund ledger on box; refund-only on rdin | VERIFIED |
| **3. Cached `users.wallet_amount`** | Present both DBs; refreshed on Desk credit; can drift if other writers skip refresh | VERIFIED |
| **4. `desk_refund_reference`** | Box: 4 production credits; rdin: 51; unique constrained | VERIFIED |
| **5. Email fallback mapping** | Box `DeskWalletRefundCreditService` falls back to `customer_email` → `users.id` if order userid missing | VERIFIED source; risk if email wrong |
| **6. Wallet reversal** | rdin only (API + 2 prod rows); box not deployed | VERIFIED |

---

## 6. Count-only identity collision analysis

**Method:** Read-only `SELECT COUNT(*)` via Laravel DB on production. **No emails, names, or phone numbers exported.**

### 6.1 radiumbox.com (`radiumbox_prod`)

| Metric | Count |
|--------|------:|
| Total `users` rows | 516,800 |
| Non-null non-empty email rows | 516,800 |
| Distinct normalized emails (`LOWER(TRIM(email))`) | 516,800 |
| Duplicate normalized email groups | 0 |
| Users in duplicate-email groups | 0 |
| Non-null non-empty phone rows | 431,951 |
| Distinct phone values | 369,388 |
| `email_verified_at` set | 44,995 |
| `phone_verified_at` column | absent |
| Email unique index | **true** |
| Phone unique index | **false** |

### 6.2 rdservice.in (`rdservice_in_prod`)

| Metric | Count |
|--------|------:|
| Total `users` rows | 253,195 |
| Non-null non-empty email rows | 253,195 |
| Distinct normalized emails | 253,195 |
| Duplicate normalized email groups | 0 |
| Users in duplicate-email groups | 0 |
| Non-null non-empty phone rows | 252,888 |
| Distinct phone values | 243,064 |
| `email_verified_at` set | 11,529 |
| `phone_verified_at` column | absent |
| Email unique index | **true** |
| Phone unique index | **false** |

### 6.3 Cross-site email overlap

| Metric | Result | Status |
|--------|--------|--------|
| Normalized email overlap (box ∩ rdservice.in) | Not computed | **UNKNOWN** |
| Reason | `radiumbox_prod` and `rdservice_in_prod` DB users cannot `SELECT` across schemas (permission denied) | **VERIFIED** boundary |

**Important:** Even if overlap were computed, matching normalized email would **not** prove same person. No automatic identity merge is implied.

### 6.4 Verified-email rate (INFERRED risk context)

| Site | Verified / Total | Rate |
|------|------------------|------|
| radiumbox.com | 44,995 / 516,800 | ~8.7% |
| rdservice.in | 11,529 / 253,195 | ~4.6% |

Majority of accounts have **not** completed email verification despite unique email constraint.

---

## 7. Customer identity mapping risk

Based on verified counts and schema (not on cross-site email matching):

| Risk | Assessment | Status |
|------|------------|--------|
| **Duplicate accounts within one site** | Email collision prevented by unique index; phone collisions possible (62k+ duplicate phone capacity on box) | VERIFIED |
| **Duplicate accounts across sites** | Separate `users.id` namespaces; overlap count UNKNOWN | VERIFIED / UNKNOWN |
| **Email collision risk (within site)** | Zero duplicate emails in production counts | VERIFIED |
| **Phone collision risk** | Phone not unique; distinct count < non-null count on both DBs | VERIFIED |
| **Guest account risk** | Box: guest cart; rdin: checkout upsert by email; sign: guest checkout | VERIFIED (Phase 1) |
| **Deleted/reused email** | Soft-delete behaviour not verified; unique email prevents concurrent duplicates | UNKNOWN |
| **Account takeover** | Per-site session auth; low cross-site blast radius today | INFERRED |
| **Wrong-wallet risk** | Desk resolves wallet owner via order → `userid`; box email fallback if userid missing | VERIFIED mechanism; INFERRED risk on fallback |

---

## 8. Current wallet refund flow (read-only trace)

```
Operator: POST /refunds/{id}/complete (Desk web, permission refunds.execute)
  → RefundExecutorResolver
    → WalletRefundExecutor (approved_refund_method = wallet)
      → BusinessOrderId owner check (RD* → rdservice.in, RB*/RDE* → radiumbox.com)
      → RdServiceInWalletRefundClient | RadiumBoxWalletRefundClient
        POST https://{spoke}/api/integrations/v1/wallet-refunds
          Headers: Authorization: Bearer {spoke token}
          Body: desk_refund_reference, order_id, amount, [customer_email], [source_system on rdin]
        → AuthenticateDeskIntegration (spoke)
        → DeskWalletRefundCreditService::credit()
          → Order lookup (box) or RdOrderDeskLookupService (rdin)
          → Resolve userid (order owner; box email fallback)
          → DB transaction + idempotency on desk_refund_reference
          → INSERT users_wallet credit row (status=success)
          → Set txnid = RD{id} (box) / wallet id (rdin)
          → Refresh users.wallet_amount cache
        ← JSON: wallet_transaction_id, wallet_reference, balance
      ← Desk stores execution_reference_no, execution_transaction_id on refund_requests
```

### Reversal path (rdservice.in only, when Desk revoke runs)

```
RefundRevokeService
  → WalletRefundReversalResolver
    → RdServiceInWalletRefundReversalClient
      POST /api/integrations/v1/wallet-refund-reversals
        idempotency_key, desk_refund_reference, amount (must match original)
      → DeskWalletRefundReversalService::reverse()
        → New debit row; original credit preserved
        → Balance check fail-closed
```

### Flow properties

| Property | Detail | Status |
|----------|--------|--------|
| **Source of truth (customer money)** | Spoke `users_wallet` ledger | VERIFIED |
| **Source of truth (refund workflow)** | Desk `refund_requests` | VERIFIED |
| **Desk request idempotency** | `refund.reference_no` sent as `desk_refund_reference` | VERIFIED |
| **Spoke idempotency** | Unique `desk_refund_reference` (box); unique `(source_system, desk_refund_reference)` (rdin) | VERIFIED |
| **Duplicate protection** | Idempotent 200/201 on replay | VERIFIED — source + tests |
| **Timeout** | Desk client: connect 3s, timeout 8s (spoke config) | VERIFIED |
| **Retry** | No automatic retry in client; operator must re-attempt | VERIFIED |
| **Reversal** | rdin: API + flag ON + 2 prod debits; box: not available | VERIFIED |
| **Audit trail** | Desk `audit_logs`; spoke `users_wallet` rows with message | VERIFIED |
| **Reconciliation** | Sep 2026 forensic work (P-25-09-66–68) documented manual/backfill needs for `execution_transaction_id` | VERIFIED docs |

---

## 9. `users_radium_wallet` decision analysis

### What it represents

| Aspect | Finding | Status |
|--------|---------|--------|
| **Name** | "Radium Money" — promotional/capped tender | VERIFIED — checkout max ₹200 |
| **Table** | `users_radium_wallet` on radiumbox.com only | VERIFIED |
| **Model** | `App\Models\User\RadiumWallet` | VERIFIED |
| **Writers** | (1) Checkout `PaymentController` pending debits; (2) Old Admin `RadiumWalletController::Store` credits | VERIFIED |
| **Readers** | `PaymentController`, `HomeController`, `WalletController`, order `wallet_type=radium_money` | VERIFIED |
| **Desk integration** | No Desk refund/reversal API targets this table | VERIFIED |
| **Production activity** | 172 ledger rows; first 2025-10-01, last **2026-09-27 18:05:44** | VERIFIED |
| **Order usage** | 55 orders with `wallet_type=radium_money` | VERIFIED |
| **Separate from `users_wallet`** | Distinct table, balance, checkout path | VERIFIED |

### Classification

| Question | Answer |
|----------|--------|
| Redundant? | **No** — actively used today |
| Legacy only? | **No** — recent production writes |
| Local projection of central wallet? | **No** — standalone ledger |
| Safe to leave as local projection during pilot? | **INFERRED yes** if central wallet scopes only `users_wallet` / Desk refunds |
| Appropriate to migrate to central wallet? | **UNKNOWN** — Owner must decide if ₹200 promotional tender is in scope |

**INFERRED recommendation for Owner discussion:** Treat `users_radium_wallet` as **out of scope for Phase 1 central wallet pilot** unless promotional tender centralisation is explicitly required.

---

## 10. Pilot architecture options

*Comparison based on verified production evidence. **No option selected.***

### Option 1 — Central Wallet ID linked to existing local customer IDs

| Dimension | Assessment |
|-----------|------------|
| Migration complexity | **Lower** — local `users.id` unchanged; add link table + central ledger |
| Security | Spoke breach isolated; linking ceremony needed |
| Fraud risk | Linking attacks if verification weak |
| Customer experience | Per-site login; unified balance via link |
| Protected functionality | Checkout wallet on box continues on local ledger during parallel run |
| Rollback | Disable central writes; revert to spoke `users_wallet` |
| Reconciliation | Must map `desk_refund_reference` + local `userid` + central wallet ID |
| Dependencies | Central service, Desk resolver update, link verification |
| Unknowns | Cross-site email overlap; link UX |

### Option 2 — Central Group Customer ID first, then central wallet

| Dimension | Assessment |
|-----------|------------|
| Migration complexity | **High** — 770k+ combined user rows, verification gaps |
| Security | Single identity surface |
| Fraud risk | Wrong merge catastrophic |
| Customer experience | Best long-term UX |
| Protected functionality | High risk during identity cutover |
| Rollback | Difficult once merges occur |
| Reconciliation | Simpler after merge |
| Dependencies | IdP, collision policy, SSO per site |
| Unknowns | Phone collisions; guest upsert patterns |

### Option 3 — Hybrid: Central Wallet ID now + Group Identity/SSO later

| Dimension | Assessment |
|-----------|------------|
| Migration complexity | **Medium** — phased |
| Security | Balanced |
| Fraud risk | Linking risk now; merge risk deferred |
| Customer experience | Improves balance first; login unification later |
| Protected functionality | **Best fit** for current Desk→order→userid seam | INFERRED |
| Rollback | Per-phase flags |
| Reconciliation | Parallel run period required |
| Dependencies | Option 1 + future IdP |
| Unknowns | When/how to sunset local ledgers |

**Verified alignment:** Desk already credits **per-site wallet ledgers** via order prefix routing — Option 1/3 extend rather than replace this pattern initially.

---

## 11. Pilot-site factual considerations

*No ranking — suitability factors and Owner decision points only.*

### radiumbox.com

| Factor | Evidence |
|--------|----------|
| Wallet capability | `users_wallet` + `users_radium_wallet`; checkout split tender | VERIFIED |
| Desk integration | Credit API live; reversal absent; ledger read API live | VERIFIED |
| API maturity | Idempotent credit; token auth; prod credits exist | VERIFIED |
| Refund integration | Desk flag ON; 4 prod Desk credits | VERIFIED |
| Rollback | Feature flag on Desk side | VERIFIED |
| Operational risk | Dual wallet ledgers + cached balance + pending debits | VERIFIED |
| **Owner decision** | Include `users_radium_wallet`? Deploy reversal API? | Required |

### rdservice.in

| Factor | Evidence |
|--------|----------|
| Wallet capability | Refund-credit ledger only (no checkout wallet) | VERIFIED |
| Desk integration | Credit + reversal APIs live; flags ON | VERIFIED |
| API maturity | Strongest idempotency (`source_system`, reversal keys) | VERIFIED |
| Refund integration | 51 Desk credits; 2 reversals in prod | VERIFIED |
| Rollback | Desk flags + spoke routes | VERIFIED |
| Operational risk | Lower checkout coupling | VERIFIED |
| **Owner decision** | Pilot primary site for refund-only wallet? | Required |

### rdservice.net

| Factor | Evidence |
|--------|----------|
| Wallet | Schema only; Desk blocks wallet approval | VERIFIED Phase 1 |
| **Owner decision** | Defer until wallet schema + API exist | INFERRED |

### radiumsign.com / rdserviceonline.in

| Factor | Evidence |
|--------|----------|
| Wallet | None | VERIFIED |
| **Owner decision** | Out of initial pilot unless scope expands | INFERRED |

### Customer volume (safe counts only)

| Site | User rows |
|------|----------:|
| radiumbox.com | 516,800 |
| rdservice.in | 253,195 |

---

## 12. Security boundary (future central wallet requirements)

Architecture requirements only — **not implementation tasks**:

1. **No client-authoritative balance** — balance computed server-side from ledger
2. **Server-side immutable ledger** — append-only entries with typed credit/debit
3. **Immutable transaction IDs** — globally unique, never reused
4. **Idempotency keys** — on all credit/debit/reversal APIs (pattern: `desk_refund_reference`)
5. **Authorization** — customer can only read own balance; writes via service accounts
6. **Service authentication** — Bearer/HMAC between Desk and central wallet (extend current pattern)
7. **Replay protection** — unique constraints + request key TTL where applicable
8. **Concurrency control** — `lockForUpdate` / row locks (verified on box credit path)
9. **Audit trail** — who/when/why for every mutation
10. **Reconciliation** — daily tie-out between central ledger, spoke projections, Desk `refund_requests`
11. **Reversal semantics** — separate debit row; preserve original credit; fail-closed balance check (rdin pattern)
12. **Least-privilege DB access** — cross-schema denial is correct; central service uses dedicated credentials

---

## 13. VERIFIED / INFERRED / UNKNOWN summary

### VERIFIED

- Wallet-refund APIs deployed and auth-gated on box + rdservice.in
- Desk wallet credit automation enabled for both spokes
- Desk wallet reversal enabled for rdservice.in only
- Production Desk wallet credits: box 4, rdin 51; rdin reversals 2
- Zero within-DB duplicate emails (both production DBs)
- Email unique index; phone not unique
- `users_radium_wallet` production-active on radiumbox.com
- Production wallet service files match local workspace SHA-256
- Cross-DB SELECT denied between spoke DB users

### INFERRED

- Option 3 (hybrid) best matches current integration seams
- `users_radium_wallet` should default out of central-wallet pilot scope
- Cached `wallet_amount` can drift if non-Desk writers skip refresh
- Email fallback on box is a wrong-wallet edge case

### UNKNOWN

- Cross-site normalized email overlap count
- Deleted-account / email reuse behaviour
- Whether all historical wallet writes refreshed cache
- Mobile app identity requirements
- Owner approval of any pilot option

---

## 14. Owner decisions required

| # | Decision |
|---|----------|
| 1 | Pilot option: 1, 2, or 3 (or hybrid variant) |
| 2 | Pilot site(s): rdin only vs rdin+box vs box-first |
| 3 | Include `users_radium_wallet` in central wallet scope? |
| 4 | Require radiumbox.com reversal API before box pilot? |
| 5 | Cross-DB email overlap study — grant read-only cross-schema SELECT to a dedicated audit user? |
| 6 | Parallel-run duration and reconciliation SLA |
| 7 | Minimum identity verification for wallet account linking |

---

## 15. Recommended next gate

1. **Owner-authorized cross-DB overlap query** — single `COUNT` via privileged read-only DB user (no email export), or hashed-email intersection in a secure batch job.
2. **Pilot charter document** — select Option 1/3, pilot site, and explicit exclusion of `users_radium_wallet`.
3. **Parallel-run design** — Desk credits central + spoke during transition; reconciliation playbook before any write switch.
4. **Do NOT create central wallet repository** until Owner signs pilot charter.

---

## Appendix A — Evidence index

| Evidence | Location |
|----------|----------|
| Box wallet routes | `/var/www/radiumbox.com/routes/api.php` |
| rdin wallet routes | `/var/www/rdservice.in/routes/api.php` |
| Desk flags | `/var/www/radium-desk/.env` keys (names only in §4.3) |
| Auth middleware | `radiumbox.com/app/Http/Middleware/AuthenticateDeskIntegration.php` |
| Credit service | `*/app/Services/Integrations/DeskWalletRefundCreditService.php` |
| Reversal service | `rdservice.in/app/Services/Integrations/DeskWalletRefundReversalService.php` |
| Desk executor | `radium-desk/app/Services/Refunds/WalletRefundExecutor.php` |
| Radium Money checkout | `radiumbox.com/app/Http/Controllers/Orders/PaymentController.php` |
| Admin Radium Money | `Admin/app/Http/Controllers/Customer/RadiumWalletController.php` |

---

## Appendix B — Inspection SHAs

| Project | SHA |
|---------|-----|
| radium-desk (before) | `35a747100ec2211b249a18696bc46112bdb6a417` |
| radiumbox.com | `116990ababede743647e20c4c3138d1b01dbb1fb` |
| rdservice.in | `bcb380ac009e4949346ba332d0eb8eef7e969bd9` |

---

*End of Phase 2 production discovery. No application code, wallet balances, refunds, or infrastructure were modified.*
