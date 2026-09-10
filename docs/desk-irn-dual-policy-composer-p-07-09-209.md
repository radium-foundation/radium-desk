# Dual issuance policy + Composer QR deps — P-07-09-209

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-209  
**Date:** 2026-09-10  
**Before SHA:** `ab0912615d2b23340c76bf7e4020ea5cbd307b38`

Source-level preparation gate for **both** automatic e-Invoice policies. Automatic issuance remains **OFF**.

No production WhiteBooks calls. No deploy. No requeue of skipped backlog.

## Policies

| Mode | Source default | Production enabled |
|---|---|---|
| `HARDWARE_ONLY` | YES (hardcoded) | NO |
| `ALL_ELIGIBLE_B2B` | implemented + tested | NO |

Policy stays hardcoded. It is **not** an env toggle, so it cannot silently become an issuance switch or requeue skipped rows.

`IsServc` remains payload classification. `EInvoiceIssuancePolicy` decides GENERATE permission.

Mixed and unknown stay fail-closed in both modes.

## Duplicate WhiteBooks GENERATE

**UNKNOWN — WhiteBooks production duplicate GENERATE envelope not verified.**

Inspected mapper, Postman/P-207 evidence, existing tests. No production duplicate GENERATE envelope. NIC sandbox `2150` is **not** mapped.

`VERIFIED_GENERATE_DUPLICATE_ERROR_CODES = []`

GENERATE HTTP 200 with no IRN remains `permanentFailure` (`missing_irn`). Get-IRN `2154` is still IRN-not-found.

**Production probe:** NO — Not performed.

## Composer / QR

`bacon/bacon-qr-code` `^3.0` and `dasprid/enum` `^1.0.3` are now both root `require` entries. Lockfile: bacon `v3.1.1`, dasprid `1.0.7`. `composer validate` and `composer install --dry-run` succeed. Autoload: `BaconQrCode\` + `DASPRiD\Enum\`.

Production previously needed a vendor overlay because production `composer.json`/`composer.lock` did not declare bacon. **This branch now has installable Composer state.** The next production deploy must run `composer install` from this lockfile. Overlay is no longer the source of truth.

**This gate did not deploy.**

## Production

```text
STATUTORY_EINVOICE_PROVIDER=none
Gateway=NullEInvoiceGateway
worker_may_mint=false
auto_issue_on_pos_complete=false
issuance_policy=hardware_only
```

**GENERATE / Get-IRN / Cancel / invoice creation / backlog requeue / provider / worker / auto-issue / deploy:** NO — Not performed.
