# Merge WhiteBooks IRN implementation into authoritative main

**Prompt ID:** RadiumDesk-P-07-09-288  
**Date:** 2026-09-15  
**Repository:** `radium-foundation/radium-desk`  
**Authoritative branch:** `main`  
**Source branch:** `cursor/irn-whitebooks-on-main-c269` @ `a1aaac4f`

## Merge

Fast-forward only. `origin/main` was `2ddf3b44` (merge-base of the source branch). No rebase. No history rewrite. No IRN-branch wholesale merge.

`AppServiceProvider` on main binds `WhitebooksEInvoiceGateway` when `STATUTORY_EINVOICE_PROVIDER=whitebooks`, else `NullEInvoiceGateway`. `ConfirmRadiumBoxPaymentOnOrderPaid` remains registered. Do not overlay the IRN-branch `AppServiceProvider.php`.

## Production mechanism

Verified mechanism remains **named-file overlay** of main-sourced files to KVM `/var/www/radium-desk`. Not `deskd`. Not `deploy-kvm.sh` rsync `--delete` (that would drop unrelated live overlays such as purchasing/historical-search that are not on `main`). Not a wholesale IRN `AppServiceProvider`.

After this gate, production `AppServiceProvider.php` must match **main** (WhiteBooks bind + RBP94), not the P-07-09-286 unique overlay hash.

INV-076792 / 2280 recovery is complete. Do not GENERATE again. Do not restore a Null-only `AppServiceProvider`.

## Rollback

Backup production files before overlay under `/var/backups/radium-desk/overlays/p-07-09-288-<UTC>/`. Restore those copies to unwind. Do not restore unmodified pre-P-286 Null-only `AppServiceProvider.php`.
