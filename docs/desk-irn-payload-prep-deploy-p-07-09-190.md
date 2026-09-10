# Deploy tested IRN payload preparation — P-07-09-190

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-190  
**Date:** 2026-09-10  
**Intended commit:** `956e514a799f3495a53c780c3eb2146111395746` (P-189)  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk-irn-foundation`  
**Branch:** `feat/irn-foundation-phase-a`  
**Production:** KVM8 `srv1910783` / `187.127.129.16` `/var/www/radium-desk` DB `radium_desk`

**Mechanism:** named-file overlay (`install -m 644`) from `git archive 956e514a`. **Not** `deskd`. **Not** dirty worktree. No migrate. No `.env`. IRN remains OFF.

## Boundary

Project → repo `radium-desk` HEAD `956e514a` → branch `feat/irn-foundation-phase-a` → `/var/www/radium-desk` → KVM8 `187.127.129.16` → DB `radium_desk` → named-file overlay.

Backup: `/var/www/radium-desk/storage/app/private/overlays/p-07-09-190-20260910T091245Z`

## Files (10 PHP)

From `956e514a` only. Tests/docs/migration not copied. WhiteBooks gateway / `AppServiceProvider` not copied (Null remains bound).

| File | Production SHA-256 after overlay |
|------|----------------------------------|
| `EInvoiceIrnPayloadMapper.php` | `cab0e7dd…` (changed) |
| `EInvoiceUqcMapper.php` | `0d285b2c…` (changed; `PCS` accepted) |
| `WhitebooksNicPayloadFactory.php` | `6bcd0cee…` (changed; assessable UnitPrice) |
| 7 models/support files | already matched `956e514a`; reinstalled |

`php -l` clean. `artisan optimize:clear` + `optimize`. `/up` 200 (local Host and `https://desk.radiumbox.com/up`).

## Payload check (invoice 1062, not sent)

`map()` + `generateBody()` only. No authenticate / GENERATE / Get-IRN.

| Field | Value |
|-------|--------|
| Unit | PCS |
| Qty | 2 |
| UnitPrice | 2160.17 |
| AssAmt | 4320.34 |
| IGST | 777.66 |
| TotItemVal | 5098.00 |
| submittable | YES |
| line UQC after | NULL |
| invoice totals | 4320.34 / 777.66 / 5098.00 unchanged |
| catalog `RBMFS110L1` | PCS |

## IRN OFF after

provider `none`; `NullEInvoiceGateway`; worker false; auto-issue false; irn_filled 0; invoices/e_invoice_records 1068.

## Not performed

`deskd`, tag/release, migrate, `.env`, WhiteBooks HTTP, IRN generate, historical UQC backfill, catalog mutate, bind WhiteBooks gateway.
