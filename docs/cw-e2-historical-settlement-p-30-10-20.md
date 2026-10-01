# E-2 Historical Manual Refund Settlement — Owner-Approved Settlement Gate

**Prompt ID:** `RadiumDesk-P-30-10-20`  
**Date:** 2026-10-01  
**Mode:** Controlled settlement infrastructure + execution gate — **financial execution STOPPED (no destination CWIDs)**

---

## Executive summary

Owner authorized settlement of the **52-refund E-2 cohort** (₹34,517) where P-30-10-19 established **CORROBORATING_ONLY** forensic status — historical spoke wallet destinations cannot be reconstructed.

This prompt implements an **explicit settlement path** distinct from source-wallet migration:

| Property | Value |
|---|---|
| Settlement classification | `OWNER_APPROVED_HISTORICAL_MANUAL_REFUND_SETTLEMENT` |
| Lane | `lane_4_owner_approved_historical_settlement` |
| Spoke debit | **NONE** |
| Source wallet provenance | `unavailable_not_reconstructed` |
| Idempotency key | `desk-refund-historical-settlement:{refund_id}` |

**Execution result:** **STOPPED** — all **52 / ₹34,517** rows blocked at destination policy gate.

| Stage | Count | Amount |
|---|---:|---:|
| Prepared (executable) | **0** | **₹0** |
| Blocked (missing destination CWID) | **52** | **₹34,517** |
| Executed | **0** | **₹0** |
| Reconciled | **0** | **₹0** |

---

## Why settlement is not source-wallet migration

P-30-10-19 proved:

- All 52 refunds completed via `ManualRefundExecutor` (`provider=manual`)
- No spoke `users_wallet` authoritative destination exists
- `execution_transaction_id` is NULL for all 52
- Desk administrative wallet completion without durable spoke trace

This settlement path **does not** claim historical spoke wallet credit occurred. Ledger metadata includes explicit disclaimer:

> The original spoke wallet destination could not be reconstructed from authoritative historical evidence. Owner authorized settlement of the historical refund amount through the Central Wallet settlement mechanism. This entry is NOT evidence that the original historical spoke wallet was credited.

---

## Owner approval

| Field | Value |
|---|---|
| Owner approval reference | `OWNER-CW-E2-HISTORICAL-SETTLEMENT-20261001-001` |
| Forensic report reference | `RadiumDesk-P-30-10-19` |
| Population authorization | Owner explicit authorization in P-30-10-20 prompt |

---

## Population integrity

| Check | Result |
|---|---|
| Source manifest | `cw-e2-historical-settlement-manifest-p30-10-20.json` |
| Campaign rule | P-30-10-15 class B + `local_user_id` null |
| Count / amount | **52 / ₹34,517** |
| Manifest SHA-256 | `48f173daad2dda5bc98fae7c91ea27cbcecd937a07d941a6b2cb70f00eb4dfe0` |
| Overlap E-1 (168) | **0** |
| Overlap 263/265/266/300/360 | **0** |
| Overlap 14 class-D SOURCE_RECON | **0** |
| Overlap 53 reconciled | **0** |

**Sites:** rdservice.in **49**, radiumbox.com **2**, rdservice.net **1**

---

## Destination policy — STOP gate

**Policy (fail-closed):**

1. **Preferred:** Existing trusted Desk Customer + CWID already associated with the refund customer
2. **Otherwise:** STOP — do not infer from email/mobile/name/amount/timestamps

**P-30-10-16 identity audit result for all 220 IDENTITY_REQUIRED (including E-2):**

| Evidence | Count |
|---|---:|
| Desk Customer ID | **0** |
| CWID | **0** |
| Trusted credential | **0** |
| Active account link | **0** |

**Manifest rows:** all 52 have `desk_customer_id: null`, `cwid: null`, `blocked_reason: no_trusted_desk_customer_or_cwid`.

**No new CWIDs were created** from unverified historical data.

