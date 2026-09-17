# Merge WhiteBooks IRN implementation into authoritative main

**Prompt ID:** RadiumDesk-P-07-09-288  
**Date:** 2026-09-15  
**Repository:** `radium-foundation/radium-desk`  
**Authoritative branch:** `main` @ `5aef2207`  
**Source branch:** `cursor/irn-whitebooks-on-main-c269` @ `a1aaac4f`  
**Before SHA:** `2ddf3b44f364b563fc8e3b6ab2b50799add95c99`  
**PR:** https://github.com/radium-foundation/radium-desk/pull/7 (MERGED)

## Merge

Fast-forward only. `origin/main` was `2ddf3b44` (merge-base of the source branch). No rebase. No history rewrite. No IRN-branch wholesale merge.

`AppServiceProvider` on main binds `WhitebooksEInvoiceGateway` when `STATUTORY_EINVOICE_PROVIDER=whitebooks`, else `NullEInvoiceGateway`. `ConfirmRadiumBoxPaymentOnOrderPaid` remains registered. Do not overlay the IRN-branch `AppServiceProvider.php`.

WhiteBooks is now part of authoritative `origin/main`. A future named-file overlay of `AppServiceProvider.php` from current main keeps WhiteBooks when `.env` has `STATUTORY_EINVOICE_PROVIDER=whitebooks` and keeps the RBP94 listener. Production must not receive an unmodified Null-only `AppServiceProvider`.

## Production mechanism

Verified mechanism remains **named-file overlay** of main-sourced files to KVM `/var/www/radium-desk`. Not `deskd`. Not `deploy-kvm.sh` rsync `--delete` (that would drop unrelated live overlays such as purchasing/historical-search that are not on `main`). Not a wholesale IRN `AppServiceProvider`.

## Overlay (applied)

Backup: `/var/backups/radium-desk/overlays/p-07-09-288-20260915T090125Z`

Mechanism: `install -m 644` of four main-sourced files. Not directory rsync onto the web root (P-07-09-90 403). Web root remained `755`. Then `optimize:clear` + `optimize`. Queue worker restarted.

| File | SHA-256 after overlay (matches `main`) |
|---|---|
| `app/Providers/AppServiceProvider.php` | `28dd6921f44453ad7a7135ab1a2a2e3acd0dec395b8d7b1b9fd2802494865dc9` |
| `app/Services/HardwareFulfilment/HardwareFulfilmentEligibility.php` | `bf2505584d12ecc6202339b35dddb1a121a0e42d75fe0dfcd7dc86a62b5cb60a` |
| `app/Services/StatutoryInvoice/Whitebooks/WhitebooksIrnRecoveryGateway.php` | `15d58698f1480e71c5e0c7e734794e067498cb6a489b77bc36f5e0f83ed35b73` |
| `database/migrations/2026_09_10_090000_add_irn_input_uqc_and_pos_billing_structured.php` | `757c56152e34f9daacfc42bef7ade0e96deb141a7d5bfa6c99c8d40f129e622c` |

`AppServiceProvider` is no longer the P-07-09-286 unique overlay hash `e8cb7b88…`. Bind is unchanged (WhiteBooks when configured; Null fallback; RBP94 listener kept). Pint import order now matches main.

`HardwareFulfilmentEligibility::requiresSerialAllocatedInvoice()` was missing on live while `HardwareCommerceStatutoryInvoiceGuard` (already matching main) called it. Overlay adds only that method.

**Not overlaid** (live is a superset of main; wholesale replace would drop purchasing / historical-search / richer hardware IRN overlays):

- `routes/web.php` (POS statutory routes already present)
- `resources/views/pos/sales/show.blade.php` (statutory partial already present)
- `HardwareFulfilmentInvoiceService.php`
- `HardwareSerialAllocationService.php`
- `HardwareConfigurableVariantDisplay.php`

87 of 96 WhiteBooks/IRN runtime files already matched main from the P-07-09-286 overlay. `bacon/bacon-qr-code` and `dasprid/enum` already present. IRN migrations already Ran. UPI migrations left Pending. No `composer install`. No `migrate`. No GENERATE.

## Production verification

- Runtime `provider()=whitebooks`, class `WhitebooksEInvoiceGateway`
- `.env` `STATUTORY_EINVOICE_PROVIDER=whitebooks`
- `/up` 200, `/login` 200 (`Host: desk.radiumbox.com`)
- `ConfirmRadiumBoxPaymentOnOrderPaid` still registered
- INV-076792 / id 2280: 1 invoice row, 1 `e_invoice_records` row; IRN `96c24140e26b0cfd7c6923ff4e3331e75a42e37a90ecd71cb117c5f073618b36`; Ack `172621177803391` / `2026-09-15 13:37:00`; signed QR 944; PDF `statutory-invoices/2280.pdf` 172961 bytes; status `submitted` / provider `whitebooks`
- POS-000013 `completed`, Bank Transfer, `payment_reference` null, `finance_journal_id=27539` / `JRN-2026-27539` unchanged
- No GENERATE of INV-076792. No invoice email/WhatsApp from this overlay
- Access log 5xx in overlay window: 0
- Purchasing and historical-order routes still registered

INV-076792 recovery is complete. Do not GENERATE again. Do not restore a Null-only `AppServiceProvider`. Do not restore the P-286-only ASP hash as a requirement; current main ASP is the source of truth.

## Rollback

Restore the four files from `/var/backups/radium-desk/overlays/p-07-09-288-20260915T090125Z`, then `optimize:clear` + `optimize`, then restart `radium-desk-queue-worker`. Do not restore unmodified pre-P-286 Null-only `AppServiceProvider.php`. Do not roll back INV-076792 IRN data.

## Remaining risk

`deploy-kvm.sh` rsync `--delete` from main remains unsafe: it would drop live purchasing/historical-search overlays and regress the three richer hardware files left in place. Keep using named-file overlay until those live extras are on main.
