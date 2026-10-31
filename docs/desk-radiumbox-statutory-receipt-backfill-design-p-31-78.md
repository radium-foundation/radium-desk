# RadiumBox statutory receipt publication backfill (design only)

**Prompt:** P-31-78 companion — **not implemented.** Historical publication requires a separate Owner-approved task.

## Context

P-31-77 identified Desk statutory invoices for `radiumbox_com` / `commerce_order` that may never have been published to RadiumBox because no Desk→Box receipt publisher existed before P-31-78.

P-31-78 adds a durable outbox and publisher with **`RADIUMBOX_STATUTORY_RECEIPT_PUBLISH_ENABLED` default `false`**. New issues enqueue outbox rows; processing completes with publication status `awaiting_activation` until Owner enables publishing.

## Goals of a future backfill

1. Register immutable RadiumBox receipts for eligible historical invoices without reminting or renumbering Desk invoices.
2. Preserve Desk as the sole invoice authority (numbers, amounts, issue timestamps unchanged on Desk).
3. Avoid duplicate logical publications and respect RadiumBox one-receipt-per-source rules.

## Eligibility (proposed)

Include a statutory invoice only when **all** hold:

| Check | Rationale |
|-------|-----------|
| `channel = radiumbox_com` and `source_type = commerce_order` | Receiver contract scope |
| Status issued (not draft/cancelled) | Only completed Desk issues |
| Valid `invoice_number`, `invoice_value`, stable `source_id` | Payload completeness |
| Commerce order still resolvable on Box (lookup / billing link) | Avoid 404 `billing_link_required` storms |
| No terminal success in `radiumbox_statutory_receipt_publications` | Skip already delivered / duplicate_delivered |
| Optional: amount within Box tolerance vs paid total | Reduce 409 `amount_mismatch` |

Exclude: service-only channels, support-order sources, credit notes unless explicitly scoped in a later prompt.

## Duplicate protection

1. **Desk outbox idempotency:** `statutory-rbox-receipt:invoice:{id}` — one outbox row per invoice.
2. **HTTP Idempotency-Key:** `desk-statutory:radiumbox_com:commerce_order:{source_id}:{statutory_invoice_id}` — stable across retries (same as live publisher).
3. **Publication row:** unique on `statutory_invoice_id` and `http_idempotency_key`.
4. **RadiumBox:** unique receipt per `(channel, source_type, source_id)`; replays return `duplicate_delivery`.

Backfill must **not** invent alternate idempotency keys per run.

## Proposed operator workflow

1. **Preflight report (read-only):** Artisan command or admin export listing candidate invoice IDs, source ids, amounts, current publication status, and predicted eligibility (Box lookup dry-run where available).
2. **Owner sign-off** on manifest (count, date range, max amount delta policy).
3. **Batch enqueue:** For each approved invoice, call `RadiumBoxStatutoryReceiptPublicationOutboxWriter::enqueue()` inside a transaction — same path as live issue — without re-running mint or e-invoice.
4. **Enable publisher on production only after Owner approval:** set `RADIUMBOX_STATUTORY_RECEIPT_PUBLISH_ENABLED=true` in a controlled window. There is **no Desk staging environment** — use `desk:radiumbox-statutory-receipt-requeue-awaiting --dry-run`, post-deploy smoke, and RadiumBox receiver verification before enabling (see **`docs/desk-radiumbox-statutory-receipt-publisher-runbook-p-31-80.md`**).
5. **Re-queue awaiting publications:** `php artisan desk:radiumbox-statutory-receipt-requeue-awaiting` (after publisher enabled; use `--dry-run` first). See **`docs/desk-radiumbox-statutory-receipt-publisher-runbook-p-31-80.md`**.
6. **Process outbox** via existing scheduler/worker (`outbox:process` / `schedule:light-tick`); monitor `radiumbox_statutory_receipt_publications` for `pending`, `delivered`, `failed_permanent`.
7. **Stop criteria:** Error rate threshold, first 409 payload conflict, or manual abort.

## Safety gates

- No automatic backfill in P-31-78.
- No production enablement without Owner prompt.
- No changes to legacy `orders.invoicecode` on Box (receiver API non-goal).
- Reconcile failures individually; do not bulk-retry permanent eligibility errors.

## Open decisions

- Maximum batch size and rate limit vs `throttle:desk-order-api` on Box.
- Whether to require Box billing total pre-check before enqueue for each row.
- Reporting format for finance (CSV of receipt_id ↔ INV-*).

## Status

| Action | Status |
|--------|--------|
| Implement backfill command | **NO — Not performed.** |
| Run historical publication | **NO — Not performed.** |
| Enable production publisher | **NO — Not performed.** |