---

## Pre-change financial state (verified)

| Check | Value |
|---|---|
| Central Wallet ledger credits | **53 / ₹27,725** |
| Reconciled migration journal | **53** |
| `CENTRAL_WALLET_REFUND_MIGRATION_EXECUTION_ENABLED` | **false** |
| `CENTRAL_WALLET_BALANCE_MIGRATION_EXECUTION_ENABLED` | **false** |

**Post-change (no execution):** unchanged

---

## Implementation

### New components

| Component | Purpose |
|---|---|
| `E2HistoricalSettlementClassification` | Settlement type constants + audit disclaimer |
| `E2HistoricalSettlementIdempotencyKey` | `desk-refund-historical-settlement:{refund_id}` |
| `RefundMigrationLane::Lane4OwnerApprovedHistoricalSettlement` | Distinct lane from spoke cutover / provenance |
| `E2HistoricalSettlementManifestLoader` | Immutable 52-row manifest validation + SHA-256 |
| `E2HistoricalSettlementJournalImportService` | Import → journal (`pending` without CWID) |
| `E2HistoricalManualRefundSettlementService` | CW credit only, no spoke interaction |
| `E2HistoricalSettlementBatchGate` | Fail-closed destination + manifest gate |
| `E2HistoricalSettlementOrchestrator` | Batch/single execution |
| `E2HistoricalSettlementDryRunService` | Non-writing readiness report |

### Artisan commands

```bash
php artisan central-wallet:e2-historical-settlement-import
php artisan central-wallet:e2-historical-settlement-dry-run --owner-approval-ref=OWNER-CW-E2-HISTORICAL-SETTLEMENT-20261001-001
php artisan central-wallet:e2-historical-settlement-execute --owner-approval-ref=... --force
```

### Rollback

Reuses `RefundMigrationRollbackService` with lane-aware reversal key:

- `desk-refund-historical-settlement-rollback:{refund_id}`
- Reverses settlement ledger entry only
- Does **not** modify historical refund Desk status
- Does **not** invent spoke wallet state

---

## Dry-run result (expected)

After journal import:

| Metric | Value |
|---|---|
| `journal_count` | 52 |
| `journal_amount` | ₹34,517 |
| `executable_count` | **0** |
| `blocked_count` | **52** |
| `expected_spoke_debit` | **₹0** |
| `expected_cw_credits` | **₹0** |
| `batch_executable` | **false** |
| Primary blocker | `missing_destination_cwid` (×52) |

---

## Unblocking path (Owner action required)

For each refund row, Owner must provide **one** of:

1. **Trusted identity establishment** via existing ceremony / verified credential paths (not unverified email inference), producing Desk Customer + CWID + account link
2. **Explicit per-row designated settlement CWID** in an updated manifest with owner approval

Then:

1. Update manifest with `desk_customer_id` + `cwid` per row
2. Recompute manifest SHA-256
3. Import (or assign targets)
4. Dry-run must show `executable_count = 52`
5. Enable execution flag + execute with `--force`

---

## Artifacts

| File | Location |
|---|---|
| Settlement manifest | `storage/app/private/cw-e2-historical-settlement-manifest-p30-10-20.json` |
| Forensic report (input) | `docs/cw-e2-forensic-reconstruction-p-30-10-19.md` |
| This report | `docs/cw-e2-historical-settlement-p-30-10-20.md` |

---

## Financial safety verification

| Check | Result |
|---|---|
| Ledger credits executed | **0** |
| New settlement credits | **₹0** |
| Historical refund statuses changed | **NO** |
| Spoke wallets modified | **NO** |
| `execution_transaction_id` invented | **NO** |
| Execution flags | **OFF** |

---

## Tests

`tests/Feature/CentralWallet/E2HistoricalSettlementTest.php` — manifest validation, pending import, dry-run block, idempotent settlement credit (isolated test row with explicit CWID).
