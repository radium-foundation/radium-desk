# P0 Production Recovery Audit — `order_record_id` outage

**Date:** 2026-08-07  
**Priority:** P0 production (read-only)  
**Status:** Audit complete · **no code changes · no recovery commands run · no data writes**  
**Outage window:** 2026-08-07 **16:26–16:39 IST**  
**Final snapshot:** 2026-08-07 **16:52:01 IST**  
**Prod HEAD:** `e1370d76`  
**Canvas:** [/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-order-record-id-recovery-audit.canvas.tsx](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-order-record-id-recovery-audit.canvas.tsx)

---

## Verdict

**Not every failed webhook has recovered.**

| Area | Status at 16:52:01 IST |
|------|------------------------|
| Schema / live ingest | Healthy (`incidents.order_record_id` present; new webhooks process) |
| BonVoice (3 ANSWERED) | **Fully recovered** via outbox retry |
| Cashfree outage cohort | **Partial** — 15 recovered, 3 safe redeliveries, **18 still missing Order+Incident** |
| Outbox `order_record_id` stuck | **None** (0 pending / 0 failed / 0 exhausted) |

**Customers are not all safe.** **18 paid payments** still have no Desk Order and no Incident (**₹9,962**).

---

## 1. Cashfree

Cohort = failed webhook logs **35512–35547** from the outage / error-spike set (all `order_record_id` SQL at fail time).

| Metric | Count |
|--------|------:|
| Total failed webhook logs in cohort | **36** |
| Now have valid Order **and** Incident | **18** (15 recovered + 3 already had order before redelivery fail) |
| Still missing Order | **18** |
| Still missing Incident | **18** (same set — no order-without-incident orphans) |

### Distinguishment

| Class | Count | Meaning |
|-------|------:|---------|
| **Recovered by retry / auto-recover** | **15** | Same log flipped `failed` → `processed`; Order created ~16:45–16:52 IST by `cashfree:auto-recover-missing` |
| **Recovered automatically (provider)** | **0** | No evidence of a *new* Cashfree delivery creating the missing orders for the unrecovered set |
| **Safe failed redelivery (no customer gap)** | **3** | Logs **35515–35517** (RD3479206 / RD3479208 / RD3479210) — prior log already processed; Order+Incident exist |
| **Still unrecovered** | **18** | Paid SUCCESS, log still `failed`, no Order, no Incident |

Auto-recover config on prod: `enabled=true`, interval **15m**, `max_per_run=20`. Dry-run at 16:50 listed **20** recoverable candidates (limit-capped); recovery was visibly progressing during this audit.

---

## 2. BonVoice

| Log | Call / phone | Log status now | Outbox | Attempts | Exhausted? |
|-----|--------------|----------------|--------|----------|------------|
| 18307 | ANSWERED · 9368234067 · call event 17827 | **processed** | 340347 **completed** | 4 | No (max 5) |
| 18308 | ANSWERED · 9124474442 · call event 17828 | **processed** | 340349 **completed** | 4 | No |
| 18313 | ANSWERED · 9933331123 · call event 17833 | **processed** | 340357 **completed** | 4 | No |

Outbox retries **already succeeded** after the column existed (~16:45–16:47 IST). **No BonVoice recovery command needed.**

(24h BonVoice Critical still shows **5** = pre-outage `NO_CHANNEL` / missing `callID` noise at ~13:34 — **not** this outage.)

---

## 3. Outbox (outage-related)

| State | `order_record_id` stuck | Notes |
|-------|-------------------------|--------|
| Pending | **0** | — |
| Processing | **0** stuck on this error | Brief `processing` rows elsewhere are normal live traffic |
| Failed | **0** | — |
| Retryable | BonVoice trio **already completed** | Was retryable; succeeded at attempts=4 |
| Permanently exhausted | **0** | Exhaustion threshold = 5 attempts (`OutboxProcessorService::MAX_ATTEMPTS`) |

Cashfree primary ingest is **not** outbox-driven; recovery of missing paid orders is via **`cashfree:auto-recover-missing`** / historical recovery, not outbox replay.

---

## 4. Historical failures still broken because of the outage

### Remaining — need recovery (18)

