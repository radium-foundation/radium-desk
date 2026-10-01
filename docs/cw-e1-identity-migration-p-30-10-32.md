# E-1 Identity Migration Path — 168 Historical Wallet Refunds

**Prompt ID:** `RadiumDesk-P-30-10-32`  
**Date:** 2026-10-01  
**Mode:** Trusted identity + destination preparation — **no financial execution**

**Owner approval reference (destination prep only):** `OWNER-CW-E1-IDENTITY-MIGRATION-20261001-001`

---

## Summary

Extended the existing Central Wallet identity architecture (E-2 pattern) for the **E-1 cohort** (168 / ₹92,811). Customers can complete trusted verification; upon success the system prepares migration journal destinations **without** crediting Central Wallet or debiting spoke wallets.

| Metric | Value |
|---|---:|
| E-1 population | **168 / ₹92,811** |
| Verification cohort manifest SHA-256 | `28a97ac1e24b3cacbf1013bf655398681785622e4c9afbc2490c11a9ec8ef33a` |
| Destination-ready | **0 / ₹0** (awaiting real customer verification) |
| Ledger | **54 / ₹28,574** unchanged |

---

## Identity states (per refund)

| State | Production count |
|---|---:|
| TRUSTED_EXISTING_CWID | 0 |
| TRUSTED_IDENTITY_NO_CWID | 0 |
| **VERIFICATION_AVAILABLE** | **168** |
| AMBIGUOUS_OR_CONFLICTING | 0 |
| IDENTITY_INSUFFICIENT | 0 |

---

## Workflow

```
E-1 refund (site + local_user_id)
  → provisional display (existing HistoricalCohortProvisionalBalanceService)
  → customer trusted verification (email OTP / Google / M2 ceremony)
  → CustomerIdentityResolveService / CustomerFoundationFromCeremonyService
  → E1VerificationDestinationService (journal target assignment)
  → MIGRATION_DESTINATION_READY metadata — NO ledger credit
```

**Lookup key:** `site` + `local_user_id` (not email hash — E-2 difference).

---

## New components

| Component | Purpose |
|---|---|
| `E1CohortManifestLoader` | Immutable 168-row cohort manifest |
| `E1CohortStateResolver` | Identity + destination state audit |
| `E1VerificationDestinationService` | Post-verification destination prep hook |
| `E1IdentityMigrationJournalImportService` | Pending journal rows (identity-only) |
| `E1DestinationReadinessManifestService` | Per-refund readiness manifest |

---

## Artisan commands

```bash
php artisan central-wallet:e1-verification-cohort-manifest-build
php artisan central-wallet:e1-verification-audit
php artisan central-wallet:e1-destination-readiness-manifest-build
```

Artifacts:
- `storage/app/private/cw-e1-verification-cohort-manifest-p30-10-32.json`
- `storage/app/private/cw-e1-destination-readiness-manifest-p30-10-32.json`

---

## Flags

| Flag | Default | Purpose |
|---|---|---|
| `CENTRAL_WALLET_E1_IDENTITY_MIGRATION_VERIFICATION_ENABLED` | `false` | Enable E-1 destination prep after trusted verification |
| `CENTRAL_WALLET_REFUND_MIGRATION_EXECUTION_ENABLED` | `false` | Must remain OFF for this prompt |
| `CENTRAL_WALLET_E2_HISTORICAL_SETTLEMENT_EXECUTION_ENABLED` | `false` | Unchanged |

---

## Tests

`E1VerificationPathTest` (7) + `E1DestinationReadinessManifestTest` (3) + `E2DestinationReadinessManifestTest` (4) = **14/14 PASS**

---

## Customer action required

**168** customers must complete real trusted verification (OTP / Google / ceremony). No fabricated production identities were created.

---

## Financial gate

**STOP** before settlement. When destination-ready rows exist, Owner approval + `CENTRAL_WALLET_REFUND_MIGRATION_EXECUTION_ENABLED` gate required for spoke cutover migration.
