# E-2 Forensic Reconstruction — 52 IDENTITY_REQUIRED Refunds

**Prompt ID:** `RadiumDesk-P-30-10-19`  
**Date:** 2026-10-01  
**Mode:** Read-only forensic reconstruction — **no financial mutations**

---

## Executive summary

Forensic reconstruction was performed on the immutable **52-refund E-2 sub-cohort** (campaign class B, `local_user_id` null) totaling **₹34,517**, building on P-30-10-18 source-reconciliation findings.

**Finding:** **No refund has authoritative destination provenance.** All **52** rows classify **D (CORROBORATING_ONLY)**. Desk records corroborate administrative manual wallet completion, but no historical evidence chain proves `refund → spoke wallet-credit → exact spoke wallet → local customer`.

| Classification | Label | Refunds | Amount |
|---|---|---:|---:|
| **A** | RECONSTRUCTED_AUTHORITATIVE | **0** | **₹0** |
| **B** | RECONSTRUCTED_WALLET_ONLY | **0** | **₹0** |
| **C** | RECONSTRUCTED_CUSTOMER_ONLY | **0** | **₹0** |
| **D** | CORROBORATING_ONLY | **52** | **₹34,517** |
| **E** | NO_EVIDENCE_FOUND | **0** | **₹0** |
| **F** | INFRASTRUCTURE_ACCESS_BLOCKED | **0** | **₹0** |
| **Total** | | **52** | **₹34,517** |

**Safe to advance to source-wallet migration preparation:** **0 / 52**

---

## Population definition

**Selection rule:** `classification = B` AND `local_user_id IS NULL` in `cw-remaining-239-campaign-p30-10-15.json`.

| Check | Result |
|---|---|
| Count | **52** |
| Amount (manifest) | **₹34,517** |
| Overlap with 168 E-1 | **0** |
| Overlap with refunds 263/265/266 | **0** |
| Overlap with refund 300 | **0** |
| Overlap with refund 360 | **0** |
| Overlap with 14 class-D SOURCE_RECON rows | **0** |
| Overlap with 53 reconciled / ₹27,725 | **0** |

**Sites:** rdservice.in **49**, radiumbox.com **2**, rdservice.net **1**

---

## Pre-change verification

| Item | Value |
|---|---|
| Repository | `/Users/ravi/RadiumWebsites/radium-desk` (`radium-foundation/radium-desk`) |
| Branch | `feat/direct-ledger-debit-gate` |
| Before SHA | `5b50e7bd` |
| Remote | `origin` → `git@github.com:radium-foundation/radium-desk.git` |

| Financial check | Before | After |
|---|---|---|
| Central Wallet ledger credits | **53** / **₹27,725** | **unchanged** |
| Reconciled migration journal | **53** rows | **unchanged** |
| `CENTRAL_WALLET_REFUND_MIGRATION_EXECUTION_ENABLED` | **false** | **false** |
| `CENTRAL_WALLET_BALANCE_MIGRATION_EXECUTION_ENABLED` | **false** | **false** |
| Provisional cohort display flag | **false** | **unchanged** |

**Financial mutation:** NO — NOT PERFORMED

---

## Forensic methodology

### Evidence hierarchy applied

| Tier | Sources searched |
|---|---|
| **STRONG / AUTHORITATIVE** | Spoke `users_wallet.desk_refund_reference`; unique spoke wallet `message` match; Desk `execution_transaction_id` corroborated on spoke; immutable audit with destination + spoke corroboration |
| **MEDIUM** | Desk `audit_logs.refund.completed` provider metadata; deployment timeline; `order_rdservice.userid` (rdin only) |
| **WEAK (excluded from reconstruction)** | `approved_refund_method=wallet`; `review_notes` wallet prose; email/name/balance/timestamp matching |

### Evidence sources inspected

