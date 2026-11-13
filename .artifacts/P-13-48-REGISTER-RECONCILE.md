# P-13-48 — Canonical register reconciliation

| Global Prompt | **P-13-48** |
| Cursor ledger | **RadiumDesk-P-13-48** |
| Baseline SHA (start) | **`51ee6d7e2c185bc61383636969b0e1c975ecf962`** |
| Started | **2026-11-13 ~15:39 IST** |
| Wallet WIP | **`d9481615`** unchanged |

## Row-by-row classification changes

| Row | Test (short) | From | To | Merge / evidence |
| ---: | --- | --- | --- | --- |
| **10** | AssignReferencePhase9Performance batch coalesce | STILL FAILING | **VERIFIED FIXED** | PR **#243** `70b3843c`; spot PASS P-13-48 |
| **15** | BonvoiceLiveCallAssist enrichment | STILL FAILING | **VERIFIED FIXED** | PR **#244** `4d3b9d89`; spot PASS P-13-48 |
| **19** | CashfreeHistoricalRecovery replay | STILL FAILING | **OWNER EXCLUDED** | P-13-46; historical FAIL preserved |
| **98** | JulyLeaveReconcile matrix | STILL FAILING | **OWNER EXCLUDED** | P-13-44; historical FAIL preserved |
| **124** | PresenceEngine superadmin login | VERIFIED FIXED (stale evidence) | **VERIFIED FIXED** | PR **#245** `51ee6d7e`; evidence finalized |
| **125** | ProductOrderRequestSerial UI | STILL FAILING | **VERIFIED FIXED** | PR **#241** `0e2e2261`; spot PASS |
| **189** | WorkforceEventIsolation LeaveCancelled | STILL FAILING | **VERIFIED FIXED** | PR **#242** `aaed2900`; spot PASS |
| **202** | WorkspaceRemarkAction refresh | STILL FAILING | **VERIFIED FIXED** | PR **#241** `0e2e2261`; spot PASS |

## Verified unchanged (spot-check)

| Row | Status | Evidence |
| ---: | --- | --- |
| **188** | VERIFIED FIXED | PR **#239** `65493e8b`; P-13-40 register — no CSV change |

## Totals after reconcile

| # | Bucket | Count |
| ---: | --- | ---: |
| 1 | Historical rows | **228** |
| 2 | VERIFIED FIXED | **222** |
| 3 | STILL FAILING (active) | **4** |
| 4 | ENVIRONMENT BLOCKED | **0** |
| 5 | UNKNOWN / unverified | **0** |
| 6 | OWNER EXCLUDED | **2** |
| 7 | **Active remediation** | **4** (= STILL FAILING only) |

## Spot validation (P-13-48)

```text
bin/phpunit-isolated --filter test_batch_assign_coalesces|test_existing_customer_creates_agent|...
exit 0 · 6/6 tests · 63 assertions · 7660 ms @ 51ee6d7e
```

Full suite: **NOT RUN**.