| Provider | Reference | Current state | Can auto-recover? | Needs manual command? | Needs no action? |
|----------|-----------|---------------|-------------------|-----------------------|------------------|
| Cashfree | Log **35530** · RD3479229 · pay 6184608722 · ₹499 | failed · no Order · no Incident | Yes | Yes (or wait for schedule) | No |
| Cashfree | Log **35531** · RD3479232 · pay 6184606441 · ₹499 | failed · no Order · no Incident | Yes | Yes | No |
| Cashfree | Log **35532** · RD3479235 · pay 6184617055 · ₹717 | failed · no Order · no Incident | Yes | Yes | No |
| Cashfree | Log **35533** · RD3479234 · pay 6184613590 · ₹599 | failed · no Order · no Incident | Yes | Yes | No |
| Cashfree | Log **35534** · RD3479238 · pay 6184621673 · ₹481 | failed · no Order · no Incident | Yes | Yes | No |
| Cashfree | Log **35535** · RD3479236 · pay 6184619419 · ₹499 | failed · no Order · no Incident | Yes | Yes | No |
| Cashfree | Log **35536** · RD3479241 · pay 6184627008 · ₹599 | failed · no Order · no Incident | Yes | Yes | No |
| Cashfree | Log **35537** · RD3479242 · pay 6184628841 · ₹499 | failed · no Order · no Incident | Yes | Yes | No |
| Cashfree | Log **35538** · RD3479246 · pay 6184629451 · ₹499 | failed · no Order · no Incident | Yes | Yes | No |
| Cashfree | Log **35539** · RD3479243 · pay 6184629734 · ₹599 | failed · no Order · no Incident | Yes | Yes | No |
| Cashfree | Log **35540** · RD3479233 · pay 6184623809 · ₹879 | failed · no Order · no Incident | Yes | Yes | No |
| Cashfree | Log **35541** · RD3479244 · pay 6184628853 · ₹499 | failed · no Order · no Incident | Yes | Yes | No |
| Cashfree | Log **35542** · RD3479249 · pay 6184631298 · ₹599 | failed · no Order · no Incident | Yes | Yes | No |
| Cashfree | Log **35543** · RD3479251 · pay 6184638352 · ₹499 | failed · no Order · no Incident | Yes | Yes | No |
| Cashfree | Log **35544** · RD3479248 · pay 6184640822 · ₹499 | failed · no Order · no Incident | Yes | Yes | No |
| Cashfree | Log **35545** · RD3479247 · pay 6184631088 · ₹499 | failed · no Order · no Incident | Yes | Yes | No |
| Cashfree | Log **35546** · RD3479252 · pay 6184640811 · ₹499 | failed · no Order · no Incident | Yes | Yes | No |
| Cashfree | Log **35547** · RD3479254 · pay 6184653855 · ₹499 | failed · no Order · no Incident | Yes | Yes | No |

### Outage-related but no customer action (3)

| Provider | Reference | Current state | Can auto-recover? | Needs manual command? | Needs no action? |
|----------|-----------|---------------|-------------------|-----------------------|------------------|
| Cashfree | Log **35515** · RD3479206 · pay 6184542357 | log `failed`; Order+Incident exist | N/A | No | **Yes** |
| Cashfree | Log **35516** · RD3479208 · pay 6184543389 | log `failed`; Order+Incident exist | N/A | No | **Yes** |
| Cashfree | Log **35517** · RD3479210 · pay 6184545004 | log `failed`; Order+Incident exist | N/A | No | **Yes** |

### Outage BonVoice — no action (3)

| Provider | Reference | Current state | Can auto-recover? | Needs manual command? | Needs no action? |
|----------|-----------|---------------|-------------------|-----------------------|------------------|
| BonVoice | Log **18307** | processed · outbox completed | Already done | No | **Yes** |
| BonVoice | Log **18308** | processed · outbox completed | Already done | No | **Yes** |
| BonVoice | Log **18313** | processed · outbox completed | Already done | No | **Yes** |

---

## Final answers

| Question | Answer |
|----------|--------|
| Are all customers now safe? | **No** |
| Any paid customers still missing Order or Incident? | **Yes — 18** (₹9,962) |
| Is any recovery command still required? | **Yes** (Cashfree only) |
| Exact command | See below |
| If no further action? | **Not applicable** — action still required |

### Exact command (not run by this audit)

```bash
cd /home/u215544208/laravel/radium-desk && /opt/alt/php84/usr/bin/php artisan cashfree:auto-recover-missing --limit=25
```

Optional equivalent:

```bash
cd /home/u215544208/laravel/radium-desk && /opt/alt/php84/usr/bin/php artisan cashfree:recover-historical
```

**Do not use** `cashfree:reprocess-failed` for this outage — it only targets `resolveAutomationActor` failures.

Waiting for the next scheduled `cashfree:auto-recover-missing` ticks (15m / max 20) would also finish the queue eventually; a manual run finishes the remaining **18** immediately.

---

## Watchdog at snapshot

| Alert | Message |
|-------|---------|
| `cashfree:paid_missing_order` | 18 paid payment(s) have no matching Desk order |
| `cashfree:webhook_failures` | 17 actionable Cashfree webhook failure(s) require recovery |
| `bonvoice:webhook_failures` | 5 in 24h (NO_CHANNEL noise only) |
| `errors:spike` | 20 in last 60m (rolling window still includes unrecovered fails) |

No production writes and no recovery commands were executed in this audit.
