# Error Spike: 39 webhook/integration failures (60 minutes)

**Date:** 2026-08-07  
**Priority:** P0 production (read-only)  
**Status:** Investigation complete · no fixes applied  
**Captured:** 2026-08-07 16:43 IST  
**Prod HEAD:** `e1370d76`  
**Canvas:** [/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/error-spike-39-webhook-failures.canvas.tsx](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/error-spike-39-webhook-failures.canvas.tsx)

---

## Verdict

The active **Error Spike (39)** is **one root cause repeated 39 times**: SQL `42S22 Unknown column 'incidents.order_record_id'` during a ~16:26–16:39 IST window after deploy `e1370d76`.

| Question | Answer |
|----------|--------|
| Top contributor | **Cashfree 36/39 (92%)** |
| BonVoice NO_CHANNEL dominate? | **No — 0/39** |
| Cashfree retries included? | **Yes** (3 redeliveries after success; 19 delayed process stamps) |
| Interakt / Gmail / Telegram / RadiumBox? | **Interakt 0**; others **not in** `errors:spike` formula |
| Same root cause repeated? | **39/39 (100%)** |
| Watchdog severity | **Keep Critical** — 33 paid customers have no Desk order |

---

## How the alert is computed

`ProductionWatchdogService::errorSpikeAlerts()` counts failed rows in the last **60** minutes:

| Source | Timestamp column | In spike? |
|--------|------------------|-----------|
| `cashfree_webhook_logs` (`processing_status=failed`) | `processed_at` | Yes |
| `bonvoice_webhook_logs` (failed) | `received_at` | Yes |
| `interakt_webhook_logs` (failed) | `received_at` | Yes |
| Gmail / Telegram / RadiumBox | — | **No** |

Threshold: **≥ 10** → Critical `errors:spike`.

Observed at capture: **cf=36 + bv=3 + ik=0 = 39**.

---

## Breakdown

| Provider | Error type | Count | Recoverable vs permanent | Customer impact | Duplicate retries vs unique |
|----------|------------|------:|--------------------------|-----------------|------------------------------|
| Cashfree | `SQLSTATE[42S22]` missing `incidents.order_record_id` | 36 | **Recoverable** (column present now; new webhooks OK since 16:44) | **33** paid with no Desk order (**₹18,191**); **3** already have order+case | **33** unique first-failures; **3** provider redeliveries after prior `processed` log |
| BonVoice | Same SQL on **ANSWERED** webhooks (valid `callID`) | 3 | **Recoverable** (outbox still `pending`, attempts=3) | Call events exist; incident linkage incomplete | **3** unique logs; outbox retries do **not** inflate the spike count |
| Interakt | — | 0 | — | None | — |

### Action table

| Provider | Error | Count | Customer impact | Action required |
|----------|-------|------:|-----------------|-----------------|
| Cashfree | `order_record_id` missing at process time | 33 | Paid, no Desk order/case | **P0** reprocess / create orders |
| Cashfree | Same SQL · redelivery after prior success | 3 | None (order+case exist) | Resolve duplicate failed logs; do not re-create |
| BonVoice | Same SQL on ANSWERED (not NO_CHANNEL) | 3 | CDR saved; case link failed | Re-drive outbox now that column exists |
| Interakt / Gmail / Telegram / RadiumBox | Not in spike formula | 0 | None | No action for this spike |

---

## Answers to numbered questions

### 1. Top contributors

1. **Cashfree** — 36 (92%), of which 33 are real paid-missing incidents  
2. **BonVoice** — 3 (8%), schema failures on real ANSWERED calls  
3. Everything else — 0

### 2. Timeline (IST)

| Time | Event |
|------|-------|
| 16:22 | Deploy `e1370d76` (`feat(workflow): protect manual ownership and optimize scheduler`) |
| 16:20–16:25 | Cashfree webhooks still **processed** (incl. RD3479206/08/10) |
| 16:26–16:39 | Failure window — all spike rows |
| 16:27 | First 3 Cashfree fails (redeliveries of already-created orders) |
| 16:31–16:33 | 3 BonVoice ANSWERED fails |
| **16:35** | Peak — **22** Cashfree fails stamped |
| 16:36–16:39 | Remaining Cashfree fails |
| 16:44+ | Cashfree processing **healthy** again (`order_record_id` present) |

