# BonVoice Outgoing Retirement — Architecture Investigation

**Status:** Read-only investigation complete — no code changes
**Date:** 2026-08-08
**Production:** `v4.0.12` / `748875c`
**Canvas:** [`bonvoice-outgoing-retirement-investigation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/bonvoice-outgoing-retirement-investigation.canvas.tsx)

**Constraints honored:** No code/routes/DB/scheduler/worker/Cashfree/IRA changes, no deploy, no commit.

Related: [bonvoice-global-outbox-process-investigation.md](./bonvoice-global-outbox-process-investigation.md) · [ivr-incoming-popup-latency.md](./ivr-incoming-popup-latency.md)

---

## Verdict

Outgoing BonVoice is **Desk → BonVoice REST click-to-call** plus **outbound live-status UI** driven by webhook legs tagged `callback_params.source = radium_desk`.

Incoming BonVoice is the **webhook → outbox → call event → IVR popup / missed recovery** pipeline. It must stay.

**Retiring outgoing does not remove the need for synchronous processing of `bonvoice.webhook.process`.** That outbox type is incoming-webhook-only (proven: sole BonVoice outbox event type in prod). Global `process()` remains an incoming concern; the minimal incoming-safe replacement is scoped `processAggregate` (Option B from the prior outbox investigation) — not because of outgoing code, but because IVR popup still requires in-request processing of the **incoming** outbox row.

Prod last 24h: **2,209 Inbound call events, 0 Outbound.** Click-to-call is enabled but produced no outbound legs in that window.

---

## 1. Incoming architecture that must remain

```
BonVoice PBX
  → POST /api/webhooks/bonvoice
  → BonvoiceWebhookLog
  → outbox bonvoice.webhook.process
  → BonvoiceWebhookProcessorService
       → BonvoiceCallEventStore (upsert bonvoice_call_events)
       → BonvoiceLiveCallAssistService (inbound-only popup / alerts / auto-open / dismiss)
       → BonvoiceMissedCallRecoveryService (inbound missed case create; answered auto-resolve)
  → IncomingCallReceived / hybrid realtime → incoming-call-card.js
```

| Capability | Keep |
|------------|------|
| Webhook endpoint + auth | Yes |
| Webhook log + call event persistence | Yes |
| Customer phone capture (inbound SourceNumber) | Yes |
| IVR / live-assist popup | Yes |
| Answered auto-open / missed popup dismiss | Yes |
| Missed-call recovery | Yes (flagged; prod `missed_recovery=true`) |
| `bonvoice:reprocess-failed` | Yes |
| Timeline / C360 / analytics / KPIs reading `bonvoice_call_events` | Yes |
| `users.bonvoice_extension` | Yes — **agent match for inbound IVR** (label is misleading) |
| Schema / historical rows | Yes — no drop |

---

## 2. Outgoing architecture that can be retired

```
Agent UI Call button
  → POST /bonvoice/click-to-call
  → BonvoiceClickToCallService → BonVoice autoCallBridging API
  → broadcastStarted (OutboundClickToCallStatusUpdated)
  → (later) PBX webhooks Direction=Outbound + callback_params.source=radium_desk
  → OutboundClickToCallLiveStatusService.maybeBroadcast → button lifecycle UI
```

| Capability | Retire |
|------------|--------|
| REST click-to-call initiation | Yes |
| API auth token client (`BonvoiceAuthentication`) | Yes |
| Outbound lifecycle button status | Yes |
| Click-to-call metrics / evening health slice | Yes |
| Env secrets for API username/password/DID/base URL | Yes (after disable) |
| Call button API mode | Replace with plain `tel:` (or remove API branch) |

**Do not confuse:** PBX may still POST webhooks with `Direction=Outbound` for non–Desk-initiated calls. Those are still **incoming HTTP** and must continue to persist into `bonvoice_call_events` for history/timeline. Only Desk-initiated C2C + its live-status UX go away.

---

## 3. Dependency map (classification)

| Component | Incoming | Outgoing | Both | Safe to remove? |
|-----------|:--------:|:--------:|:----:|-----------------|
| `POST /api/webhooks/bonvoice` | ✓ | | | **No** |
| `BonvoiceWebhookController` | ✓ | | | **No** |
| `BonvoiceWebhookOutboxWriter` (`bonvoice.webhook.process`) | ✓ | | | **No** |
| `OutboxProcessorService` BonVoice dispatch | ✓ | | | **No** (keep; stop global drain separately) |
| `BonvoiceWebhookProcessorService` | ✓ | hook | ✓ | **Partial** — remove outbound live-status call only |
| `BonvoiceCallEventStore` / `BonvoiceCallEvent` | ✓ | | ✓ data | **No** |
| `BonvoiceWebhookLog` | ✓ | | | **No** |
| `BonvoiceLiveCallAssistService` + popup JS/events | ✓ | | | **No** |
| `BonvoiceMissedCallRecoveryService` | ✓ | answered auto-resolve also for outbound legs | ✓ light | **No** |
| `BonvoiceInboundCustomerResolver` | ✓ | | | **No** |
| `BonvoiceAgentResolver` | ✓ | used by C2C | ✓ | **No** |
| `BonvoiceIncomingCallLatency` | ✓ | | | **No** |
| `bonvoice:reprocess-failed` | ✓ | | | **No** |
| `POST /bonvoice/click-to-call` | | ✓ | | **Yes** |
| `BonvoiceClickToCallController` / Request / Service | | ✓ | | **Yes** |
| `BonvoiceAuthentication` | | ✓ | | **Yes** |
| `BonvoiceClickToCallContext*` / Result / FailureCode / Metrics / SupportReference | | ✓ | | **Yes** |
| `BonvoiceOutboundClickToCallLiveStatusService` | | ✓ | | **Yes** |
| `OutboundClickToCallLifecycle*` + `OutboundClickToCallStatusUpdated` | | ✓ | | **Yes** |
| `resources/js/bonvoice-click-to-call.js` | | ✓ | | **Yes** |
| `resources/js/bonvoice-outbound-call-status.js` | | ✓ | | **Yes** |
| `x-bonvoice.call-button` API branch | | ✓ | | **Partial** → `tel:` only |
| `users.bonvoice_extension` + ClickToCallMobile rule | ✓ agent match | ✓ dial agent | ✓ | **No column**; **Partial** rename/relabel |
| Timeline / C360 / Team Activity / PI / Contribution | | | ✓ readers | **No** |
| `BonvoiceAnalyticsService` | ✓ primary | | | **No** |
| `config/bonvoice.php` `click_to_call` block | | ✓ | | **Yes** |
| Webhook env keys (`WEBHOOK_TOKEN`, `ACCOUNT_ID`, …) | ✓ | | | **No** |
| Outbox event types other than `bonvoice.webhook.process` | — | — | — | **None exist** |

---

## 4. Exact files / routes / config / tests

### A–C. Delete candidates (outgoing)

**Routes**
- `routes/web.php` — `POST bonvoice/click-to-call`

**Controllers / requests / services / data / enums / events / support**
- `app/Http/Controllers/BonvoiceClickToCallController.php`
- `app/Http/Requests/BonvoiceClickToCallRequest.php`
- `app/Services/Bonvoice/BonvoiceClickToCallService.php`
- `app/Services/Bonvoice/BonvoiceAuthentication.php`
- `app/Services/Bonvoice/BonvoiceClickToCallContextResolver.php`
- `app/Services/Bonvoice/BonvoiceClickToCallMetrics.php`
- `app/Services/Bonvoice/BonvoiceOutboundClickToCallLiveStatusService.php`
- `app/Support/Bonvoice/BonvoiceClickToCallSupportReference.php`
- `app/Support/Bonvoice/OutboundClickToCallLifecycleNormalizer.php`
- `app/Data/Bonvoice/BonvoiceClickToCallContext.php`
- `app/Data/Bonvoice/BonvoiceClickToCallResult.php`
- `app/Enums/BonvoiceClickToCallFailureCode.php`
- `app/Enums/OutboundClickToCallLifecycleStatus.php`
- `app/Events/Dashboard/OutboundClickToCallStatusUpdated.php`

**Frontend**
- `resources/js/bonvoice-click-to-call.js`
- `resources/js/bonvoice-outbound-call-status.js`
- Unbind imports in `order-workspace.js`, `customer-360-drawer.js`, `customer-360-cockpit.js`, `realtime-notifications.js`

### D. Frontend controls

| Control | Action |
|---------|--------|
| API Call button (`data-bonvoice-click-to-call`) | Remove API path; keep/simplify `tel:` fallback in `call-button.blade.php` |
| Outbound status label on button | Remove with outbound-call-status.js |
| C360 / order workspace bindings | Remove `bindBonvoiceClickToCall` / shortcut helpers |
| System settings telephony card | Remove/simplify click-to-call env display |

### E. Config / secrets to remove (after feature off)

| Key | Remove? |
|-----|---------|
| `BONVOICE_CLICK_TO_CALL_ENABLED` | Yes |
| `BONVOICE_API_BASE_URL` | Yes |
| `BONVOICE_API_USERNAME` | Yes |
| `BONVOICE_API_PASSWORD` | Yes |
| `BONVOICE_DID` | Yes |
| `BONVOICE_WEBHOOK_TOKEN` / `ACCOUNT_ID` / verify flags | **Keep** |
| `BONVOICE_MISSED_CALL_RECOVERY_ENABLED` | **Keep** |
| `BONVOICE_AUTO_OPEN_CUSTOMER360` | **Keep** |
| `BONVOICE_INCOMING_LATENCY_LOG` | **Keep** |

### F. Tests

| Delete | Retain |
|--------|--------|
| `BonvoiceClickToCallTest` | `BonvoiceWebhookTest` / `BonvoiceWebhookAuthTest` |
| `BonvoiceOutboundClickToCallLiveStatusTest` | `BonvoiceLiveCallAssistTest` |
| Unit ClickToCall* / Authentication / Outbound* / LifecycleNormalizer | `BonvoiceMissedCallRecoveryTest` |
| `tests/js/bonvoice-click-to-call.test.js` | `ReprocessFailedBonvoiceWebhooksCommandTest` |
| `tests/js/bonvoice-outbound-call-status.test.js` | Incoming-call JS tests / HybridRealtimePhase3 |
| Partial: evening-health / UserManagement C2C copy | Analytics / timeline / contact intelligence |

### G. Database

| Asset | Action |
|-------|--------|
| `bonvoice_webhook_logs` | **Keep** |
| `bonvoice_call_events` (+ indexes, recording_url) | **Keep** (inbound history + any historical outbound rows) |
| `bonvoice_call_alerts` | **Keep** |
| `incident_bonvoice_call_links` | **Keep** |
| `users.bonvoice_extension` | **Keep** (inbound agent matching) |
| Migrations | **Do not rollback** |

No schema deletion required for outgoing retirement.

### Partial edits (must keep file)

| File | Edit |
|------|------|
| `BonvoiceWebhookProcessorService` | Drop `BonvoiceOutboundClickToCallLiveStatusService` dependency + `maybeBroadcast()` |
| `call-button.blade.php` | `tel:` only |
| `config/bonvoice.php` | Remove `click_to_call` array |
| `.env.example` | Remove API keys |
| `bootstrap/app.php` | Remove click-to-call JSON exception match |
| `ProductionEveningHealthService` | Remove click-to-call metrics slice |
| User form / `ClickToCallMobile` | Relabel; move phone normalize off ClickToCallService if deleted |

---

## 5. Global outbox impact

### Facts

| Question | Answer |
|----------|--------|
| BonVoice outbox event types | **Only** `bonvoice.webhook.process` |
| Incoming vs outgoing outbox rows | **All are webhook-driven** (incoming HTTP). Outgoing API does **not** write outbox rows. |
| Does C2C create outbox work? | No. C2C may later cause PBX webhooks that create the same `bonvoice.webhook.process` rows. |
| Prod 24h directions | Inbound 2209 / Outbound **0** |
| Is global `process()` only for outgoing? | **No** — it runs on every webhook, including pure inbound IVR |

### H–K answers

| # | Question | Answer |
|---|----------|--------|
| **H** | Does removing outgoing eliminate need for BonVoice global `process()`? | **No.** Outbox + sync drain serve incoming webhooks. Outgoing retirement does not remove that path. |
| **I** | Minimum incoming-only processing path? | Persist log → `writeProcessingJob` → process **that** outbox row in-request → live-assist + recovery side effects → HTTP 200. Cron `outbox:process` remains retry/safety net. |
| **J** | Can remaining incoming webhook use `processAggregate()` safely? | **Yes.** Same aggregate shape as today (`bonvoice_webhook_log` + log id). Preserves IVR sync side effects; stops Cashfree/Interakt FIFO drain. This is an **incoming** fix, not an outgoing intermediate. Justified by IVR popup dependency (see prior investigation + `ivr-incoming-popup-latency.md`). |
| **K** | Could outgoing code cause CPU today? | When used: lsphp HTTP to BonVoice API + later webhook processing + live-status broadcasts. **Currently negligible** (0 outbound events / 24h). Dominant BonVoice-related CPU remains **incoming webhook + global outbox drain**. |

### P08-08-040 / `processAggregate`

Per this investigation: **do not treat `processAggregate` as a temporary bridge for outgoing removal.**
It is the correct **incoming-only** processing replacement when the webhook controller is next touched. It can ship:

- in the same change set as outgoing retirement (webhook processor loses outbound hook; controller switches to aggregate), or
- as a separate incoming perf PR.

It is **not** required *by* outgoing deletion itself — outgoing deletion alone does not change outbox semantics.

---

## 6. CPU / resource impact

| Source | After outgoing retirement | Notes |
|--------|---------------------------|-------|
| Click-to-call API HTTP | Gone | Small today (no outbound volume) |
| Outbound live-status broadcasts | Gone | Removes processor hook work on outbound C2C legs |
| Incoming webhook volume | Unchanged | Dominates BonVoice traffic |
| Global `process()` FIFO drain | **Unchanged unless separately fixed** | Still drains Cashfree/Interakt on inbound POSTs |
| Missed-call recovery | Unchanged | Still runs when enabled |

Largest BonVoice-adjacent CPU win remains replacing global `process()` with `processAggregate` on the **incoming** webhook — orthogonal to deleting click-to-call classes.

---

## 7. Safest removal sequence

1. **Feature-flag off in prod:** `BONVOICE_CLICK_TO_CALL_ENABLED=false` (immediate behavior stop; no code deploy).
2. **Soft UI:** force `tel:` fallback / hide API button (optional interim).
3. **Code removal PR (outgoing only):**
   - Delete route + C2C stack + outbound live-status + JS
   - Strip processor hook
   - Relabel `bonvoice_extension`
   - Trim config/env example + evening health + settings card
   - Delete outgoing tests; keep incoming suite green
4. **Optional separate PR (incoming outbox):** `processAggregate` on webhook controller + unrelated-pending regression test.
5. **Rotate/remove API secrets** from host `.env` after step 3 is live.
6. **Do not** drop tables or purge historical outbound rows.

---

## 8. Rollback strategy

| Stage | Rollback |
|-------|----------|
| Flag-only disable | Re-enable `BONVOICE_CLICK_TO_CALL_ENABLED` |
| Code removal deploy | Revert git commit / redeploy previous tag; restore API env keys |
| `processAggregate` (if shipped) | Restore `process()` one-liner independently |
| Schema | N/A — no destructive migrations |

Incoming webhook path never depends on click-to-call being enabled.

---

## 9. Must NOT remove

- `POST /api/webhooks/bonvoice` and auth
- Outbox writer + BonVoice outbox dispatch
- `BonvoiceWebhookProcessorService` core (store + live-assist + missed recovery)
- Incoming popup stack (events, notifications, JS, blade host)
- Missed-call recovery service
- Reprocess command
- All BonVoice tables + `users.bonvoice_extension`
- Timeline / analytics / C360 readers of call events
- Webhook-related env keys

---

## Answers A–G (summary)

| | Decision |
|---|----------|
| **A** API/functionality to delete | Click-to-call REST client, auth, metrics, support refs, outbound live-status |
| **B** Routes | Remove `POST /bonvoice/click-to-call` only |
| **C** Services/classes/events | See delete list §4; strip one hook from processor |
| **D** Frontend | Remove C2C JS + outbound status; simplify call button to `tel:` |
| **E** Config/secrets | Remove `click_to_call.*` / API env vars; keep webhook/incoming keys |
| **F** Tests | Delete outgoing suite; retain webhook/live-assist/missed/reprocess/incoming JS |
| **G** DB | Keep all schema + history; no purge required |

---

## STOP

Investigation complete. No implementation performed.
