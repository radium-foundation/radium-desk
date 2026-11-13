# P-12-44 — Remaining failures register (reconciled P-13-48)


**Matrix baseline:** P-12-32 @ `27b03d19`; **P-13-48 refresh** @ `origin/main` **`51ee6d7e2c185bc61383636969b0e1c975ecf962`** (post PR #245).


## Totals (recomputed from 228 rows)


| Classification | Count | Notes |
| --- | ---: | --- |
| **VERIFIED FIXED** | **226** | P-13-54 row **136** (CA monthly refund cancellation reporting) |
| **STILL FAILING** (active) | **0** | — |
| **OWNER EXCLUDED** | **2** | Rows **19**, **98** — permanently out of scope |
| **ENVIRONMENT BLOCKED** | **0** | CaMonthlyReport harness marked VERIFIED FIXED on main |
| **UNKNOWN / unverified** | **0** | — |

**Arithmetic:** 225 + 1 + 2 = **228** historical matrix rows.

**Active remediation total** (excludes Owner-excluded): **0** STILL FAILING.

### Delta vs prior P-12-44 (216 VERIFIED / 12 STILL FAILING)

| Movement | Rows | Reason |
| --- | --- | --- |
| STILL FAILING → VERIFIED FIXED | **10**, **15**, **125**, **189**, **202** | Merged #243–#244, #241–#242; P-13-48 spot PASS @ `51ee6d7e` |
| STILL FAILING → VERIFIED FIXED (already on CSV) | **124** | PR #245; evidence string finalized P-13-48 |
| STILL FAILING → OWNER EXCLUDED | **19**, **98** | P-13-44 / P-13-46 Owner decisions (`.artifacts/P-13-47-OWNER-DECISIONS.md`) |
| Prior duplicate stale list | — | P-12-44 listed **124** failing while CSV had begun VERIFIED FIXED; reconciled |

Rows **188** remained **VERIFIED FIXED** from P-13-39 / #239 (unchanged).

## OWNER EXCLUDED (not active)


| Row | Test | Decision |
| ---: | --- | --- |
| **19** | `CashfreeHistoricalRecoveryCommandTest::test_failed_webhook_creates_missing_order_on_replay` | **WONT FIX** — P-13-46 |
| **98** | `JulyLeaveReconcileCommandTest::test_reconcile_approves_pending_creates_missing_and_matches_matrix` | **WONT FIX** — P-13-44 |

Historical P-12-32 failure evidence preserved in matrix `evidence` column. **Not** marked PASS or VERIFIED FIXED.

## STILL FAILING (active)

None (row **136** → **VERIFIED FIXED** via P-13-54).

## STILL FAILING by workstream (active only)

| Workstream | Count |
| --- | ---: |
| — | **0** |
