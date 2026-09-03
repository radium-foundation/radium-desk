# Production ingest gate — rdservice.net (2026-09-01 onward)

**Project:** Radium Desk  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk`  
**Prompt ID:** **RadiumDesk-P-03-09-14**  
**Date:** 2026-09-03  
**Type:** Production gate / investigation. **No production write, migration, secret, tag, push, `deskd`, restart, or invoice.**

**Business scope:** rdservice.net paid orders with commercial date `>= 2026-09-01 00:00:00` only. No historical backfill. rdservice.in, radiumsign.com, Admin, and Stocky are out of scope.

Companion spoke work: rdservice.net `RadiumServiceNet-P-03-09-03` (`desk_channel_outbox` + authorized `/myaccount/orders/{id}/invoice`). Earlier Desk stop report for rdservice.in: [`desk-channel-order-ingest-production.md`](desk-channel-order-ingest-production.md) (P-03-09-11).

Classification used throughout:

| Label | Meaning |
|-------|---------|
| **VERIFIED** | Observed in this ticket (git, live SSH, or public HTTPS) |
| **INFERRED** | Consistent with verified facts; not independently proven here |
| **UNKNOWN** | Not established. Not upgraded. |

---

## Inspect (this ticket)

| Item | Value | Class |
|------|-------|-------|
| Ledger file | `docs/cursor-prompt-ledger.md` (no `cursor-prompt-log.md`) | VERIFIED |
| This ID | **RadiumDesk-P-03-09-14** | VERIFIED |
| Local repository | `/Users/ravi/RadiumWebsites/radium-desk` | VERIFIED |
| Branch | `feat/rd-fresh-01-inventory-pos` | VERIFIED |
| Local HEAD | `98c2c5e4faa92535df276d189da5d57b36990577` | VERIFIED |
| Worktree | Dirty. ~184 inventory/POS/statutory/shipping/IRN files uncommitted | VERIFIED |
| Remote | `origin` `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| `origin/main` | `21fc11c5fe33f594f4f1a690c3a09fbde101a293` (docs-only tip after the live tag) | VERIFIED |
| `origin/feat/rd-fresh-01-inventory-pos` | `43f1284bef80002866db7303bf5e9f96beb49d33` | VERIFIED |
| Annotated tag `v4.0.64` | commit `0d734f85ab097fd7c25f9d49e9f6c9a40a6a3f84` | VERIFIED |
| CHANGELOG latest | `4.0.64`. No `4.0.65` | VERIFIED |
| rdservice.in / Sign / Admin / Stocky | **Not inspected for mutation; not modified** | VERIFIED |

`origin/main` has **no** `ChannelIngest`, `commerce_orders`, or `StatutoryInvoice` paths. **VERIFIED.**

---

## Production identity (re-verified 2026-09-03)

Inspected over SSH `ravi@187.127.129.16` → `/var/www/radium-desk`, plus public HTTPS from this workstation. Secret **values were never printed**.

| Item | Value | Class |
|------|-------|-------|
| Host | `srv1910783` / `187.127.129.16` (`tools/config.sh`) | VERIFIED |
| Deploy path | `/var/www/radium-desk` | VERIFIED |
| Deploy mechanism | `DEPLOY_MODE=kvm` → `./tools/desk deploy` / `deskd` (local rsync, **no** remote git). `.env` is rsync-excluded | VERIFIED |
| Required deploy branch | `main` + clean worktree + latest semver tag matching CHANGELOG | VERIFIED (`tools/commands/deploy-kvm.sh`) |
| KVM git | **None** | VERIFIED prior + this host has no deploy git | INFERRED |
| `release.json` | version `4.0.64`, tag `v4.0.64`, build `0d734f85`, deployed_at `2026-08-31T13:19:03+05:30` | VERIFIED |
| `APP_ENV` / `APP_DEBUG` / `APP_URL` | `production` / `false` / `https://desk.radiumbox.com` | VERIFIED |
| PHP | `/usr/local/lsws/lsphp84/bin/php` | VERIFIED |
| DB | `DB_CONNECTION=mysql` `127.0.0.1:3306` database **`radium_desk`** | VERIFIED |
| Queue | `QUEUE_CONNECTION=redis`; `radium-desk-queue-worker` **RUNNING** pid 2029202 | VERIFIED |
| Worker uptime at inspect | ~26 minutes | VERIFIED observation. **This ticket did not restart the worker.** Cause of the short uptime is **UNKNOWN** |
| Public `GET /up` | HTTP **200** before and after SSH | VERIFIED |
| Public `GET /login` | HTTP **200** before and after SSH | VERIFIED |
| Application rollback | `desk rollback` **disabled** on KVM. Recovery = redeploy a known-good local tag via `desk deploy`. Migrations are **not** auto-reversed | VERIFIED |
| Post-deploy side effects if `deskd` were used | `php artisan migrate --force`, `RolePermissionSeeder --force`, `optimize`, **supervisor worker restart** | VERIFIED in `deploy-kvm.sh`. **Not executed.** |

