# Central Wallet — TYPE-1 Migration Cohort Identity (M2 OTP Retired for Migration Path)

**Prompt ID:** `RadiumDesk-P-30-10-04`  
**Date:** 2026-10-01  
**Mode:** Identity / migration-prep only — **no financial migration**

---

## Executive summary

The Owner has retired the requirement that the **50 TYPE-1 migration cohort** customers personally complete Connect Wallet + WhatsApp OTP to establish identity for refund-migration prep.

| Item | Value |
|---|---|
| Cohort | **50** customers / **50** refunds / **₹26,230** |
| M2 dependency for this migration path | **REMOVED** |
| Normal customer M2 Connect Wallet | **PRESERVED** (unchanged for other CW use) |
| Implementation decision | `SMALL_NON_FINANCIAL_EXTENSION_REQUIRED` (implemented) |
| Production deployment | **NOT PERFORMED** (separate gate) |
| Production identities established | **0** (flag OFF) |

---

## 1. Problem statement

P-30-10-03 showed **0/50** pilot success because M2 requires customer WhatsApp OTP. The Owner determined this is not the desired operational path for these 50 audited TYPE-1 customers.

---

## 2. Trust model (per customer)

| Class | Meaning | Action |
|---|---|---|
| **A** | Existing trusted Google identity | Delegate to `MigrationControlledCwidProvisionService` |
| **B** | Existing verified email credential | Delegate to trusted provisioner |
| **C** | Deterministic business identity + Owner authorization | **New cohort anchor establishment** |
| **D** | Ambiguous / conflicting | `IDENTITY_BLOCKED` |
| **E** | Insufficient evidence | `IDENTITY_BLOCKED` |

All **50** cohort customers are class **C** (deterministic business identity from refund→order→spoke linkage). None have trusted Google/verified email on production.

**Critical rule:** Raw email/mobile from order data are **NOT** promoted to trusted credentials.

---

## 3. New mechanism: Owner-authorized migration cohort anchor

### Credential type

`migration_cohort_anchor` — subject hash derived from:

```
SHA256("migration_cohort_anchor:{cohort_id}:{site}:{local_user_id}:{sorted_refund_ids}")
```

This anchors identity to the **immutable Owner-approved cohort manifest**, not to email/mobile strings.

### Service

`Type1MigrationCohortIdentityEstablishmentService`

- Loads immutable manifest (`cw-type1-migration-cohort-p30-10-04.json`)
- Rejects customers outside exact 50-cohort
- Creates CWID + Desk Customer + active account link
- `verification_method`: `owner_migration_cohort`
- **No ledger writes**
- Idempotent replay safe

### CLI

```bash
php artisan central-wallet:establish-type1-cohort-identity {site} {local-user-id} --owner-authorized
php artisan central-wallet:establish-type1-cohort-identity rdservice.in 126483 --owner-authorized --dry-run
php artisan central-wallet:establish-type1-cohort-identity rdservice.in 0 --all --owner-authorized  # all 50
```

### Feature flag (default OFF)

```
CENTRAL_WALLET_TYPE1_MIGRATION_COHORT_IDENTITY_ENABLED=false
CENTRAL_WALLET_TYPE1_MIGRATION_COHORT_MANIFEST_PATH=storage/app/private/cw-type1-migration-cohort-p30-10-04.json
```

---

## 4. Migration identity states (replaces M2 blocker message)

| State | Meaning |
|---|---|
| `IDENTITY_ESTABLISHMENT_REQUIRED` | Cohort member; Owner-authorized establishment not yet run |
| `IDENTITY_ESTABLISHED` | Desk Customer + CWID + link valid |
| `IDENTITY_BLOCKED` | Ambiguous/conflicting/disabled |

**Removed for this path:** `customer_must_complete_connect_wallet_whatsapp_otp`

---

## 5. Files changed

| File | Purpose |
|---|---|
| `Type1MigrationCohortManifestLoader.php` | Immutable 50-customer manifest validation |
| `Type1MigrationCohortIdentityEstablishmentService.php` | Owner-authorized establishment |
| `CentralWalletEstablishType1CohortIdentityCommand.php` | CLI with `--owner-authorized` gate |
| `CustomerIdentityCredentialType.php` | Added `MigrationCohortAnchor` |
| `CustomerIdentitySubjectHasher.php` | Cohort anchor hashing |
| `config/central_wallet.php` | `type1_migration_cohort` config block |
| `CentralWalletServiceProvider.php` | Service registration |
| `tests/fixtures/cw-type1-migration-cohort-p30-10-04.json` | Committed cohort manifest |
| `Type1MigrationCohortIdentityEstablishmentTest.php` | 6 tests |

**Not changed:** `CeremonyCompleteService`, Connect Wallet UI, spoke M2 flows.

---

## 6. Cohort restriction

| Check | Enforcement |
|---|---|
| Exactly 50 customers | Manifest loader |
| Exactly 50 refunds / ₹26,230 | Manifest loader |
| Customer not in manifest | `not_in_type1_migration_cohort` |
| 160 blocked customers | Not in manifest — cannot process |
| 3 OWNER_RESOLUTION | Not in manifest |
| 55 insufficient | Not in manifest |

---

## 7. Financial safety

| Invariant | Expected |
|---|---|
| `central_wallet_ledger_entries` | **0** (unchanged) |
| Financial variance | **₹0** |
| Spoke wallets | unchanged |
| Refund rows | unchanged |
| `REFUND_MIGRATION_EXECUTION_ENABLED` | **false** |

Service calls `assertNoFinancialMutation()` after writes.

---

## 8. Rollback

1. Set `CENTRAL_WALLET_TYPE1_MIGRATION_COHORT_IDENTITY_ENABLED=false`
2. Do not run establishment CLI
3. If identities were established in error: manual Owner review required (no automated deletion in this task)
4. Revert code deploy if needed — M2 customer flow unaffected

---

## 9. Deployment gate (NOT PERFORMED)

Before production use:

1. Owner explicitly approves cohort manifest hash
2. Deploy code to KVM8 (non-financial only)
3. Set `CENTRAL_WALLET_TYPE1_MIGRATION_COHORT_IDENTITY_ENABLED=true` on Desk only
4. Run with `--dry-run` for all 50
5. Run with `--owner-authorized --all` once
6. Verify 50/50 `IDENTITY_ESTABLISHED`, ledger still 0
7. **Do not** enable refund migration execution
8. **Do not** expand to 160 blocked customers

---

## 10. Tests

`Type1MigrationCohortIdentityEstablishmentTest`: **6/6 PASS**

- Cohort establishment without M2
- Out-of-cohort rejection
- Idempotent replay
- Manifest size validation
- Feature flag fail-closed
- M2 not required for migration path

---

## Execution gate

```
M2 OTP required for 50-customer migration path: NO
Normal M2 Connect Wallet: YES (preserved)
Financial migration: NOT PERFORMED
Deployed: NOT PERFORMED
```
