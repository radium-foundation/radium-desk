# Email Intake Dashboard Counter Investigation

**Date:** 2026-08-06  
**Symptom:** Dashboard Email Intake tile stuck at **“3 Needs Attention”** since last night  
**Scope:** Root-cause investigation only — **no code changes**  
**Canvas:** none  

---

## Executive verdict

| Layer | Can freeze a count overnight? | Verdict |
|-------|-------------------------------|---------|
| `OperatorDashboardCache` / `DashboardSnapshotStore` | No | **Not involved** — case queues only |
| Email widget cache `incoming_email:dashboard_widget:{date}` | No (max **45–60s** TTL) | **Not an overnight freeze mechanism** |
| Live KPI polling (admin layout) | Only while tab is hidden / not refreshed | Possible **UI-only** freeze |
| Learning Center Needs Human tabs | No cache | Live DB each load |
| Ingest / sync / processor | Yes | **Most likely if hard refresh still shows 3** |

**Bottom line:** The tile is **not** driven by the operator dashboard snapshot cache. A count that survives a **hard page reload** means the underlying `needs_review` / `failed` backlog (or the intake pipeline feeding it) is stable at 3 — not a stale snapshot.

---

## Production verification (2026-08-06 09:40:40 IST)

Observed on production via SSH + `php artisan tinker` (`tools/config.sh` → `desk.radiumbox.com` app).

| Source | Value | How measured |
|--------|------:|--------------|
| 1. Dashboard Needs Attention | **3** | `IncomingEmailIntakeCounterService::dashboardWidget($admin)['needs_attention']` |
| 2. Learning Center Needs Human | **3** | `IncomingEmailIntakeCounterService::needsHumanCount()` (same query as tab) |
| 3. DB `needs_review`/`failed` | **3** | `SELECT COUNT(*) … WHERE status IN ('needs_review','failed')` |

```text
all_three_equal: true
inbound_email.enabled: true
gmail.enabled: true
widget_cache_seconds: 45
cached_needs_attention: 3
attention_buckets: Sales=3, Orders=0, Escalations=0
status_breakdown: needs_review=3, failed=0, ignored=177953, linked=668, historical_customer=575
```

### Conclusion from equality check

**No dashboard counter bug.** Query, widget cache, and Learning Center tab all agree. Divergence is **not** in polling/rendering/cache for this symptom — the tile correctly reflects a stable backlog of three `needs_review` rows.

### Why the backlog stays at exactly 3

Pipeline is **healthy and advancing**; new mail is **not** piling into Needs Human.

| Signal (last 24h) | Value |
|-------------------|------:|
| Messages created | **184** |
| → `ignored` | 53 |
| → `linked` | 131 |
| → `needs_review` / `failed` | **0** |
| Latest message `created_at` | 2026-08-06 09:34:06 IST |
| Latest message `received_at` | 2026-08-06 09:33:46 IST |
| `jobs` / `failed_jobs` | 0 / 0 |

Gmail sync log shows recent pulls (`pulled 15`, `6`, `5`, `2`, …) with zero failed messages. Outbox processor continues to process events. Intake is running; auto-routing/ignore is clearing new mail.

The three open items are **unchanged since 2026-08-03** (not “frozen overnight by cache” — they have been Needs Human for ~3 days):

| id | received_at (UTC) | from | subject (truncated) | classification | ignore_reason |
|----|-------------------|------|---------------------|----------------|---------------|
| 178723 | 2026-08-03 14:43 | amazon-in-andon@amazon.in | `[CASE 13118096352] Reminder: Urgent… Andon Cord` | `possible_sales_lead` | `unknown_customer` |
| 178727 | 2026-08-03 15:35 | store+23635731@t.shopifyemail.com | `Order confirmed` | `possible_sales_lead` | `unknown_customer` |
| 178731 | 2026-08-03 16:02 | aditya.sharma2307@gmail.com | `Regarding RD Service` | `possible_sales_lead` | `unknown_customer` |

All three bucket to **Sales** on the tile. They remain because nobody has applied a Learning Center action (Assign / Ignore / Move To / etc.) — not because ingest stopped. Note: a newer Shopify “Order confirmed” from the same store sender (`id` 179218, today) was **linked** as `vendor_action`, so similar later mail is being auto-handled; these three older rows were left in `needs_review`.