Production still matches tag `v4.0.64`, not `origin/main` tip and not this feature branch. **VERIFIED.**

---

## Channel / document / statutory API surface

| Endpoint | Production `v4.0.64` | Committed feature (`f8175975` on this branch) | Dirty worktree |
|----------|----------------------|-----------------------------------------------|----------------|
| `POST /api/v1/channel-orders` | HTTP **404** “route could not be found.” `ChannelOrderIngestController.php` **ABSENT**. `routes/api.php` has no `channel` string | Present. HMAC persist `commerce_orders`. Does **not** mint | Same POST + dirty outbox writer |
| `GET /api/v1/channel-orders/{type}/{id}` | HTTP **404** | **Absent** | Present (status; no public PDF URL) |
| `GET /api/v1/channel-orders/{type}/{id}/document` | HTTP **404** | **Absent** | Present (`hmac_document_get`) |
| Manual statutory issue from commerce order | **Absent** (`StatutoryInvoiceService.php` ABSENT) | `mint()` + `issueFromPosSale()` only. **No** `issueFromCommerceOrder()` | `issueFromCommerceOrder()` + Finance `StatutoryInvoiceIssueController` |
| B2C skip IRN / B2B queue without IRP HTTP | **Absent** | Foundation only; no dispatcher | Dirty W8/IRN: Null bound; `einvoice.provider` default `none`; `worker_may_mint=false` |
| `config/channel_ingest.php` / `config/statutory_invoices.php` | **ABSENT** | Present; `auto_issue_invoice=false` | Present; both auto-issue flags **false** |

Public probes this ticket (empty JSON / unauthenticated GET, no customer order, no secret):

- `POST https://desk.radiumbox.com/api/v1/channel-orders` → **404**
- `GET .../api/v1/channel-orders/commerce_order/RA1` → **404**
- `GET .../api/v1/channel-orders/commerce_order/RA1/document` → **404**

HMAC accept/reject and idempotency are **not testable** on production while the route 404s. **VERIFIED.**

HMAC contract in owner source (committed + dirty authenticator):

- Headers: `X-Desk-Channel`, `X-Desk-Timestamp`, `X-Desk-Signature`
- `signature = HMAC-SHA256(timestamp + rawBody)` (hex)
- Empty / missing channel secret → 401
- Idempotency key: `statutory:{channel}:{source_type}:{source_id}`

**VERIFIED** in source. **Not** exercised on production.

---

## Production configuration / secrets (names only)

| Key | Production | Class |
|-----|------------|-------|
| `CHANNEL_INGEST_SECRET_RDSERVICE_NET` | **KEY_ABSENT** | VERIFIED |
| Other `CHANNEL_INGEST_SECRET_*` | **KEY_ABSENT** | VERIFIED |
| `CHANNEL_INGEST_CUTOVER_APPROVED` | **KEY_ABSENT** | VERIFIED |
| `DESK_CHANNEL_INGEST_ENABLED` | **KEY_ABSENT** (this flag belongs to **rdservice.net**, not Desk) | VERIFIED |
| `STATUTORY_EINVOICE_PROVIDER` | **KEY_ABSENT** | VERIFIED |
| `STATUTORY_INVOICE_*` numbering keys | **KEY_ABSENT** | VERIFIED |
| `DESK_ORDER_API_TOKEN` | **KEY_ABSENT** | VERIFIED |

