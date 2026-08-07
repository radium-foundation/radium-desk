# BonVoice webhook failures — P0 investigation

**Date:** 2026-08-07  
**Alert:** Watchdog `bonvoice:webhook_failures` — 5 BonVoice webhook failure(s) in the last 24 hours  
**Mode:** Read-only (production MySQL via SSH / `artisan tinker`; no writes, no code changes)  
**Canvas:** [/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/bonvoice-webhook-failures-p0.canvas.tsx](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/bonvoice-webhook-failures-p0.canvas.tsx)

---

## Verdict

All **5/5** failures share one root cause: BonVoice sent inbound `Status=NO_CHANNEL` webhooks with **`callID: null`**. Desk cannot upsert a call event without `callID`, so each outbox event exhausted 5 retries and stayed failed. Reprocess cannot recover them. Customer call capture for these phones was **not lost** — companion `NOANSWER` / `NOINPUT` webhooks with real `callID`s succeeded around the same minute and linked to open cases.

---

## Root causes

| # | Root cause | Count | Permanent? | Auto-recover? |
|---|------------|------:|------------|---------------|
| 1 | BonVoice `NO_CHANNEL` payload with null `callID` (PBX channel unavailable / unassigned) | 5 | Yes (as stored) | **No** |

No other root-cause groups in the 24h alert window.

---

## Every failed webhook

| Log | Outbox | Received (IST) | Source | Order | Case | Error | Attempts |
|-----|--------|----------------|--------|-------|------|-------|----------|
| 17708 | 337857 | 13:33:51 | 8404856971 | RD3478597 | SC29415 | missing callID | 5 → failed |
| 17709 | 337858 | 13:33:51 | 9801421492 | INQ-SC29509 | SC29509 | missing callID | 5 → failed |
| 17710 | 337862 | 13:33:56 | 8126623052 | RD3477832 | prior SC28923 links | missing callID | 5 → failed |
| 17716 | 337881 | 13:34:20 | 8096487753 | INQ-SC29374 | SC29374 | missing callID | 5 → failed |
| 17721 | 337888 | 13:34:39 | 9801421492 | INQ-SC29509 | SC29509 | missing callID | 5 → failed |

### Shared payload shape

```json
{
  "Status": "NO_CHANNEL",
  "callType": "2",
  "Direction": "Inbound",
  "callID": null,
  "DestinationNumber": "None",
  "Network": "unassigned",
  "CallDuration": "0",
  "DisplayNumber": "1204404276",
  "AccountID": "<configured account — matches>"
}
```

`callID` key is present and explicitly `null` (not omitted).

### Per-field checklist (all 5)

| Dimension | Finding |
|-----------|---------|
| **Payload** | `2:NO_CHANNEL`; null `callID`; dest `None`; network `unassigned`; duration 0 |
| **Error** | `BonVoice webhook payload is missing callID.` |
| **Retry history** | Outbox backoff 30s → 120s → 600s → 1800s; 5 attempts; terminal ~43 min later |
| **Duplicate detection** | New `bonvoice_webhook_logs` row per POST; outbox idempotency `bonvoice.webhook.process.{log_id}`; call-event unique `(call_id, leg)` never reached |
| **Queue** | Transactional outbox only (`event_type=bonvoice.webhook.process`). No Laravel queue job |
| **Order linkage** | Not via failed log (no call event). Phone match: all 4 numbers map to active orders |
| **Customer linkage** | Same — phone→order exists; failed path did not create/update call event or incident link |
| **Permanent vs temporary** | **Permanent** for these rows. PBX channel condition may be transient; stored payload cannot succeed |

---

## Companion capture (why customer impact is low)

Same source phones had successful webhooks with real `callID`s immediately before/after:

| Phone | Nearby successful statuses | Case links |
|-------|---------------------------|------------|
| 8404856971 | Multiple `NOANSWER` | missed → SC29415 |
| 9801421492 | `NOANSWER` / `NOINPUT` | case SC29509 |
| 8126623052 | `NOANSWER` (+ earlier `ANSWERED`) | answered/missed → SC28923 |
| 8096487753 | `NOANSWER` / `NOINPUT` | missed → SC29374 |

Window 13:33–13:35 IST also processed many other `2:NOANSWER` / `2:NOINPUT` events successfully — only the five `NO_CHANNEL` rows failed.

---

## Outbox / pipeline health (24h)

| Metric | Value |
|--------|------:|
| BonVoice outbox completed | 1468 |
| BonVoice outbox failed | 5 (these) |
| BonVoice outbox pending/processing | 1 |
| Reprocess dry-run recoverable | **0 / 5** |

Dry-run:

```text
php artisan bonvoice:reprocess-failed --dry-run --from=... --status=failed
→ Would recover: 0 · Still failed: 5 (would still fail (missing callID))
```

---

## Historical context

| Window | Observation |
|--------|-------------|
| Last 7d failed webhooks | 7 — all `missing callID`, all `NO_CHANNEL` (2 on 06 Aug + 5 today) |
| Last 30d `NO_CHANNEL` | 7 total; **100%** null `callID`; **100%** failed |
| Last 30d any `missing callID` | 41 (includes other statuses with null `callID`: NOANSWER 19, ANSWERED 12, NO_CHANNEL 7, NOINPUT 3) |

Today’s alert is a burst of the known `NO_CHANNEL` pattern, not a new outage class.

---

## Can auto-recover?

**No.**

- Payload lacks `callID`; `BonvoiceCallEventStore` / processor hard-require it.
- `bonvoice:reprocess-failed` predicts permanent failure.
- BonVoice does not redeliver failed Desk processing.
- Marking logs processed without a call event would only silence the watchdog — it would not reconstruct a call.

---

## Production risk

| Risk | Severity | Notes |
|------|----------|-------|
| Lost inbound call capture for these 5 events | **Low** | Companions with `callID` already stored + case-linked |
| Watchdog / critical-alert noise | **Medium** | Counts permanent provider junk as P0 |
| Outbox retry waste | **Low–Medium** | 25 attempts over ~43 min per burst; adds FIFO load |
| Missing NO_CHANNEL analytics | **Low** | Channel-exhaustion not visible in call events |
| Broader null-`callID` stream (30d=41) | **Medium** | Not limited to NO_CHANNEL; worth separate hygiene if volume grows |

**Overall production risk for this alert: Low operational impact, Medium alert/ops noise.**

---

## Investigation method

Read-only production queries via SSH + `php artisan tinker` using `tools/config.sh` (`desk.radiumbox.com` / `radium-desk`).

Tables consulted: `bonvoice_webhook_logs`, `outbox_events`, `bonvoice_call_events`, `incident_bonvoice_call_links`, `orders`, `incidents`.

Commands: `bonvoice:reprocess-failed --dry-run` (no writes).

No code changes. No production writes.
