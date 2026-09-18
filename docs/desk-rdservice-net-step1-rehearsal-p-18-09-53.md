# Radium Desk — Step 1 isolated E2E rehearsal (P-18-09-53)

**Companion:** RDServiceNet-P-18-09-03  
**Date:** 2026-09-18  
**Implementation HEAD:** `c6ce97da` (not deployed; production remains v4.0.88 @ `c2b24169`)

## Staging environment verdict

| Item | Evidence |
|------|----------|
| Production Desk | `https://desk.radiumbox.com` → `/var/www/radium-desk`, `APP_ENV=production`, DB `radium_desk` |
| Desk staging | **NONE** — no staging directory or vhost (SSH re-verified 2026-09-18) |
| Rehearsal used | Local isolated SQLite + `php artisan serve` on `127.0.0.1:19876` |
| Production isolation | **YES** |

## Rehearsal coverage

- Aligned `rdservice_net` ingest: seller_gstin, gst_percentage, billing state, branch_code.
- HMAC-SHA256 `{timestamp}{rawBody}`, replay ±300s, idempotency 200 / conflict 409.
- B2C mint `INV-671*`; B2B RN60-shape mint `INV-0767*` with 1-paisa tolerance and callback suppression.
- IRN: **UNRESOLVED** — B2B records skip with policy gate (no external IRN HTTP).
- Write-back: **NOT implemented** — pull-only document route tested; fails PDF bytes locally without Imagick.

## Local PDF blocker

`SimplePdfRenderer` requires PHP Imagick. Operator Homebrew PHP 8.5 used for CLI/serve **does not load imagick** → statutory documents status `failed` → HMAC document GET returns 409/JSON, not PDF. This matches pre-existing failures in `RdServiceNetPhase1CleanTest` / `StatutoryInvoiceOptionalLineSuppressionTest` on this machine. **Not a regression from `c6ce97da`.**

## Protected regression (post-rehearsal)

- `RdServiceNetPayloadContractAlignmentTest`, `ChannelOrderIngestTest`, `GstSplitServiceTest`: **PASS**
- `StatutoryInvoiceOptionalLineSuppressionTest`: PDF cases error (Imagick) — pre-existing local env
- rdservice.in / RB* / hardware GST contracts: unchanged code paths; no new failures in focused suites run this ticket
