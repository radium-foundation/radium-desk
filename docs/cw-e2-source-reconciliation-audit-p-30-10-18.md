# E-2 Source Reconciliation Audit — 52 IDENTITY_REQUIRED Refunds

**Prompt ID:** `RadiumDesk-P-30-10-18`  
**Date:** 2026-10-01  
**Mode:** Read-only source reconciliation — **no financial mutations**

---

## Executive summary

Read-only source reconciliation was performed on the **52-refund E-2 sub-cohort** from the P-30-10-16/P-30-10-17 IDENTITY_REQUIRED population (campaign class B, `local_user_id` null).

**Finding:** **All 52 rows remain blocked.** No refund has deterministic spoke-wallet provenance under project rules. **0** rows classified `SOURCE_WALLET_DETERMINED`.

| Classification | Label | Refunds | Amount |
|---|---|---:|---:|
| **A** | SOURCE_WALLET_DETERMINED | **0** | **₹0** |
| **B** | CUSTOMER_DETERMINED_WALLET_UNRESOLVED | **0** | **₹0** |
| **C** | WALLET_DETERMINED_CUSTOMER_UNRESOLVED | **0** | **₹0** |
| **D** | MULTIPLE_POSSIBLE_WALLETS | **0** | **₹0** |
| **E** | MULTIPLE_POSSIBLE_CUSTOMERS | **0** | **₹0** |
| **F** | NO_AUTHORITATIVE_PROVENANCE | **52** | **₹34,517** |
| **G** | ALREADY_RECONCILED | **0** | **₹0** |
| **Total** | | **52** | **₹34,517** |

### Amount correction (prior reports)

P-30-10-16 and P-30-10-17 cited E-2 as **₹8,078**. That figure was an arithmetic error in those reports (E-1 was overstated as ₹119,250). The **immutable P-30-10-15 campaign manifest** is authoritative:

| Sub-cohort | Count | Amount |
|---|---:|---:|
| E-1 (wallet user resolved) | 168 | **₹92,811** |
| **E-2 (wallet user unresolved)** | **52** | **₹34,517** |
| Total IDENTITY_REQUIRED (B) | 220 | **₹127,328** |

---

## Population definition

**Selection rule:** `classification = B` AND `local_user_id IS NULL` in `cw-remaining-239-campaign-p30-10-15.json`.

| Check | Result |
|---|---|
| Count | **52** |
| Amount (manifest) | **₹34,517** |
| Overlap with 263/265/266 | **0** |
| Overlap with refund 300 (User 3) | **0** |
| Overlap with refund 360 | **0** |
| Overlap with 14 class-D SOURCE_RECONCILIATION | **0** |
| Overlap with 53 reconciled / ₹27,725 | **0** |
| Overlap with 168 E-1 rows | **0** |

**Sites:** rdservice.in **49**, radiumbox.com **2**, rdservice.net **1**

---

## Pre-change safety (verified)

| Check | Before | After |
|---|---|---|
| Central Wallet ledger | **53** credits / **₹27,725** | **unchanged** |
| Reconciled migration journal | **53** rows | **unchanged** |
| `CENTRAL_WALLET_REFUND_MIGRATION_EXECUTION_ENABLED` | **false** | **false** |
| `CENTRAL_WALLET_BALANCE_MIGRATION_EXECUTION_ENABLED` | **false** | **false** |
| Provisional cohort display flag | **false** | **unchanged** |

**Financial mutation:** NO — NOT PERFORMED

---

## Audit methodology

For each of the 52 refunds, read-only evidence was collected from:

1. **Desk:** `refund_requests`, `orders`
2. **Spoke:** `users_wallet` (rdservice.in, radiumbox.com)
3. **Spoke:** `order_rdservice.userid` (rdservice.in only)

### Deterministic classification rules (fail-closed)

| Evidence | Classification |
|---|---|
| Exactly one `users_wallet.desk_refund_reference` = desk refund reference | **A** |
| Exactly one wallet `message` contains desk refund reference (no `desk_refund_reference` set) | **A** |
| Exactly one wallet `message` contains desk order number, consistent with `order_rdservice.userid` | **A** |
| `order_rdservice.userid` found, no deterministic wallet | **B** |
| Wallet determined via message, customer ownership ambiguous | **C** |
| Multiple wallet candidates | **D** |
| Multiple customer candidates | **E** |
| Journal/ledger already reconciled | **G** |
| Otherwise | **F** |

