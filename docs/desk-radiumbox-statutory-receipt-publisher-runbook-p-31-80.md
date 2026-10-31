# RadiumBox statutory receipt publisher — operator runbook

**Scope:** Desk → RadiumBox immutable receipt for `radiumbox_com` / `commerce_order` statutory invoices (P-31-78+).  
**Not in scope:** Historical backfill, invoice remint, or changing `orders.invoicecode` on RadiumBox.

## Preconditions

1. Deploy includes migration `radiumbox_statutory_receipt_publications` and publisher code.
2. **`RADIUMBOX_STATUTORY_RECEIPT_PUBLISH_ENABLED=true`** only after explicit Owner approval (this flag is **not** changed by the requeue command).
3. Spoke integration configured: `order_lookup.spokes.radiumbox_com` token and base URL (same as order lookup / wallet integrations).

## Safe activation sequence (production)

There is **no staging environment** for Desk; follow read-only checks first, then enable, then requeue, then monitor.

1. **Inspect awaiting queue (no writes):**
   ```bash
   php artisan desk:radiumbox-statutory-receipt-requeue-awaiting --dry-run
   ```
   Optional cap: `--limit=50`

2. **Owner enables publisher** in environment (`RADIUMBOX_STATUTORY_RECEIPT_PUBLISH_ENABLED=true`) and reloads PHP/workers per normal deploy practice.

3. **Re-pend completed outbox rows** for publications stuck in `awaiting_activation`:
   ```bash
   php artisan desk:radiumbox-statutory-receipt-requeue-awaiting
   ```
   Optional: `--limit=50`

4. **Allow normal outbox drain** (already scheduled):
   - `schedule:light-tick` → `outbox:process`
   - Or manual: `php artisan outbox:process --limit=50`

5. **Monitor** table `radiumbox_statutory_receipt_publications`:
   - `delivered` / `duplicate_delivered` — success
   - `failed_permanent` — investigate eligibility / Box response; do not remint invoice
   - `awaiting_activation` — publisher was disabled at issue time; requeue after enable

6. **Repeat requeue safely** if new invoices were issued while disabled:
   ```bash
   php artisan desk:radiumbox-statutory-receipt-requeue-awaiting --dry-run
   php artisan desk:radiumbox-statutory-receipt-requeue-awaiting
   ```
   Idempotent: already-pending outbox rows are not duplicated; delivered publications are untouched.

## Command reference

| Option | Effect |
|--------|--------|
| `--dry-run` | Prints `selected`, `requeueable`, `requeued=0`; no DB changes |
| `--limit=N` | Consider at most **N** oldest `awaiting_activation` publication rows |

**Exit codes:**

- `--dry-run` → **0** always (informational).
- Live run with publisher **disabled** → **1**, zero changes.
- Live run with publisher **enabled** → **0** when command completes.

**Output:** Counts only — no tokens, customer fields, or invoice payloads.

## What this command does *not* do

- Does not POST to RadiumBox (workers do).
- Does not create or alter statutory invoices or invoice numbers.
- Does not rotate HTTP idempotency keys on existing publication rows.
- Does not backfill historical invoices outside the `awaiting_activation` queue.
- Does not requeue unrelated outbox event types.

## Related docs

- Receiver contract: RadiumBox `docs/desk-statutory-invoices-api-v1.md` (integrate baseline P-31-76).
- Historical backfill design (separate Owner task): `docs/desk-radiumbox-statutory-receipt-backfill-design-p-31-78.md`.
