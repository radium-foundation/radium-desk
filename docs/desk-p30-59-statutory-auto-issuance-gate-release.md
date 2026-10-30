# P-30-59 — rdservice.net service statutory auto-issuance gate (release prep)

Branch **`fix/p30-59-statutory-auto-issuance-gate`** @ **`origin/main`** **`af77d628`** (includes merged P-30-47 SKU **4.1.49**).

## Behavior

Same as P-30-50 hardened gate: **rdservice.net** automatic mint requires per-order approval; missing commerce **fail-closed**; `issueFromSupportOrder(..., $trigger)` defense; Finance manual unchanged.

## Channel ingest

**`channel_ingest.auto_issue_invoice=false`** in production; if true, foundation guard throws — no mint bypass.

## Rollback

`SERVICE_STATUTORY_INVOICE_AUTO_ISSUANCE_ENFORCE=false`.

## Release gates (separate)

Deploy → migrate `2026_10_30_120000_add_statutory_auto_issue_approval_to_commerce_orders_table.php` → enforce → CLI approve per net order.

## Tests

```bash
cd .worktrees/p30-59-statutory-auto-issuance-gate
composer install
php vendor/bin/phpunit tests/Feature/StatutoryInvoice/ServiceStatutoryAutoIssuancePolicyTest.php \
  tests/Feature/StatutoryInvoice/ServiceStatutoryInvoiceMintRetryTest.php
```