### Explicitly excluded as proof

- Email/mobile similarity alone
- Name matching
- Balance equals refund amount
- NULL `execution_transaction_id` used as wallet ID
- Manufactured or inferred `desk_refund_reference`

---

## Findings

### All 52 → Class F (NO_AUTHORITATIVE_PROVENANCE)

Every E-2 refund shares these blocking conditions:

| Missing evidence | Count |
|---|---:|
| No `users_wallet.desk_refund_reference` matching desk refund reference | **52** |
| `execution_transaction_id` is NULL on desk refund | **52** |
| `orders.customer_id` is NULL | **52** |
| No unique wallet `message` match for refund reference | **52** |
| No unique wallet `message` match for desk order number | **52** |
| `order_rdservice` user not found for desk `order_id` (RD34xxxx format) | **49** (rdservice.in) |

### Desk vs spoke discrepancy

| Desk field | Value for all 52 |
|---|---|
| `approved_refund_method` | **wallet** (52/52) |
| Refund status | **closed** (51), **completed** (1) |

Despite desk records showing wallet refunds as closed/completed, **no spoke `users_wallet` row** was found with:

- matching `desk_refund_reference`, or
- deterministic `message` containing the desk refund reference or order number.

At least **1** refund (refund **1**) has desk `review_notes` explicitly claiming wallet credit, but **no corresponding spoke wallet transaction** exists in authoritative `users_wallet` data.

This indicates a **systemic wallet-credit recording gap** for this cohort — not merely missing identity.

---

## Per-site notes

### rdservice.in (49 refunds / ₹33,363)

- Desk order IDs use format `RD34xxxxx`
- `order_rdservice.rdorderid` uses a different numbering sequence (e.g. `RD13221`) — **no matches** for desk order IDs
- Cannot establish customer via `order_rdservice` for these orders
- No spoke wallet provenance via reference or message

### radiumbox.com (2 refunds / ₹948)

- Refunds **272**, **273**
- No `users_wallet` match by `desk_refund_reference` or order message (`RBX3511439`, `RBX3511409`)

### rdservice.net (1 refund / ₹206)

- Refund **276** (`RA3506948`)
- rdservice.net spoke DB present on KVM8; no deterministic wallet match found in read-only query

---

## Class A rows

**None.** No refund has deterministic source-wallet provenance. **₹0** prepared for future migration gate.

---

## Blocked rows — exact next actions

### Class F — all 52 (₹34,517)

Each row requires **one or more** of:

1. **Spoke wallet credit record** with `desk_refund_reference` = desk `REF-2026-NNNNNN` populated at credit time
2. **Desk `execution_transaction_id`** populated with authoritative `users_wallet.id` on refund completion
3. **Desk `orders.customer_id`** populated with spoke `userid` where order ownership is authoritative
4. **Owner source attribution** with documentary evidence linking refund → specific `users_wallet` row (not email/balance inference)
5. For rdservice.in: resolve desk `RD34xxxxx` ↔ spoke order numbering if a deterministic mapping exists (not evidenced today)

**Do not** auto-classify from:
- matching refund amount to wallet balance
- matching order email to spoke user email
- desk `review_notes` prose without spoke `users_wallet` corroboration

---

## Artifacts

| File | Location |
|---|---|
| JSON (per-refund evidence) | `storage/app/private/cw-e2-source-reconciliation-audit-p30-10-18.json` |
| CSV (operational summary) | `storage/app/private/cw-e2-source-reconciliation-audit-p30-10-18.csv` |
| Audit script | `storage/app/private/cw-e2-source-reconciliation-audit-p30-10-18.py` |
| This report | `docs/cw-e2-source-reconciliation-audit-p-30-10-18.md` |

---

## Financial zero-check (post-audit)

| Check | Result |
|---|---|
| Ledger delta | **₹0** |
| Journal delta | **0 rows** |
| New CW ledger entries | **0** |
| Refund statuses changed | **0** |
| CWID/customer/link created | **0** |
| Financial execution flags | **OFF** |
