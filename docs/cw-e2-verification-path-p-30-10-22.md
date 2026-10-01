# E-2 Customer Verification Path — 52 Historical Manual Wallet Refunds

**Prompt ID:** `RadiumDesk-P-30-10-22`  
**Date:** 2026-10-01  
**Mode:** Verification / destination preparation — **no Lane 4 financial execution**

**Owner approval reference:** `OWNER-CW-E2-HISTORICAL-SETTLEMENT-20261001-001`  
**Forensic report reference:** `RadiumDesk-P-30-10-19`  
**Lane 4 settlement batch:** `desk-refund-historical-settlement-e2-52-p30-10-20`

---

## Summary

Implemented the customer verification path for the **52-refund E-2 cohort** (₹34,517) so each customer can establish trusted identity and prepare a Lane 4 settlement destination **without** executing settlement or crediting wallets.

| Step | Mechanism |
|---|---|
| Provisional display | Extended `provisional-resolve` → `E2ProvisionalDisplayService` |
| Email OTP | Existing `customer-identity/resolve` (`verified_email`) |
| Mobile OTP / M2 | Existing `ceremony/complete` + optional `establish-customer-from-ceremony` |
| Google Sign-In | Existing `customer-identity/resolve` (`google` + verified email in payload) |
| Destination preparation | `E2VerificationDestinationService` (journal target assignment only) |
| Cohort state audit | `central-wallet:e2-verification-audit` |

**Historical spoke-wallet destination reconstructed:** **NO**

**Lane 4 financial execution:** **NOT invoked** by this path

---

## Feature flags (default OFF)

| Env var | Default | Purpose |
|---|---|---|
| `CENTRAL_WALLET_E2_HISTORICAL_SETTLEMENT_VERIFICATION_ENABLED` | `false` | E-2 provisional display + post-verification destination prep |
| `CENTRAL_WALLET_E2_HISTORICAL_SETTLEMENT_EXECUTION_ENABLED` | `false` | Lane 4 settlement execution (separate gate — remains OFF) |
| `CENTRAL_WALLET_REFUND_MIGRATION_EXECUTION_ENABLED` | `false` | Unchanged — must remain OFF |

Provisional display also requires existing flags from P-30-10-17:

- `CENTRAL_WALLET_PROVISIONAL_IDENTITY_ENABLED`
- `CENTRAL_WALLET_IDENTITY_REQUIRED_COHORT_PROVISIONAL_DISPLAY_ENABLED` (220-cohort path; E-2 has its own gate)

---

## Customer experience (E-2 cohort)

When `verification_enabled=true` and the spoke user supplies an unverified email matching a Desk order email hash in the E-2 verification manifest:

```json
{
  "identity_state": "provisional",
  "verification_status": "unverified",
  "available_balance": "717.00",
  "balance_source": "historical_refund_amount_pending_verification",
  "message": "Verify your identity to use this wallet balance.",
  "verification_paths": ["verified_email_otp", "verified_mobile_otp", "google_sign_in"],
  "source_wallet_provenance": "unavailable_not_reconstructed"
}
```

Responses **never** expose refund IDs, CWID, Desk Customer ID, or internal settlement metadata.

The displayed amount is the **historical refund total** from the immutable cohort manifest — **not** a confirmed spoke-wallet balance.

---

## Verification success flow

After trusted verification via existing APIs:

1. Resolve trusted identity (Desk Customer + CWID + active site link)
2. Match verified email hash to E-2 `order_email_hash` on the same site (deterministic only)
3. Import Lane 4 journal rows if missing (`E2HistoricalSettlementJournalImportService`)
4. Assign `desk_customer_id` + `cwid` on journal rows (`RefundMigrationTargetAssignmentService`)
5. Record audit + metadata `e2_verification.state = SETTLEMENT_DESTINATION_READY`

This does **not** call `central-wallet:e2-historical-settlement-execute` or write ledger credits.

---

## Cohort state tracking

`E2CohortStateResolver` classifies each refund:

| State | Meaning |
|---|---|
| `UNVERIFIED` | No trusted email credential match |
| `VERIFIED_IDENTITY` | Trusted credential exists |
| `CWID_READY` | Desk Customer + CWID exist |
| `LINK_READY` | Active site account link exists |
| `SETTLEMENT_DESTINATION_READY` | Journal row prepared with destination + active link |
| `AMBIGUOUS` | Conflicting identity evidence |

Audit: `php artisan central-wallet:e2-verification-audit`

---

## Manifests

| File | Purpose |
|---|---|
| `cw-e2-historical-settlement-manifest-p30-10-20.json` | Lane 4 settlement journal (52 rows) |
| `cw-e2-verification-cohort-manifest-p30-10-22.json` | E-2 lookup index with `order_email_hash` (no raw email) |

Build verification manifest on production (one-time, before enabling flag):

```bash
php artisan central-wallet:e2-verification-cohort-manifest-build
```

---

## Tests

`tests/Feature/CentralWallet/E2VerificationPathTest.php` — 16 focused cases covering provisional entry, trusted verification paths, idempotency, fail-closed ambiguity, no Lane 4 / ledger mutation, and cohort state advancement.

---

## Deployment note

Enabling `CENTRAL_WALLET_E2_HISTORICAL_SETTLEMENT_VERIFICATION_ENABLED` is a **separate deployment gate** from Lane 4 financial execution. Both execution flags must remain **OFF** until Owner-approved settlement execution.
