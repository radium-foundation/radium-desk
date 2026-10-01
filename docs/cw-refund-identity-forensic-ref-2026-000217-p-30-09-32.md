# Central Wallet — Forensic Identity Trace: REF-2026-000217

**Prompt ID:** `RadiumDesk-P-30-09-32`  
**Date:** 2026-10-01  
**Mode:** Read-only identity/data trace — **no financial migration**

---

## Executive summary

REF-2026-000217 demonstrates that **business customer identity is deterministically identifiable** from existing Desk/order/spoke records, while **trusted Central Wallet identity is not yet established**.

**Important cohort note:** REF-2026-000217 is **not** in the 292 terminal wallet-refund migration population (`approved_refund_method = cashfree`, not `wallet`). It was refunded to original payment method and is **closed**. The forensic methodology still answers the identity question; the adjacent wallet cohort case **REF-2026-000218** (refund id 218, `OWNER_IDENTITY_REQUIRED`, Group B) shares the same identity pattern.

**Primary conclusion:** For the majority of the 270 `OWNER_IDENTITY_REQUIRED` cases (**211 / ₹115,126**, TYPE-1), the blocker is **trusted identity establishment**, not missing business customer information.

---

## 1. Environment

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

## 2. Refund trace — REF-2026-000217

| Field | Value |
|---|---|
| Refund request ID | **217** |
| Reference | **REF-2026-000217** |
| Amount | **₹381.00** |
| Status | **closed** |
| Approved refund method | **cashfree** |
| Customer preferred method | **opm** |
| Execution reference | **REF-2026-000217** |
| Execution transaction ID | **NULL** (cashfree/manual completion) |
| Case / incident ID | **39238** (SC39278) |
| Desk order PK | **38229** |
| Order number | **RD3490575** |
| Order prefix / site | **RD*** → **rdservice.in** |
| Created | 2026-08-21 18:23:46 IST |
| Completed / closed | 2026-08-22 12:08:56 IST |
| In 292 wallet migration cohort | **NO** |

---

## 3. Order identity

| Source | Field | Value | Trust level |
|---|---|---|---|
| Desk.orders.order_id | order_number | RD3490575 | TRUSTED (order key) |
| Desk.orders.customer_name | customer_name | Sonu Kumar | UNVERIFIED |
| Desk.orders.customer_email | customer_email | ssonukumar75957@gmail.com | UNVERIFIED |
| Desk.orders.customer_phone | customer_phone | 8178576391 | UNVERIFIED |
| Desk.orders.customer_id | customer_id | NULL | ABSENT |
| Desk.orders.customer_*_locked | lock flags | all NULL | — |
| Desk.orders.payment_method | payment | UPI / Cashfree | TRUSTED (payment fact) |
| Desk.orders.cashfree_payment_id | gateway ref | 6243484826 | TRUSTED (payment fact) |

No billing/shipping identity tables on Desk `orders`; customer identity is stored on the order row itself.

**Order owner relationship:** Desk order has no `customer_id` FK. Ownership is established via spoke `order_rdservice.userid` (see §5).

---

## 4. Case / refund identity

### RefundRequest (no direct customer FK)

| Field | Value |
|---|---|
| order_id | 38229 → orders |
| incident_id | 39238 → incidents |
| requested_by | 8 (staff: Sushant Shetty) |
| reviewed_by / executed_by | 3 (staff: Shipra) |
| reason | "please refund reminig amount" |
| requester_remarks | customer recharge with other website so please refund 381rs |

### Incident (case)

| Field | Value |
|---|---|
| reference_no | SC39278 |
| source | cashfree |
| title | Cashfree payment — RD3490575 |
| order_record_id | 38229 |
| recovery_phone | NULL |
| customer FK | **none** |

### Audit trail (selected events)

| Event | Timestamp |
|---|---|
| refund.requested | 2026-08-21 18:23:53 |
| refund.approved (cashfree) | 2026-08-22 12:08:43 |
| refund.completed | 2026-08-22 12:08:56 |
| refund.closed | 2026-08-22 12:08:56 |

**Finding:** Refund identity is mediated entirely through `order_id` and `incident_id`. There is **no** `customer_id` or `user_id` on `refund_requests`.

---

## 5. Spoke identity (rdservice.in)

| Field | Value |
|---|---|
| Local user ID | **534268** |
| Name | Sonu Kumar |
| Email | ssonukumar75957@gmail.com |
| Email verified | **NULL** (unverified) |
| Mobile | 8178576391 |
| Google ID | **NULL** |
| Order ownership | `order_rdservice.rdorderid=RD3490575` → `userid=534268` |
| Wallet | **no** `users_wallet` rows for this user |
| Desk/spoke alignment | name, email, phone **match** Desk order |

**Deterministic connection:** RD* prefix → rdservice.in; `order_rdservice.userid` is authoritative for order ownership. This is **business identity**, not trusted CW identity.

---

## 6. Authentication / trusted identity signals

