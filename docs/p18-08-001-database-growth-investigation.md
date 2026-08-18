# P18-08-001 — Production database growth investigation

Canvas: [`/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p18-08-001-database-growth.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p18-08-001-database-growth.canvas.tsx)

**Inspection:** 2026-08-18 04:55–10:31 UTC / 10:25–16:01 IST  
**Target:** Hostinger KVM `187.127.129.16` (`srv1910783`), MariaDB **11.8.8**, schema `radium_desk`  
**Mode:** Read-only. Nothing was deleted, truncated, archived, optimized, migrated, or reconfigured. AIC was not modified.

## Verdict

**This is an abnormal retention/storage problem mixed with a real unbounded log stream. It is not 5 GB of one month of core business data, and it is not a realistic crash risk on this 200 GB KVM in the next 12 months.**

| Question | Answer |
|---|---|
| Schema size | **5.02 GB** data+index (`information_schema`); **5.2 GB** `radium_desk/` ibd files; **5.8 GB** datadir |
| Core business (orders, incidents, remarks, finance) | **~110 MB (2.2%)** |
| Logs / history / copies | **~95%** |
| Ongoing if unbounded | **~78 MB/day · 2.3 GB/month** |
| 12-month unbounded | **~31–33 GB** |
| 12-month disk-crash risk | **LOW** (183 GB free) |
| Recommended max schema | **20 GB** (alert 8 GB / 12 GB) |

Go-live of desk data is **2026-06-25** (~55 days). Gmail ingest started **2026-07-19** (~30 days). The “one month / 5 GB” observation matches calendar time, not organic row content.

---

## 1. Database size

| Location | Size |
|---|---|
| `information_schema` `radium_desk` | 4,144 MB data + 872 MB index = **5,016 MB** · 93 tables · ~2.88M estimated rows |
| `/var/lib/mysql/radium_desk` | **5.2 GB** |
| `/var/lib/mysql` | **5.8 GB** (includes 512 MB `ib_logfile0`) |
| KVM `/` | **193 GB** ext4 · **9.7 GB used (6%)** · **183 GB free** · inodes 1% |
| App tree `/var/www/radium-desk` | **189 MB** |

MariaDB: `innodb_file_per_table=ON`, `innodb_buffer_pool_size=4G`, `log_bin=OFF`, `binlog_expire_logs_seconds=864000` (unused while binlog is off).

Exact `COUNT(*)` on large tables is higher than InnoDB `table_rows` estimates (import is hours old; stats are stale). Rankings use allocated size, which is what fills disk.

---

## 2. Top 20 largest tables

Sizes from `information_schema.tables` (MB). Row counts marked * are `COUNT(*)`.

| # | Table | Rows | Data MB | Index MB | Total MB | % | Avg row (schema) |
|---|---|---:|---:|---:|---:|---:|---:|
| 1 | `incoming_email_messages` | 402,002* | 2,216 | 242 | **2,458** | 49.0% | 6.3 KB |
| 2 | `audit_logs` | 1,511,395* | 574 | 379 | **953** | 19.0% | 0.65 KB |
| 3 | `interakt_webhook_logs` | 71,867* | 625 | 7 | **632** | 12.6% | 9.0 KB |
| 4 | `cashfree_webhook_logs` | 49,487* | 207 | 8 | **215** | 4.3% | 4.4 KB |
| 5 | `outbox_events` | 606,719* | 117 | 91 | **208** | 4.1% | 0.35 KB |
| 6 | `interakt_messages` | 26,071* | 137 | 10 | **147** | 2.9% | 5.8 KB |
| 7 | `cache` | 5,650* | 71 | 2 | **72** | 1.4% | 13 KB |
| 8 | `notifications` | 131,181* | 42 | 10 | **51** | 1.0% | 0.40 KB |
| 9 | `orders` | 40,255* | 12 | 38 | **50** | 1.0% | 1.3 KB |
| 10 | `bonvoice_webhook_logs` | 26,751* | 46 | 4 | **49** | 1.0% | 1.9 KB |
| 11 | `bonvoice_call_events` | 26,258* | 23 | 16 | **39** | 0.8% | 1.5 KB |
| 12 | `incidents` | 41,417* | 11 | 26 | **36** | 0.7% | 0.9 KB |
| 13 | `ira_notifications` | 38,664* | 25 | 6 | **31** | 0.6% | 0.8 KB |
| 14 | `whatsapp_template_dispatches` | 27,251* | 16 | 8 | **24** | 0.5% | 0.9 KB |
| 15 | `remarks` | ~28,875 | 6 | 8 | **14** | 0.3% | 0.5 KB |
| 16 | `incident_bonvoice_call_links` | ~13,020 | 3 | 5 | **7** | 0.1% | 0.6 KB |
| 17 | `finance_journal_lines` | ~27,525 | 3 | 3 | **6** | 0.1% | 0.2 KB |
| 18 | `finance_journals` | ~13,689 | 3 | 2 | **4** | 0.1% | 0.3 KB |
| 19 | `bonvoice_call_alerts` | ~7,749 | 2 | 3 | **4** | 0.1% | 0.6 KB |
| 20 | `automation_executions` | ~3,020 | 3 | 1 | **3** | 0.1% | 1.1 KB |

Top 6 = **4,613 MB (92%)**. On-disk `.ibd` files match this ranking (`incoming_email_messages.ibd` 2.5 GB, `audit_logs.ibd` 972 MB, `interakt_webhook_logs.ibd` 648 MB).

---

## 3. What is inside the large tables

### `incoming_email_messages` (2.46 GB) — historical Gmail, not bodies

| Fact | Value |
|---|---|
| Mailbox | `mail@radiumbox.com` 401,983 · `support@radiumbox.com` 1 |
| `received_at` | 2017-12-22 → 2026-08-17 |
| Rows before desk go-live (2026-06-25) | **396,741** |
| Rows since go-live | **5,255** |
| Status | ignored **388,285** · linked 12,955 · historical_customer 578 · needs_review 183 |
| Ignore reasons | promotions 198,648 · unknown_customer 162,196 · own_outbound 10,011 · known_system_email 6,982 |
| Full HTML/text bodies | **No** — avg `raw_payload` 344 chars; `body_html` key present on 56 rows |
| Attachments as blobs | **No** — `attachment_count` metadata only |
| Headers | JSON of **all RFC headers**, avg **3,906 chars**, max 16,113 · **1,568 MB** of header+payload text on ignored rows |

Code: `IncomingEmailIngestService` persists `headers` + `raw_payload`; `GmailMessageMapper` copies every Gmail payload header. Tests (`IncomingEmailStorageOptimizationTest`) already assert bodies are omitted. The size is **header JSON for nine years of a marketing-heavy inbox**, ingested into desk over ~30 days (created_at 2026-07-19 → 2026-08-18; 221k rows created 2026-08-10–13).

Each ingest also writes `audit_logs` (`incoming_email.received` and/or `.ignored`) and `outbox_events` (`email.inbound.process`). That triples storage for mail that desk then ignores.

### `audit_logs` (0.95 GB) — verbose event history, index-heavy

- 1,511,395 rows, 2026-06-25 → now.
- `old_values` avg 7 chars (mostly empty); `new_values` avg 225 chars (**325 MB** total).
- `user_agent` **27 MB** across the table.
- Indexes **379 MB (40%)**. Overlapping indexes observed: `event` vs `event+created_at`; `auditable_type+id` (from `morphs()`) vs `auditable_type+id+created_at`.
- Top events: `incoming_email.received` 391,972 + `incoming_email.ignored` 388,272 = **780k rows (52%)** from email ingest alone. Remaining ~730k are real desk events (enrichment, status, assignment, WhatsApp, remarks) at **~29k/weekday**.

No prune/archive job. `AuditLogService::log()` is called from many services; Laravel `model:prune` is not used.

### Webhook tables — payload + raw_body duplication

| Table | Rows | payload MB | raw_body MB | headers MB | payload≈raw? |
|---|---:|---:|---:|---:|---|
| `interakt_webhook_logs` | 71,867 | 235.6 | 234.9 | 39.4 | avg 3437 vs 3427 chars; 0 exact equals |
| `cashfree_webhook_logs` | 49,487 | 61.0 | 61.2 | 44.8 | 8 exact equals |
| `bonvoice_webhook_logs` | 26,751 | 13.6 | 14.3 | 9.8 | near-duplicate |

Controllers (`InteraktWebhookController`, `CashfreeWebhookController`, `BonvoiceWebhookController`) store parsed JSON **and** `$request->getContent()`.

Interakt event mix: `message_api_sent` 25,971 · `delivered` 24,671 · `read` 20,144 · `failed` 1,058. That is **3–4 full webhook copies per outbound WhatsApp**. `interakt_messages` (all `outgoing`, 26,071) stores another **87 MB** `payload` (avg 3,488 chars — same shape as the webhook).

### `outbox_events` (0.21 GB) — completed rows never deleted

| event_type | completed | failed |
|---|---:|---:|
| `email.inbound.process` | 391,967 | 0 |
| `cashfree.webhook.deferred_operation` | 87,185 | 2,742 |
| `interakt.webhook.process` | 71,867 | 0 |
| `interakt.template.send` | 27,251 | 0 |
| `bonvoice.webhook.process` | 25,686 | 41 |

`OutboxProcessorService` claims, retries, and recovers stale `processing` rows. It does **not** delete `completed`.

### Other growers

| Table | Finding |
|---|---|
| `cache` | 5,650 keys · **3,785 expired (67%)** still on disk · 59 MB values · Laravel database driver, no prune scheduled |
| `notifications` | 131,181 · **130,823 unread (99.7%)** · never pruned |
| `ira_notifications` | 38,664 · **all unread** · Ira memory snapshots are the only scheduled prune (`retention_days` 90) |
| `orders` / `incidents` | Real business; **23 / 16 indexes**; orders is **77% index bytes** (38 MB) — worth a later EXPLAIN pass, not the 5 GB |

---

## 4. Growth rate

Desk data min timestamp: orders/incidents **2026-06-25**. Gmail ingest **2026-07-19**.

### Monthly row counts (selected)

| Month | audit_logs | cashfree WH | interakt WH | bonvoice WH | outbox | notifications | emails created |
|---|---:|---:|---:|---:|---:|---:|---:|
| 2026-06 | 9,251 | 1,698 | — | — | — | 9,508 | — |
| 2026-07 | 544,672 | 22,694 | 31,611 | 11,812 | 207,923 | 61,180 | 99,336 |
| 2026-08 (to 18th) | 957,484 | 25,095 | 40,256 | 14,940 | 398,805 | 60,484 | 302,648 |

August email **created_at** is the historical backfill, not new mail. Emails with `received_at` in 2026-08: **2,572** (1,201 linked).

### Steady weekday rate (2026-08-04–08, before 10–13 backfill)

| Stream | Rows/day | MB/day | MB/month |
|---|---:|---:|---:|
| `interakt_webhook_logs` | 3,614 | 31.7 | 951 |
| `audit_logs` (non-email) | 29,146 | 18.4 | 552 |
| `cashfree_webhook_logs` | 2,215 | 9.6 | 288 |
| `interakt_messages` | 1,312 | 7.4 | 222 |
| `outbox_events` | 9,947 | 3.4 | 102 |
| `bonvoice_webhook_logs` | 1,420 | 2.6 | 78 |
| `notifications` | 4,061 | 1.6 | 48 |
| `orders` | 1,320 | 1.6 | 48 |
| New email (`received_at`) | 219 | 1.3 | 40 |
| **Total (approx.)** | | **~78** | **~2,340** |

2026-08-10–13: Gmail backfill 29k / 74k / 75k / 43k emails created per day (and matching audit/outbox spikes). 2026-08-15–16: freeze window (audit 769 then 0) while Cashfree/Bonvoice/orders still arrived.

---

## 5. Classification (unbounded tables)

| Table | Class | Notes |
|---|---|---|
| `orders`, `incidents`, `remarks`, finance_*, `users`, settings, `incident_*` links | **REQUIRED BUSINESS DATA** | Keep forever |
| `incoming_email_messages` status=linked / needs_review | **REQUIRED BUSINESS DATA** | Keep; ~13.5k rows, ~60 MB text |
| `interakt_messages`, `whatsapp_template_dispatches`, `bonvoice_call_events` | **REQUIRED BUT ARCHIVABLE** | Conversation/call history; 12–24 months then archive |
| `audit_logs` (non-email business events) | **REQUIRED BUT ARCHIVABLE** | 12 months online is enough for investigations |
| `incoming_email_messages` ignored (promotions, unknown_customer, newsletters, …) | **TEMPORARY/RETENTION-BASED** | 388k rows, 1.57 GB text; not needed in live MariaDB |
| `outbox_events` completed | **TEMPORARY/RETENTION-BASED** | Idempotency already consumed |
| `cache`, `sessions` | **TEMPORARY/RETENTION-BASED** | 67% of cache already expired |
| `notifications`, `ira_notifications` | **TEMPORARY/RETENTION-BASED** | 99%+ unread; product may want a short in-app window |
| `cashfree_webhook_logs`, `interakt_webhook_logs`, `bonvoice_webhook_logs` | **LOG/DEBUG DATA** | Needed for replay/debug 30–90 days, not forever |
| `automation_executions` | **LOG/DEBUG DATA** | Small today |
| Dual `payload` + `raw_body`; two audit events per ignored mail; outbox row per ignored mail | **POSSIBLE DUPLICATION/BUG** | Intentional for debugging, unbounded in production |

Indefinite growers if nothing changes: all LOG/DEBUG + audit + outbox + notifications + new Gmail + Interakt messages.

---

## 6. Insert paths and existing retention

| Writer | Schedule / trigger | Retention today |
|---|---|---|
| `inbound-email:sync-gmail` → `IncomingEmailIngestService` | every 2 minutes (`bootstrap/app.php`) | **None**. Unique on `provider_message_id` / `rfc_message_id` only |
| `AuditLogService::log` | synchronous from ingest, webhooks, automation, remarks, assignment, … | **None** |
| `InteraktWebhookController` / Flow / Cashfree / Bonvoice | HTTP webhooks → log row + outbox | **None** |
| `*OutboxWriter` + `OutboxProcessorService` | `schedule:light-tick` every minute | Recover stale processing; **no delete** |
| Interakt outbound processor | outbox `interakt.template.send` | Writes `interakt_messages`; **no prune** |
| Laravel `cache` / `notifications` | app + `platform:snapshots:warm` | **No** `cache:prune` / `model:prune` |
| `ira:capture-memory-snapshot` | daily 00:05 | **Yes** — `IraMemoryService::pruneOldSnapshots`, 90 days (`config/ira.php`) |

This KVM currently has `ravi` cron `schedule-run.sh` + `queue-worker.sh`, and `artisan inbound-email:sync-gmail` was running during inspection (`mail@` `last_synced_at` 2026-08-18 10:30). That is noted only as write activity on the copy. **Nothing was stopped.**

---

## 7. Storage and MariaDB (no config changes)

| Item | Value |
|---|---|
| Disk | 200 GB NVMe · `/dev/sda1` 193 GB ext4 |
| Used / free | 9.7 GB / 183 GB (6%) |
| RAM | 15 Gi · ~5.0 Gi used (buffer pool 4G accounts for most) · 2 Gi swap unused |
| `innodb_log_file_size` | 512 MB (matches `ib_logfile0`) |
| Binary logs | **OFF** — not a growth factor |
| Buffer pool vs schema | 4 GB pool < 5 GB schema; hot set (orders/incidents) still tiny |

---

## 8. Risks

| Risk | 12-month rating | Detail |
|---|---|---|
| Disk exhaustion / MariaDB crash | **LOW** | 183 GB free. Unbounded ~33 GB still well under 20% of disk. |
| Query performance | **MEDIUM** | `audit_logs` → ~11M rows/year at 29k/day. Point lookups stay indexed; scans and webhook explorer on JSON will slow. |
| Backup size / time | **MEDIUM** | 5 GB dump is easy. 30 GB dump + restore during a future rebuild is the real operational pain. |
| Restore difficulty | **LOW now / MEDIUM later** | Same as backup size. |
| Index growth | **MEDIUM** | Audit 40% indexes; orders 77% but only 38 MB. Overlapping audit indexes waste ~100 MB, not gigabytes. |
| App UX queries | **MEDIUM** | Ready Queue / C360 do not need 388k ignored 2018 promotions. They will pay for it if those rows stay in InnoDB. |
| Realistic crash this year | **No** | Do not panic-delete. |

---

## 9. Retention strategy (not implemented)

### Keep forever

orders, incidents, remarks, finance journals/lines/accounts, users/roles/permissions, settings, linked/needs_review emails, incident↔email and incident↔call links, reference sequences.

### 12 months online, then archive

business `audit_logs` (not promo-email noise), `interakt_messages`, `whatsapp_template_dispatches`, `bonvoice_call_events` / alerts.

### 90 days then delete (after optional file archive)

`cashfree_webhook_logs`, `interakt_webhook_logs`, `bonvoice_webhook_logs`, ignored `incoming_email_messages` (promotions, unknown_customer, newsletter, social, spam, trash), `notifications`, `ira_notifications`.

### 14 days then delete

`outbox_events` where `status=completed`. Keep `failed` until triaged.

### Hours–days

Expired `cache` keys (already 3,785). Old `sessions` if they accumulate.

### Stop writing (highest leverage for *future* growth)

1. Do not store `raw_body` when `payload` / `request_payload` is already JSON (saves ~50% of webhook tables going forward; Interakt ~16 MB/day).
2. Do not write `incoming_email.ignored` / `incoming_email.received` audit (+ outbox) for promotions/unknown_customer.
3. Do not rebaseline Gmail `mail@radiumbox.com`.
4. Optionally store Interakt **sent** payload only; keep delivered/read as status timestamps on `interakt_messages`.

### Recommended maximum schema size

- **Soft ceiling: 20 GB** (fits this plan; backups stay practical).
- **Alert: 8 GB** (investigate), **12 GB** (retention overdue), **25 GB** (must act before dump/restore pain).
- **Disk alerts:** 50% / 70% / 85% of 193 GB (96 / 135 / 164 GB) — currently 6%.

### Monthly storage cost / risk

Hostinger KVM 4 200 GB is included. **Incremental MariaDB storage cost is $0/month** until a larger disk SKU is required. The cost is **backup time, restore time, and InnoDB working-set pressure**, not the invoice.

### Safest implementation approach (later)

1. Logical dump of `radium_desk` on this KVM (not AIC).
2. Dry-run `SELECT` counts only; no `DELETE`.
3. Lowest-risk first: expired `cache`, then `outbox_events` completed older than 14 days, in chunks of 1,000.
4. Code change: stop dual `raw_body` on **new** webhook writes.
5. Export ignored email to compressed JSON/files, then chunked `DELETE` by `id` range. Never `TRUNCATE` or `OPTIMIZE TABLE` as a cleanup.
6. 90-day webhook cap.
7. Do not run this on AIC first. Do not change AIC, DNS, or application config as part of cleanup.

---

## 10. Is 5 GB in one month normal?

**No.**

| Bucket | Size | Normal? |
|---|---|---|
| Nine years of `mail@` headers, 96.6% ignored | **2.46 GB** | One-time backfill. Will not repeat unless Gmail is rebaselined. |
| Unbounded webhooks + audit + outbox + notifications in ~7 weeks | **~2.4 GB** | **Abnormal retention**, but the *rate* (~2.3 GB/month) is real if policy stays “keep everything”. |
| Orders + incidents + remarks + finance | **~110 MB** | Normal business growth. |

**Root causes (ranked):**

1. Gmail historical ingest of `mail@radiumbox.com` (headers JSON, ignored mail, plus audit+outbox fan-out).
2. Interakt webhook JSON stored 3–4 times per message, each time as payload **and** raw_body, plus `interakt_messages.payload`.
3. Audit log of every automation/email event with no TTL; overlapping indexes.
4. Completed outbox rows kept forever.
5. Cashfree/Bonvoice payload+raw_body copies; unread notifications; expired cache.

---

## Immediate vs later

### Immediate (operations only — not done here)

- Treat 5 GB as **explained**, not as an emergency delete.
- Do **not** rebaseline Gmail `mail@`.
- Monitor schema size weekly; alert at **8 GB** and **12 GB**.
- Do not `DELETE` / `TRUNCATE` / `OPTIMIZE` / `ALTER` without a dump.
- Leave AIC untouched.

### Later (code + jobs)

- ~~Retention commands with `--dry-run`.~~ **Phase 1 done (code only; not scheduled, not deployed).**
- Stop `raw_body` duplication on new webhooks.
- ~~Skip audit/outbox for ignored promo mail.~~ **Phase 1 done for high-confidence Gmail labels only.**
- Prune completed outbox, expired cache, old notifications. **Inspect only in Phase 1; no DELETE yet.**
- Archive ignored email off MariaDB.
- Index review (audit/orders) only after `EXPLAIN` on live queries.

---

## 11. Phase 1 implementation (2026-08-18 — code only)

**Baseline:** commit `0563d8dc3bfdb5aae6f9178f14e17a2a973ec8c6` on `feature/lcds-phase-1-dry-run`.  
**Scope:** retention foundation + Gmail label early skip. **Not deployed.** **No production data deleted.** **Gmail history/cursor not reset.**

### Retention configuration (`config/retention.php`)

| Policy key | Retention | Notes |
|---|---|---|
| `completed_outbox_days` | **14 days** | Completed `outbox_events` older than cutoff |
| `expired_cache_immediate` | **immediate** | `cache.expiration < now` |
| `webhook_logs_days` | **90 days** | Cashfree, Interakt, Bonvoice webhook log tables |
| `notifications_days` | **90 days** | `notifications` + `ira_notifications` |
| `business_audit_days` | **365 days** | `audit_logs` excluding `incoming_email.%` events |
| `ignored_email_days` | **90 days** | `incoming_email_messages` with `status = ignored` |

Env overrides: `RETENTION_COMPLETED_OUTBOX_DAYS`, `RETENTION_WEBHOOK_LOGS_DAYS`, `RETENTION_NOTIFICATIONS_DAYS`, `RETENTION_BUSINESS_AUDIT_DAYS`, `RETENTION_IGNORED_EMAIL_DAYS`, `RETENTION_EXPIRED_CACHE_IMMEDIATE`.

### Dry-run inspection command

```bash
php artisan database:retention-inspect --dry-run
```

- **Read-only:** zero database writes; `--dry-run` is always enforced.
- Pattern: `RetentionInspectCommand` → `RetentionInspectionService` → `RetentionInspectionSummary` / `RetentionCategorySummary` DTOs.
- Reports candidate counts and table totals per category.
- Count queries only (no chunk deletes yet). Future prune commands should use `chunkById()`, `withoutOverlapping()`, and explicit policies from `config/retention.php`.
- **Not registered in the scheduler.**

### Gmail irrelevant-message prevention

**Problem (before):** SPAM / TRASH / PROMOTIONS / SOCIAL messages were persisted in `incoming_email_messages`, then received `incoming_email.received` audit + outbox, then filtered in `IncomingEmailProcessorService` → `incoming_email.ignored` audit.

**Change (after):** `IncomingEmailIngestService` calls `IncomingEmailFilterService::ignoredLabelReason()` **after dedup, before insert**. When a configured Gmail label matches (`config/inbound_email.ignored_labels`), ingest:

1. Increments `incoming_email_ignore_stats` (dashboard Spam/Promotional counters unchanged).
2. Returns `null` — **no row**, **no `incoming_email.received` audit**, **no outbox event**.

**Unchanged:**

- INBOX / legitimate customer mail: full pipeline (ingest → audit → outbox → processor → link/ignore/review).
- Header-based ignores (bounce, auto-responder, system sender, unknown_customer): still ingested and processed in the processor.
- Gmail history cursor: still advanced in `GmailInboundEmailProvider::pullIncremental()` **after** the ingest batch callback; skipping ingest does **not** block cursor advancement.
- No Gmail rebaseline, no history reset, no DNS/Cloudflare/MariaDB/LCDS/.env changes.

**Operational visibility trade-off:** label-skipped mail no longer appears in the admin Spam/Promotions queue (those views query `incoming_email_messages`). Counts come from `incoming_email_ignore_stats` instead.

### Intentionally NOT done in Phase 1

- No DELETE / TRUNCATE / archive jobs.
- No scheduler registration for pruning.
- No webhook `raw_body` deduplication.
- No changes to AIC, production `.env`, MariaDB, LCDS, DNS, or Cloudflare.
- No rebaseline of `mail@radiumbox.com` Gmail history.

### Safety constraints preserved

- LCDS migration/cutover work on branch untouched.
- All existing business-email matching behavior for legitimate messages preserved.
- High-confidence label skip only — unknown_customer and header heuristics not moved to ingest.

---

## Stop

Investigation: read-only on production (2026-08-18). Phase 1 code landed locally only — no production data deleted, no deployment, Gmail cursor not reset.
