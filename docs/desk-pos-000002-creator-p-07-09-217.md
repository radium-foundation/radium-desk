# Who created POS-000002 — P-07-09-217

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-217  
**Date:** 2026-09-10  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk-irn-foundation`  
**Remote:** `git@github.com:radium-foundation/radium-desk.git`  
**Production:** KVM8 `srv1910783` / `187.127.129.16` `/var/www/radium-desk` DB `radium_desk`  
**Host:** `https://desk.radiumbox.com`  
**Timezone:** `Asia/Kolkata` (APP_TIMEZONE + host)

**Verdict: CREATOR VERIFIED — User 1 Ravi (`info@radiumbox.com`, superadmin).**

Read-only. No INSERT/UPDATE/DELETE, no mint, no IRN, no serial allocate, no deploy, no Git commit.

## Production boundary (re-verified)

| Axis | Value | Class |
|------|--------|--------|
| Hostname | `srv1910783` / `srv1910783.hstgr.cloud` | VERIFIED |
| App path | `/var/www/radium-desk` | VERIFIED |
| DB | `radium_desk` @ `127.0.0.1` | VERIFIED |
| APP_URL | `https://desk.radiumbox.com` | VERIFIED |
| Timezone | `Asia/Kolkata` | VERIFIED |
| PHP | `/usr/local/lsws/lsphp84/bin/php` 8.4.24 | VERIFIED |

## Creator

| Field | Value | Class |
|-------|--------|--------|
| POS ID | `2` | VERIFIED `inventory_sales.id` |
| POS number | `POS-000002` | VERIFIED `inventory_sales.sale_no` |
| Created at | `2026-09-10 17:19:40` IST | VERIFIED `created_at` = `completed_at` |
| Created by | User 1 Ravi | VERIFIED `created_by=1` |
| Email | `info@radiumbox.com` | VERIFIED `users.email` |
| Username | none (no username column) | VERIFIED schema |
| Role | `superadmin` | VERIFIED Spatie `model_has_roles` |
| POS perms | `pos.view`, `pos.sell`, `pos.cancel` via role | VERIFIED |
| Branch | `DELHI-RETAIL` / Delhi Retail / `07AAICP1128M1Z9` | VERIFIED |
| Terminal/session | none recorded; Laravel `sessions` empty in 17:00–17:40 IST | VERIFIED absence |
| Payment | Cash, no UTR, no UPI intent | VERIFIED |
| Idempotency key | NULL | VERIFIED |
| Channel | Walk-in POS test, not commerce/order | VERIFIED `support_order_id` NULL |
| Notes | `RadiumDesk-P-07-09-203 controlled IRN GENERATE test…` | VERIFIED |

Creation was **not** `POST /pos/counter`. P-203 wrote `/tmp/irn-p203-create.php` on KVM8 and called:

```php
$actor = User::query()->find(1);
$sale = app(PosSaleService::class)->completeSale(..., actor: $actor, paymentMethod: 'Cash', ...);
$invoice = app(StatutoryInvoiceService::class)->issueFromPosSale($sale, $actor);
```

`PosStatutoryInvoiceIssuer::issueAfterSaleCommit()` did **not** exist at 17:19 (landed `8553637f` 21:00 IST). Invoice mint for this sale is the explicit `issueFromPosSale` in that CLI script.

## Sale row (authoritative)

`inventory_sales` id 2: status `completed`, total `2499.00`, internal receipt `INV-DELHI-RETAIL-2026-00002`, statutory_invoice_id `1123`, journal `26000`, `created_at` = `updated_at` = `2026-09-10 17:19:40`. No later modification of the sale row.

Customer id 2 `Phil Technologies (P) Limited`, GSTIN `27AAICP1128M1Z7`, place of supply Maharashtra. Phone masked. Created in the same second as the sale (`findOrCreateCustomer`).

## Serials

Product `RBMFS110L1` (id 28), qty 1, serialized, required serial count 1, actual `10564153` (`inventory_serials.id=1`, assignment id 2). Assigned inside `completeSale()` at `2026-09-10 17:19:40` by actor 1. Movement 4437 `sale` available→sold. No later serial movement.

## Invoice / IRN

| Field | Value | Class |
|-------|--------|--------|
| Invoice | `INV-076749` / id 1123 | VERIFIED |
| Channel | `desk_pos` / source `inventory_sale` / `2` | VERIFIED |
| Idempotency | `statutory:desk_pos:inventory_sale:2` | VERIFIED |
| Issued by | User 1 | VERIFIED `issued_by=1` |
| Issued at | `2026-09-10 17:19:40` IST | VERIFIED |
| Trigger | CLI `issueFromPosSale`, not Finance Hub UI, not worker | VERIFIED transcript + timestamps |
| IRN | `d858cf9f0a582a7aec79eade603e66cf584e9a68bdde5a50a823793527a9d776` | VERIFIED `e_invoice_records` 1123 |
| Ack | `172621145994081` / `2026-09-10 17:21:00` | VERIFIED |
| IRN actor | Same P-203 CLI GENERATE, **not** the WhiteBooks worker | VERIFIED P-203 + `worker_may_mint=false` |
| Outbox | id 709502 `statutory.invoice.einvoice` completed 17:20:03 | VERIFIED; skip-complete INFERRED (`worker_may_mint_off`) |

PDF document 1123 created 17:19:41, last generated 17:39:59 (P-204 visual QR). Attempts 3. Sale fields unchanged.

## Audit / logs

- `audit_logs`: 0 rows for InventorySale 2 / POS-000002 / INV-076749. POS `completeSale` does not write audits. VERIFIED code + SELECT.
- `storage/logs/laravel.log` (1017M): 0 hits for POS-000002 / INV-076749 / serial / P-07-09-203. Bounded grep.
- LiteSpeed access logs: not readable as `ravi` (`/usr/local/lsws/logs` root). UNKNOWN.
- Interactive bash/psysh history: no P-203 commands (non-interactive SSH).

## UI `/pos/sales/2`

`auth`+`active` → `GET pos/sales/{sale}` → `SaleController::show` → `PosAccess::allows` (`pos.view`) → `InventoryBranchScope::assertCanOperate` → `pos.sales.show`. Loads `createdBy` but the Blade **does not render** creator name/id.

## Later actors

| Time IST | Actor | Action |
|----------|--------|--------|
| 17:19:40 | User 1 via CLI | payment + serials + sale + journal |
| 17:19:40 | User 1 via CLI | statutory mint INV-076749 |
| 17:20:03 | queue worker | einvoice outbox completed (no GENERATE) |
| 17:20:35 | P-203 CLI | WhiteBooks GENERATE; SignedInvoice persist |
| 17:39:59 | P-204 overlay/script | PDF QR rewrite; document attempts=3 |

No later cashier/admin edited serials, payment, customer, or status.

## Execution

Changed: NO — read-only investigation (ledger + this doc only).  
Database modified: NO.  
Deployment: NO.  
IRN/WhiteBooks: NO.  
Git commit: NO.