Do **not** invent `CHANNEL_INGEST_SECRET_RDSERVICE_NET`. Do **not** reuse Cashfree / BonVoice / order-API tokens. `deskd` will not create the key (`.env` excluded from rsync).

`auto_issue` / `worker_may_mint` cannot be true on production today because the config files are absent. **VERIFIED.**

---

## Production schema (live `radium_desk`)

`Schema::hasTable` this ticket. `outbox_events` count is a read-only `COUNT(*)`.

| Table | Present | Notes | Class |
|-------|---------|-------|-------|
| `users` | YES | FK target for inventory/statutory | VERIFIED |
| `device_models` | YES | FK target for `inventory_products.device_model_id` | VERIFIED |
| `orders` | YES | Existing support orders. **Not** altered by the four Sept-1 migrations | VERIFIED |
| `outbox_events` | YES | count **373832**. Live automation/enrichment outbox. **Not** commerce pending-issue | VERIFIED |
| `inventory_*` / `inventory_user_branches` | NO | | VERIFIED |
| `statutory_invoices` / items / documents / sequences / `e_invoice_records` | NO | | VERIFIED |
| `gst_states` / `statutory_seller_profiles` | NO | Dirty later migration | VERIFIED |
| `commerce_orders` / items / `channel_ingest_attempts` | NO | | VERIFIED |
| `shipments` | NO | Dirty Shiprocket. Not required for net invoicing | VERIFIED |
| `desk_channel_outbox` | NO | Correct: that table belongs on **rdservice.net**, not Desk | VERIFIED |

`migrate:status` on KVM shows the four `2026_09_01_*` files **absent** (not Pending). They are not on the production disk. **VERIFIED.**

---

## Schema gate — four committed migrations (DO NOT APPLY)

All four exist only on `feat/rd-fresh-01-inventory-pos` (committed). None are on `origin/main` or production. Applying the existing ingest implementation requires **all four, in order**, because of foreign keys.

Lock/duration notes below are **INFERRED** for empty `CREATE TABLE` / alter-of-new-table on MariaDB 11.8.8. Exact runtime on this host is **UNKNOWN**. None of the four `ALTER` existing production `orders` or `outbox_events`.

### 1. `2026_09_01_120000_create_inventory_and_pos_foundation_tables`

| | |
|--|--|
| Creates | `inventory_branches`, `inventory_products`, `inventory_product_variants`, `inventory_customers`, `inventory_serials`, `inventory_stock_balances`, `inventory_transfers`, `inventory_transfer_lines`, `inventory_adjustments`, `inventory_adjustment_lines`, `inventory_sales`, `inventory_sale_lines`, `inventory_sale_serials`, `inventory_reservations`, `inventory_reservation_lines`, `inventory_movements` |
| Alters existing production tables | **No** |
| FKs to existing live tables | `inventory_products.device_model_id` → `device_models` (nullable); several `created_by` / `actor_user_id` → `users` |
| Internal FKs | Dense inventory graph, including a later FK from `inventory_serials.reserved_reservation_id` → `inventory_reservations` |
| Indexes / uniques | `code`, `sku`, `phone`, `serial_number`, `sale_no`, `invoice_number`, `transfer_no`, `adjustment_no`, `reservation_no`, `balance_key`, plus status/date indexes |
| Lock / online concern | New empty tables. Brief metadata locks. FK create against live `users` / `device_models` is **INFERRED** short; not a rewrite of those tables |
| Duration | **INFERRED** seconds–low minutes. **UNKNOWN** exact |
| Required for rdservice.net flow | **Yes, for the existing migration chain** (`statutory_invoices.inventory_sale_id` / `branch_id` → these tables). Not because POS must go live. A thinner ingest-only schema would be **new design** |
| Existing data modified | **No** (create-only) |

### 2. `2026_09_01_140000_add_inventory_branch_assignments_and_sale_idempotency`