**Operational root cause:** Untouched Needs Human backlog of three `possible_sales_lead` / `unknown_customer` messages from 3 Aug. Dashboard “stuck at 3” is accurate.

**Recommended next step (ops, not counter fix):** Triage ids `178723`, `178727`, `178731` in Learning Center. After disposition, tile should drop without code changes.

---

## What the tile actually counts

**Service (authoritative):** `IncomingEmailIntakeCounterService::buildDashboardWidget()`  
**Formula:**

```text
Needs Attention = Sales + Orders + Priority(Escalations)
```

Those buckets are assigned over **every** row with:

```text
status IN (needs_review, failed)
```

via `IncomingEmailAttentionCategoryService::aggregateCounts()`. Every such row gets exactly one bucket (default → Sales). Therefore:

```text
Needs Attention  ==  Needs Human queue COUNT(*)
```

(same row set; different presentation).

**Ignored mail** (Promotions / Spam / Completed Automatically) is **not** in the big number — only in hover, from today’s `incoming_email_ignore_stats`.

**Docs** is a Learning Center classification label, **not** a dashboard queue or Needs Attention bucket.

---

## Answers to the ten checks

### 1. Are new emails still being ingested?

**Local (this workspace):** No.

| Signal | Value |
|--------|-------|
| `config('inbound_email.enabled')` | `false` |
| `config('inbound_email.gmail.enabled')` | `false` |
| `incoming_email_messages` rows | `0` |
| `storage/logs/inbound-email-gmail-sync.log` | missing |

**Production (must verify):** Scheduler only runs sync when **both** flags are true:

```php
// bootstrap/app.php
$schedule->command('inbound-email:sync-gmail')
    ->when(fn (): bool => config('inbound_email.enabled')
        && config('inbound_email.gmail.enabled'))
```

Known Hostinger risk: hung `inbound-email:sync-gmail` + host `flock` can **skip all** `schedule:run` work (see `docs/hostinger-scheduler-cron-wrapper.md` / `docs/production-stuck-scheduler-recovery-runbook.md`).

### 2. Is the Email Intake processor running?

Pipeline:

```text
inbound-email:sync-gmail
  → ingest + outbox email.inbound.process
outbox:process (every minute)
  → IncomingEmailProcessorService::process()
```

Processor `finally` calls `forgetDashboardWidgetCache()`. If outbox is stuck or sync never enqueues, the tile will not move even though Gmail has new mail.

**Local:** no sync log; no messages to process.

### 3. Are database counts increasing?

**Local:** `needs_human_db = 0`, messages last 24h = 0.

**Production verify:**

```sql
SELECT status, COUNT(*) FROM incoming_email_messages GROUP BY status;
SELECT COUNT(*) FROM incoming_email_messages
  WHERE status IN ('needs_review','failed');  -- should match tile if live
SELECT MAX(received_at), MAX(created_at), MAX(processed_at)
  FROM incoming_email_messages;
SELECT COUNT(*) FROM incoming_email_messages
  WHERE created_at >= NOW() - INTERVAL 12 HOUR;
```

If `needs_review+failed` is still **3** after reload → tile is **correct**.  
If DB ≫ 3 but tile shows 3 → then investigate cache/UI (unlikely with 45s TTL after reload).

### 4. Is the dashboard polling firing?

| Layout | Email Intake location | Updated by `/dashboard/live`? |
|--------|----------------------|-------------------------------|
| Admin / ops admin / superadmin | `#dashboard-kpi-strip` | **Yes** — inside `kpi_strip_html` |
| Support agent layout | Beside Ready Queue chips (`recent-service-cases`) | **No** — strip renders agent cards only |

Admin roles never use support layout (`OperationsRoleService::usesSupportQueues` returns false for admins).

**Polling pauses when `document.hidden`** (`resources/js/live-dashboard.js` — `refreshDashboard_suppressed` / `document_hidden`). An overnight background tab can show last painted “3” until focus + poll (or hard reload).

Intervals: active **20s**, idle **60s**.

