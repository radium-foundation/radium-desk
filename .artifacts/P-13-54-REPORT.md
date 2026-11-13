# P-13-54 — CA monthly refund cancellation reporting (matrix row 136)

| Global | **P-13-54** | Ledger | **RadiumDesk-P-13-54** |
| --- | --- | --- | --- |
| Start (IST) | 2026-11-13 (session; implementation continued post-handoff) | End (IST) | **2026-11-13 16:13:26 IST** |
| Baseline HEAD | **`44c48ab15338e4f6cf4be1afefe325067355e065`** | Final HEAD | *(filled after commit)* |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | PR | *(filled after create)* |

## Business rule (Owner-authorized)

- November 2026 CA monthly report period: **`2026-11-01`–`2026-11-30`** (app `ReportPeriod` / `issued_at` convention).
- Refund credit notes: selected by **real `statutory_invoices.issued_at`** in period.
- Eligible cancellations: initiation **≥ `2026-09-07`** (inclusive), config `ca_monthly_report.refund_cancellation_eligible_on_or_after`.
- Paired original invoices included when linked credit note is eligible (even if original issued in an earlier month).
- **No** backdating of credit note `issued_at`.

## Source of truth — cancellation initiation

1. **Primary:** `refund_requests.executed_at` when `refund_statutory_adjustments.status = succeeded` on the original invoice.
2. **Fallback:** `statutory_invoice_cancellations.completed_at` on the original when no succeeded RSA exists.

## Application changes

| File | Action |
| --- | --- |
| `app/Reports/CaMonthly/CaMonthlyReportCancellationReportingScope.php` | **Added** — period + initiation gate |
| `app/ReadModels/Finance/CaMonthlyStatutoryLineReadModel.php` | **Changed** — delegate filter to scope |
| `config/ca_monthly_report.php` | **Extended** — eligibility date config |
| `app/Reports/CaMonthly/CaMonthlyReportDefinition.php` | **Changed** — docblock only |
| `tests/Feature/Finance/CaMonthlyReportRefundCancellationReportingTest.php` | **Added** — 15 regressions |
| `tests/Feature/Refunds/RefundStatutoryAdjustmentTest.php` | **Changed** — row 136 + `withoutVite()` |
| `tests/Feature/Finance/CaMonthlyReportTest.php` | **Changed** — eligible initiation fixtures |
| `.artifacts/P-12-32-matrix-reconciliation.csv` | Row **136** → **VERIFIED FIXED** |
| `.artifacts/P-12-44-REMAINING-FAILURES.md` | **226** VERIFIED / **0** active failing |

## Validation (measured)

| Check | Result | Evidence |
| --- | --- | --- |
| Row 136 `RefundStatutoryAdjustmentTest::test_generated_credit_note_*` | **PASSED** | 1 test, 8 assertions, ~2421ms |
| `RefundStatutoryAdjustmentTest` (full class) | **PASSED** | 16/16, 50 assertions, ~17839ms |
| `CaMonthlyReportRefundCancellationReportingTest` | **PASSED** | 15/15, 48 assertions, ~14421ms |
| `CaMonthlyReportTest` (credit-note pair tests) | **PASSED** | 2/2, 19 assertions, ~3202ms |
| Pint (`app/Reports/CaMonthly/CaMonthlyReportCancellationReportingScope.php` + read model + config) | **PASSED** | `vendor/bin/pint --test` |
| Full PHPUnit suite | **NOT RUN** | Per policy |
| Production deploy / prod data | **NO** | Not performed |
| Refunds / payments / wallet execution | **NO** | Reporting selection only |

## Wallet WIP preservation

Primary clone verified: **`feature/p18-33-unified-wallet-display`** @ **`d9481615`** — **unchanged**.

## Remaining risks

- Production November 2026 run must use the configured eligibility date and existing RSA/cancellation data quality.
- Credit notes without initiation evidence remain excluded (intentional fail-safe).
