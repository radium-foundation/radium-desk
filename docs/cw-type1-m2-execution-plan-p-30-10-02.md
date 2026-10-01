# Central Wallet — TYPE-1 M2 Ceremony Execution Plan

**Prompt ID:** `RadiumDesk-P-30-10-02`  
**Date:** 2026-10-01  
**Mode:** Identity ceremony planning only — **no financial migration**

---

## Executive summary

| Metric | Value |
|---|---|
| TYPE-1 refunds | **211** / **₹115,126** |
| Unique customers | **210** |
| Ceremony mechanism (all 210) | **M2_EXISTING_CEREMONY** (WhatsApp OTP) |
| Currently cohort-eligible | **50** customers / **50** refunds / **₹26,230** |
| Blocked by cohort allowlist | **160** customers / **161** refunds / **₹88,896** |

**Implementation decision:** `SMALL_NON_FINANCIAL_EXTENSION_REQUIRED`

M2 Connect Wallet exists and is production-tested. Gaps are operational (cohort allowlist) and procedural (Desk Customer ID follow-up after ceremony), not a new identity flow.

---

## 1. Existing M2 ceremony map (production code)

### Spoke entry points

| Site | URL | Controller | Verification |
|---|---|---|---|
| **rdservice.in** | `/connect-wallet` | `ConnectWalletController` | `m2_whatsapp_otp` (Interakt WhatsApp) |
| **radiumbox.com** | `/user/wallet/connect` | `ConnectWalletController` | `m2_dual_otp` (Interakt WhatsApp) |

### Customer flow (existing)

```
1. Customer logs into spoke site (session required)
2. Opens Connect Wallet page
3. Initiates ceremony → OTP sent to registered mobile
4. Customer enters OTP on spoke site
5. Spoke issues HMAC ceremony_verification_ref (TTL 300s, single-use jti)
6. Spoke calls Desk POST /api/central-wallet/v1/ceremony/complete
7. Desk: validates proof → creates/resolves CWID → active account link
8. Audit events: ceremony.wallet_created | ceremony.linked
```

**Financial effect at step 7:** **NONE** (no ledger, no wallet debit/credit)

### Desk API

| Endpoint | Service | Creates CWID | Creates Desk Customer |
|---|---|:---:|:---:|
| `POST /ceremony/complete` | `CeremonyCompleteService` | Yes | **No** |
| `POST /customer-identity/resolve` | `CustomerIdentityResolveService` | Yes | Yes |
| CLI `central-wallet:establish-customer-from-ceremony` | `CustomerFoundationFromCeremonyService` | No | Yes |

### Alternate trusted paths (not primary for TYPE-1 today)

| Path | Trigger | Requires |
|---|---|---|
| **Google Sign-In** | `GoogleController` → `CentralWalletTrustedIdentityLinkService` | `google_id` on spoke user + `CUSTOMER_IDENTITY_ENABLED` |
| **Verified email** | Same service | `email_verified_at` on spoke user + flag |

All **210** TYPE-1 customers currently have `google_id=null` and `email_verified_at=null` → primary path is **M2 WhatsApp OTP**.

### Idempotency (existing)

| Layer | Mechanism |
|---|---|
| Proof replay | `central_wallet_ceremony_proof_consumptions.jti` unique |
| API idempotency | `central_wallet_idempotency_records` per caller+key |
| Active link | Partial unique index `(site_code, local_user_id)` |
| Repeat ceremony | Returns existing CWID (`resolved_local_link`, HTTP 200) |
| Desk Customer CLI | `establishFromCeremony` idempotent if customer exists |

### Production flags (verified, values redacted where secret)

| Flag | Desk | rdservice.in | radiumbox.com |
|---|---|---|---|
| `CENTRAL_WALLET_ENABLED` | **true** | — | — |
| `CENTRAL_WALLET_API_ENABLED` | **true** | — | — |
| `CENTRAL_WALLET_ACCOUNT_LINKING_ENABLED` | — | **true** | **true** |
| `CENTRAL_WALLET_ACCOUNT_LINKING_COHORT_ENABLED` | — | **true** | **true** |
| Cohort size | — | **55** users | **2** users |
| `CENTRAL_WALLET_REFUND_MIGRATION_EXECUTION_ENABLED` | **false** | — | — |

**160/210 TYPE-1 customers are NOT in the current spoke cohort allowlists.**

---

## 2. Customer action classification

| Action | Customers | Refunds | Amount (₹) |
|---|---:|---:|---:|
| **M2_EXISTING_CEREMONY** (cohort-eligible) | 50 | 50 | 26,230 |
| **BLOCKED** (not in cohort) | 160 | 161 | 88,896 |
| GOOGLE_SIGN_IN | 0 | 0 | 0 |
| VERIFIED_EMAIL | 0 | 0 | 0 |
| MANUAL_OWNER_REVIEW | 0 | 0 | 0 |

### Ceremony method (identity mechanism)

| Method | Customers | Refunds | Amount (₹) |
|---|---:|---:|---:|
| M2_EXISTING_CEREMONY | 210 | 211 | 115,126 |

---

## 3. Site grouping

| Site | Customers | Refunds | Amount (₹) | Ceremony |
|---|---:|---:|---:|---|
| **rdservice.in** | 208 | 209 | 114,409 | `m2_whatsapp_otp` |
| **radiumbox.com** | 2 | 2 | 717 | `m2_dual_otp` |

---

## 4. Customer experience (existing vs required)

