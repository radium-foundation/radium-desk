# Central Wallet — IDENTITY_REQUIRED 220 Read-Only Audit

**Prompt ID:** `RadiumDesk-P-30-10-16`  
**Date:** 2026-10-01  
**Mode:** Read-only identity-resolution audit — **no financial mutations, no identity provisioning**

---

## Executive summary

This audit re-examines the **220** refunds (₹127,328) classified `IDENTITY_REQUIRED` (class **B**) in the P-30-10-15 remaining-239 campaign. The goal is to determine whether trusted Desk Customer / CWID / account-link evidence already exists in production.

**Finding:** The current `IDENTITY_REQUIRED` classification is **correct for all 220 rows**. None have established trusted identity credentials, Desk Customer IDs, CWIDs, or active account links. Every row requires genuine customer identity evidence (Google sign-in or verified email) before any identity-only provisioning can proceed.

| Audit state | Label | Refunds | Amount |
|---|---|---:|---:|
| **A** | ALREADY_VERIFIED_IDENTITY | **0** | **₹0** |
| **B** | VERIFIED_IDENTITY_LINK_MISSING | **0** | **₹0** |
| **C** | DESK_CUSTOMER_EXISTS_CWID_MISSING | **0** | **₹0** |
| **D** | TRUSTED_CREDENTIAL_EXISTS_BUT_UNRESOLVED | **0** | **₹0** |
| **E** | LOCAL_CUSTOMER_DATA_ONLY | **220** | **₹127,328** |
| **F** | CONFLICTING_IDENTITY | **0** | **₹0** |
| **G** | SOURCE_PROBLEM | **0** | **₹0** |
| **H** | ALREADY_MIGRATED | **0** | **₹0** |
| **Total** | | **220** | **₹127,328** |

---

## Pre-change safety (verified)

| Check | Before | After |
|---|---|---|
| Repository | `radium-foundation/radium-desk` | unchanged |
| Production server | KVM8 `srv1910783` / `187.127.129.16` | unchanged |
| Application path | `/var/www/radium-desk` | unchanged |
| Database | `radium_desk` | unchanged |
| Central Wallet ledger | **53** credits / **₹27,725** | **unchanged** |
| Refund migration journal | **53** reconciled rows | **unchanged** |
| `central_customers` count | **73** | **unchanged** |
| `CENTRAL_WALLET_REFUND_MIGRATION_EXECUTION_ENABLED` | **false** | **false** |
| `CENTRAL_WALLET_BALANCE_MIGRATION_EXECUTION_ENABLED` | **false** | **false** |

**Financial mutation:** NO — NOT PERFORMED  
**Identity mutation:** NO — NOT PERFORMED

---

## Audit population integrity

| Check | Result |
|---|---|
| Source | P-30-10-15 campaign manifest (`classification = B`) |
| Expected count / amount | **220 / ₹127,328** |
| Overlap with excluded refunds (263, 265, 266, 300, 360) | **0** |
| Overlap with SOURCE_RECONCILIATION (14 rows) | **0** |
| Overlap with ALREADY_MIGRATED journal/ledger | **0** |

---

## Identity evidence summary

| Metric | Count |
|---|---:|
| Desk Customer ID already exists | **0** |
| CWID already exists | **0** |
| Trusted credential on spoke (Google or verified email) | **0** |
| Active site → CWID account link | **0** |
| Google identity on spoke | **0** |
| Verified email on spoke | **0** |
| Verified email only | **0** |
| Verified mobile only | **0** |
| Multiple trusted credentials | **0** |
| No trusted credential at all | **220** |
| Desk order email → trusted spoke user (cross-match) | **0** |
| Desk order email → ambiguous trusted match | **0** |
| Unique email within cohort local users | **220** |
| Unique mobile within cohort local users | **167** |
| Order user = wallet user | **220** |
| Order user ≠ wallet user | **0** |
| Existing CWID through another site | **0** |
| Potentially migration-ready after identity-only op | **0** |

---

## Sub-populations within class E

All 220 rows share these missing steps:

- `no_trusted_google_or_verified_email_credential`
- `desk_customer_id_missing`
- `cwid_missing`
- `active_account_link_missing`

### E-1: Wallet user resolved (168 refunds / ₹119,250)

Spoke wallet user resolved via `desk_refund_reference` or `execution_transaction_id`, but the spoke account has **only unverified email** (no `google_id`, no `email_verified_at`).

