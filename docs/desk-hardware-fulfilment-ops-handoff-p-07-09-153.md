# Hardware Fulfilment ops + recovered Open Fulfilment — RadiumDesk-P-07-09-153

**Date:** 2026-09-09  
**Prompt ID:** `RadiumDesk-P-07-09-153`  
**Companion:** `radiumbox.com-P-09-09-04`  
**Mode:** Classifier + authenticated Open Fulfilment. No bulk HF/READY. No serial/invoice/shipment.

Before SHA: `20c3a2bff270862e411dd6cef9a8241ddf142211`

---

## Root cause

`HardwareFulfilmentOperationalClassifier::fromAwaiting()` always sent paid Hardware rows without an HF to **Review → Customer 360**, even when P-147 recovered-Commerce authorization already allowed `ensureIngestedFromExistingCommerce()` + `markReady()`.

Cashfree support shells with empty `product_name` and no Commerce line were labelled **Product data missing**, hiding Awaiting handoff / HOLD / split-tender / mapping blockers.

READY rows with a physical line and no Owner SKU map still offered **Allocate Serial**, which then failed closed.

## Permanent Desk change

- Recovered authorized Commerce → Dashboard **Open Fulfilment** (existing isolated ingest, then existing `markReady()`).
- No HF and no Commerce → **Awaiting handoff**, not generic product missing.
- HOLD → **Owner HOLD / recovery authorization required**. Frozen unauthorized and `BLOCKED_UNTIL_AUTHORIZED` stay **View**.
- Commerce `wallet_tender_amount` → **Split-tender recovery required**.
- READY + zero serials + missing map → **Product mapping required**, not Allocate Serial.
- INGESTED eligible → **Ready for Fulfilment** (P-149 unchanged).
- Customer 360 remains on row click and `/dashboard/orders/{order}/customer-360`.

Unauthorized frozen, RDE318400, HOLD, and split-tender cannot POST Open Fulfilment.

## Safety

Frozen source list unchanged. P-147 authorization unchanged. No production HF/READY/serial/invoice/shipment in this prompt. Model 1410 is not mapped.

## Tests

Hardware Fulfilment feature+unit: **398 passed, 1 skipped**.  
Channel Ingest: **32 passed**.  
New Open Fulfilment HTTP tests: passed.  
Vitest: not run (no JS change).  
Vite: not run.  
Pint: dirty PHP formatted.  
PHP syntax: passed on changed PHP.