| Signal | Value | Classification |
|---|---|---|
| Google provider subject | NULL | ABSENT |
| Verified email credential | NULL (`email_verified_at` null) | ABSENT |
| Verified mobile credential | NULL | ABSENT |
| Desk Customer ID | NULL | ABSENT |
| CWID | NULL | ABSENT |
| central_wallet_account_link | 0 rows for rdservice.in:534268 | ABSENT |
| central_wallet_ceremony_identity | 0 rows | ABSENT |
| Raw email equality (Desk ↔ spoke) | match | **UNVERIFIED** (not proof) |
| Raw mobile equality (Desk ↔ spoke) | match | **UNVERIFIED** (not proof) |

---

## 7. Identity chain

```
REFUND (217)          → ESTABLISHED
  ↓
CASE (SC39278)        → ESTABLISHED
  ↓
ORDER (RD3490575)     → ESTABLISHED
  ↓
ORDER CUSTOMER        → ESTABLISHED (Desk fields + rdin userid 534268)
  ↓
SPOKE ACCOUNT (534268)→ ESTABLISHED
  ↓
TRUSTED IDENTITY      → MISSING
  ↓
DESK CUSTOMER         → MISSING
  ↓
CWID                  → MISSING
```

---

## 8. Critical questions

### A. Is the customer deterministically identifiable from existing business records?

**YES.**

Evidence: refund → order 38229 → RD3490575 → `order_rdservice.userid=534268` with consistent name/email/phone across Desk and spoke.

### B. What exact evidence establishes the customer?

1. `refund_requests.order_id = 38229`
2. `orders.order_id = RD3490575` (RD* → rdservice.in)
3. `order_rdservice.userid = 534268`
4. Matching `Sonu Kumar` / `ssonukumar75957@gmail.com` / `8178576391` on Desk order and spoke user

### C. Is the missing piece merely trusted identity establishment?

**YES** (for Central Wallet purposes). Business customer is known; trusted credential is absent.

### D. Could M2 ceremony safely establish identity?

**YES**, subject to customer action: ceremony must yield verified Google subject or verified email. Raw email/phone on records alone cannot auto-provision.

### E. After ceremony, could the same Customer ID/CWID receive the refund?

**YES in principle** for wallet migration cases with this pattern. REF-2026-000217 itself is **cashfree-completed** and outside the wallet migration cohort.

### F. What prevents automatic provisioning today?

1. Policy: trusted credential required (verified Google / verified email / completed ceremony)
2. `google_id = NULL`, `email_verified_at = NULL` on spoke user 534268
3. No Desk Customer, CWID, account link, or ceremony record
4. This specific refund is not a wallet migration candidate

---

## 9. Statement accuracy (from REF-2026-000217 evidence)

### "Email/mobile/order information exists, therefore Central Wallet ownership is already proven."

**FALSE** for this case.

Order email and phone exist but are **unverified**. No Google subject, no verified credential, no CWID. Co-occurrence of contact fields does not prove CW ownership.

### "Email/mobile/order information exists, therefore the customer is identifiable but still requires trusted identity establishment."

**TRUE** for this case.

Business identity is deterministic via order/spoke ownership chain; trusted identity establishment remains the gap.

---

## 10. 270-case population comparison

Based on `cw-owner-identity-cases-p30-09-31.json` using P-30-09-31 evidence rules:

| Type | Count | Amount (₹) | Sample IDs | Reason |
|---|---:|---:|---|---|
| **TYPE-1** Business complete; trusted missing | **211** | 115,126 | 2, 6, 7, 218 | Group B: order/spoke user linked; ceremony required |
| **TYPE-2** Trusted identity available | **2** | 998 | 271, 275 | Group A: Google + verified email present |
| **TYPE-3** Multiple customers conflict | **5** | 2,917 | 281, 298, 329 | Group E: wallet/order user mismatch |
| **TYPE-4** Insufficient business identity | **52** | 34,517 | 1, 3, 4, 5 | Group D: no usable order/spoke path |
| **TYPE-5** Other | **0** | 0 | — | — |

**Separate:** 3 `OWNER_RESOLUTION_REQUIRED` refunds (263, 265, 266) with circular mismatch — not in the 270 identity-case set.

**REF-2026-000217 analog in cohort:** REF-2026-000218 (wallet, ₹850, rdin user 540026, Group B / TYPE-1).

---

## 11. Financial safety

| Check | Result |
|---|---|
| `central_wallet_ledger_entries` | 0 (unchanged) |
| Ledger sum | ₹0.00 |
| Spoke wallet balances | unchanged |
| Refund records | unchanged |
| Financial variance | **₹0** |

---

## Artifacts

| File | Purpose |
|---|---|
| `storage/app/private/cw-ref-2026-000217-identity-trace.json` | Machine-readable trace |
| `storage/app/private/cw-owner-identity-cases-p30-09-31.json` | 270-case comparison source |

---

## Execution gate

```
EXECUTION READY = NO
Financial migration: NOT PERFORMED
```
