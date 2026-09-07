# P7 production hardware ingest verification

**Project:** Radium Desk  
**Prompt:** **RadiumDesk-P-07-09-21**  
**Date:** 2026-09-07  
**Type:** Observe-first production verification. No deploy. No code change. No seven-order processing.  
**Verdict:** **STOPPED / BLOCKED.** No new Box → Desk hardware ingest was sent.

Classification: **VERIFIED** / **OWNER-LOCKED** / **INFERRED** / **UNKNOWN**.

---

## 0. Repository verification (before any production read)

| Item | Value | Class |
|------|-------|-------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Branch | `main` | VERIFIED |
| HEAD | `517354787f8379ea3738150f2daf02f7f4d2f532` (P6) | VERIFIED |
| origin/main | `e4c3aec328a2a132d3763c115aeb259021bc2585` | VERIFIED |
| Local vs origin | ahead 6 (P1–P6 unpushed) | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Ledger last ID | `RadiumDesk-P-07-09-20` | VERIFIED |
| This prompt ID | `RadiumDesk-P-07-09-21` | VERIFIED |

P1–P6 exist on local `main` only. They are **not** on `origin/main` and **not** on the production app tree.

---

## 1. Production boundary

| Item | Value | Class |
|------|-------|-------|
| Server | `srv1910783` / `187.127.129.16` | VERIFIED |
| Path | `/var/www/radium-desk` | VERIFIED |
| Public URL | `https://desk.radiumbox.com` | VERIFIED |
| `APP_ENV` / debug | `production` / `false` | VERIFIED |
| Git on deploy path | none | VERIFIED |
| Production deployment SHA | **UNKNOWN** (no git / RELEASE_SHA) | UNKNOWN |
| Overlay evidence | `GstSplitService.php` SHA-256 `37083d43…` matches this worktree | VERIFIED |
| Database | `radium_desk` @ `127.0.0.1` (tinker) | VERIFIED |
| Channel endpoint | `POST https://desk.radiumbox.com/api/v1/channel-orders` | VERIFIED |
| Unauthenticated POST | HTTP **401** `unauthorized` | VERIFIED |

P0 enablement sequence still requires merge of P1–P6 with flags off **before** P7 secrets/URL observe. That merge/deploy was **not** performed.

---

## 2. HMAC / channel contract (production files)

Production `ChannelIngestAuthenticator` and `ChannelOrderIngestController` SHA-256 match this worktree.

| Check | Result | Class |
|-------|--------|-------|
| Headers | `X-Desk-Channel`, `X-Desk-Timestamp`, `X-Desk-Signature` | VERIFIED |
| Signature | `hash_hmac('sha256', $timestamp.$rawBody, $secret)` | VERIFIED |
| Compare | `hash_equals` | VERIFIED |
| Replay window | `channel_ingest.replay_window_seconds` default **300**; env key **KEY_ABSENT** | VERIFIED |
| Empty Box secret | 401 | VERIFIED |

---

## 3. Production flags (Desk `.env`, values not printed)

| Flag | Production | Class |
|------|------------|-------|
| `CHANNEL_INGEST_SECRET_RADIUMBOX_COM` | **KEY_ABSENT** | VERIFIED |
| `CHANNEL_INGEST_SECRET_RDSERVICE_IN` | SET | VERIFIED |
| `CHANNEL_INGEST_CUTOVER_APPROVED` | KEY_ABSENT | VERIFIED |
| `DESK_INGEST_ENABLED` (Desk env) | KEY_ABSENT (Box-side flag) | VERIFIED |
| `HARDWARE_FULFILMENT_ENABLED` | KEY_ABSENT | VERIFIED |
| `HARDWARE_FULFILMENT_CALLBACK_*` / `DESK_CALLBACK_SECRET` | KEY_ABSENT | VERIFIED |
| `SHIPROCKET_*` | KEY_ABSENT | VERIFIED |
| `STATUTORY_INVOICE_WORKER_MAY_MINT` | KEY_ABSENT | VERIFIED |
| `config/channel_ingest.php` `auto_issue_invoice` | hardcoded `false` | VERIFIED |
| `config/statutory_invoices.php` `worker_may_mint` | `false` | VERIFIED |
| `config/hardware_fulfilment.php` | **ABSENT** | VERIFIED |
| `config/shipping.php` | **ABSENT** | VERIFIED |

---

## 4. Box delivery gate (`.env` presence only; `radiumbox_prod` not queried)

| Flag | Production | Class |
|------|------------|-------|
| `DESK_INGEST_ENABLED` | `false` | VERIFIED |
| `DESK_BASE_URL` | **EMPTY** | VERIFIED |
| `CHANNEL_INGEST_SECRET_RADIUMBOX_COM` | **EMPTY** | VERIFIED |
| `DESK_CALLBACK_SECRET` | KEY_ABSENT | VERIFIED |
| `VENDOR_SHIPROCKET_ENABLED` | `false` | VERIFIED |

