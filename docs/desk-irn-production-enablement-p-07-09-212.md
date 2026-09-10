# Production WhiteBooks automatic IRN enablement — P-07-09-212

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-212  
**Date:** 2026-09-10  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk-irn-foundation`  
**Branch:** `feat/irn-foundation-phase-a`  
**Before SHA:** `65cc870a70c98986e9219ba98db4489ee829fb30`  
**Production:** KVM8 `srv1910783` / `187.127.129.16` `/var/www/radium-desk` DB `radium_desk`

Owner decision: **all eligible B2B** (`STATUTORY_EINVOICE_ISSUANCE_POLICY=all_eligible_b2b`). This is not all invoices. B2C, historical, cancelled, invalid, and ambiguous remain excluded.

## Production baseline (read-only, before mutation)

| Item | Value |
|---|---|
| Hostname | `srv1910783` / `desk.radiumbox.com` |
| Path | `/var/www/radium-desk` |
| DB | `radium_desk` |
| PHP | `/usr/local/lsws/lsphp84/bin/php` 8.4.24 |
| Laravel | 13.17.0 |
| `/up` | 200 (Host `desk.radiumbox.com`) |
| Provider | `STATUTORY_EINVOICE_PROVIDER=none` |
| Gateway | `NullEInvoiceGateway` (always bound) |
| `worker_may_mint` | hardcoded `false` |
| `auto_issue_on_pos_complete` | `false` |
| Policy key | ABSENT on production config |
| WhiteBooks base | `https://api.whitebooks.in` |
| GSP IP | `187.127.129.16` |
| Delhi/Mumbai GST usernames/passwords | PRESENT (values not printed) |
| Client id/secret/email | PRESENT |
| Worker process | `queue:work redis` running |
| Invoices | 1196 issued, 0 cancelled, 0 pre-cutoff |
| `e_invoice_records` | 1196 |
| IRN filled | 2 |
| submitted | 2 |
| skipped | 1194 |
| pending/processing/ambiguous/temporary/permanent | 0 |
| skip `b2c_not_eligible` | 1145 |
| skip `worker_may_mint_off` | 49 (42 `rdservice_in`, 7 `radiumbox_com`) |
| einvoice outbox | 51 completed, 0 pending |
| Existing IRNs | INV-076746 / 1062 (`radiumbox_com`), INV-076749 / 1123 (`desk_pos`) |
| Composer bacon in lock/json | NO |
| Vendor bacon overlay | YES (class loads) |
| Dasprid autoload | FAIL (vendor copy without lock) |
| `config:cache` | present |

## Source changes

- Bind `WhitebooksEInvoiceGateway` when `STATUTORY_EINVOICE_PROVIDER=whitebooks`; otherwise Null.
- `worker_may_mint` from `STATUTORY_EINVOICE_WORKER_MAY_MINT` (default false).
- `issuance_policy` from `STATUTORY_EINVOICE_ISSUANCE_POLICY` (default `hardware_only`; invalid → hardware_only).
- `auto_issue_on_pos_complete` remains hardcoded **false**. Enabling it still **aborts POS checkout** (`StatutoryInvoiceAccountingPolicy`). Automatic IRN is mint-then-worker.
- phpunit forces provider none, worker false, policy hardware_only.

## Auto-issue on POS complete

**NO — Not enabled.** The current flag is an abort guard, not a mint path. Enabling it would break POS. Hardware fulfilment / Finance Hub mint already calls `queueEinvoiceIfEligible`.

## Backlog

**NO bulk requeue.** Skipped `worker_may_mint_off` rows stay skipped. Enabling the worker does not replay completed einvoice outbox.

First controlled GENERATE candidate (current operational, not historical): invoice **901 / INV-076740**, `radiumbox_com`, B2B hardware HSN 84716050, seller Delhi `07AAICP1128M1Z9`, issued 2026-09-10, no IRN. Targeted one-row promote only if used.

## Composer

Repo lock vs production lock: **only** `bacon/bacon-qr-code` v3.1.1 and `dasprid/enum` 1.0.7 missing. No other package version diffs. Safe `composer install --no-dev` from the repo lockfile after overlaying `composer.json` + `composer.lock`.

## Duplicate GENERATE codes

Still **UNKNOWN**. `VERIFIED_GENERATE_DUPLICATE_ERROR_CODES = []`. Do not invent a code.

## Production steps (this prompt)

Named-file overlay from the P-212 commit. **Not** `deskd`. Backup under `storage/app/private/overlays/`. Then Composer install, `.env` provider/policy/worker, one controlled GENERATE, then enable worker if that GENERATE verifies.

Rollback: `STATUTORY_EINVOICE_PROVIDER=none` and `STATUTORY_EINVOICE_WORKER_MAY_MINT=false`. Do not delete IRN rows.
