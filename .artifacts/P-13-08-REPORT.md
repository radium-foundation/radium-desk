# P-13-08 — WS-10 statutory issuance cluster (7 rows)

| Field | Value |
| --- | --- |
| Global Prompt ID | **P-13-08** |
| Cursor ledger ID | **RadiumDesk-P-13-08** |
| Repository | `/Users/ravi/RadiumWebsites/radium-desk` |
| Worktree | `.worktrees/p13-08-statutory-issuance` |
| Branch | `feature/p13-08-statutory-issuance` |
| Baseline | `f358ab04a9a57623085f19c47c6e0045d9b0a7ea` (`origin/main`) |
| Start | **2026-11-13 09:04:00 IST** (resumed implementation pass) |
| End | **2026-11-13 09:13:18 IST** |
| Elapsed (implementation) | **~9 minutes** |

## Assigned matrix rows (WS-10)

| CSV line | Test |
| ---: | --- |
| 20 | `Customer360StatutoryInvoiceActionsTest::test_customer_360_shows_failed_when_b2b_statutory_data_is_incomplete` |
| 164 | `EInvoiceIssuancePathTest::test_commerce_b2b_service_is_blocked_in_phase_a_and_queued_in_phase_b` |
| 165 | `IrnInputSnapshotTest::test_commerce_ingest_preserves_valid_uqc_and_rejects_invalid` |
| 166 | `IrnInputSnapshotTest::test_commerce_uqc_and_structured_billing_are_snapshotted_at_mint` |
| 167 | `RdServiceNetPhase1CleanTest::test_invalid_gstin_and_missing_statutory_data_fail_closed` |
| 168 | `ServiceGstSplitIssuanceTest::test_b2b_split_follows_seller_state_and_place_of_supply_not_billing_state` |
| 169 | `ServiceStatutoryInvoiceReconciliationBacklogTest::test_rdservice_net_ra_prefix_orders_are_reconciled` |

## Root-cause classification

| Row | Classification | Finding (VERIFIED / INFERRED) |
| ---: | --- | --- |
| 20 | **APPLICATION** | **VERIFIED** — `StatutoryBillingAddressComposer` dropped redundant `line2` at mint via `dropRedundantLine2`, shrinking IRP canonical text below 200 chars so Customer 360 showed plain **Pending**. Fix: skip redundant `line2` removal when IRP canonical (line1 + line2) exceeds 200. |
| 164 | **TEST HARNESS** | **VERIFIED** — Fixture used Delhi GSTIN (`07…`) with Maharashtra billing/POS → service B2C downgrade / wrong skip reason. Aligned billing/POS/branch to Delhi for Delhi GSTIN. |
| 165–166 | **APPLICATION** | **VERIFIED** — `commerce_order_items.uqc` not fillable; channel ingest omitted UQC validation/persist. Added fillable, draft field, validator rules + `EInvoiceUqcMapper` rejection, ingest persist. |
| 167 | **TEST HARNESS** (product-aligned) | **VERIFIED** — `ServiceStatutoryGstB2bClassification` downgrades invalid GSTIN to B2C mint (documented in class). Test updated: invalid GSTIN mints B2C with null buyer GSTIN; missing statutory data still fail-closed. |
| 168 | **TEST HARNESS** | **VERIFIED** — GSTIN/billing/POS mismatch triggered B2C; inter-case expected B2B with incompatible POS/GSTIN. Fixtures aligned to B2B rules (MH intra; Karnataka buyer for Delhi-seller IGST). |
| 169 | **TEST HARNESS** | **VERIFIED** — `RdServiceNet` requires `statutory_auto_issue_approved_at` per `ServiceStatutoryAutoIssuancePolicy`. Test seeds approval before reconcile. |

## Behaviour

| Area | Preserved / Extended / Changed |
| --- | --- |
| IRP address limits | **Extended** — redundant `line2` dedup unchanged when IRP canonical ≤ 200; **preserved** when > 200 for fail-closed readiness/C360. |
| Channel ingest UQC | **Extended** — valid NIC codes persisted on lines; invalid codes rejected at ingest. |
| Service B2C downgrade | **Preserved** — invalid GSTIN still downgrades; no mint blocking added. |
| Statutory validation | **Preserved** — no weakening of eligibility or IRN rules. |

## Tests

| Command | Result | Duration |
| --- | --- | ---: |
| Cluster filter (7 tests + `test_redundant_line2_over_irp_limit_is_preserved_for_readiness_gaps`) | **PASSED** 8/8 | ~8.0s |
| `tests/Feature/StatutoryInvoice/IrnInputSnapshotTest.php` | **PASSED** 11/11 | ~11.8s |
| `tests/Unit/StatutoryInvoice/StatutoryBillingAddressComposerTest.php` | **PASSED** 6/6 | ~0.5s |
| Full PHPUnit suite | **NOT RUN** (policy) | — |
| Pint `--dirty` | **PASSED** | ~2s |

## Checks

| Check | Status |
| --- | --- |
| Focused cluster regressions | **PASSED** |
| Related statutory ingest/billing regressions | **PASSED** |
| Canonical matrix CSV | **NOT MODIFIED** |
| Full CI on `origin/main` | **NOT RUN** (pre-PR) |
| PR CI | *see commit section after push* |
| Production deploy | **NO — Not performed** |
| Production data changes | **NO — Not performed** |

## Git / PR

| Item | Value |
| --- | --- |
| Commit | *filled after push* |
| PR | *filled after push* |
| Merge SHA | *pending Owner/CI* |

## Wallet WIP

| Check | Result |
| --- | --- |
| Branch `feature/p18-33-unified-wallet-display` @ `d94816153cd2c89f8175d29a1510b38b4c16df6b` | **Unchanged** (verify-only) |

## Remaining risks / Owner decisions

- Long redundant real-world addresses (>200 IRP) may retain duplicate `line2` in structured snapshots until remediated (intentional fail-closed for e-invoice).
- PR merge and post-merge matrix re-run remain Owner/CI gated.

## Stage durations (measured)

| Stage | Duration |
| --- | ---: |
| Autoload fix + implementation | ~6 min |
| Focused test iterations | ~2 min |
| Report + git | ~1 min |

## Other unexecuted actions

| Action | Status |
| --- | --- |
| Deployment | **NO — Not performed** |
| Self-approve / bypass branch protection | **NO — Not performed** |
