# BonVoice Global Outbox `process()` Investigation

**Status:** Read-only investigation complete — no code changes
**Date:** 2026-08-08
**Production:** `v4.0.12` / `748875c` (verified on host)
**Canvas:** [`bonvoice-global-outbox-process-investigation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/bonvoice-global-outbox-process-investigation.canvas.tsx)

**Constraints honored:** No code edits, schedule changes, worker restarts, deploys, commits, or IRA/Cashfree code touch.

---

## Verdict

**Recommend Option B: enqueue + scoped `processAggregate`.**

BonVoice must still run its own outbox row inside the HTTP request so IVR popup / call-event side effects fire before the provider disconnects. It must **not** call unbounded global `OutboxProcessorService::process()`, which drains Cashfree / Interakt / email / template rows first.

Interakt’s proven enqueue-only pattern (`a6366e5`) is the right model for WhatsApp ack latency, but is **unsafe for BonVoice IVR** (popup would wait on cron `outbox:process`, up to ~1 minute via light-tick).

`processAggregate` already exists and is the production pattern used by Interakt Flow and Incoming Email.

---

## 1. Current BonVoice flow

```
POST /api/webhooks/bonvoice
  → log request
  → insert bonvoice_webhook_logs (STATUS_RECEIVED)
  → optional auth verify (fail → 4xx, no outbox)
  → BonvoiceWebhookOutboxWriter::writeProcessingJob(log_id)
       firstOrCreate outbox_events
         event_type     = bonvoice.webhook.process
         aggregate_type = bonvoice_webhook_log
         aggregate_id   = webhook_log_id
         idempotency    = bonvoice.webhook.process.{log_id}
  → OutboxProcessorService::process()   ← GLOBAL, unbounded
       recoverStaleProcessingEvents()
       while pending:
         claimNextEvent()  // oldest pending by id, any event_type
         dispatch(...)
  → latency mark S0_request
  → HTTP 200 {status: ok}
```

Inside BonVoice dispatch (`dispatchBonvoiceWebhookProcessing`):

1. Load `BonvoiceWebhookLog`
2. Latency S2_outbox with `outbox_ahead_count` / `outbox_processed_before`
3. `BonvoiceWebhookProcessorService::process`:
   - upsert `bonvoice_call_events`
   - mark webhook log processed
   - live assist notify / popup broadcast (`ShouldBroadcastNow` path)
   - answered auto-open / missed dismiss broadcasts
   - outbound click-to-call live status
   - missed-call recovery

**HTTP semantics:** controller always returns 200 after `process()` unless persist/auth/outer throw. Event failures are swallowed inside `processClaimedEvent` (retry/backoff). Sync processing is for **side effects**, not for returning 422.

**Cron:** `schedule:light-tick` → `outbox:process --limit=N` is the safety net. IVR runbook still documents webhook sync drain as the primary path.

---

## 2. Interakt proven flow

### Main webhook (`a6366e5` — shipped, on prod)

```
POST /api/webhooks/interakt
  → persist interakt_webhook_logs
  → optional signature verify
  → writeProcessingJob(log_id)   // enqueue only
  → HTTP 200
  // NO OutboxProcessorService call
```

Cron / light-tick drains `interakt.webhook.process` later.

Tests: `InteraktWebhookEnqueueOnlyPerformanceTest` proves:

- ack stays lean (&lt;100ms / &lt;25 queries in test)
- unrelated Cashfree pending row stays `Pending`
- Interakt outbox row stays `Pending` after HTTP ack

Functional tests use `postInteraktWebhookAndDrain()` / `drainOutbox()` to simulate cron.

### Sibling patterns already using `processAggregate`

| Caller | Pattern | Why sync scoped |
|--------|---------|-----------------|
| `InteraktFlowWebhookController` | write + `processAggregate(flow, id)` | Needs in-request fail → HTTP 422 |
| `IncomingEmailIngestService` | write + optional `processAggregate` | Immediate intake when flagged |
| `WhatsAppTemplateDispatcher` | `processAggregate` (Phase 12.1) | Avoid global FIFO stealing the dispatch |

---

## 3. Exact difference

| Dimension | BonVoice (prod today) | Interakt main (prod today) | Interakt Flow / Email |
|-----------|----------------------|----------------------------|------------------------|
| Write outbox | Yes | Yes | Yes |
| In-request process | **`process()` global unbounded** | **None** | **`processAggregate` only** |
| Unrelated FIFO drain | **Yes** | No | No |
| HTTP depends on process success | No (always 200) | No | Flow: yes (422 on fail) |
| Product latency need | IVR popup / live assist **seconds** | WhatsApp can wait for cron | Flow validation / intake |
| Tests assume sync processed | Yes (`STATUS_PROCESSED` after POST) | No (explicit drain helper) | Scoped |

Outbox writers are structurally identical (`AGGREGATE_TYPE` + `webhook_log_id` payload). BonVoice already has everything needed for Option B.

---

## 4. Production CPU / resource impact

### Deploy / code fact

| Fact | Value |
|------|-------|
| Prod HEAD | `748875c` `v4.0.12` |
| BonVoice controller | still `writeProcessingJob` + `process()` |
| Interakt controller | enqueue-only (no processor injection) |

### Outbox inventory (prod snapshot 2026-08-08)

| event_type | completed | failed | pending |
|------------|----------:|-------:|--------:|
| `bonvoice.webhook.process` | 19,332 | 41 | ~0–1 |
| `cashfree.webhook.deferred_operation` | 73,312 | 2,742 | ~0–1 (+ occasional processing) |
| `interakt.webhook.process` | 54,305 | 0 | accumulates until light-tick / foreign drain |
| `interakt.template.send` | 19,895 | 0 | — |
| `email.inbound.process` | 179,640 | 0 | — |

Failed rows do **not** block `claimNextEvent` (only `pending` + `available_at`). Pending Cashfree/Interakt **do**.

### BonVoice received→processed lag (24h, n=2209)

| Metric | Seconds |
|--------|--------:|
| p50 | 0 |
| p95 | 7 |
| max | 2597 (failed missing-callID retries — not healthy path) |
| lag &gt; 0s | 372 |
| lag &gt; 5s | 155 |
| lag &gt; 30s | 8 |
| received last 1h | 221 |

### Proven unrelated drain (sample log `#19771`)

- Webhook received `13:00:29`, log processed `13:00:35` (**6s**)
- BonVoice outbox `#349295` completed `13:00:37`
- In the same window, Cashfree deferred `#349291`–`#349294` completed
- Outbox id order proves FIFO: older Cashfree rows claimed before BonVoice `#349295`

Earlier same minute: BonVoice `#19770` / outbox `#349288` took **8s** while Cashfree `#349289+` were interleaved.

**CPU implication:** each BonVoice POST can become a free global outbox worker on lsphp, paying Cashfree deferred + any Interakt backlog that enqueue-only left pending. Remeasure already ranked BonVoice sync `process()` as a secondary consumer (~0.5% in that window); after Interakt enqueue-only, BonVoice is a more attractive accidental drain path whenever pending Interakt rows exist.

Latency instrumentation (`BONVOICE_INCOMING_LATENCY_LOG=true`) is enabled, but `[BonVoice Incoming Latency]` lines were not present in the current `laravel.log` tail (log appears stale/rotated relative to midday traffic). Lag evidence above uses DB timestamps instead.

---

## 5. Functional / latency risks

### Answers to the 10 questions

1. **Sync during HTTP?** Persist + auth + write outbox + **global process loop** + side effects (call event, broadcasts, recovery).
2. **Require immediate processing before 200?** **Yes for BonVoice’s own event** (IVR popup / live assist / C360 auto-open). **No for unrelated outbox types.** HTTP status does not require process success.
3. **Outbox ops created?** Exactly one per webhook log: `bonvoice.webhook.process` / aggregate `bonvoice_webhook_log` / id = log id. Processor may create call alerts / recovery side effects, but not extra outbox rows for the webhook itself.
4. **Safe to enqueue without global drain?** **Enqueue alone is unsafe for IVR SLA.** Scoped process of that aggregate is safe and avoids unrelated drain.
5. **`processAggregate` fit?** **Yes** — same signature/shape as Flow/Email; writer already sets `aggregate_type` + `aggregate_id`.
6. **Latency lost by enqueue-only?** Popup/broadcast delayed until next `outbox:process` (light-tick every minute, limit 50). Ringing calls can finish before agents see the card. Click-to-call live status and missed-call recovery also delayed.
7. **IVR popup / customer capture regress?**
   - Option A: **yes, likely**
   - Option B: **no** for BonVoice work; **improves** when FIFO backlog exists
   - Option C: keeps current FIFO regression
8. **Retry/recovery depend on global `process()`?**
   - Outbox retries: any future `process()` / `processAggregate` / cron drain — **not** specifically global webhook drain
   - `bonvoice:reprocess-failed`: calls processor **directly**, bypasses outbox — **independent**
9. **Does global process drain unrelated work?** **Yes — proven in prod** (Cashfree deferred completed inside BonVoice webhook windows).
10. **Compare Interakt, don’t invent?** Closest safe twin is **Interakt Flow / Incoming Email `processAggregate`**, not Interakt main enqueue-only. Reuse that API + Interakt enqueue-only test ideas (assert unrelated Cashfree stays pending).

### Option comparison

| Option | CPU / lsphp | IVR popup | Unrelated drain | Test churn |
|--------|-------------|-----------|-----------------|------------|
| **A** Enqueue-only | Best HTTP ack | **High risk** (cron delay) | Eliminated | High (all sync `STATUS_PROCESSED` asserts) |
| **B** `processAggregate` | Good (only own event) | **Preserved / improved** | Eliminated | Medium (add drain-unrelated assertion; keep sync processed) |
| **C** Keep global `process()` | Worst under backlog | Degraded under backlog | Continues | None |

---

## 6. Recommended minimal fix

**Option B** — mirror Interakt Flow / Incoming Email:

```php
$this->outboxWriter->writeProcessingJob($webhookLog->id);
$this->outboxProcessorService->processAggregate(
    BonvoiceWebhookOutboxWriter::AGGREGATE_TYPE,
    $webhookLog->id,
);
```

Do **not** copy Interakt main enqueue-only for BonVoice.

Keep:

- Latency S0/S1 marks in controller
- Cron `outbox:process` as retry/safety net
- `bonvoice:reprocess-failed` unchanged
- No schedule changes, no IRA/Cashfree edits

Optional follow-up (out of scope): if enqueue-only Interakt backlog grows large, light-tick already drains it; Option B stops BonVoice from being the accidental drain.

---

## 7. Files that would change (future implementation only)

| File | Change |
|------|--------|
| `app/Http/Controllers/Webhooks/BonvoiceWebhookController.php` | `process()` → `processAggregate(AGGREGATE_TYPE, id)` |
| `tests/Feature/BonvoiceWebhook*Test.php` (and live-assist / missed-call / hybrid tests that POST webhook) | Keep sync processed asserts; add “unrelated pending Cashfree not drained” test modeled on `InteraktWebhookEnqueueOnlyPerformanceTest` |
| `docs/ivr-incoming-popup-latency.md` | Update pipeline note: scoped aggregate, not global drain |
| `docs/production-stabilization-backlog.md` P1-11 | Mark addressed by scoped process (not full priority queue) |

No migrations. No scheduler edits. No OutboxProcessorService API changes required.

---

## 8. Test plan

1. **Feature parity:** existing `BonvoiceWebhookTest` / live-assist / missed-call / hybrid phase-3 — still expect `STATUS_PROCESSED` and call-event side effects after POST (Option B preserves this).
2. **New regression (Interakt twin):** seed pending Cashfree outbox row with lower id → POST BonVoice → assert Cashfree still `Pending`, BonVoice outbox `Completed`, webhook `processed`, ack 200, query/query budget reasonable.
3. **Auth failure path:** invalid auth still fails before outbox write (unchanged).
4. **Idempotency:** duplicate `writeProcessingJob` still firstOrCreate.
5. **Failure retry:** force processor throw → outbox attempts/backoff; cron/`processAggregate` can retry — webhook HTTP still 200.
6. **Manual prod soak after deploy:** watch `received_at→processed_at` p95 for BonVoice; confirm Cashfree deferred no longer completes inside BonVoice windows; sample IVR RINGING → popup.

---

## 9. Rollback plan

1. Revert controller line to `$this->outboxProcessorService->process();`
2. Redeploy — no migrations / config flags required
3. Cron outbox path unchanged throughout

Soft observation: keep `BONVOICE_INCOMING_LATENCY_LOG=true` and watch S2 `outbox_ahead_count` / `outbox_processed_before` (should stay 0 for the claimed BonVoice event under Option B).

---

## Why global `process()` was retained

1. **Original pattern copy** — BonVoice/Interakt controllers inherited Cashfree-style “write outbox then `process()`” from Phase 8.2.
2. **IVR product need** — `docs/ivr-incoming-popup-latency.md` explicitly keeps sync drain so popup broadcast happens in-request; cron is safety net only.
3. **Tests encode sync completion** — feature tests assert `STATUS_PROCESSED` immediately after POST without a drain helper.
4. **Interakt was fixed first** (`a6366e5`) because it was the larger CPU burst; BonVoice was listed as follow-up in remeasure (“stop Bonvoice global `process()` similarly”) but never implemented — and “similarly” should mean **stop global drain**, not blindly enqueue-only.

---

## STOP

Investigation complete. No implementation performed.
