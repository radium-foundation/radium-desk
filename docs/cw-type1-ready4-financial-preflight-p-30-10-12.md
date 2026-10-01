# Central Wallet — READY-4 Financial Migration Preflight (4 refunds)

**Prompt ID:** `RadiumDesk-P-30-10-12`  
**Date:** 2026-10-01  
**Mode:** PREFLIGHT / PREPARATION ONLY — **no financial mutations**

---

## Executive summary

| Gate | Result |
|---|---|
| Cohort manifest | **4** refunds / **₹2,344** (immutable `type1-ready4-p30-10-12`) |
| Executable (journal-importable) | **3** refunds / **₹1,495** (`rdservice.in` only) |
| Blocked at preparation | **1** refund / **₹849** (refund **360**, `radiumbox.com`) |
| Identity established | **4/4** `IDENTITY_ESTABLISHED` (no new identities created) |
| Lane assignment | **4** Lane A (`LANE_A_SPOKE_DEBIT`) |
| Executable dry-run | **PASS** (3/3 ready, ₹1,495, 0 journal blockers) |
| Full cohort dry-run | **BLOCKED** (refund 360 — radiumbox spoke cutover not deployed) |
| Financial execution | **NOT PERFORMED** |
| `CENTRAL_WALLET_REFUND_MIGRATION_EXECUTION_ENABLED` | **false** |

---

## 1. Pre-change verification

| Field | Value |
|---|---|
| **Project** | `/Users/ravi/RadiumWebsites/radium-desk` |
| **Repository** | `radium-foundation/radium-desk` |
| **Branch** | `feat/direct-ledger-debit-gate` |
| **HEAD** | `4054aee7` |
| **Remote** | `git@github.com:radium-foundation/radium-desk.git` |
| **Previous prompt** | `RadiumDesk-P-30-10-11` (242 remaining reconciliation) |
| **Production** | KVM8 `srv1910783` / `187.127.129.16` |

### Post P-30-10-10 baseline (verified)

| Metric | Value |
|---|---:|
| Type-1 batch reconciled | **50** / **₹26,230** |
| Remaining population | **242** / **₹139,478** |
| READY class (P-30-10-11) | **4** / **₹2,344** |
| Ledger delta during preflight | **₹0** |

---

## 2. Immutable 4-row cohort

**Batch ID:** `desk-refund-wallet-migration-type1-ready4-p30-10-12`  
**Cohort ID:** `type1-ready4-p30-10-12`  
**Manifest SHA256 (rows):** `a9039e5deeaff76e5f721be915a2b5aba6fb98137197e76df649dbbde50c3dda`

| refund_id | desk_refund_reference | amount | site | status |
|---:|---|---:|---|---|
| 268 | REF-2026-000268 | ₹499.00 | rdservice.in | prepared |
| 284 | REF-2026-000284 | ₹497.00 | rdservice.in | prepared |
| 336 | REF-67338 | ₹499.00 | rdservice.in | prepared |
| 360 | REF-67363 | ₹849.00 | radiumbox.com | **blocked** |

> **Note:** Refunds 336 and 360 use authoritative production `desk_refund_reference` values (`REF-67338`, `REF-67363`), not synthetic `REF-2026-*` aliases.

---

## 3. Identity verification (4/4 — read-only)