### 5. Is snapshot cache returning stale data?

**No for Email Intake.**

| Cache | Key | Holds Email Intake? |
|-------|-----|---------------------|
| Operator snapshot | `operator.dashboard.snapshot:v2` | **No** |
| Request snapshot | `DashboardSnapshotStore` | **No** |
| Email widget | `incoming_email:dashboard_widget:{Y-m-d}` | **Yes** |

Prior docs (`docs/dashboard-snapshot-cache-safety-investigation.md`, `docs/dashboard-snapshot-cache-hardening.md`) explicitly exclude Email Intake from the incident snapshot.

### 6. Is cache invalidation happening after new emails?

Invalidation sites:

| Caller | When |
|--------|------|
| `IncomingEmailProcessorService::process()` `finally` | Every processed message |
| `IncomingEmailLearningActionService::applyToMessages()` | Learning Center Apply |

TTL fallback: **45s** (`INBOUND_EMAIL_DASHBOARD_WIDGET_CACHE_SECONDS`, clamped 30–60).

**Cannot explain multi-hour freeze after a full page load.**

Local check at investigation time: cache key miss (`cache_hit: false`).

### 7. Compare Dashboard / Learning Center / DB

| Source | Mechanism | Local now |
|--------|-----------|-----------|
| Dashboard tile | Cached widget → Needs Attention sum | Widget `null` (intake disabled / no permission path) |
| Learning Center Needs Human | Uncached `needsHumanCount()` / list | Would be empty |
| DB `needs_review`+`failed` | Source of truth | `0` |

On a healthy admin session after hard reload:

```text
tile.needs_attention === Learning Center Needs Human count === DB COUNT(needs_review|failed)
```

If tile=3 and Learning Center ≫ 3 after reload → bug (not observed in code path).  
If all three = 3 → **data is correct**; pipeline or triage explains “stuck”.

### 8. Queue transitions

| Queue / surface | Source | In Needs Attention? |
|-----------------|--------|---------------------|
| Needs Human | `needs_review` / `failed` | **Yes** (entire total) |
| Promotions | ignore stats / ignored rows | Hover only |
| Spam | ignore stats / ignored rows | Hover only |
| Completed Automatically | ignore stats / ignored rows | Hover only |
| Docs | classification teach label | **No** queue / not in tile |

New mail that filters to ignore increments ignore stats, **not** Needs Attention. Smart-routing create/link also removes rows from Needs Human.

### 9. Recent logs

| Log | Local finding |
|-----|---------------|
| `storage/logs/inbound-email-gmail-sync.log` | **Absent** |
| `storage/logs/laravel.log` | Mostly `testing.*` noise; Gmail retries / `incoming_email.communication_intake_unresolved` from **PHPUnit**, not live intake |
| `storage/logs/outbox-processor.log` | Not checked as conclusive locally (intake off) |

**Production:** inspect `inbound-email-gmail-sync.log`, `outbox-processor.log`, scheduler heartbeat, Gmail mailbox `history_id` lag.

### 10. Exact file / service responsible

| Responsibility | File | Method |
|----------------|------|--------|
| **Tile number** | `app/Services/IncomingEmail/IncomingEmailIntakeCounterService.php` | `dashboardWidget()` → `buildDashboardWidget()` |
| Categorization | `app/Services/IncomingEmail/IncomingEmailAttentionCategoryService.php` | `aggregateCounts()` / `categorize()` |
| Cache forget on ingest | `app/Services/IncomingEmail/IncomingEmailProcessorService.php` | `process()` finally |
| Cache forget on teach | `app/Services/IncomingEmail/IncomingEmailLearningActionService.php` | `applyToMessages()` |
| SSR / live stats | `app/Services/DashboardService.php` | `fastChangingStatsFor()` / `liveMetricsFor()` / `renderKpiStrip()` |
| Card markup | `resources/views/dashboard/partials/email-intake-kpi-card.blade.php` | — |
| Live DOM patch | `resources/js/dashboard-kpi-dom.js` | patches `.dashboard-email-intake-kpi__value` |
| Sync schedule | `bootstrap/app.php` | `inbound-email:sync-gmail` |
| Agent-only mount (no live strip) | `resources/views/dashboard/partials/recent-service-cases.blade.php` | compact agent layout |