`DeskChannelClient` refuses HTTP when `base_url` or `secret` is empty. `DESK_INGEST_ENABLED` is present in `config/desk.php` but the client gate is empty URL/secret. **INFERRED:** flipping the unread/unused flag alone would not send.

---

## 5. P1–P6 on production

| Artifact | Production | Class |
|----------|------------|-------|
| `HardwareFulfilment` model / foundation / P6 callback | ABSENT | VERIFIED |
| P1–P5 migrations | ABSENT | VERIFIED |
| `hardware_fulfilments` / serials / events / `channel_sku_maps` / `shipments` | tables **NO** | VERIFIED |
| `ChannelIngestService` hardware hook | 0 matches | VERIFIED |
| Local `ChannelIngestService` SHA vs production | different (local has P1 hook) | VERIFIED |

Hardware fulfilment eligibility **cannot** be reached on this production tree.

---

## 6. Counts (read-only)

| Metric | Value | Class |
|--------|-------|-------|
| `commerce_orders` total | 222 | VERIFIED |
| `commerce_orders` `radiumbox_com` | **0** | VERIFIED |
| Frozen source IDs in commerce | **0** | VERIFIED |
| `channel_ingest_attempts` | 230 | VERIFIED |
| `channel_ingest_attempts` `radiumbox_com` | **0** | VERIFIED |
| Last ingest | id 230 `rdservice_in` accepted **201** `2026-09-07 14:20:06` | VERIFIED |
| Statutory invoices issued / total / allocations | 128 / 128 / 128 | VERIFIED |
| Shipments table | NO | VERIFIED |
| Frozen RDE support `orders` | **7**, all `active`, no statutory rows | VERIFIED |

Frozen Desk support IDs (unchanged; not ingested / invoiced / shipped):

| Source | Desk `orders.id` | Created |
|--------|------------------|---------|
| RDE318360 | 50935 | 2026-09-05 17:37:33 |
| RDE318367 | 51001 | 2026-09-05 21:02:40 |
| RDE318378 | 51107 | 2026-09-06 14:30:08 |
| RDE318379 | 51111 | 2026-09-06 14:37:07 |
| RDE318382 | 51118 | 2026-09-06 15:04:25 |
| RDE318388 | 51176 | 2026-09-06 18:41:05 |
| RDE318391 | 51192 | 2026-09-06 20:35:50 |

---

## 7. Why no P7 test order was sent

A P7 order must be a **new** radiumbox.com hardware order crossing HMAC ingest. That path is closed:

1. Box `DESK_BASE_URL` empty + Box secret empty → Box will not POST. **VERIFIED.**
2. Desk `CHANNEL_INGEST_SECRET_RADIUMBOX_COM` KEY_ABSENT → HMAC 401 even if posted. **VERIFIED.**
3. P1–P6 not deployed → no `hardware_fulfilments` row can be created. **VERIFIED.**
4. `channel_sku_maps` absent → P0-M1 unresolved. **VERIFIED.**
5. No commit/deploy was authorized. **OWNER-LOCKED.**

Newer Cashfree support shells exist (`RDE318400` / `RDE318401` today). They were **not** used. They are not channel commerce orders. Fabricating an ingest payload from them would invent SKU/model/shipping fields and would violate P7.

`radiumbox_prod` was not queried. Box payload shape was read from production **PHP only**.

Live Box `DeskOutboxService::payload()` does **not** send `shipping_line_kind`, `model_id`, structured shipping object, or parcel. It flattens address to a string and puts `modelid` in `sku`. Even after a future P1 deploy, that payload would not satisfy the P4 SKU-map / P1 physical-line gates without a Box contract change. **VERIFIED** from Box source; Box apply of a later payload remains **UNKNOWN**.

---

## 8. Actions not performed

- New-order HMAC POST with a valid Box secret
- Idempotent replay
- Serial allocation / invoice / shipment / AWB / callback
- Enable ingest, Cashfree hardware correlation, Shiprocket, or callback
- Deploy / push / commit
- `radiumbox_prod` access
- Seven frozen order mutation
- Code changes

---

## 9. Remaining blockers (in order)

1. Push + controlled deploy of P1–P6 with **all** writer flags still off (P0 step 1).
2. Owner P0-M1 `channel_sku_maps` rows (do not guess).
3. Matching `CHANNEL_INGEST_SECRET_RADIUMBOX_COM` on Desk **and** Box; Box `DESK_BASE_URL=https://desk.radiumbox.com`.
4. Box payload must carry `model_id` and/or `shipping_line_kind=physical_merchandise` plus structured shipping if hardware eligibility is required.
5. Then re-run P7 observe on a **new** RDE* only.

Rollback: none required. No P7 write occurred.