| Question | Answer |
|---|---|
| Must customer log in? | **Yes** — spoke session required |
| Which site? | Same site as `local_user_id` (rdin or box) |
| Which account? | Spoke user ID from TYPE-1 plan |
| Google Sign-In establish trusted identity? | **Yes, if** customer later links Google (alternate path) |
| Verified email establish trusted identity? | **Yes, if** customer verifies email (alternate path) |
| Email OTP implemented? | **No** — M2 uses **WhatsApp/mobile OTP** via Interakt |
| Mobile OTP implemented? | **Yes** — WhatsApp OTP on Connect Wallet |
| After successful verification? | CWID + active account link on Desk; spoke shows connected |
| Manual "Connect Wallet"? | **Yes** — customer initiates via Connect Wallet UI |
| Affects money? | **No** — identity only |

---

## 5. Bulk / batch safety

| Approach | Allowed? |
|---|---|
| **A. Individual customer sessions** | **YES** — recommended |
| B. Grouped admin sessions without OTP | **NO** |
| C. Invitation/link to Connect Wallet | **YES** — after cohort add |
| D. Admin bypass of identity proof | **NO** |

No batch operation may establish identity without the customer's trusted OTP action.

---

## 6. Post-ceremony refund association (identity only)

Per customer after successful ceremony + Desk Customer establishment:

```
spoke local_user_id
  → CWID (from ceremony/complete)
  → Desk Customer ID (from establish-from-ceremony)
  → TYPE-1 refund_ids associated in manifest
  → migration_status review (separate financial gate)
```

**No ledger entries. No spoke wallet debits. No CW credits.**

Example multi-refund customer (blocked until cohort add):

| Customer | Refunds | Amount |
|---|---:|---:|
| rdin **535731** Smarajit Mahata | 244, 245 | ₹1,216 |

---

## 7. Failure handling (no financial effect in any path)

| Failure | Behavior |
|---|---|
| OTP not completed | No CWID, no Desk Customer, no financial effect |
| Google unavailable | Customer uses M2 WhatsApp OTP instead |
| Email verification unavailable | Customer uses M2 OTP (primary path) |
| Credential belongs to another customer | 409 conflict; Owner review |
| Identity conflict | Ceremony blocked; Owner review |
| Customer retries | Idempotent if link exists |
| Customer changes email | Does not auto-trust; ceremony uses verified phone |
| Cross-site login | Cross-site ceremony disabled on production |
| Proof expires (300s) | Retry ceremony |
| Multiple refunds same customer | Single ceremony resolves all associated refunds |

---

## 8. Owner control

| Control | Status |
|---|---|
| Production-wide ceremony enable | **Not enabled** — cohort fail-closed |
| Cohort allowlist modification | **Owner gate required** for 160 blocked customers |
| `REFUND_MIGRATION_EXECUTION_ENABLED` | **false** |
| Financial execution | **OFF** |

**Do not enable production-wide flags without explicit Owner approval.**

---

## 9. Implementation decision

### `SMALL_NON_FINANCIAL_EXTENSION_REQUIRED`

**Why not `EXISTING_M2_SUFFICIENT`:**

1. **160/210 customers blocked** by spoke `ACCOUNT_LINKING_COHORT` allowlist
2. **Desk Customer ID** not auto-created by `ceremony/complete` — requires CLI `establish-from-ceremony` or small extension
3. **Progress tracking** for 210-customer rollout not yet automated

**Why not `NEW_IDENTITY_FLOW_REQUIRED`:**

M2 Connect Wallet + Desk `ceremony/complete` + idempotency + audit already exist and are tested.

### Smallest isolated extensions (non-financial)

1. **Owner-controlled cohort expansion** — add 160 TYPE-1 `local_user_id` values to spoke allowlists (config only, phased)
2. **Ceremony progress manifest** — track per-customer status (this artifact + optional CLI report)
3. **Optional:** call `CustomerFoundationFromCeremonyService` after successful ceremony (or document batch CLI step) — still no financial writes
4. **Optional:** customer notification template pointing to Connect Wallet (operational, not code)

**Not in scope:** wallet credit, debit, migration execution, financial flags.

---

## 10. Proposed customer-level flow

```
Known TYPE-1 customer (210 groups)
        ↓
Owner adds to cohort allowlist (phased)
        ↓
Customer logs into spoke → Connect Wallet
        ↓
WhatsApp OTP verification (customer action)
        ↓
Desk ceremony/complete → CWID + account link
        ↓
Desk establish-customer-from-ceremony → Desk Customer ID
        ↓
TYPE-1 refunds identity-resolved in manifest
        ↓
Separate Owner financial migration gate
```

---

## 11. Financial safety

| Invariant | Result |
|---|---|
| `central_wallet_ledger_entries` | **0** (unchanged) |
| Financial variance | **₹0** |
| Spoke wallets | unchanged |
| Refund rows | unchanged |

---

## Artifacts

| File | Purpose |
|---|---|
| `storage/app/private/cw-type1-m2-execution-plan-p30-10-02.json` | Per-customer execution record |
| `storage/app/private/cw-type1-m2-execution-plan-p30-10-02.csv` | CSV export |
| `storage/app/private/cw-type1-m2-execution-plan-p30-10-02.py` | Read-only generator |

---

## Execution gate

```
EXECUTION READY = NO
Financial migration: NOT PERFORMED
```

Remaining blocker: **Owner cohort expansion** for 160 customers before ceremonies can begin at scale.