---

## Ranked root-cause hypotheses

### A. Backlog really is still 3 (most likely if hard reload still shows 3)

**Meaning:** Pipeline is routing/ignoring/linking new mail; Needs Human queue not growing.

**Evidence to confirm:** Learning Center Needs Human = 3; DB `needs_review|failed` = 3; `created_at` / `received_at` still advancing for `ignored` / `linked`.

### B. Ingest / scheduler / outbox stalled (most likely if Gmail has mail but Desk does not)

**Meaning:** Sync not running, flock skip, flags off, or outbox not draining.

**Evidence to confirm:** No new `incoming_email_messages` rows; sync log quiet; `history_id` stale; outbox backlog for `email.inbound.process`.

### C. UI freeze only (tab left open overnight, no reload)

**Meaning:** Polling suppressed while `document.hidden`; painted “3” never refreshed.

**Evidence to confirm:** Hard reload changes the number **or** Learning Center differs from the open dashboard tab before reload.

### D. Stale widget cache / snapshot cache — **ruled out for overnight**

- Widget TTL ≤ 60s  
- Snapshot caches do not store Email Intake  

### E. Surgical live patch bug — **low**

Admin strip includes the card; patch targets the value node. Would not survive hard reload.

---

## Recommended fix (do not implement yet)

Wait for production confirmation of A vs B vs C.

1. **If A (true backlog of 3):** No counter bug. Triage the 3 Needs Human items; optionally improve hover/ops clarity that ignored mail does not raise Needs Attention.  
2. **If B (pipeline stalled):** Ops recovery — confirm `INBOUND_EMAIL_ENABLED` + `INBOUND_EMAIL_GMAIL_ENABLED`, cron uses `bin/schedule-run.sh`, clear hung sync / flock, drain outbox, check Gmail sync log.  
3. **If C (UI-only):** Ensure focused-tab / visibilitychange forces KPI refresh; optionally re-fetch Email Intake on `visibilitychange` even for agent-mounted card.  
4. **Do not** “fix” by coupling Email Intake into `OperatorDashboardCache` — wrong layer.

### Production confirmation script (read-only)

```bash
# On production host
php artisan tinker --execute="
\$c = app(App\Services\IncomingEmail\IncomingEmailIntakeCounterService::class);
\$admin = App\Models\User::role(['superadmin','admin'])->where('is_active',true)->first();
echo json_encode([
  'enabled' => config('inbound_email.enabled'),
  'gmail' => config('inbound_email.gmail.enabled'),
  'db_needs_human' => \$c->needsHumanCount(),
  'counts' => \$c->counts(),
  'widget' => \$c->dashboardWidget(\$admin),
  'latest' => App\Models\IncomingEmailMessage::orderByDesc('id')->limit(5)
      ->get(['id','status','classification','received_at','created_at']),
], JSON_PRETTY_PRINT);
"
tail -n 80 storage/logs/inbound-email-gmail-sync.log
tail -n 80 storage/logs/outbox-processor.log
```

Then open Learning Center `?queue=needs_human` and compare the tab count to the tile.

---

## Local environment snapshot (2026-08-06 ~09:37 IST)

```json
{
  "inbound_email.enabled": false,
  "inbound_email.gmail.enabled": false,
  "widget_cache_seconds": 45,
  "cache_store": "database",
  "app_env": "local",
  "db": "radium_desk_local",
  "needs_human_db": 0,
  "messages_last_24h": 0,
  "widget": null,
  "cache_hit": false
}
```

This environment **cannot** currently show “3 Needs Attention”; the reported symptom is almost certainly from **another environment (production)** and must be confirmed there with the script above.

---

## Related docs

- `docs/email-intake-dashboard-v2.md` — formula / hover  
- `docs/performance-sprint-email-intake.md` — widget cache  
- `docs/dashboard-snapshot-cache-safety-investigation.md` — snapshot ≠ email intake  
- `docs/hostinger-scheduler-cron-wrapper.md` — sync/scheduler stall risk  
- `docs/ira-learning-center-ux-redesign.md` — Learning Center queues (presentation)
