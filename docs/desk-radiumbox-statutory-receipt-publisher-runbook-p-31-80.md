# RadiumBox statutory receipt publisher — operator runbook

**Scope:** Desk → RadiumBox immutable receipt for `radiumbox_com` / `commerce_order` statutory invoices (P-31-78+).  
**Not in scope:** Historical backfill, invoice remint, or changing `orders.invoicecode` on RadiumBox.

## Preconditions

1. Deploy includes migration `radiumbox_statutory_receipt_publications` and publisher code.
2. **`RADIUMBOX_STATUTORY_RECEIPT_PUBLISH_ENABLED=true`** only after explicit Owner approval (this flag is **not** changed by the requeue command).
3. Optional **invoice allowlist** for controlled first delivery (P-31-102): `RADIUMBOX_STATUTORY_RECEIPT_PUBLISH_ALLOWED_INVOICE_IDS` — see [Allowlist (controlled delivery)](#allowlist-controlled-delivery).
4. Spoke integration configured: `order_lookup.spokes.radiumbox_com` token and base URL (same as order lookup / wallet integrations).

## Allowlist (controlled delivery)

Use when Owner approves **C1** with a **single-invoice** (or small set) first live POST before broader publishing (**C5**).

| Variable | Meaning |
|----------|---------|
| `RADIUMBOX_STATUTORY_RECEIPT_PUBLISH_ALLOWED_INVOICE_IDS` | Comma-separated statutory **invoice primary keys** (e.g. `16543`). Whitespace around commas is ignored. Invalid, empty, duplicate, zero, and negative tokens are ignored. |

**Behavior:**

- **Unset or empty** — no allowlist restriction; when `RADIUMBOX_STATUTORY_RECEIPT_PUBLISH_ENABLED=true`, all eligible pending receipt events may POST (existing behavior).
- **Non-empty** — when the publisher is **enabled**, only listed invoice IDs may POST. Others stay `awaiting_activation` with an allowlist-specific `last_error` (no HTTP). Outbox rows still complete without delivery (same lifecycle as disabled publisher).
- **Allowlist never overrides** `RADIUMBOX_STATUTORY_RECEIPT_PUBLISH_ENABLED=false` — delivery remains blocked until Owner enables the publisher.

**Single-invoice example (future C1/C3, separate Owner approval):**

```env
RADIUMBOX_STATUTORY_RECEIPT_PUBLISH_ENABLED=true
RADIUMBOX_STATUTORY_RECEIPT_PUBLISH_ALLOWED_INVOICE_IDS=16543
```

After any `.env` change:

```bash
php artisan config:cache
```

Reload long-lived workers (Supervisor queue worker) per normal deploy practice so they read cached config.

**Verification (read-only; no credentials in output):**

```bash
php artisan tinker --execute="echo json_encode([
  'enabled' => config('radiumbox.statutory_receipt_publish.enabled'),
  'allowlist_count' => count(config('radiumbox.statutory_receipt_publish.allowed_invoice_ids')),
]);"
php artisan desk:radiumbox-statutory-receipt-requeue-awaiting --dry-run
```

Inspect `radiumbox_statutory_receipt_publications.status` and `last_error` for invoices not on the allowlist — they must remain `awaiting_activation` until allowlist or policy changes.

**Disable publisher:**

```env
RADIUMBOX_STATUTORY_RECEIPT_PUBLISH_ENABLED=false
```

Then `php artisan config:cache`.

**Clear temporary allowlist (after successful controlled delivery, separate Owner approval):**

Remove `RADIUMBOX_STATUTORY_RECEIPT_PUBLISH_ALLOWED_INVOICE_IDS` or set it empty, then `php artisan config:cache`.

**Warning:** Clearing the allowlist while `RADIUMBOX_STATUTORY_RECEIPT_PUBLISH_ENABLED=true` restores **unrestricted** HTTP delivery for all eligible pending receipt outbox events (including any requeued `awaiting_activation` rows).

**Warning:** `schedule:light-tick` runs `outbox:process` every minute for **all** outbox event types. Receipt POST only runs for `statutory.radiumbox_receipt.publish` rows; the allowlist gate is enforced in the receipt processor (global worker cannot bypass it).

**Separate Owner approvals (do not combine):**

| Gate | Action |
|------|--------|
| **C1** | Enable publisher (+ allowlist when used) |
| **C3** | Targeted first live POST (e.g. reopen + scoped drain for one invoice) |
| **C4** | Duplicate / idempotency retry |
| **C5** | Remove allowlist / broader automatic publishing |
| **C6** | Historical backfill |

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