1. **Desk DB:** `refund_requests`, `orders`, `audit_logs`, `central_wallet_ledger_entries`, `central_wallet_refund_migrations`
2. **Spoke DB (read-only):** `rdservice.in.users_wallet`, `rdservice.in.order_rdservice`, `radiumbox.com.users_wallet`, `rdservice.net` schema survey
3. **Application logs:** Desk `storage/logs/laravel*.log` (grep by refund reference)
4. **Application source / git history:** `RefundRequestService`, `RefundExecutorResolver`, `WalletRefundExecutor`, `ManualRefundExecutor`, `RefundExecutionInputGuard`, `RefundCaseCloseService`, wallet refund HTTP clients
5. **Deployment timeline:** `WalletRefundExecutor` production introduction **2026-09-12** (`4773fffa`); rdservice.in automation **2026-09-16** (`cba85eb1`)

### Not inspected (blockers / out of scope)

- Database backup restore (would require destructive/risky operations)
- Historical rotated log archives beyond current `storage/logs` on KVM8
- rdservice.net has **no `users_wallet` table** (wallet capability absent on this spoke schema)

---

## Critical findings

### 1. All 52 completions used ManualRefundExecutor — not automated wallet credit

Every `audit_logs` row for `refund.completed` on these 52 refunds records:

```json
"provider": "manual"
```

| Metric | Value |
|---|---:|
| Desk manual completion (`provider=manual`) | **52** |
| Desk automated wallet completion (`rdservice_in_wallet` / `radiumbox_wallet`) | **0** |
| `execution_transaction_id` populated | **0** |
| `execution_reference_no` = desk `REF-2026-NNNNNN` (self-reference) | **52** |

At execution time (Jul–Sep 2026), production refund workflow used `RefundExecutorResolver` → **`ManualRefundExecutor` only**. `WalletRefundExecutor` was not deployed until **2026-09-12** — after the latest E-2 execution (**2026-09-08**).

### 2. No spoke wallet-credit evidence for any refund

| Spoke search | Hits |
|---|---:|
| `users_wallet.desk_refund_reference` = desk refund reference | **0** |
| Unique `users_wallet.message` containing desk refund reference | **0** |
| Unique `users_wallet.message` containing desk order number | **0** |
| Desk application log hits for refund references | **0** |

P-30-10-18 spoke-negative results are **confirmed** under the stricter forensic hierarchy.

### 3. Administrative completion without durable destination capture

The manual completion path (`ManualRefundExecutor`) accepts an operator-supplied reference or transaction ID. Operators recorded the **desk refund reference itself** as `execution_reference_no`. No spoke `users_wallet.id` was captured.

`RefundExecutionInputGuard` (which strips wallet execution fields to prevent REF/txn conflation) was added **2026-09-27** — after all E-2 executions. It does not retroactively affect these historical rows.

### 4. Desk could reach closed/completed without spoke acknowledgement

Flow at time of execution:

```
RefundRequestService::complete()
  → RefundExecutorResolver::for(wallet) → ManualRefundExecutor (only executor in production)
  → ManualRefundExecutor accepts desk REF as reference_number
  → refund.status = completed; execution_reference_no = REF-...
  → RefundCaseCloseService::closeLinkedCase() → status = closed
```

No HTTP call to spoke wallet-refund APIs occurred. `RefundCaseCloseService` closes the case after completion regardless of spoke wallet state.

### 5. rdservice.net (refund 276)

- Order `RA3506948`, amount **₹1,079**
- `rdservice.net` database accessible on KVM8 but **has no `users_wallet` table**
- Wallet forensic source does not exist on this spoke; desk manual completion evidence only

---

## Critical questions — answers with evidence

