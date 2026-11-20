# P-20-23 — Permanent automatic B2C implementation report

**Task:** P-20-23  
**Cursor ledger:** `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef`  
**Branch:** `feature/p20-23-permanent-auto-b2c`  
**Target:** `main`  
**Risk:** HIGH (GST classification, statutory invoicing)

## Summary

Narrowed automatic B2C to **confirmed B2B ineligibility** only; provider uncertainty and `Pending` hold without automatic conversion. Exceptional manual B2C via `approve()` remains for technical/operational outcomes. Unified commerce product (verification on) and service classification through `ServiceStatutoryGstB2bClassification`. Hardware mint uses shared buyer GSTIN resolver and links conversion after mint.

## Activation

- **Production:** `STATUTORY_BUYER_GSTIN_B2C_FALLBACK_ENABLED` remains **OFF** (no production flag change in this task).
- When ON: inactive/invalid confirmed outcomes auto-convert without manual `approve()`.

## RBP2295

No production mutation. Order **23661** / **RBP2295** unchanged and un-invoiced.

## Tests (Cursor)

| Suite | Result | Detail |
| --- | --- | --- |
| GST B2C filter bundle | PASS 59/59 | exit 0, ~176s |
| `BuyerGstB2cConversionMysqlConcurrencyTest` (workers) | PASS 1/1 | ~147s |
| `StatutoryCommerceMintMysqlConcurrencyTest` | PASS 5/5 | ~445s |
| Full PHPUnit | NOT RUN | Owner gate |

## Rollback

Revert merge commit; no migrations. Disable fallback flag if already enabled in a future deploy.