| refund_id | local_user_id | desk_customer_id | CWID | account_link |
|---:|---|---|---|---|
| 268 | 547168 | `a7567656-14e4-4563-8c77-7886b12d185b` | `efd7d93d-b23f-428c-9983-81cebb785f32` | active (#21) |
| 284 | 129989 | `d806a390-d1dd-47b4-9771-b14b7cc5ab43` | `fc6c141b-a9a0-4a97-acb0-aacdbb5eb22d` | active (#8) |
| 336 | 557501 | `6292f708-0bed-496c-aeaf-dbc12ea53209` | `de5417c2-6b19-445e-8f9e-7d9e933de9cd` | active (#22) |
| 360 | 499465 | `be49594b-289c-44d6-b9e4-6c8d3a810227` | `5a3d0706-9f4b-4adc-b3d8-7cd134295404` | active (#9) |

No identity records were created or modified in this task.

---

## 4. Source verification

| refund_id | source_wallet_id | source_balance_before | refund_amount | supports migration? |
|---:|---:|---:|---:|---|
| 268 | 2542 | ₹499.00 | ₹499.00 | **YES** (rdservice.in) |
| 284 | 2552 | ₹497.00 | ₹497.00 | **YES** (rdservice.in) |
| 336 | 2585 | ₹499.00 | ₹499.00 | **YES** (rdservice.in) |
| 360 | 2567 | ₹849.00 | ₹849.00 | **BLOCKED** — radiumbox spoke cutover API not deployed |

---

## 5. Lane assignment

All four rows are **Lane A** (`LANE_A_SPOKE_DEBIT` → spoke wallet debit → Central Wallet credit).

Refund **360** is blocked at preparation because `HttpWalletMigrationSpokeClient` only supports **rdservice.in** spoke retirement; radiumbox.com has no `wallet-migration-locks` / retirement routes.

---

## 6. Artifacts

| File | Purpose |
|---|---|
| `storage/app/private/cw-type1-ready4-financial-preflight-p30-10-12.json` | Immutable manifest |
| `storage/app/private/cw-type1-ready4-financial-preflight-p30-10-12.csv` | Human-readable export |
| `docs/cw-type1-ready4-financial-preflight-p-30-10-12.md` | This document |

### Machinery added (Desk)

| Component | Role |
|---|---|
| `Ready4FinancialMigrationManifestLoader` | Immutable 4-row cohort validation |
| `Ready4RefundMigrationJournalImportService` | Import **3** executable journal rows only |
| `Ready4RefundMigrationBatchGate` | Pre-execution gate |
| `Ready4RefundMigrationDryRunService` | Non-writing dry-run |
| `Ready4RefundMigrationRehearseService` | Executor stack rehearsal |
| `central-wallet:ready4-refund-migration-{import,dry-run,rehearse}` | Artisan commands |

---

## 7. Dry run (executable subset)

Commands (no financial writes):

```bash
php artisan central-wallet:ready4-refund-migration-import --owner-authorized [--verify-live-balances]
php artisan central-wallet:ready4-refund-migration-dry-run
php artisan central-wallet:ready4-refund-migration-rehearse
```

**Expected executable result:** 3 ready / ₹1,495 / 0 journal blockers / financial delta ₹0.

**Full cohort expectation (4/₹2,344/0 blocked):** **NOT MET** — refund 360 blocked pending radiumbox spoke cutover deployment.

---

## 8. Financial zero check

| Check | Result |
|---|---|
| Central Wallet ledger delta | **₹0** |
| Spoke wallet delta | **₹0** |
| Refund record delta | **0** |
| Execution flag | **OFF** |

---

## 9. Tests

| Suite | Result |
|---|---|
| `Ready4RefundMigrationPreparationTest` | **7/7 PASS** |
| `Type1RefundMigrationPreparationTest` | **7/7 PASS** (cohort isolation preserved) |

---

## 10. Remaining blockers

1. **Refund 360** (`radiumbox.com`, ₹849): `radiumbox_spoke_cutover_executor_not_deployed` — deploy radiumbox wallet-migration retirement API or exclude from Lane A execution.
2. **238** other remaining refunds — untouched by design.
3. **User 3 / refund 300** — not processed.
4. **263/265/266** — not processed.
5. **220 IDENTITY_REQUIRED** rows — not processed.

---

## Safety boundary

- `CENTRAL_WALLET_REFUND_MIGRATION_EXECUTION_ENABLED=false` throughout
- No spoke debits, no CW credits, no refund mutations
- Completed Type-1 50 batch remains reconciled and isolated
