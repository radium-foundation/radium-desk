# P-13-55 — Statutory cancellation workflow path filter gap

| Global | **P-13-55** | Ledger | **RadiumDesk-P-13-55** |
| --- | --- | --- | --- |
| Baseline | **`dce22076c22bdeb75d950ff452755d2dd4072737`** | Final HEAD | *(post-commit)* |
| Start (IST) | 2026-11-13 ~16:17 | End (IST) | 2026-11-13 ~16:22 |

## Root cause

`statutory-cancellation-phpunit` uses GitHub `paths` filters on `pull_request` / `push`. PR **#250** (P-13-54) only touched CA monthly reporting, `ca_monthly_report` config, and refund statutory / CA PHPUnit files. None of those paths were listed. GitHub therefore **did not schedule** the workflow (expected behaviour for path-filtered workflows; not a runner failure).

## Correction

**Extended** path lists (PR + push) with refund-driven credit note + CA monthly cancellation reporting paths, and **added** a focused PHPUnit step so the job exercises those regressions when triggered.

## Validation

| Check | Result |
| --- | --- |
| YAML load | **PASSED** |
| fnmatch vs P-13-54 app/test files (8/8) | **PASSED** (artifacts-only paths do not match) |
| fnmatch unrelated `User.php` / `README.md` | **no match** |
| Local CI bundle (31 + 2 tests) | **PASSED** (~31s + ~3s) |
| Full PHPUnit | **NOT RUN** |
| Prod deploy / data | **NO** |

## Wallet WIP

Primary **`feature/p18-33-unified-wallet-display`** @ **`d9481615`** — verify only, unchanged.
