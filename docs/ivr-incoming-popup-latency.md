# IVR Incoming Popup Latency Runbook

Observe-only instrumentation for the Bonvoice → floating incoming-call card path. No business behavior changes. Optimize only after production stage samples identify the bottleneck.

## Pipeline (actual)

```
Bonvoice IVR
  → POST /api/webhooks/bonvoice
  → BonvoiceWebhookLog persist
  → OutboxEvent (sync drain in the HTTP request — not a Laravel queue job)
  → BonvoiceWebhookProcessorService
  → BonvoiceLiveCallAssistService::maybeNotify
  → IncomingCallReceived (ShouldBroadcastNow → Reverb)
  → Echo private notifications.{userId}
  → incoming-call-card.js floating card
```

Cron `outbox:process` is a safety net only; the webhook request drains the outbox synchronously.

## Stages

| Stage | Name | Where |
|---|---|---|
| S0 | `S0_request` | End of webhook HTTP request (`total_ms` from receive) |
| S1 | `S1_persist` | Persist `BonvoiceWebhookLog` |
| S2 | `S2_outbox` | Bonvoice outbox event claimed; includes `outbox_ahead_count`, `outbox_processed_before` |
| S3 | `S3_process` | Previous status + call event upsert |
| S4 | `S4_resolve` | Agent + customer resolve |
| S5 | `S5_broadcast` | Immediately before `IncomingCallReceived` broadcast |
| S6 | `S6_side_paths` | Operator alert / Telegram / history (must not gate the card) |
| S7 | `S7_browser_popup` | Browser: `call.received_at` → card show (`console.info`) |

Log prefix: `[BonVoice Incoming Latency]`.

Correlation fields: `webhook_log_id`, `call_id`. Durations: `duration_ms` (stage), `total_ms` (from webhook `received_at` / begin).

## Config / rollback

| Knob | Default | Effect |
|---|---|---|
| `BONVOICE_INCOMING_LATENCY_LOG` | `true` | When `false`, server stages do not log |

Rollback: set `BONVOICE_INCOMING_LATENCY_LOG=false` or revert the instrumentation commit. No schema migration.

## How to query

Server (Laravel log / aggregator):

```text
"[BonVoice Incoming Latency]"
```

Useful filters:

- `stage:S5_broadcast` — server path until popup broadcast
- `stage:S2_outbox` with `outbox_ahead_count > 0` or `outbox_processed_before > 0` — FIFO contention
- `stage:S4_resolve` high `duration_ms` — agent/customer match cost

Browser (agent dashboard DevTools console):

```text
[BonVoice Incoming Latency] { stage: "S7_browser_popup", ... }
```

`S7.total_ms` is wall time from `call.received_at` (alert `notified_at`) to card render. It includes Reverb + browser delay after S5.

## Success criteria before any fix PR

1. Collect enough production samples for inbound live-assist calls (RINGING/ANSWERED path that creates an alert).
2. Compute p50/p95 for S1–S5 and S7.
3. Identify the dominant stage (or provider gap if S0/S7 is large but S1–S5 are small).
4. Only then propose a targeted fix (do not reorder outbox, change `ShouldBroadcastNow`, or alter feature flags without evidence).

## Related code

- `app/Services/Bonvoice/BonvoiceIncomingCallLatency.php`
- `app/Http/Controllers/Webhooks/BonvoiceWebhookController.php`
- `app/Services/Outbox/OutboxProcessorService.php`
- `app/Services/Bonvoice/BonvoiceWebhookProcessorService.php`
- `app/Services/Bonvoice/BonvoiceLiveCallAssistService.php`
- `app/Services/HybridRealtime/HybridRealtimeNotificationBroadcaster.php`
- `resources/js/incoming-call-card.js`
- Architecture: [hybrid-reverb-phase-3.md](hybrid-reverb-phase-3.md)
- Outbox FIFO note: [phase-12.1-production-stabilization.md](phase-12.1-production-stabilization.md)
