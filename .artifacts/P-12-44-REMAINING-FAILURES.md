# P-12-44 — Remaining failures register (reconciled P-13-48)


**Matrix baseline:** P-12-32 @ `27b03d19`; **P-13-48 refresh** @ `origin/main` **`51ee6d7e2c185bc61383636969b0e1c975ecf962`** (post PR #245).


## Totals (recomputed from 228 rows)


| Classification | Count | Notes |
| --- | ---: | --- |
| **VERIFIED FIXED** | **224** | P-13-50 row **220** (test harness admin settings cache) |
| **STILL FAILING** (active) | **2** | Actionable remediation targets |
| **OWNER EXCLUDED** | **2** | Rows **19**, **98** — permanently out of scope |
| **ENVIRONMENT BLOCKED** | **0** | CaMonthlyReport harness marked VERIFIED FIXED on main |
| **UNKNOWN / unverified** | **0** | — |

**Arithmetic:** 224 + 2 + 2 = **228** historical matrix rows.

**Active remediation total** (excludes Owner-excluded): **2** STILL FAILING.

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


| Row | Test | Last evidence |
| ---: | --- | --- |
| **135** | `RefundExecutionReferenceGuardTest::test_wallet_completion_ignores_manual_execution_reference_fields` | P-12-32 @ `27b03d19` → ERROR |
| **136** | `RefundStatutoryAdjustmentTest::test_generated_credit_note_follows_ca_monthly_negative_offset_treatment` | P-12-32 @ `27b03d19` → FAIL |
## STILL FAILING by workstream (active only)


| Workstream | Count |
| --- | ---: |
| WS-10 | **2** |
