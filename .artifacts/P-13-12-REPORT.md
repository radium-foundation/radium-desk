# P-13-12 Report — RadiumBox backfill cluster

| Field | Value |
| --- | --- |
| Global Prompt ID | **P-13-12** |
| Ledger ID | **RadiumDesk-P-13-12** |
| Branch | `feature/p13-12-radiumbox-backfill` |
| Worktree | `.worktrees/p13-12-radiumbox-backfill` |
| Baseline | `3fd6975c5376d37f1806de0c20490b44d31614da` (`origin/main` post PR #212) |
| Program baseline | `f358ab04a9a57623085f19c47c6e0045d9b0a7ea` |
| Report started | 2026-11-13 09:38:00 IST |
| Report completed | 2026-11-13 09:42:30 IST |

## Assigned rows (2)

| CSV line | Test method | Status |
| --- | --- | --- |
| 127 | `BackfillRadiumBoxOrdersCommandTest::test_order_with_manual_serial_and_missing_device_model_is_skipped_when_device_model_assigned` | **VERIFIED FIXED** |
| 128 | `BackfillReadyQueueCommandTest::test_evaluates_eligibility_for_validated_unassigned_case` | **VERIFIED FIXED** |

Shard source: `.artifacts/P-13-07-REPORT.md` § P-13-12.

## Root cause and evidence

| Row | Classification | Label | Summary |
| --- | --- | --- | --- |
| 127 | Application defect | **VERIFIED** | `radiumbox:backfill-orders` used `RadiumBoxService::needsEnrichment()` for `already_complete`. That gate also requires product name and service history, so manual serial + `device_model_id` still queued enrichment on `--order=`. |
| 128 | Application defect | **VERIFIED** | `radiumbox:backfill-ready-queue` used the same broad gate for `dispatch_enrichment`, dispatching `RadiumBoxOrderEnrichmentJob` on validated, synced orders that only lacked service history JSON. Test expects `evaluate_eligibility` without a job push. |

**Reproduction @ `3fd6975c` (pre-fix):** cluster **0/2** (~3.6s).

## Source/target and idempotency

| Command | Source | Target action | Idempotency / safety |
| --- | --- | --- | --- |
| `radiumbox:backfill-orders` | Paid Cashfree orders missing serial or device model (chunked query); optional `--order=` override | Queue `RadiumBoxOrderEnrichmentJob` via maintenance dispatcher | Skips `already_complete`, pending, expired window, interval; dry-run supported; **no live API** in command (async jobs only) |
| `radiumbox:backfill-ready-queue` | Active incidents with orders | Dispatch/retry enrichment **or** `evaluateAssignmentEligibility` | Skips ready-queue assignee, grace, non-service orders; tracks per-run enrichment dedupe; dry-run supported |

**Change:** Backfill commands now use `Order::isMissingDeviceEnrichment()` (serial + device model contract) for queue/skip decisions. Full `needsEnrichment()` remains on ingestion/jobs for warranty/service-history completeness — **no change** to job runtime idempotency.

**Real backfill / production data:** **NO — Not performed.**

## Behaviour changes

| Area | Preserved | Extended | Changed | Removed |
| --- | --- | --- | --- | --- |
| `radiumbox:backfill-orders` | Qualifying query, retry policy, manual override pacing | — | `already_complete` when serial + device model satisfied | Treating missing product/service history as incomplete for backfill |
| `radiumbox:backfill-ready-queue` | Pending/failed retry paths, grace skips, assignment eligibility | — | Dispatch only when device enrichment missing | Dispatching enrichment for synced orders with only non-device gaps |
| `RadiumBoxService::needsEnrichment` | Unchanged for jobs/sync | — | — | — |

## Tests

**Cluster:**

```bash
cd .worktrees/p13-12-radiumbox-backfill
FILTER='test_order_with_manual_serial_and_missing_device_model_is_skipped_when_device_model_assigned|test_evaluates_eligibility_for_validated_unassigned_case'
DESK_PHP_BIN=/opt/homebrew/bin/php ./bin/phpunit-isolated --filter "$FILTER" \
  tests/Feature/RadiumBox/BackfillRadiumBoxOrdersCommandTest.php \
  tests/Feature/RadiumBox/BackfillReadyQueueCommandTest.php
```

| Run | Result | Duration |
| --- | --- | --- |
| Cluster | **2/2 PASS** | 3284ms |
| Regressions (both test classes) | **30/30 PASS** | 27077ms |

Full PHPUnit suite: **NOT RUN**.

## Git / CI

| Item | Status |
| --- | --- |
| Commit | `10aac3e3` |
| PR | [#213](https://github.com/radium-foundation/radium-desk/pull/213) |
| CI | **rip** + **release-rip** PASS ([rip](https://github.com/radium-foundation/radium-desk/actions/runs/43363886573)) |
| Merge SHA | **`aaeb19354a63b62be5c444fbd670a761f72f8802`** |

## Data risks / Owner approval

None — test-only validation; no production writes. No Owner approval required.

## Overlap / ownership

No changes to P-12-49 dashboard snapshot rules or P-13-04 ready-queue eligibility services. Did not touch `radiumbox.com` repo.

## Production / deployment

**NO — Not performed** for production data changes and deployment.

## Wallet preservation

`feature/p18-33-unified-wallet-display` @ **`d94816153cd2c89f8175d29a1510b38b4c16df6b`** — **VERIFIED** unchanged.

## Stage durations

| Stage | Duration |
| --- | --- |
| Worktree + prechecks | ~3 min |
| Repro + trace | ~5 min |
| Fix + validation | ~4 min |
| Report + git gates | ~3 min |
