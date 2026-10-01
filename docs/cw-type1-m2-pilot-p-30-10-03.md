# Central Wallet — TYPE-1 M2 Pilot (50-Customer Cohort)

**Prompt ID:** `RadiumDesk-P-30-10-03`  
**Date:** 2026-10-01  
**Mode:** Identity-only pilot — **no financial migration**

---

## Executive summary

| Metric | Value |
|---|---|
| Pilot cohort | **50** customers / **50** refunds / **₹26,230** |
| **PILOT_SUCCESS** | **NO** |
| Successful | **0** customers / **0** refunds / **₹0** |
| Pending customer action | **50** customers / **50** refunds / **₹26,230** |
| Blocked (system) | **0** |

All 50 cohort-eligible customers remain at `PILOT_PENDING_CUSTOMER_ACTION`. None have completed the M2 Connect Wallet WhatsApp OTP ceremony on production. **Customer OTP cannot be automated or bypassed** — the pilot infrastructure is validated and ready, but identity resolution requires genuine customer action.

---

## 1. Pre-change verification

| Field | Value |
|---|---|
| Repository | `radium-foundation/radium-desk` |
| Branch | `feat/direct-ledger-debit-gate` |
| Server | KVM8 `srv1910783` / `187.127.129.16` |
| Desk release | v4.0.168 (`013ec2c6`) |
| Desk DB | `radium_desk` |
| rdservice.in DB | `rdservice_in_prod` |
| RadiumBox DB | `radiumbox_prod` |

---

## 2. Pilot cohort verification

Source: `cw-type1-m2-execution-plan-p30-10-02.json` — `READY_M2_CEREMONY` + `in_account_linking_cohort=true`

| Check | Result |
|---|---|
| Unique customers | **50** ✓ |
| Refunds | **50** ✓ |
| Amount | **₹26,230.00** ✓ |
| Site | **rdservice.in only** (all 50) |
| Blocked customers included | **0** ✓ |
| Additional customers added | **0** ✓ |

---

## 3. Safety gate (verified before pilot)

| Flag / invariant | Status |
|---|---|
| `CENTRAL_WALLET_REFUND_MIGRATION_EXECUTION_ENABLED` | **false** (unset/false) |
| `central_wallet_ledger_entries` | **0** (before and after) |
| Ledger sum | **₹0.00** |
| Financial variance | **₹0** |

---

## 4. Special case checks

| Case | In pilot? | Result |
|---|---|---|
| rdin user **535731** (REF-244/245, ₹1,216) | **NO** | Correctly excluded — `BLOCKED_COHORT` |
| RadiumBox customers (2 in TYPE-1 total) | **NO** | Not in current 50-user rdin cohort allowlist |

---

## 5. Pilot execution model

For each of the 50 customers, the approved flow is:

```
1. Customer logs into rdservice.in (session)
2. Customer opens /connect-wallet
3. Customer completes WhatsApp OTP (Interakt)
4. Spoke issues HMAC proof → Desk ceremony/complete
5. CWID + active account link created
6. Operator runs: central-wallet:establish-customer-from-ceremony
7. Desk Customer ID resolved
8. TYPE-1 refund associated (identity readiness only)
```

**Steps 1–4 require the actual customer.** No admin bypass, synthetic OTP, or impersonation was performed.

---

## 6. Per-customer results

| Status | Count | Refunds | Amount |
|---|---:|---:|---:|
| `PILOT_SUCCESS` | 0 | 0 | ₹0 |
| `PILOT_PENDING_CUSTOMER_ACTION` | 50 | 50 | ₹26,230 |
| `PILOT_BLOCKED` | 0 | 0 | ₹0 |

**Failure reason (all 50 pending):** `customer_must_complete_connect_wallet_whatsapp_otp`

Full per-customer records: `storage/app/private/cw-type1-m2-pilot-p30-10-03.json`

---

## 7. Idempotency (validated for cohort with zero completions)

| Check | Result |
|---|---|
| Duplicate Desk Customer IDs | **0** |
| Duplicate CWIDs | **0** |
| Credential collisions | **0** |

When customers complete ceremony, `establish-from-ceremony` is idempotent per existing tests.

---

## 8. Financial invariants (after pilot assessment)

| Invariant | Before | After |
|---|---|---|
| `central_wallet_ledger_entries` | 0 | **0** |
| Central wallet balances | unchanged | **unchanged** |
| Spoke wallet balances | unchanged | **unchanged** |
| Refund rows/statuses | unchanged | **unchanged** |
| CW migration credits | 0 | **0** |
| Spoke debits/credits | 0 | **0** |

---

## 9. Operational next steps (post-pilot, Owner-gated)

1. **Notify cohort customers** to complete Connect Wallet at rdservice.in (no code change required)
2. **Re-run** `cw-type1-m2-pilot-p30-10-03.py` after ceremonies to auto-invoke `establish-from-ceremony` for completed users
3. **Re-assess** PILOT_SUCCESS when 50/50 reach `PILOT_SUCCESS`
4. **Do not** expand to 160 blocked customers until independent pilot review

---

## 10. Cohort expansion

**NOT performed.** The 160 blocked customers (₹88,896) remain outside allowlists.

---

## Artifacts

| File | Purpose |
|---|---|
| `storage/app/private/cw-type1-m2-pilot-p30-10-03.json` | Per-customer pilot state |
| `storage/app/private/cw-type1-m2-pilot-p30-10-03.csv` | CSV export |
| `storage/app/private/cw-type1-m2-pilot-p30-10-03.py` | Assessment + safe establish script |

---

## Execution gate

```
PILOT_SUCCESS = NO
EXECUTION READY = NO
Financial migration: NOT PERFORMED
```
