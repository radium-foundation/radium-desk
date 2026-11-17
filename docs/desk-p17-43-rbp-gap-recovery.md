# P-17-43 — RBP hardware fulfilment gap recovery

**Risk:** HIGH — payment-linked fulfilment rows, idempotency and concurrency.

## Allowlist (15)

`RBP2129`, `RBP2133`, `RBP2136`, `RBP2140`, `RBP2143`, `RBP2146`, `RBP2152`, `RBP2161`, `RBP2162`, `RBP2163`, `RBP2166`, `RBP2181`, `RBP2182`, `RBP2190`, `RBP2191`

## Gates (per order)

Desk `cashfree_payment_id`, successful Cashfree webhook, amount/currency vs commerce; physical lines + SKU map; no existing HF; config allowlist only.

## Commands

- Dry-run: `php artisan desk:recover-rbp-hardware-fulfilment-gaps --json`
- Execute: `php artisan desk:recover-rbp-hardware-fulfilment-gaps --execute --force --json`

## Monitor fix

`RbpHardwareFulfilmentGapMonitor` pre-filters `RBP%` in SQL and applies `--limit` after eligibility, fixing limit starvation from unrelated paid commerce rows.