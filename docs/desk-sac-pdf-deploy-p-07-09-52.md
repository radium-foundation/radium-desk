# SAC + invoice PDF production overlay — RadiumDesk-P-07-09-52

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-52`  
**Source commit:** `d53fd8a8a85a3297a89129d04c06ef0f08629d96`  
**Mechanism:** named-file rsync (no `--delete`). Not `./tools/desk deploy`.

Ledger file is `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-51**. This ticket: **P-07-09-52**.

---

## Why not full `desk deploy`

| Gate | Finding |
|---|---|
| Local worktree | Dirty unrelated docs |
| HEAD tag | `d53fd8a8` is not the latest semver tag (`v4.0.67`) |
| CHANGELOG | No 4.0.68 entry; release-workflow forbids tagging without an approved changelog |
| Pending UPI migrations | `220000` / `220100` / `220200` still Pending |
| This overlay | No migrations. PHP + config only |
| Owner rule | Do not alter production DB data; do not remint/regenerate historical invoices |

Official `./tools/desk deploy` would require a clean tagged tree and would run `migrate --force` plus `RolePermissionSeeder`. That is out of scope.

Same class as P-07-09-34 / P-07-09-41.

---

## Overlay set (7 files)

From `git show d53fd8a8` (not the dirty worktree):

New:

- `app/Services/StatutoryInvoice/ServiceSacResolver.php`

Replaced (pre-overlay hashes matched live production):

- `app/Services/StatutoryInvoice/SimplePdfRenderer.php`
- `app/Services/StatutoryInvoice/StatutoryDocumentService.php`
- `app/Services/StatutoryInvoice/StatutoryInvoiceService.php`
- `app/Services/StatutoryInvoice/Data/StatutoryInvoicePdfPayload.php`
- `app/Services/ChannelIngest/ChannelIngestService.php`
- `config/statutory_invoices.php`

`AppServiceProvider.php` already matched `d53fd8a8` and was not copied.

Tests/docs/`.env` were not copied. No migrate. No seed. No flag change. No Shiprocket / hardware fulfilment files.

SHA-256 of all seven production files **MATCH** `git show d53fd8a8`.

Then `artisan optimize:clear` + `optimize`.

---

## Backup / rollback

Backup: `/var/www/radium-desk/storage/app/private/overlays/p-07-09-52-20260908T012934Z`

Contains the six replaced files, `MANIFEST.before`, `STATE.before`, `MANIFEST.after`, `STATE.after`.

Rollback: copy the six backed-up files back, delete `ServiceSacResolver.php`, then `optimize:clear` + `optimize`.

---

## After verify

| Check | Result |
|---|---|
| Host | `srv1910783` / `187.127.129.16` `/var/www/radium-desk` |
| `release.json` | unchanged 4.0.67 / `5d14a582` |
| Resolver | PRESENT; RD/AMC → 998313; hardware+amcid → 84716050; Future Service A → 998399 |
| Renderer | redesigned 674-line Helvetica invoice; no `GSTIN B2C` / `IRN not submitted` |
| Fixture PDFs | `/tmp/desk-sac-pdf-prod-p-07-09-52` on KVM only; not stored as invoice documents |
| INV-276710 | issued; items 998314; checksum `e3f60a86…`; attempts 1; timestamps 2026-09-07 18:28:19 |
| Issued invoices / items | 265 / 557; still 0 stored 998313 |
| Invoice/PDF rows updated after overlay | **0** |
| Flags | auto-issue / worker mint / e-invoice provider / shipping all false/none |
| UPI migrations | still Pending |
| `/up` `/login` | 200 |
| `/finance/invoices` unauth | 302 |

No remint. No historical PDF regenerate. No IRN submit. No `.env` change.
