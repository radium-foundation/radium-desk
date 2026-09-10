# Automatic IRN issuance readiness — P-07-09-205

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-205  
**Date:** 2026-09-10  
**HEAD:** `ef521c20b14cba9cf2e82cea4dde1c08f2b9da03`  
**Production:** KVM8 `srv1910783` `/var/www/radium-desk` DB `radium_desk` (SELECT only)

**Overall: NOT READY**

Canvas: `irn-automatic-issuance-readiness.canvas.tsx`

No WhiteBooks calls. No .env change. No deploy. No enablement.

## Production snapshot (VERIFIED)

| Item | Value |
|------|--------|
| Provider | `none` |
| Gateway | `NullEInvoiceGateway` |
| worker_may_mint | `false` (hardcoded in config) |
| auto_issue_on_pos_complete | `false` |
| einvoice outbox | 51 completed, 0 pending, max attempts 1, 0 errors |
| e_invoice_records | 1093 `b2c_not_eligible`, 49 `worker_may_mint_off`, 2 submitted |
| B2B issued without IRN | 49 |
| Pre-2026-09-01 invoices | 0 |
| bacon in production vendor | present (P-204 overlay) |
| bacon in production composer.json/lock | absent |

B2B by channel: rdservice_in 42 (all HSN/SAC 99), radiumbox_com 8 (84/85), desk_pos 1 (already IRN).

## Paths

**Commerce:** ingest never mints (`channel_ingest.auto_issue_invoice` throws if true). Mint is Finance Hub `issueFromCommerceOrder` or hardware `HardwareFulfilmentInvoiceService::issueInvoice`. Both call `queueEinvoiceIfEligible`.

**POS:** `PosSaleService` asserts auto-issue-on-complete is off, so complete does not mint. Mint is Finance Hub `issueFromPosSale`, then the same queue.

**Other:** `issueFromSupportOrder` delegates to commerce. Hardware fulfilment is a separate mint + queue.

## Eligibility (committed HEAD)

GSTIN present + valid → `b2b_eligible`. Missing GSTIN → B2C skip, no outbox. Invalid GSTIN → skip.

Committed HEAD does **not** skip cancelled invoices or non-tax documents. Dirty worktree adds those guards; they are not in HEAD and not this review’s code change.

GST completeness, UQC, buyer PIN/city, IsServc are mapper fail-closed, not eligibility.

## Payload / gateway

Mapper uses stored statutory values. Catalog UQC only when line empty and product/channel map is unique. No PCS/NOS default. NIC `UnitPrice` = AssAmt/Qty. Auth/GENERATE/Get-IRN paths match verified production contracts. Credentials and IP come from config. `WhitebooksEInvoiceGateway` is not bound.

GENERATE timeout/5xx → Ambiguous. Get-IRN 2154 → `irn_not_found`. Recovery service never calls `submit()`.

## Idempotency gap (BLOCKER)

`EInvoiceProcessor::process` performs WhiteBooks HTTP inside `DB::transaction`. Outbox is claimed **outside** that transaction. A crash after NIC GENERATE and before commit rolls back the IRN row; stale Processing outbox returns to Pending after 5 minutes and GENERATE runs again.

Ambiguous GENERATE is **not** retryable, so the outbox is marked completed. Cron will not Get-IRN unless `process()` is invoked again on that event.

HTTP 429 on GENERATE is TemporaryFailure and **does** retry GENERATE (up to 5 attempts).

## Backlog

Enabling flags would **not** replay the 51 completed einvoice events. The 49 skipped B2B invoices stay skipped unless an operator requeues them. That is currently safe. A requeue of those 49 without a hardware allowlist would GENERATE rdservice_in B2B services.

## Transition (do not perform)

`.env` `STATUTORY_EINVOICE_PROVIDER=whitebooks` is insufficient. Required together: bind `WhitebooksEInvoiceGateway`, set `worker_may_mint` true (source change), provider ≠ none, config cache reload. No schema migration. Do not requeue skipped rows as part of enablement.

## Composer

Repo `composer.json` / `composer.lock` include `bacon/bacon-qr-code`. Production Composer files do not. Separate PDF/vendor gate.

## Tests not in git

`EInvoiceFoundationTest` (timeout/ambiguous recovery, worker flags) is untracked. Duplicate-worker crash-after-success is not covered.
