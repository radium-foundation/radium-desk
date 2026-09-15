# Permanently reconcile WhiteBooks e-invoice provider onto main

**Prompt ID:** RadiumDesk-P-07-09-287  
**Date:** 2026-09-15  
**Repository:** `radium-foundation/radium-desk`  
**Authoritative production Git branch:** `main` (`tools/config.sh` `DEFAULT_BRANCH=main`)  
**Implementation branch:** `cursor/irn-whitebooks-on-main-c269`  
**Source of verified implementation:** `cursor/irn-whitebooks-recovery-c269` / `45486258` (IRN foundation + P-07-09-286)

## Why

Production runtime already resolves `WhitebooksEInvoiceGateway` because P-07-09-286 applied a surgical named-file overlay on live `AppServiceProvider.php`. Unmodified `origin/main` still bound `NullEInvoiceGateway` only. A normal future deploy from `main` would restore Null and skip GENERATE as `provider_disabled`.

INV-076792 already has IRN `96c24140e26b0cfd7c6923ff4e3331e75a42e37a90ecd71cb117c5f073618b36` / Ack `172621177803391`. This prompt must not regenerate that IRN or change payment/journal state.

## What this change is

Bring the already-verified WhiteBooks provider and the IRN e-invoice contract it implements onto `main` using `git checkout 45486258 -- <paths>` (no history rewrite, no class duplication).

Surgical edits on `main` files (not wholesale replace):

- `AppServiceProvider.php`: import + env-based WhiteBooks bind + `shouldBindWhitebooksEInvoiceGateway()`. Keep `Event::listen(OrderPaid::class, ConfirmRadiumBoxPaymentOnOrderPaid::class)`. Null remains the explicit non-whitebooks fallback.
- `routes/web.php`: POS statutory pdf/download/email routes only.
- `SaleController.php` + `pos/sales/show.blade.php`: POS skipped-vs-queued presentation.
- `OutboxProcessorService.php`: `EInvoiceRecoveryRequiredException` retry path only.
- `HardwareFulfilmentEligibility.php`: add `requiresSerialAllocatedInvoice()` only. Do **not** replace the file (RBP/main hardware source-id policy stays).
- `composer.json` / lock: add `bacon/bacon-qr-code` and `dasprid/enum` only.
- `.env.example` / `phpunit.xml`: GSP env keys. Test default provider remains `none`.

## What this change is not

- Not a merge of `feat/irn-foundation-phase-a` into `main`.
- Not a wholesale replace of `AppServiceProvider.php` (that file on IRN lacks RBP94 and carries historical-search binds).
- Not `HardwareFulfilmentEligibility` / `BusinessOrderId` / `SpokeOrderClient` / `config/operations.php` / `bootstrap/app.php` from IRN.
- Not CounterController scanner work.
- Not purchasing, DUE/UNPAID, Cashfree, or payment lifecycle design.
- Not INV-076792 data mutation. Do not GENERATE again for that invoice.

## Overlay safety

Verified WhiteBooks production binding: bind `WhitebooksEInvoiceGateway` when `config('statutory_invoices.einvoice.provider') === 'whitebooks'`, else `NullEInvoiceGateway`.

**Do not overlay the IRN-branch `AppServiceProvider.php` wholesale onto production.** That file lacks `ConfirmRadiumBoxPaymentOnOrderPaid` (RBP94).

After this lands on `main`, a normal deploy of `AppServiceProvider.php` from `main` keeps WhiteBooks when `.env` has `STATUTORY_EINVOICE_PROVIDER=whitebooks`.

Follow-up wiring on the same branch (still P-07-09-287): `HardwareConfigurableVariantDisplay`, after-commit `HardwareStatutoryInvoiceIssuer` / `ServiceStatutoryInvoiceIssuer` callers, and catalog UQC on the inventory product form. These are required by the IRN `StatutoryInvoiceService` contract already ported; they do not change Cashfree/RBP94 payment confirmation.

P-07-09-286 production overlay (already live; do not repeat GENERATE):

| File | SHA-256 after overlay |
|---|---|
| `app/Providers/AppServiceProvider.php` | `e8cb7b881736fcd1b10d4478ba2e7ca590a4fe10309da8909a653183cc49608e` |
| `app/Services/Pos/PosSaleStatutoryInvoicePresenter.php` | `4f47e8e9102d34cb023073a2dcab1b6269c96e837b7e7d197dacaa63ec7d60a3` |

Backup: `/var/backups/radium-desk/overlays/p-07-09-286-20260915T080547Z`

`provider_disabled` / skipped invoices are not a stuck queue. Recover with `desk:einvoice-backfill --invoice={id} --recover` (Get-IRN only). GENERATE only after Get-IRN confirms absence (`--recover --generate`).

## Rollback

Before any named-file overlay of this branch: copy current production files to `/var/backups/radium-desk/overlays/p-07-09-287-<UTC>/`. Restore those copies to unwind. Do not restore unmodified pre-P-286 `origin/main` `AppServiceProvider.php` (that rebinds Null). Do not `deskd` a mixed worktree.