| | |
|--|--|
| Creates | `inventory_user_branches` (`user_id`+`branch_id` unique) |
| Alters | `inventory_sales.idempotency_key` nullable unique — **only the table created in (1)**, which is empty |
| FKs | `user_id` → `users`; `branch_id` → `inventory_branches` |
| Existing production data | **No** |
| Required | **Yes**, as a dependency of the committed chain. Not otherwise needed for HMAC ingest |

### 3. `2026_09_01_160000_create_statutory_invoice_foundation_tables`

| | |
|--|--|
| Creates | `invoice_sequences`, `statutory_invoices`, `invoice_sequence_allocations`, `statutory_invoice_items`, `e_invoice_records` |
| Alters | `inventory_sales.statutory_invoice_id` nullable FK — empty new table from (1) |
| Uniques | `invoice_number`, `idempotency_key`, `(channel,source_type,source_id)`, `inventory_sale_id`, allocation numbers |
| Existing production data | **No**. Sequences start empty |
| Required | **Yes.** Persist + later manual mint / PDF / IRN records live here |
| Mint on migrate | **No.** Source flags stay off |

### 4. `2026_09_01_180000_create_channel_order_ingest_tables`

| | |
|--|--|
| Creates | `commerce_orders`, `commerce_order_items`, `channel_ingest_attempts` |
| FKs | `commerce_orders.statutory_invoice_id` → `statutory_invoices` (why (3) must run first); items/attempts → `commerce_orders` |
| Uniques | `order_no`, `idempotency_key`, `(channel,source_type,source_id)` |
| Existing production data | **No** |
| Required | **Yes.** This is the persist target for `POST /api/v1/channel-orders` |

`down()` on (4) drops only the three ingest tables. `down()` on (1)–(3) would drop the new inventory/statutory surface. Tag redeploy of `v4.0.64` does **not** run those `down()` methods. **VERIFIED** in source / deploy tooling.

### Dirty later migrations (must not ride along)

Present only as **untracked** files in this worktree. **Not** on HEAD. **Not** on `origin/main`.

| Migration | Needed for Sept-1+ net customer path? |
|-----------|----------------------------------------|
| `2026_09_02_120000` seller profiles + `gst_states` seed | **Yes** for current dirty mint/numbering (`INV-SSFFNNNN` / seller profile). Not in committed `845e2831` |
| `2026_09_02_121000` POS snapshot columns | POS only. **No** for net ingest |
| `2026_09_02_130000` `statutory_invoice_documents` | **Yes** for private PDF + document GET |
| `2026_09_02_140000` / `160000` e-invoice audit / reconcile columns | **Yes** if B2B IRN *queue* (still no HTTP while provider=`none`) is required |
| `2026_09_02_150000` / `171000` / `2026_09_03_180000` shipments / shipping snapshots / line kind | **No.** Shiprocket. Out of this gate |
| `2026_09_02_170000` NIC input snapshots | B2B IRN input readiness. Not required to ingest or to skip B2C IRN |
| `2026_09_03_190000` series policy FY code | **Yes** for current dirty tax-invoice number format |

---

## rdservice.net `desk_channel_outbox` compatibility

Spoke migration: rdservice.net `database/migrations/2026_09_03_180000_create_desk_channel_outbox_table.php` (commit `646d988` on the net repo).

| Fact | Class |
|------|-------|
| Table lives on the **rdservice.net** database, not `radium_desk` | VERIFIED (source) |
| Production Desk correctly has **no** `desk_channel_outbox` | VERIFIED |
| Net production overlay of that migration | **UNKNOWN** this ticket (net production was not migrated here) |
| Desk compatibility required | `POST /api/v1/channel-orders` with the HMAC headers above; channel `rdservice_net`; matching `CHANNEL_INGEST_SECRET_RDSERVICE_NET` on **both** sides; empty Desk secret → 401; empty/`false` net `DESK_CHANNEL_INGEST_ENABLED` → net never POSTs | VERIFIED (source) |
| Customer PDF | Net proxies `GET /api/v1/channel-orders/commerce_order/{rdorderid}/document` after auth. That Desk route exists only in the **dirty** worktree | VERIFIED |
| Checkout / paid order | Must not wait on Desk. Outbox failure must not unpay | VERIFIED (net source) |