| # | Question | Answer |
|---|---|---|
| 1 | Did Desk historically record a wallet destination when completing these refunds? | **No.** `execution_transaction_id` is NULL for all 52. `execution_reference_no` is the desk REF itself, not a spoke wallet ID. |
| 2 | Did the old WalletRefundExecutor receive a deterministic destination wallet ID? | **No.** WalletRefundExecutor was not in production during E-2 execution window. All 52 used ManualRefundExecutor. |
| 3 | Did the spoke API return a transaction/wallet ID? | **No evidence.** No automated wallet-credit API calls; no spoke `users_wallet` rows with matching references. |
| 4 | Was that response persisted anywhere? | **No.** Audit `provider=manual`; no `wallet_transaction_id` in audit `new_values`. |
| 5 | Did the spoke actually receive the wallet-credit request? | **No authoritative evidence.** No integration logs, idempotency records, or `users_wallet` rows found. |
| 6 | If yes, is there any historical trace proving the destination? | **N/A** — no spoke receipt evidenced. |
| 7 | Could Desk refund status become CLOSED/COMPLETED even if spoke wallet credit failed? | **Yes.** ManualRefundExecutor completes on operator attestation; `RefundCaseCloseService` then closes. No spoke acknowledgement required. |
| 8 | Are there retry/idempotency logs that identify the destination? | **No.** rdin `paid_order_idempotency` exists but is unrelated; no wallet-refund idempotency table found. |
| 9 | Is missing provenance recoverable from existing records? | **No.** No dormant authoritative fields found on Desk or accessible spokes. |
| 10 | Evidence of administrative-only completion without durable wallet credit? | **Yes.** 52/52 `provider=manual`; 52/52 executed before WalletRefundExecutor deploy; 0 spoke wallet matches. |

---

## Reconciliation: Desk wallet-refund completion vs spoke evidence

| Population | Count | Amount |
|---|---:|---:|
| Desk `approved_refund_method=wallet` + closed/completed | **52** | **₹34,517** |
| Desk audit `provider=manual` | **52** | **₹34,517** |
| Historical spoke `users_wallet` credit evidence | **0** | **₹0** |
| **Unmatched (desk-marked wallet, no spoke trace)** | **52** | **₹34,517** |

---

## Per-refund classification

All **52** rows: **D — CORROBORATING_ONLY**

**Corroborating evidence chain (typical row):**

1. **MEDIUM** — `desk.audit_logs.refund.completed` → `provider=manual`, `execution_transaction_id=null`
2. **WEAK** — `desk.refund_requests.approved_refund_method=wallet`
3. **WEAK** (4 rows) — `review_notes` contains wallet keyword
4. **MEDIUM** — executed before `WalletRefundExecutor` production deploy (2026-09-12)

**Missing for all rows:**

- `no_spoke_users_wallet_desk_refund_reference`
- `no_unique_spoke_wallet_message_match`
- `execution_transaction_id_null`

See `storage/app/private/cw-e2-forensic-reconstruction-p30-10-19.csv` for the full per-refund matrix.

---

## Blockers and recommended next gate

### Blockers

1. **No authoritative spoke wallet destination** for any of the 52 refunds
2. **Historical manual completion** without `users_wallet.id` capture
3. **Wallet automation did not exist** at time of execution for rdin/box; rdservice.net has no wallet table
4. **Desk order ID format** (`RD34xxxxx`) does not match `order_rdservice.rdorderid` on rdin — customer cannot be established via order linkage

### Recommended next gate

**Do not advance E-2 to source-wallet migration preparation.** Require Owner-approved policy for one of:

- **Attestation + documentary evidence** per refund linking to a specific spoke `users_wallet` row (not inference)
- **Spoke wallet credit backfill** with `desk_refund_reference` populated at credit time (new financial operation — separate gate)
- **Explicit write-off / alternate settlement** path for administratively completed refunds without spoke trace

---

## Artifacts

| File | Location |
|---|---|
| JSON forensic result | `storage/app/private/cw-e2-forensic-reconstruction-p30-10-19.json` |
| CSV evidence matrix | `storage/app/private/cw-e2-forensic-reconstruction-p30-10-19.csv` |
| Audit script | `storage/app/private/cw-e2-forensic-reconstruction-p30-10-19.py` |
| This report | `docs/cw-e2-forensic-reconstruction-p-30-10-19.md` |

---

## Financial zero-check (post-audit)

| Check | Result |
|---|---|
| Ledger delta | **₹0** |
| Journal delta | **0 rows** |
| New CW ledger entries | **0** |
| Wallet balances changed | **0** |
| Refund/order/customer records changed | **0** |
| Financial execution flags | **OFF** |
| Production data mutation | **NONE** |

---

## Tests

**NO** — Not performed (read-only forensic audit).
