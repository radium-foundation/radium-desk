# Production WhiteBooks automatic IRN enablement — P-07-09-212

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-212  
**Date:** 2026-09-10  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk-irn-foundation`  
**Branch:** `feat/irn-foundation-phase-a`  
**Before SHA:** `65cc870a70c98986e9219ba98db4489ee829fb30`  
**After SHA:** `a7e3d241944c19d2470e89ff7e8d74639a546c3a`  
**Production:** KVM8 `srv1910783` / `187.127.129.16` `/var/www/radium-desk` DB `radium_desk`

Owner decision: **all eligible B2B**. Not all invoices. B2C, historical, cancelled, invalid, and ambiguous remain excluded.

## Production baseline (before mutation)

| Item | Value |
|---|---|
| Provider | `none` |
| Gateway | `NullEInvoiceGateway` |
| Worker mint | hardcoded `false` |
| Auto-issue POS | `false` |
| Policy | ABSENT on production config (source default would be hardware_only) |
| Invoices | 1196 issued / 0 cancelled / 0 pre-cutoff |
| e_invoice_records | 1196 |
| IRN | 2 (`INV-076746` / 1062, `INV-076749` / 1123) |
| submitted / skipped | 2 / 1194 |
| skip B2C | 1145 |
| skip `worker_may_mint_off` | 49 |
| pending / ambiguous / temporary / permanent | 0 |
| einvoice outbox | 51 completed / 0 pending |
| Composer bacon in lock | NO (vendor overlay copy) |
| Dasprid autoload | FAIL |
| `/up` | 200 |

## Source

Bind `WhitebooksEInvoiceGateway` when `STATUTORY_EINVOICE_PROVIDER=whitebooks`.  
`STATUTORY_EINVOICE_WORKER_MAY_MINT` default false.  
`STATUTORY_EINVOICE_ISSUANCE_POLICY` default `hardware_only`; production `all_eligible_b2b`.  
`auto_issue_on_pos_complete` remains **false** (still aborts POS checkout).

## Composer

Lockfile vs production: only `bacon/bacon-qr-code` v3.1.1 and `dasprid/enum` 1.0.7 added. Zero other version diffs.  
`composer install --no-dev`: **2 installs, 0 updates, 0 removals**. Autoload Bacon + DASPRiD OK. No permanent vendor overlay.

## Deployment

Named-file overlay from `a7e3d241`. **Not** `deskd`.  
Backup: `/var/www/radium-desk/storage/app/private/overlays/p-07-09-212-20260910T143940Z`  
Rollback: restore overlay backup; set `STATUTORY_EINVOICE_PROVIDER=none` and `STATUTORY_EINVOICE_WORKER_MAY_MINT=false`. Do not delete IRN rows.

## Auto-issue on POS complete

**NO — Not enabled.** Flag remains an abort guard.

## First controlled GENERATE

Today's newest hardware B2B (`INV-076740` / 901) failed closed: `buyer_state_mismatch`. Not generated.

Used current operational submittable hardware: **INV-076738 / 818** (`radiumbox_com`, RDE318388, issued 2026-09-09, after cutoff). One-row unskip only. No bulk requeue.

| Field | Value |
|---|---|
| B2B | YES |
| Kind | hardware |
| Seller | `07AAICP1128M1Z9` (Delhi) |
| Buyer | `03JXBPK4262F1ZO` |
| HSN | 85269190 |
| UQC | PCS (`IsServc=N`) |
| Taxable / tax / total | 1694.07 / 304.93 IGST / 1999 |
| Auth / GENERATE / Get-IRN / Cancel | 1 / 1 / 0 / 0 |
| IRN | `c03672a56bb0d1ce09b722fa6ac8558dfb0263adfbd098518bee57beecaa966e` |
| AckNo / AckDt | `172621148091774` / `2026-09-10 20:12:00` |
| SignedQR len | 944 |
| SignedInvoice | 2150 bytes, SHA256 `c2d33f2bc31c97a5eec887ccf75304400bb4a059efd9753699fb55fdca53f180` |
| PDF | `statutory-invoices/818.pdf` 115227 bytes, `% signed-qr-image` present, no JWT |
| Idempotent retry | Auth 0 GENERATE 0 Get-IRN 0 |

Mumbai credentials resolve (`27AAICP1128M1Z7` → location mumbai, missingReasons `[]`). No second live GENERATE.

## Backlog after success

**NO bulk requeue.**

| Class | Count |
|---|---|
| Already issued IRN | 3 |
| Current submittable B2B still skipped | 5 hardware (`worker_may_mint_off`; left skipped) |
| Service B2B skipped / not submittable | 42, mostly `missing_uqc` |
| B2C | 1145 + 71 new during window, skip `b2c_not_eligible`, 0 IRN |
| Historical | 0 |
| Cancelled | 0 |
| Ambiguous | 0 |

Service automatic GENERATE is policy-permitted but fail-closed until stored UQC/`IsServc` exist. Do not invent UQC.

## Production after

```
STATUTORY_EINVOICE_PROVIDER=whitebooks
STATUTORY_EINVOICE_WORKER_MAY_MINT=true
STATUTORY_EINVOICE_ISSUANCE_POLICY=all_eligible_b2b
auto_issue_on_pos_complete=false
Gateway=WhitebooksEInvoiceGateway
```

Worker RUNNING. `/up` 200. IRN count 3. Pending einvoice 0.

## Tests

E-invoice enablement subset 119 passed. Broader statutory 355/361; 3 failures are pre-existing Vite `manifest.json` HTTP tests. Pint dirty passed.

## Remaining conditions

1. POS `auto_issue_on_pos_complete` cannot be true without replacing the abort guard.
2. Eligible B2B services need authoritative stored UQC (and `IsServc` where still gapped).
3. Duplicate WhiteBooks GENERATE envelope still **UNKNOWN**.
4. Five current submittable hardware rows remain skipped by design (no bulk requeue).
5. Visual QR screenshot in a browser: **NO — Not performed** (PDF contains the signed-qr drawing operators; same renderer as P-204).
