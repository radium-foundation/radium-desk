# Cashfree historical identity repair (P-04-10-157)

Identity-only repair for pre–v4.1.15 Cashfree orders (population **P-04-10-156**). Refunds, ledger credits, and spoke wallet migration are **out of scope**.

## Population (do not change)

- `orders.deleted_at IS NULL`
- `cashfree_payment_id` present (`Order::scopeCashfreeVerified`)
- `orders.created_at <` `central_wallet.historical_identity_repair.deploy_cutoff_utc` (default **2026-10-09 12:51:27 UTC** = production v4.1.15 deploy)
- `EXISTS cashfree_webhook_logs` with matching `cf_payment_id` and `processing_status = processed`
- Exclude `order_id` prefix **`INQ-`**
- Identity match: `CashfreeCentralCustomerBinder` providers `desk_email` + `cashfree_order_email`, `verified_email`, `verified_at` set

## Command (explicit mode)

```bash
php artisan cashfree:repair-historical-identity --mode=dry-run
php artisan cashfree:repair-historical-identity --mode=dry-run --limit=100
php artisan cashfree:repair-historical-identity --mode=dry-run --order=RD16854
php artisan cashfree:repair-historical-identity --mode=dry-run --email=visheshp453@gmail.com
```

**Apply mode** requires:

- Migration `2026_10_09_190000_create_cashfree_historical_identity_repair_cohort_audits_table.php` applied
- `CENTRAL_WALLET_HISTORICAL_IDENTITY_REPAIR_APPLY_ENABLED=true`
- Confirm token matching `CENTRAL_WALLET_HISTORICAL_IDENTITY_REPAIR_APPLY_CONFIRM`
- Separate Owner authorization (not enabled in this release)

The CLI **`--mode=apply`** is fail-closed unless **`CENTRAL_WALLET_HISTORICAL_IDENTITY_REPAIR_APPLY_ENABLED=true`**, a matching **`--confirm`** token, and (for each run) an explicit **preflight dry-run summary** printed before any mutation. Unrestricted full-population apply is never implied.

## Email-level deduplication

Cohorts are keyed by `subject_hash`. All orders sharing an exact normalized email are planned/applied in **one** cohort → one Desk customer / Central Wallet → all sibling `orders.customer_id` updates.

## Concurrency

- Cache lock per email hash: `cashfree_historical_identity_repair:email:{subject_hash}`
- `CashfreeCentralCustomerBinder::bindOrder` uses row locks + DB unique credential constraint
- Retries safe: second order in cohort gets `bound_reused`; credential unique index prevents duplicate email customer

## Audit

Table `cashfree_historical_identity_repair_cohort_audits` stores cohort-level plans/applications (run_id, emails, order_ids, previous customer_id map, targets, refund exposure flag).

Dry-run performs **no** audit writes (zero production mutation).

## Financial firewall

Apply path uses **only** `CashfreeCentralCustomerBinder` (customer + zero-balance wallet provisioning). No refund executors, no ledger credits, no `refund_requests` updates.

## Performance / indexes

Relies on existing indexes:

- `orders.cashfree_payment_id` (unique)
- `orders.created_at`
- `cashfree_webhook_logs.cf_payment_id`
- `central_customer_identity_credentials` (`central_customer_credentials_subject_uq`)

Uses `cursor()` over orders; credential index loaded once per run.

## Rollback (dependency-aware)

Before any apply pilot:

1. Export cohort audit row + affected `orders.customer_id` (NULL vs UUID).
2. **New customers:** only safe to remove if no other orders/credentials/links reference that customer (check sibling orders and credentials).
3. **Never** delete a customer/wallet after another order or refund path references it.
4. Reverting `customer_id` to NULL restores pre-repair display; **does not** undo spoke wallet credits.

## Future pilot (NOT executed in P-04-10-157)

```bash
# Example only — DO NOT RUN without Owner authorization and apply_enabled in .env
php artisan cashfree:repair-historical-identity --mode=apply \
  --limit=5 \
  --order=RD16854 \
  --order=RD3436688 \
  --run-id=pilot-20261009T001 \
  --confirm=CASHFREE_HISTORICAL_IDENTITY_REPAIR
```

**Supported execution paths (same gates):**

1. **CLI** — `--mode=apply` + `--confirm` + `apply_enabled` (prints preflight dry-run, then `runApply()`).
2. **Programmatic** — `CashfreeHistoricalIdentityRepairRunner::runApply()` with confirm token (used by tests and authorized ops scripts); not a bypass — same config token and applicator.

Suggested pilot cohort:

- **RD16854** (new customer, spoke refund exposure)
- 2–3 **EXACT_EXISTING_CUSTOMER** orders from P-04-10-156 samples
- 1 **multi-order email** cohort (`--email=` with >1 order)
- 1 order with **Desk ledger** refund exposure (bind-only verification)

Verify before/after: `orders.customer_id`, C360 resolver, **no** new ledger rows, **no** refund status changes.
