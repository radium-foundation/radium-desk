# GENERATE recovery boundary — P-07-09-206

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-206  
**Date:** 2026-09-10  
**Before SHA:** `b82eb67deb46dbe4b149006a60b0aff8f36615d2`

Fixes the two P-205 BLOCKERs: crash-after-GENERATE must not GENERATE again, and ambiguous GENERATE must remain recoverable through Get-IRN.

This gate is implementation + tests only. Automatic issuance remains OFF.

## Architecture

GENERATE is no longer inside the persist transaction.

```text
lock invoice + claim
  → commit status=processing
WhiteBooks authenticate + GENERATE   (no DB transaction)
  → persist IRN/Ack/QR/SignedInvoice/outbox in a new transaction
```

| GENERATE outcome | Record status | Next action | Outbox |
|---|---|---|---|
| Success + persist | `submitted` | none | completed |
| Success + persist crash | `ambiguous` (or leftover `processing`) | Get-IRN only | pending |
| Timeout / 5xx / GENERATE 429 / malformed 2xx | `ambiguous` | Get-IRN only | pending |
| Proven pre-submit (auth timeout / auth 429) | `temporary_failure` | GENERATE retry allowed | pending |
| Get-IRN 2154 | `irn_not_found` | no GENERATE | can complete |
| Get-IRN temp/ambiguous | `ambiguous` | Get-IRN only | pending |

Stale outbox (≈5 minutes) can return to `pending`. If the e-invoice row is `processing` or `ambiguous`, the next worker is recover-only. The timeout is not increased.

HTTP 429 on GENERATE is Ambiguous because the request may already have reached WhiteBooks. Authenticate 429 remains TemporaryFailure (GENERATE was not sent).

No schema migration. Existing `processing` / `ambiguous` / `irn_not_found` statuses are sufficient.

## Production

Not performed: GENERATE, Get-IRN, Cancel, invoice creation, provider enablement, worker enablement, auto-issue, deploy.

Production remains:

```text
STATUTORY_EINVOICE_PROVIDER=none
Gateway=NullEInvoiceGateway
worker_may_mint=false
auto_issue_on_pos_complete=false
```

## Remaining P-205 blockers (intentionally not this gate)

- Hardware vs service allowlist
- Cancelled / tax-invoice eligibility (dirty worktree, not HEAD)
- Duplicate WhiteBooks error-code → recover mapping
- Production Composer bacon lockfile vs overlay

Automatic issuance is still **NOT READY**.
