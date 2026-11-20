# P-20-27 — Permanent confirmed ineligibility → automatic B2C

| Field | Value |
| --- | --- |
| Task | **P-20-27** |
| Cursor ledger | `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef` |
| Risk | HIGH |
| Branch | `feature/p20-27-permanent-confirmed-b2c` |

## Summary

Decoupled **confirmed** B2B-ineligible automatic B2C from Stage B (`STATUTORY_BUYER_GSTIN_B2C_FALLBACK_ENABLED`). When buyer GST **verification is ON**, `ensureAutomaticConversion` runs for confirmed outcomes; provider uncertainty remains held. Manual `approve()` / `reject()` still require Stage B fallback flag.

## Code

| Item | Change |
| --- | --- |
| `BuyerGstB2cConversionPolicy` | `verificationPolicyEnabled()`, `automaticConversionActive()`; auto eligibility uses verification OR fallback |
| `CommerceOrderBuyerGstB2cConversionService` | `ensureAutomaticConversion` gated on `automaticConversionActive()` |
| Tests | Updated hold vs confirmed fixtures; hardware block tests use `provider_timeout` |

## Tests (this task)

| Suite | Result |
| --- | --- |
| Focused GST filter bundle | **67/68 PASS** (1 Imagick env error in billing rule PDF test) |
| MariaDB concurrency | See shell log |

Full PHPUnit: **NOT RUN** (per policy / P-20-24 eight failures documented).

## Production

**NOT deployed.** RBP2295 resolution **NOT RUN** — requires separate deploy authorization.

## Config

No new env vars. Production can remain `b2c_fallback=false` with verification `true`.