Additional missing steps per row:

- `email_not_verified_on_spoke`
- `no_google_subject_on_spoke`

**What is missing:** Customer must complete trusted identity ceremony (Google sign-in or email verification). Until then, Desk Customer, CWID, and account link cannot be established under project identity policy.

### E-2: Wallet user unresolved (52 refunds / ₹8,078)

No authoritative spoke wallet or local user could be resolved. Desk order has customer email but:

- `execution_transaction_id` is NULL
- `order.customer_id` is NULL
- No matching `users_wallet.desk_refund_reference`

Additional missing steps per row:

- `authoritative_source_wallet_unresolved`
- `order_customer_id_missing`

**What is missing:** Same trusted identity gap as E-1, plus source-wallet provenance cannot be tied to a spoke user without Owner/source reconciliation.

Sites: rdservice.in **49**, radiumbox.com **2**, rdservice.net **1**.

---

## Site breakdown

| Site | Refunds | Amount |
|---|---:|---:|
| rdservice.in | 210 | ₹120,320 |
| radiumbox.com | 9 | ₹5,929 |
| rdservice.net | 1 | ₹1,079 |

---

## Cross-site identity check

No row in this cohort has an existing Desk Customer, CWID, or active account link on any site. Cross-site identity evidence was not found via:

- existing `central_customers` / `central_wallet_account_links`
- trusted spoke credentials matched to desk identity credentials
- desk order email matching a trusted spoke user on another site

**Rows with CWID via another site:** **0**

---

## Conflict detection

Within the 220-row audit cohort:

| Conflict type | Count |
|---|---:|
| Same email → multiple local users (cohort) | **0** |
| Same mobile → multiple local users (cohort) | **0** |
| Order user ≠ wallet user | **0** |
| Multiple Desk Customers from credential hash | **0** |
| Desk Customer → conflicting CWIDs | **0** |
| Cross-site identity conflicts | **0** |

No row was classified **F CONFLICTING_IDENTITY**. Identity blockers here are **absence of trusted evidence**, not conflicting evidence.

---

## Answer to key questions

### 1. Already identity-complete, only missing account linkage

**0 / ₹0**

No row has Desk Customer + CWID + trusted credential. Class **B** count is zero.

### 2. Enough trusted evidence to provision/link safely (identity-only operation)

**0 / ₹0**

No spoke user in this cohort has `google_id` or `email_verified_at`. Desk order emails do not resolve to trusted spoke users elsewhere. The 50 `migration_cohort_anchor` credentials from P-30-10-05 belong to the separate Type-1 50 cohort, not these 220.

### 3. Need genuine customer identity evidence

**220 / ₹127,328**

Every row lacks trusted identity per project policy. Local data (name, unverified email, phone, order metadata) exists but is insufficient alone.

### 4. Conflicting evidence requiring Owner resolution

**0 / ₹0** (within this 220 cohort)

Refunds 263/265/266 are excluded from this audit and remain in class **C** of the broader campaign.

---

## Is the current IDENTITY_REQUIRED classification correct?

**Yes.** All 220 refunds genuinely lack:

1. Trusted Google or verified-email credential
2. Desk Customer ID
3. Central Wallet ID (CWID)
4. Active site account link

The campaign classification is not overstated. No subset is secretly migration-ready pending only a link operation.

---

## Artifacts

| File | Location |
|---|---|
| JSON (per-refund evidence) | `storage/app/private/cw-identity-required-220-audit-p30-10-16.json` |
| CSV (operational summary) | `storage/app/private/cw-identity-required-220-audit-p30-10-16.csv` |
| Audit script | `storage/app/private/cw-identity-required-220-audit-p30-10-16.py` |
| This report | `docs/cw-identity-required-220-audit-p-30-10-16.md` |

PII: email/mobile masked in CSV and JSON human fields. No secrets written.

---

## Testing

Targeted Central Wallet regression tests (**41/41 PASS**) were run in P-30-10-15 on production. This audit run verified ledger/journal/customer counts unchanged before and after script execution. Local PHP test runner unavailable in dev environment; no production code was modified.

---

## Post-audit financial zero-check

| Check | Result |
|---|---|
| Ledger delta | **₹0** |
| Journal delta | **0 rows** |
| Customer table delta | **0 rows** |
| Refund statuses | **unchanged** |
| Financial flags | **OFF** (execution) |
