# IDENTITY_REQUIRED 220 — Provisional Identity Implementation

**Prompt ID:** `RadiumDesk-P-30-10-17`  
**Date:** 2026-10-01  
**Mode:** Identity/display policy implementation — **no financial migration**

---

## Summary

Implemented read-only provisional balance display for the immutable **220-refund / ₹127,328** IDENTITY_REQUIRED cohort, with verification paths reusing existing trusted identity mechanisms.

| Capability | Status |
|---|---|
| Unverified email/mobile → read-only local spoke balance | **Implemented** (flag-gated) |
| Verified email OTP → Desk Customer + CWID + link | **Existing** `customer-identity/resolve` |
| Verified mobile OTP → M2 ceremony | **Existing** `ceremony/complete` |
| Google Sign-In → trusted identity | **Existing** `customer-identity/resolve` |
| Financial migration on verification | **NOT triggered** |
| Auto-create identity from unverified data | **NOT implemented** |

---

## Feature flag

| Env var | Default | Purpose |
|---|---|---|
| `CENTRAL_WALLET_PROVISIONAL_IDENTITY_ENABLED` | `false` | Master provisional-resolve API gate |
| `CENTRAL_WALLET_IDENTITY_REQUIRED_COHORT_PROVISIONAL_DISPLAY_ENABLED` | `false` | Historical 220-cohort local wallet display path |

Both must be enabled for cohort provisional display. Financial migration flags remain **OFF**.

---

## API behavior

`POST /api/central-wallet/v1/customer-identity/provisional-resolve`

**Request (extended):**
- `email` — optional if `mobile` provided
- `mobile` — optional if `email` provided
- At least one contact field required
- `email_verified` must be `false` for provisional path

**Historical cohort success (200):**
```json
{
  "identity_state": "provisional",
  "verification_status": "unverified",
  "available_balance": "597.00",
  "balance_source": "local_spoke_wallet",
  "currency": "INR",
  "verification_required": true,
  "verification_paths": ["verified_email_otp", "verified_mobile_otp", "google_sign_in"],
  "message": "Verify your email, mobile, or continue with Google to use this balance.",
  "financial_use_requires_verification": true,
  "historical_migration_separate": true
}
```

**Source unresolved (404):**
```json
{
  "error": "source_reconciliation_required",
  "identity_state": "unresolved",
  "blocker": "authoritative_source_wallet_unresolved"
}
```

Responses **never** expose CWID or Desk Customer ID.

---

## Cohort population (P-30-10-16 audit baseline)

| Metric | Count |
|---|---:|
| Total IDENTITY_REQUIRED | **220 / ₹127,328** |
| Wallet user resolved (E-1) | **168** |
| Wallet user unresolved (E-2) | **52** |
| Provisional balance eligible (resolved wallet + contact on spoke) | **168** |
| Source-reconciliation blocked | **52** |
| Verification eligible (cohort member with contact data) | **168** |

---

## Policy statements

1. Unverified email/mobile may be used to **display** a provisional read-only balance only.
2. Unverified contact data is **NOT** trusted financial identity.
3. Financial use requires successful trusted verification.
4. Verification/linking does **not** automatically migrate historical funds.
5. Ambiguous/conflicting identity fails closed.

---

## Components

| File | Role |
|---|---|
| `IdentityRequiredCohortManifestLoader` | Loads immutable B-class rows from P-30-10-15 campaign manifest |
| `HistoricalCohortProvisionalBalanceService` | Cohort-gated read-only local wallet balance resolution |
| `ProvisionalIdentityResolveService` | Orchestrates Path A (Desk credential) then Path B (historical cohort) |
| `ProvisionalIdentityController` | Optional email/mobile contact validation |

---

## Tests

- `IdentityRequiredCohortProvisionalIdentityTest` — 14 feature tests
- `IdentityRequiredCohortManifestLoaderTest` — 2 unit tests
- Existing `CentralWalletProvisionalIdentityTest` — preserved (cohort flag OFF)

Focused identity tests: **33/33 PASS**