Do not copy the net outbox table onto Desk.

---

## Clean release

| Candidate | Provides | Ship? |
|-----------|----------|-------|
| Production `v4.0.64` | Live support Desk only | Already live. **No** ingest |
| `origin/main` | Same era + later docs | **No** ingest code |
| Committed feature `f8175975` + inventory/statutory parents | HMAC **POST** + persist commerce order. Auto-issue off. **No** document GET, **no** `issueFromCommerceOrder`, **no** PDF, **no** seller-profile numbering | Insufficient for Phase 1 customer retrieval / manual issue |
| Dirty worktree | Full local test path (ingest → manual mint → PDF → HMAC GET → B2C skip IRN → B2B queue) **plus** Shiprocket, WhiteBooks adapter files, shipping schema | **Forbidden.** Do not deploy |

**No clean, reviewable release exists today** that provides the required production capability without shipping unrelated WIP.

Required **implementation boundary** for a later ticket (do **not** create it in this gate):

1. New clean branch from `main` / `v4.0.64`, not this dirty tree.
2. Take only: inventory foundation (FK chain), statutory foundation, channel POST ingest, seller profile + documents + commerce issue + private PDF + HMAC document GET + B2C/B2B eligibility with `NullEInvoiceGateway` still bound.
3. Leave out: Shiprocket (`shipments`, S2–S6), WhiteBooks HTTP bind/credentials, shipping snapshot/line-kind migrations, rdservice.in secrets, auto-issue flags.
4. Changelog + tag (next would be **4.0.65**) only after notes are approved.
5. `deskd` still auto-runs `migrate --force` and **restarts the queue worker**. That restart must be an explicit later owner decision; this ticket forbids it.

---

## Backup / rollback

| Item | Value | Class |
|------|-------|-------|
| Production DB | `radium_desk` on loopback MariaDB **11.8.8** | VERIFIED |
| Procedure | [`docs/backup-runbook.md`](backup-runbook.md): `bin/backup-run.sh` (mysqldump → gzip → gpg/age); KVM cron 02:00 and 14:00 IST | VERIFIED (docs + live run dir) |
| Latest local run | `20260903T083001Z` (2026-09-03 08:30 UTC / 14:00 IST) | VERIFIED |
| Manifest | `phase=cloud_uploaded`; `upload.status=completed`; `artifacts_verified=true`; app `4.0.64` / `0d734f85` | VERIFIED |
| Artifacts | `database.sql.gz.gpg` (~383 MiB) + `secrets.tar.gz.gpg` + `manifest.json` | VERIFIED names/sizes |
| `last-run-status.json` | Stale (`generated_at` 2026-08-22, sentinel `backup_id` `20991231T000000Z`). **Do not use as SoT** | VERIFIED |
| Cloud inventory JSON | Absent / unreadable to the web user this ticket | VERIFIED |
| Restore this ticket | **NO — Not performed** | VERIFIED |
| Prior restore drill | Runbook: proven on an operator Mac; automated restore CLI still future | Prior doc; **UNKNOWN** whether that drill still applies to `20260903T083001Z` |

**Production migration is BLOCKED** until an owner either restore-rehearses `20260903T083001Z` on a non-production clone or explicitly accepts the prior Mac drill for this backup id. The prompt requires a verified restorable backup before migrate.

Application rollback after a future deploy: redeploy `v4.0.64` via `desk deploy` from a clean checkout of that tag. That does **not** drop newly created inventory/statutory/commerce tables.

Migration rollback implication:

| If applied (not applied) | Rollback |
|--------------------------|----------|
| (4) ingest tables only | Safe to `down()` those three empty/new tables after a backup |
| (1)–(3) inventory + statutory | Do **not** casually `down()` on production. Redeploy tag leaves empty new tables in place until a separate drop ticket |
| Dirty shipping / seller / FY migrations | Must never be applied by this gate |

