# P-30-59 / P-30-76 — rdservice.net service statutory auto-issuance gate (release prep)

**Planned production release:** **`v4.1.50`** (see **`CHANGELOG.md`** and **`docs/desk-v4.1.50-release-deployment-procedure.md`**). Production today: **`v4.1.49`** / **`af77d628`**.

Merged to **`origin/main`** (PR **#91** @ **`16fd9c0a`**, MariaDB index fix PR **#93** @ **`564b603a`**).

## Behavior summary

- **rdservice.net (`RN*` service):** Automatic mint (workflow, reconciliation, outbox, and any `issueFromSupportOrder` call) requires explicit per-order approval on the linked commerce order. Missing commerce is **fail-closed**.
- **rdservice.in / radiumbox.com service:** Automatic issuance unchanged (no per-order approval column required).
- **Finance Hub manual:** `issueFromCommerceOrder` only — **not** gated by this policy.
- **Hardware (`RDE*` etc.):** Excluded from service auto-issuance reconciliation; not affected by approval columns.
- **Channel ingest:** Production must keep **`channel_ingest.auto_issue_invoice=false`**. If enabled, foundation guard throws — no silent mint or approval.

## Release steps (Owner-operated; not executed in P-30-59 / P-30-76)

### 1. Code deployment

Deploy this branch (or its merge to `main`) through the normal Desk release pipeline after PR merge and CI. Do not enable enforcement-only config on a build that lacks the migration and policy code.

### 2. Migration execution and verification

Run once on the target database:

```bash
php artisan migrate --path=database/migrations/2026_10_30_120000_add_statutory_auto_issue_approval_to_commerce_orders_table.php
```

Verify nullable columns exist on `commerce_orders`:

- `statutory_auto_issue_approved_at` (nullable timestamp)
- `statutory_auto_issue_approved_by` (nullable FK to users)

Existing rows remain **NULL** — **do not assume retroactive approval**.

### 3. Enforcement behavior

Default: **`SERVICE_STATUTORY_INVOICE_AUTO_ISSUANCE_ENFORCE=true`** (config `service_statutory_invoice.auto_issuance.enforce`).

When enforce is **true**:

- Automatic paths for **rdservice.net** deny until approval (permanent failure classifications `auto_issuance_commerce_unlinked` / `auto_issuance_approval_required`).
- Outbox rows for denied mints should **fail permanently** (no endless retry loop for the same classification).

When enforce is **false**:

- Automatic behavior reverts to pre-gate semantics for eligible channels.
- **Does not** delete statutory invoices, approval timestamps, or audit history.

### 4. Per-order approval (eligible rdservice.net orders only)

Superadmin CLI only (not run in implementation prompts):

```bash
php artisan desk:approve-statutory-auto-issue {order_id} --actor={user_id}
```

- `{order_id}` = Desk business id (e.g. `RN…`), rdservice.net **service** orders only.
- **Existing production orders are not approved by migration or deploy** — each order that should auto-mint after go-live needs an explicit approval (or Finance manual issuance).

### 5. Validation before automatic issuance resumes

After deploy + migration + enforce=true (if used):

1. Pick a **non-production** or controlled test order, or a single Owner-approved pilot order.
2. Confirm automatic mint **denied** without approval (outbox/coordinator error text).
3. Run CLI approval for that order only.
4. Confirm exactly **one** statutory invoice via automatic path (reconciliation retry idempotent).
5. Confirm Finance manual path still works without setting approval columns.
6. Confirm RD/RB service orders still auto-mint without approval.

### 6. Rollback

Set **`SERVICE_STATUTORY_INVOICE_AUTO_ISSUANCE_ENFORCE=false`** (and redeploy or reload config). This disables gating only — **does not** remove invoices or approval column values. Re-enable enforce when ready after operational review.

## Tests (local)

```bash
cd .worktrees/p30-59-statutory-auto-issuance-gate
composer install
php vendor/bin/phpunit \
  tests/Feature/StatutoryInvoice/ServiceStatutoryAutoIssuancePolicyTest.php \
  tests/Feature/StatutoryInvoice/ServiceStatutoryInvoiceMintRetryTest.php \
  tests/Feature/Console/SeedRadiumboxHardwareSkuMapsCommandTest.php \
  tests/Feature/HardwareFulfilment/RadiumboxHardwareSkuMapResolutionTest.php
```

MariaDB integration (migration + policy against real engine): run in a dedicated non-production MariaDB instance if available; not required for PHPUnit (SQLite in-memory).
