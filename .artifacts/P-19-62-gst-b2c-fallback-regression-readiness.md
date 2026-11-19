# P-19-62 — GST B2C fallback regression & rollout readiness

**ChatGPT ID:** P-19-62  
**Cursor ledger ID:** `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef`  
**Desk ledger row:** `RadiumDesk-P-19-62`  
**Start:** 2026-11-19T20:14:00+05:30 (approx.)  
**End:** UNKNOWN until merge (measured test window below)

## VERIFIED

- PR #365 merged to `main` @ `48fc21ea319adcaba9a5d1a6339200fd6cfbc249`.
- Production deploy identity **v4.1.101** / `7edf904bb` (pre P-19-62 merge on prod).
- Post-mint guard uses persisted `statutory_invoices` row match on channel + `commerce_order` `source_id`.
- Migration order: `120000` verifications → `130000` conversions (FK).

## INFERRED

- Production remains safe while both env flags default **false** and migrations pending.

## UNKNOWN

- MariaDB concurrency on CI (skipped locally without port `33118` disposable DB).
- Final merge SHA for P-19-62 until PR completes.

## Risk

**HIGH** — statutory classification, audit, concurrent approval, post-mint immutability.

## Changes (P-19-62)

| Category | Detail |
| --- | --- |
| **Preserved** | P-19-61 behaviour and policy gates |
| **Extended** | `CommerceOrderBuyerGstB2cFallbackTest` status matrix + post-mint + audit |
| **Added** | MariaDB worker harness + `BuyerGstB2cConversionMysqlConcurrencyTest` |
| **Added** | CA matrix, rollout runbook, this artifact |
| **Removed** | Nothing |

## Key regression tests

| Test | Result |
| --- | --- |
| `test_b2c_conversion_rejected_after_persisted_statutory_invoice_mint` | **PASSED** |
| `test_two_workers_concurrent_approval_single_conversion_record` | **SKIPPED** (no MariaDB disposable DB on loopback non-3306) |
| Provider failure matrix (timeout, 429, 5xx, malformed, provider_error) | **PASSED** (PHPUnit) |
| `test_provisional_registration_stays_b2b_and_is_not_conversion_eligible` | **PASSED** |

### Commands

```bash
phpunit tests/Feature/StatutoryInvoice/CommerceOrderBuyerGstB2cFallbackTest.php \
  tests/Feature/StatutoryInvoice/CommerceOrderBuyerGstVerificationTest.php \
  tests/Feature/StatutoryInvoice/BuyerGstB2cConversionMysqlConcurrencyTest.php
```

**Environment:** Worktree `.worktrees/p19-62-gst-b2c-regression`, SQLite + Http::fake; flags enabled in test `setUp` only.  
**Result:** 23 passed, 1 skipped, exit **0**, ~2.8s.

## Statutory rollout readiness

**NOT READY** — engineering regressions improved; **all CA/Finance decisions PENDING** (`docs/desk-p19-62-gst-b2c-fallback-ca-approval-matrix.md`).

## NO — Not performed

Production migration · backup · feature activation · deployment · WhiteBooks production · invoice **23163** / **RBP2295** · production data changes

## Unresolved blockers

1. CA/Finance written approval matrix.  
2. Production migration window for `120000` + `130000`.  
3. MariaDB concurrency proof in CI (provision disposable DB or accept SKIP with documented blocker).  
4. Protected-journey UAT before flag activation.