---

## Validation / rollout sequence (DO NOT EXECUTE)

1. **Backup** — confirm `20260903T083001Z` (or a newer completed run) is restore-rehearsed. Take a fresh `backup-run.sh` immediately before migrate.
2. **Deploy a clean compatible release** — only after the implementation-boundary branch exists, is tagged, and is on `main`. Not this dirty tree.
3. **Run only required additive migrations** — the four committed Sept-1 files, plus only the later seller/document/e-invoice-audit/FY migrations that the clean extract actually contains. Never Shiprocket.
4. **Verify schema** — `commerce_orders`, `statutory_invoices`, `statutory_invoice_documents` present; `orders` / `outbox_events` unchanged in structure; no `shipments` unless explicitly approved later.
5. **Verify `/up` and `/login`** — HTTP 200. No planned downtime; `deskd` still restarts the queue worker (owner must accept that blip).
6. **Verify queue worker** — `radium-desk-queue-worker` RUNNING. Do not enable `worker_may_mint`.
7. **Verify channel endpoint** — route exists (no longer 404).
8. **HMAC reject/accept without a real customer order** — use a **non-production** fixture payload and a secret installed through the documented `.env` mechanism. Do not invent the value in git/chat. Empty secret must 401. Do not enable net `DESK_CHANNEL_INGEST_ENABLED` until this passes.
9. **Idempotency** — same key + same body → duplicate/existing; same key + different body → conflict. Still no mint.
10. **Automatic statutory issue stays OFF** — `channel_ingest.auto_issue_invoice=false`, `statutory_invoices.auto_issue_on_pos_complete=false`.
11. **Production IRN HTTP stays disabled** — `STATUTORY_EINVOICE_PROVIDER` absent or `none`; Null gateway bound; no WhiteBooks/GSP/NIC/IRP call.
12. **Customer document retrieval authorization** — HMAC GET 401 without signature; 404 before issue; 200 PDF after a **later** controlled manual issue of a Sept-1+ eligible order. That mint is **not** this ticket.
13. **Rollback** — redeploy `v4.0.64`; do not `migrate:rollback` inventory; restore `radium_desk` from the pre-migrate backup only if schema/data is wrong.

Sept-1+ paid rdservice.net production orders today: **0** (spoke P-03-09-03). There is **no** production mint candidate. Do not manufacture one.

---

## Verdict

**Existing production Desk cannot safely receive the September-1+ rdservice.net channel-order flow.**

Hard stops:

1. Live release `v4.0.64` has no ingest/document/statutory code or routes (**404**).
2. Required schema is absent; applying it needs four committed migrations plus a later clean extract for PDF/manual issue.
3. `CHANNEL_INGEST_SECRET_RDSERVICE_NET` is **KEY_ABSENT**. Do not invent it here.
4. No clean releasable tree: `main` lacks ingest; committed feature lacks document GET / commerce issue / PDF; dirty tree must not be deployed.
5. Migration remains **BLOCKED** until backup `20260903T083001Z` (or newer) is restore-verified for this gate.
6. `deskd` would `migrate --force` and restart the queue worker. Both are forbidden in this ticket.

`https://desk.radiumbox.com` was left up. No invoice was created. No historical row was touched.

---

## What this ticket did not do

| Action | Status |
|--------|--------|
| Production deploy / `deskd` / rsync | **NO** — Not performed |
| `migrate --force` | **NO** — Not performed |
| Production `.env` edit / secret creation | **NO** — Not performed |
| Enable `DESK_CHANNEL_INGEST_ENABLED` | **NO** — Not performed |
| Deploy dirty feature branch | **NO** — Not performed |
| Create a clean extract branch/tag | **NO** — Not performed (boundary only) |
| Restart Desk / queue worker | **NO** — Not performed |
| Production invoice / IRN / GSP / WhiteBooks | **NO** — Not performed |
| Historical backfill / test-order manufacture | **NO** — Not performed |
| rdservice.in / Sign / Admin / Stocky change | **NO** — Not performed |
| Push | **NO** — Not performed |
