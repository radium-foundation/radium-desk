# Owner decisions — permanent exclusions (recorded P-13-47)

Administrative record only. **Not** implementation or test-completion claims for skipped prompts.

| Prompt / row | Decision | Effective |
| --- | --- | --- |
| **P-13-44** | **SKIPPED — OWNER DECISION** | 2026-11-13 |
| Matrix **row 98** (`JulyLeaveReconcileCommandTest::test_reconcile_approves_pending_creates_missing_and_matches_matrix`) | **WONT FIX — PERMANENTLY OUT OF SCOPE** | 2026-11-13 |
| **P-13-46** | **SKIPPED — OWNER DECISION** | 2026-11-13 |
| Matrix **row 19** (`CashfreeHistoricalRecoveryCommandTest::test_failed_webhook_creates_missing_order_on_replay`) | **WONT FIX — PERMANENTLY OUT OF SCOPE** (Owner confirms Cashfree functionality working) | 2026-11-13 |

## Register handling

- Preserve historical failure evidence in P-12-32 artifacts.
- Do **not** mark excluded rows **VERIFIED FIXED** or **PASS**.
- Exclude rows **19** and **98** from **active remediation counts** in companion registers updated after P-13-47.
- Do not reopen without explicit Owner reversal.