Minute histogram (failed): `16:27×3`, `16:31×1`, `16:32×1`, `16:33×1`, `16:35×22`, `16:36×6`, `16:37×2`, `16:38×2`, `16:39×1`.

### 3. Does BonVoice NO_CHANNEL dominate?

**No.** Spike BonVoice rows are:

| Log | Status | callID | Error class |
|-----|--------|--------|-------------|
| 18307 | ANSWERED | present | `order_record_id` |
| 18308 | ANSWERED | present | `order_record_id` |
| 18313 | ANSWERED | present | `order_record_id` |

The earlier **5× NO_CHANNEL / missing callID** (logs 17708–17721 at ~13:34) are **outside** this 60-minute spike. They still inflate the separate 24h BonVoice Critical alert (8 = 5 noise + 3 schema).

### 4. Are Cashfree retries included?

**Yes, partially, by design of the counter:**

- Spike counts **failed webhook log rows**, not outbox attempts (Cashfree primary ingest is not outbox-driven; outbox is deferred post-success work).
- **3/36** failed logs are **Cashfree redeliveries** after a prior successful log for the same `cf_payment_id` (35509/10/11 processed → 35515/16/17 failed). Orders already exist.
- **19/36** show `received_at` → `processed_at` lag &gt; 30s (many stamped together at 16:35:26) — delayed processing of the **same** log row, not extra spike counts.

### 5. Interakt, Gmail, Telegram, RadiumBox?

| Provider | Contributes to this spike? | Evidence |
|----------|----------------------------|----------|
| Interakt | No (0 failed in window) | Query count = 0 |
| Gmail | No | Not in `errorSpikeAlerts()` |
| Telegram | No | Not in `errorSpikeAlerts()` |
| RadiumBox | No | Separate RadiumBox alerts only |

### 6. How many are the same root cause repeated?

**39 / 39 (100%).** One schema/deploy gap, not independent integration outages.

Related active Critical alerts collapse to the same incident class:

- `cashfree:paid_missing_order` → 33  
- `cashfree:webhook_failures` → 33  
- `bonvoice:webhook_failures` → 8 (24h; mixed)  
- `errors:spike` → 39  

### 7. Should watchdog severity change?

**Recommendation: keep Critical — do not downgrade.**

| Option | Verdict |
|--------|---------|
| Downgrade Error Spike | **No** — 33 paid customers without Desk orders is P0 |
| Raise severity | Unnecessary — already Critical; dedicated Cashfree alerts already fire |
| Exclude NO_CHANNEL from spike | Fine as future hygiene; **would not change this alert** (0 NO_CHANNEL in the 39) |
| Dedup spike vs paid-missing | Optional UX cleanup (overlapping surfaces); not a severity change |

Column is present now (`bigint unsigned NOT NULL`). Failures are **recoverable** via reprocess; they are **not** self-clearing until logs are reprocessed / orders created.

---

## Evidence notes

- Missing amount histogram (33): ₹499×22, ₹599×5, ₹879×2, ₹631×2, ₹717×1, ₹481×1 → **sum ₹18,191**
- Existing orders for redelivery trio: RD3479206, RD3479208, RD3479210 (open incidents 29921–29923)
- BonVoice outbox: ids 340347 / 340349 / 340357 · `pending` · attempts=3 · last_error = same `order_record_id` SQL
- Migration `2026_08_07_150000_add_order_record_id_to_incidents_table` is recorded (batch 79); column exists at capture time

---

## Clear path (ops only — not done here)

1. Reprocess the **33** Cashfree failed webhooks (or integrity recovery) now that `order_record_id` exists.  
2. Ignore/resolve the **3** Cashfree failed redelivery logs tied to existing orders.  
3. Re-drive BonVoice outbox for logs **18307 / 18308 / 18313**.  
4. Separately (not this spike): stop treating BonVoice `NO_CHANNEL` / missing `callID` as Critical.

No production writes and no code changes in this investigation.
