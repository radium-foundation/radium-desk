# Central Wallet — TYPE-1 Customer-Level Identity Ceremony Plan

**Prompt ID:** `RadiumDesk-P-30-10-01`  
**Date:** 2026-10-01  
**Mode:** Identity ceremony planning only — **no financial migration**

---

## Executive summary

TYPE-1 refunds (business identity complete, trusted identity missing) collapse from **211 refunds** into **210 unique customers**.

| Metric | Value |
|---|---|
| TYPE-1 refunds | **211** |
| TYPE-1 amount | **₹115,126.00** |
| Unique customers | **210** |
| M2 ceremony eligible | **210** |
| Already trusted | **0** |
| Identity conflicts | **0** |
| Insufficient | **0** |
| Other | **0** |

**Key finding:** The 211-refund identity blocker is **not** 211 separate customer mysteries. It is **210 customer-level M2 ceremonies** (one customer has 2 refunds). Financial migration remains a **separate gate** after identity resolution.

---

## 1. TYPE-1 population verification

| Check | Result |
|---|---|
| Source | `cw-owner-identity-cases-p30-09-31.json` Group B |
| Refund count | **211** ✓ |
| Amount | **₹115,126.00** ✓ |
| Duplicate refund IDs | **0** |
| Missing vs Group B manifest | **0** |
| Extra refunds | **0** |

---

## 2. Customer grouping method

Primary deterministic chain:

```
refund → order → spoke → local_user_id
```

Grouping key: `(site, local_user_id)` — **not** email, mobile, name, or wallet ID.

| Rule | Applied |
|---|---|
| Merge by email/mobile alone | **NO** |
| Merge by name alone | **NO** |
| Merge by wallet ID alone | **NO** |
| Cross-user email/phone collision flag | **YES** (0 collisions found) |

---

## 3. Unique customer count

```
211 refunds → 210 unique customers
```

| Refunds per customer | Customers |
|---:|---:|
| 1 refund | 209 |
| 2 refunds | 1 |
| 3+ refunds | 0 |

**Multi-refund customer:**

| Site | Local user | Name | Refunds | Amount | Refund IDs |
|---|---|---:|---:|---|---|
| rdservice.in | 535731 | Smarajit Mahata | 2 | ₹1,216.00 | 244, 245 |

---

## 4. Site distribution

| Site | Customers | Refunds |
|---|---:|---:|
| rdservice.in | 208 | 209 |
| radiumbox.com | 2 | 2 |

---

## 5. Identity consistency

All **210** customer groups: **IDENTITY_CONSISTENT**

| Check | Result |
|---|---|
| Multiple local users in one group | 0 |
| Order user ≠ spoke user within group | 0 |
| Email shared across different local users | 0 |
| Phone shared across different local users | 0 |

---

## 6. M2 ceremony eligibility

For every TYPE-1 customer:

| Field | Value |
|---|---|
| M2 ceremony available | **YES** (210/210) |
| Current auth state | `google_id=null`, `email_verified_at=null` (typical) |
| Required customer action | Complete M2 ceremony on spoke site |
| Expected credential | Verified Google subject **or** verified email |
| Desk Customer ID | `PROVISION_ON_CEREMONY_COMPLETION` |
| CWID | `PROVISION_ON_CEREMONY_COMPLETION` |
| Financial action | **NONE** |

**Policy enforced:** Raw email/mobile on order or spoke records are **not** converted to trusted identity.

---

## 7. Customer-level outcome classification

| Status | Customers |
|---|---:|
| READY_FOR_IDENTITY_CEREMONY | **210** |
| ALREADY_TRUSTED | 0 |
| IDENTITY_CONFLICT | 0 |
| INSUFFICIENT | 0 |
| OTHER | 0 |

---

## 8. Proposed customer-level flow

For each eligible customer:

```
Known business customer (spoke + local_user_id)
        ↓
Customer performs M2 trusted identity ceremony
        ↓
Trusted credential established (Google or verified email)
        ↓
Desk Customer ID created/resolved
        ↓
CWID provisioned (controlled, no ledger write)
        ↓
All associated TYPE-1 refunds → identity-resolved
        ↓
Separate migration readiness review (financial gate)
```

**Financial rule:** A customer with 10 refunds / ₹5,000 total establishes **identity only**. Ceremony does **not** credit ₹5,000, debit wallets, or create ledger entries.

---

## 9. REF-2026-000217 analog (P-30-09-32)

REF-2026-000217 (rdin user **534268**, order RD3490575) is **not** in the 292 wallet cohort but demonstrates the TYPE-1 pattern. Adjacent wallet cohort case **REF-2026-000218** (rdin user **540026**) is in this TYPE-1 plan.

---

## 10. Summary table (all 210 customers)

Full machine-readable list:

| Artifact | Path |
|---|---|
| JSON (one record per customer) | `storage/app/private/cw-type1-customer-ceremony-plan-p30-10-01.json` |
| CSV | `storage/app/private/cw-type1-customer-ceremony-plan-p30-10-01.csv` |

**Aggregate table:**

| Unique customers | Refund count | Total amount | Site mix | M2 status |
|---:|---:|---:|---|---|
| **210** | **211** | **₹115,126** | rdin 208, box 2 | **210 eligible** |

---

## 11. Financial safety

| Invariant | Before | After |
|---|---|---|
| `central_wallet_ledger_entries` | 0 | **0** |
| Central wallet balances | unchanged | **unchanged** |
| Spoke wallet balances | unchanged | **unchanged** |
| Refund rows | unchanged | **unchanged** |
| Financial variance | — | **₹0** |

---

## 12. Execution gate

```
EXECUTION READY = NO
Financial migration: NOT PERFORMED
```

Next step: determine whether **210 customer identity ceremonies** (not 211 refund decisions) is a manageable resolution path before any financial migration gate.

---

## Artifacts

| File | Purpose |
|---|---|
| `storage/app/private/cw-type1-customer-ceremony-plan-p30-10-01.json` | Customer-level ceremony plan |
| `storage/app/private/cw-type1-customer-ceremony-plan-p30-10-01.csv` | CSV export |
| `storage/app/private/cw-type1-customer-ceremony-plan-p30-10-01.py` | Read-only generator (KVM8) |
