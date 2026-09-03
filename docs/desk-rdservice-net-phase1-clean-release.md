# Radium Desk — rdservice.net Phase 1 clean release

**Project:** Radium Desk  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk`  
**Worktree:** `/Users/ravi/RadiumWebsites/radium-desk-phase1-clean`  
**Prompt ID:** **RadiumDesk-P-03-09-15**  
**Date:** 2026-09-03  
**Branch:** `feat/rdservice-net-phase1-clean`  
**Clean base:** `origin/main` `21fc11c5` (two docs commits after annotated tag `v4.0.64` / `0d734f85`)

This branch is a **clean extract** from production main. It does **not** merge `feat/rd-fresh-01-inventory-pos` and does **not** include dirty Shiprocket, WhiteBooks, seller-profile, shipping, or POS operational WIP.

Target flow:

```
rdservice.net paid order
  → HMAC POST /api/v1/channel-orders
  → Desk commerce order
  → manual statutory issue
  → private Desk PDF
  → HMAC GET /api/v1/channel-orders/{type}/{id}/document
```

Customer session authorization (guest / wrong customer / pre-September) is enforced on **rdservice.net** `/myaccount/orders/{id}/invoice`. Desk additionally requires channel HMAC, optional `X-Desk-Customer` binding, and the September-1 commercial-date gate.

---

## Clean release contents

| Area | Included |
|------|----------|
| Channel ingest | `POST /api/v1/channel-orders` HMAC-SHA256(`timestamp + raw body`) |
| Headers | `X-Desk-Channel`, `X-Desk-Timestamp`, `X-Desk-Signature` |
| Secrets | Per-channel env keys; empty/missing fails closed |
| Replay | Default 300s window |
| Idempotency | `statutory:{channel}:{source_type}:{source_id}` e.g. `statutory:rdservice_net:commerce_order:{rdorderid}` |
| Commerce persist | Existing `commerce_orders` / items / `channel_ingest_attempts` schema |
| Manual issue | `StatutoryInvoiceService::issueFromCommerceOrder()` + Finance pending/issue UI |
| Seller identity | Desk config (`STATUTORY_INVOICE_GSTIN_SCOPE`, `STATUTORY_INVOICE_LEGAL_NAME`). Payload seller fields are stored but **not** used as issuer |
| Numbering | Committed config series (`series_code` + `number_format`). Unset fails closed |
| B2C | Number + private PDF. IRN record skipped (`b2c_not_eligible`). No IRN outbox |
| B2B | Number + private PDF. `e_invoice_records` queued + `statutory.invoice.einvoice` outbox. Processor never calls HTTP |
| PDF | Private `local` disk. Regenerable. No public URL |
| Document GET | HMAC required. Guest 401. Wrong `X-Desk-Customer` 404. Pre-2026-09-01 403. Missing/unissued 404 |
| Flags | Ingest default off (empty secret). `auto_issue_invoice=false`. `worker_may_mint=false`. `einvoice.provider=none` |

## Migrations included

Applied only in local/test. **Not** applied to production.

| Order | File | Why |
|-------|------|-----|
| 1 | `2026_09_01_120000_create_inventory_and_pos_foundation_tables.php` | Unchanged. Required because `statutory_invoices` FKs `inventory_sales` / `inventory_branches` |
| 2 | `2026_09_01_140000_add_inventory_branch_assignments_and_sale_idempotency.php` | Unchanged. Part of the committed chain |
| 3 | `2026_09_01_160000_create_statutory_invoice_foundation_tables.php` | Unchanged. Invoice + sequence + e-invoice tables |
| 4 | `2026_09_01_180000_create_channel_order_ingest_tables.php` | Unchanged. Commerce persist target |
| 5 | `2026_09_02_130000_create_statutory_invoice_documents_table.php` | Private PDF row. No seller-profile / shipping columns |

None of these alter live `orders` or `outbox_events`.

## Migrations excluded

| File | Reason |
|------|--------|
| `2026_09_02_120000` seller profiles / `gst_states` | Seller-profile WIP. Clean mint uses config numbering |
| `2026_09_02_121000` POS snapshot columns | POS only |
| `2026_09_02_140000` / `160000` / `170000` e-invoice audit / NIC snapshots | WhiteBooks / IRN provider WIP |
| `2026_09_02_150000` / `171000` / `2026_09_03_180000` shipments / shipping | Shiprocket |
| `2026_09_03_190000` series policy FY code | Seller-profile numbering |

Inventory **application** (POS counter, stock UI, journals) is also excluded. Empty inventory tables exist only because of the statutory FK chain.

## Dependencies

- Production `users` and `device_models` (nullable FK from `inventory_products`)
- Existing `outbox_events` processor (adds one no-HTTP `statutory.invoice.einvoice` arm)
- rdservice.net spoke already authenticates the customer and proxies HMAC GET
- Matching empty-or-installed `CHANNEL_INGEST_SECRET_RDSERVICE_NET` on both sides at go-live. **Do not invent the value here**

## September-1-only invoice boundary

- Commercial date = `ordered_at` ?? `paid_at` ?? `received_at`
- Before `2026-09-01 00:00:00` (app timezone): ingest may persist as **not invoice-eligible**; issue refuses; document GET returns **403**
- **No historical backfill.** Do not mint pre-September orders. Do not manufacture a production test order.

## Production configuration (names only)

Install through the documented `.env` mechanism on KVM. Values stay empty in git.

| Key | Required at go-live | Default |
|-----|---------------------|---------|
| `CHANNEL_INGEST_SECRET_RDSERVICE_NET` | Yes, matching rdservice.net. **KEY must be created by owner** | empty (fail closed) |
| Other `CHANNEL_INGEST_SECRET_*` | Leave empty unless that channel is approved | empty |
| `CHANNEL_INGEST_REPLAY_WINDOW_SECONDS` | Optional | 300 |
| `CHANNEL_INGEST_CUTOVER_APPROVED` | Leave false | false |
| `STATUTORY_INVOICE_SERIES_CODE` | Yes before first manual issue | empty (fail closed) |
| `STATUTORY_INVOICE_NUMBER_FORMAT` | Yes before first manual issue | empty |
| `STATUTORY_INVOICE_GSTIN_SCOPE` | Yes — Desk seller GSTIN | empty |
| `STATUTORY_INVOICE_LEGAL_NAME` | Yes — Desk seller name | empty |
| `STATUTORY_INVOICE_DOCUMENT_TYPE` | Optional | `tax_invoice` |
| `STATUTORY_INVOICE_FINANCIAL_YEAR` | Optional | empty |
| `STATUTORY_INVOICE_SELLER_ADDRESS` / `SELLER_STATE` | Optional PDF lines | empty |
| `STATUTORY_INVOICE_SCOPE_STARTS_AT` | Leave default | `2026-09-01 00:00:00` |
| `STATUTORY_EINVOICE_PROVIDER` | Leave `none` | `none` |

Hardcoded off: `channel_ingest.auto_issue_invoice`, `statutory_invoices.auto_issue_on_pos_complete`, `statutory_invoices.worker_may_mint`, `statutory_invoices.post_finance_journals`.

Do **not** add or invent a production secret in git. Do not reuse Cashfree / BonVoice / `DESK_ORDER_API_TOKEN`.

## Production rollout order (DO NOT EXECUTE in this ticket)

1. Backup `radium_desk` (`bin/backup-run.sh`). Restore-rehearse before migrate.
2. Review and merge this branch to `main`. Changelog + tag (next would be **4.0.65**) only after notes are approved.
3. Deploy a **clean** tagged `main` — not the dirty inventory/POS feature branch.
4. `deskd` will `migrate --force` and **restart the queue worker**. That restart is an owner decision.
5. Verify schema: commerce + statutory + documents present; `orders` / `outbox_events` structure unchanged; no `shipments`.
6. Verify `/up` and `/login`.
7. Confirm `CHANNEL_INGEST_SECRET_RDSERVICE_NET` on Desk (and matching net secret) without printing values.
8. HMAC reject (empty/bad/stale) then accept with a **non-production** fixture. Do not enable net `DESK_CHANNEL_INGEST_ENABLED` until reject/accept pass.
9. Leave auto-issue and IRN HTTP off.
10. First Sept-1+ paid rdservice.net order is the mint candidate. Manual issue only. No backfill.

## Backup requirement

Production migrate remains **blocked** until a verified restorable backup exists for that window. Application rollback (redeploy `v4.0.64`) does **not** drop newly created inventory / statutory / commerce / document tables. `migrate:rollback` of (1)–(3) must not be casual on production.

## Rollback limitation

| If applied | Rollback |
|------------|----------|
| Application only | Redeploy `v4.0.64` via `desk deploy`. New tables remain |
| Ingest + documents tables only, still empty | `down()` is possible after backup |
| Inventory + statutory foundation | Do not casually `down()` on production |

## Isolation — not in this release

rdservice.in activation, radiumsign.com, Admin, Stocky, Shiprocket, WhiteBooks HTTP, payment WIP, POS operational UI, seller-profile / `INV-SSFFNNNN` numbering, NIC input snapshots.

IRN provider remains `NullEInvoiceGateway`. No production IRP HTTP.

## Tests (this ticket)

- Fresh sqlite migrations via `RefreshDatabase`
- Focused: Channel HMAC + ingest + numbering + Phase 1 path — **41 passed**
- Pint on dirty PHP — passed
- Full baseline suite run separately after this document

## What this ticket did not do

| Action | Status |
|--------|--------|
| Production deploy / `deskd` / rsync | **NO** — Not performed |
| Production migrate | **NO** — Not performed |
| Production `.env` / secret creation | **NO** — Not performed |
| Desk restart / queue worker restart | **NO** — Not performed |
| Production invoice / IRN / backfill / test order | **NO** — Not performed |
| Push | **NO** — Not performed |
| Dirty feature-branch deploy | **NO** — Not performed |
| rdservice.in / Sign / Admin / Stocky change | **NO** — Not performed |
