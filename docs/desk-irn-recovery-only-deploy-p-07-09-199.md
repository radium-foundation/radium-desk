# Deploy recovery-only WhiteBooks Get-IRN — P-07-09-199

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-199  
**Date:** 2026-09-10  
**Intended commit:** `6bf2279c720b431259aac49aceea857439b4d435` (P-198)  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk-irn-foundation`  
**Branch:** `feat/irn-foundation-phase-a`  
**Production:** KVM8 `srv1910783` / `187.127.129.16` `/var/www/radium-desk` DB `radium_desk`

**Mechanism:** named-file overlay (`install -m 644`) from `git archive 6bf2279c`. **Not** `deskd`. **Not** dirty worktree. No migrate. No `.env`. IRN issuance remains OFF.

## Boundary

Project → repo `radium-desk` HEAD `6bf2279c` → branch `feat/irn-foundation-phase-a` → `/var/www/radium-desk` → KVM8 `187.127.129.16` → PHP `/usr/local/lsws/lsphp84/bin/php` → DB `radium_desk` → named-file overlay.

Hostname: `desk.radiumbox.com`. Public `/up` 200.

Backup: `/var/www/radium-desk/storage/app/private/overlays/p-07-09-199-20260910T110634Z`

## Pre-deployment

| Item | Value |
|------|--------|
| Provider | `STATUTORY_EINVOICE_PROVIDER=none` |
| Gateway | `NullEInvoiceGateway` |
| worker_may_mint | `false` (hardcoded in production config) |
| auto_issue_on_pos_complete | `false` |
| Recovery service | ABSENT |
| Recovery gateway | ABSENT |
| Overlay Get-IRN | old NIC-style (no `param1`, no `getIrnHeaders`) |
| GSP IP in `.env` | KEY_ABSENT (unchanged; not written) |

## Files deployed (8 PHP from `6bf2279c`)

| File | Production SHA-256 after overlay |
|------|----------------------------------|
| `app/Enums/EInvoiceRecordStatus.php` | `0674205c…` |
| `app/Enums/EInvoiceSubmitOutcome.php` | `b4081641…` |
| `app/Services/StatutoryInvoice/Data/EInvoiceSubmitResult.php` | `5965e306…` |
| `app/Services/StatutoryInvoice/EInvoiceIrnGuard.php` | `891f2876…` |
| `app/Services/StatutoryInvoice/EInvoiceIrnRecoveryService.php` | `c2dfb8b2…` (new) |
| `app/Services/StatutoryInvoice/Whitebooks/WhitebooksEInvoiceGateway.php` | `0f627870…` |
| `app/Services/StatutoryInvoice/Whitebooks/WhitebooksIrnRecoveryGateway.php` | `fa9636b6…` (new) |
| `app/Services/StatutoryInvoice/Whitebooks/WhitebooksResponseMapper.php` | `cd569510…` |

Hashes MATCH `git archive 6bf2279c`. `php -l` clean. `artisan optimize:clear` + `optimize`. Web root stayed `755 ravi:ravi`. `/up` 200 (local Host and `https://desk.radiumbox.com/up`).

## Files intentionally NOT deployed

`AppServiceProvider.php` (Null bind already present; comment-only in git). `config/statutory_invoices.php`. `EInvoiceProcessor.php`. `WhitebooksNicPayloadFactory.php` (comment-only vs production). Credential resolver/set (already MATCH). Contract / `NullEInvoiceGateway` / payload mapper (already MATCH). Tests, docs, `phpunit.xml`, migrations, `.env`. Unrelated dirty/untracked POS/ingest/hardware/view files.

## Recovery verification

One call: `EInvoiceIrnRecoveryService::recover(invoice 1062)`. Not the old overlay adapter. Not a hand-built HTTP request.

IP was set **in-process only** (`187.127.129.16`). `.env` `STATUTORY_EINVOICE_GSP_IP_ADDRESS` stayed KEY_ABSENT.

| Item | Result |
|------|--------|
| Auth | 1 `GET /einvoice/authenticate` |
| Get-IRN | 1 `GET /einvoice/type/GETIRNBYDOCDETAILS/version/V1_03` |
| GENERATE | 0 |
| Cancel | 0 |
| Retries | 0 |
| Outcome | `success` |
| IRN | match `974b217a7c86b097e083d625fa28691e11879539376c1a2bf5d670c309438119` |
| AckNo | `172621144003124` |
| AckDt | `2026-09-10 14:57:00` |
| Persist | no-op (`recordHasIssuedIrn`); e-invoice `updated_at` unchanged |
| Invoice | `issued`; `updated_at` unchanged; PDF mtime still `2026-09-10 14:56` |
| Records | 1 row, status `submitted`, signed QR len 944 |
| Duplicate | none |

## IRN OFF after

provider `none`; `NullEInvoiceGateway`; worker false; auto-issue false. Recovery gateway has `fetchExisting` and **no** `submit()`. Issuance gateway still Null.

## Not performed

`deskd`, tag/release, migrate, `.env` write, bind WhiteBooks as `EInvoiceGateway`, enable worker/auto-issue, GENERATE, Cancel, catalog mutate, commit/push, rollback.
