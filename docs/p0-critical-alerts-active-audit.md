# P0 Investigation — Active Critical Alerts Audit

**Date:** 2026-08-07  
**Priority:** P0 production (read-only)  
**Status:** Live audit complete · no code changes  
**Captured:** 2026-08-07 14:21 IST  
**Prod HEAD:** `2c55a190`  
**Canvas:** [/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-critical-alerts-active-audit.canvas.tsx](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-critical-alerts-active-audit.canvas.tsx)

---

## Verdict

Watchdog returned **3** active Critical Alerts. They collapse to **1 real P0 incident** (Cashfree paid order never created) plus **1 misleading BonVoice severity alert**.

| Active alerts | Real incidents | Duplicate surfaces | False-positive severity |
|---------------|----------------|--------------------|-------------------------|
| 3 | 1 | 2 Cashfree keys → same gap | BonVoice Critical |

---

## Audit table

| Alert | Reason | Real issue | False positive | Auto recover possible | Priority |
|-------|--------|------------|----------------|-----------------------|----------|
| **Cashfree · paid missing order** | `PAYMENT_SUCCESS` for **RD3478853** (`cf_payment` 6183507506); Desk order insert failed — `serial_number` **152 chars** &gt; `varchar(100)`. | Customer paid **₹499**; no Desk order/case. Same root as webhook **#35109**. Auto-recovery already failed (audit **865070** at 14:20 IST). | **No** | **No as-is** — retries hit the same SQL 1406 until serial is sanitized/trimmed | **P0** |
| **Cashfree · webhook failures** | **1** Unresolved failed webhook (log **35109**). Classifier correctly ignores **165** historical failed rows (`payment_exists_in_desk`). | **Duplicate surface** of the RD3478853 gap — not a second incident. Copy says “require recovery” after auto-recovery already failed. | **No** | Same as above — blocked until serial handling fixed | **P0 (dup of #1)** |
| **BonVoice · webhook failures** | **5 / 1471** webhooks in 24h failed: `Status=NO_CHANNEL`, empty `callID`, error “missing callID.” Reprocess would still fail. | PBX noise / incomplete CDR — no order or customer linkage. Not an outage (**99.7%** processed). | **Yes** (as Critical) | **No** (terminal missing callID). Alert rolls off after 24h window only. | **P3 / noise** |

---

## Per-alert determinations

| Alert | Actionable? | Stale? | Already fixed? | Should auto-dismiss? | Duplicate? | Misleading? |
|-------|-------------|--------|----------------|----------------------|------------|-------------|
| Cashfree paid missing | **Yes** | No | No | Only after order exists | Pairs with webhook alert | No (gap is real) |
| Cashfree webhook failures | Yes (same work) | No | No | When paid-missing clears | **Yes → RD3478853** | Partial (“require recovery” post failed auto-recover) |
| BonVoice webhook failures | **No** | No (today ~13:34 IST) | N/A — terminal invalid | **Yes** (invalid/noise class) | 5 events · 1 root cause | **Yes** (Critical for NO_CHANNEL) |

---

## Evidence

### Cashfree (one incident)

| Field | Value |
|-------|-------|
| Watchdog keys | `cashfree:paid_missing_order`, `cashfree:webhook_failures` |
| Webhook log | **35109** |
| Order ID | **RD3478853** (absent from `orders`) |
| Payment | SUCCESS · ₹499 · UPI · `6183507506` · bank ref `089203379118` |
| Customer (payload) | PRASAD BAGAL · 9850516632 · kavyacomputervaduj@gmail.com |
| `processing_error` | `SQLSTATE[22001] … Data too long for column 'serial_number'` |
| `order_tags.serial_no` | 152-char padded duplicate: `7071331` + spaces + `7071331` |
| Column | `orders.serial_number` varchar(100) |
| Auto-recovery | Audit `cashfree.missing_order_auto_recovery` id **865070** · `recovered:false` · `still_failed:true` · 14:20:50 IST |
| Classifier | Unresolved **1** / total failed **166** · historical resolved **165** |

### BonVoice

| Field | Value |
|-------|-------|
| Watchdog key | `bonvoice:webhook_failures` |
| Failed log IDs | 17708, 17709, 17710, 17716, 17721 |
| Common error | `BonVoice webhook payload is missing callID.` |
| Payload pattern | `Status=NO_CHANNEL` · `CallDuration=0` · empty `callID` · Dest/Transfer none |
| 24h volume | 1471 received · 1466 processed · 5 failed |
| Recoverability | Reprocess command outcome: `would still fail (missing callID)` |

---

## What is not alerting

| Area | State |
|------|-------|
| Automation | No open-failure Critical (terminal “already closed” filtered) |
| Queue | Not critical |
| RadiumBox / Interakt / Site Health / error spike | Not present in collect |

---

## Clear path (ops only — not done here)

1. Recover **RD3478853** with sanitized serial **7071331** (clears both Cashfree alerts).
2. Downgrade / exclude BonVoice `missing callID` + `NO_CHANNEL` from Critical (future code) so the strip stays actionable.

No production writes and no code changes in this audit.
