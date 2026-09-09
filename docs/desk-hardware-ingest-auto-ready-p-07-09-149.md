# Permanent Hardware ingest → READY — RadiumDesk-P-07-09-149

**Date:** 2026-09-09  
**Prompt ID:** `RadiumDesk-P-07-09-149`  
**Mode:** Code fix + commit/push + named-file overlay. No bulk-READY. No serial/invoice/parcel/shipment/outbox mutation.

Ledger last used: **P-07-09-148**. This ticket: **P-07-09-149**. Next unused after this row: **P-07-09-150**.

Before SHA: `6e7f314f64effdf6c814bd680d400d98c14e7253` (P-07-09-147 on `main`).

---

## Root cause

Successful Hardware ingest created `hardware_fulfilments.state = ingested` and stopped. The only writer of `INGESTED → READY_FOR_FULFILMENT` was CLI `desk:fulfil-hardware {id} --step=ready` → `HardwareFulfilmentWorkflowService::markReady()`. There was no application path after ingest.

`HardwareFulfilmentOperationalClassifier::stageAndAction()` treated every zero-serial fulfilment as **Allocate Serial**, including `ingested` rows. The Allocate dialog then correctly refused: *Serial allocation requires READY_FOR_FULFILMENT*.

## Permanent backend change

Existing `markReady()` reused: **YES**

Automatic eligible ingest → READY: **YES**

After `HardwareFulfilmentFoundationService::ensureIngested()` and `ensureIngestedFromExistingCommerce()` resolve or create an HF:

1. If state is already past `ingested`, return it.
2. Call existing `HardwareFulfilmentWorkflowService::markReady()`.
3. Expected `ValidationException` (frozen/HOLD/unpaid/cutoff/missing date/unauthorized) keeps **INGESTED** and logs a notice. Ingest is not rolled back.
4. Unexpected failures propagate so the surrounding ingest transaction can roll back.

No direct `state` / `ready_at` writes. No second state machine. Downstream operations are not invoked. Box callbacks are not enqueued for READY (`callbackWorthyStates` still starts at `serials_allocated`).

Channel ingest of frozen sources still uses `isFrozenSourceId()` / `shouldOpenRecord()` (P-147). Recovered-Commerce isolated ingest still uses `isFrozenForFulfilment()`. `--payload` on recovered sources still fails closed.

Authenticated `POST inventory/hardware-fulfilments/{fulfilment}/ready` wraps the same `markReady()` for leftover INGESTED rows. Operators do not need CLI for new eligible ingests.

## Dashboard change

INGESTED action:

- eligibility passes → **Ready for Fulfilment**
- legitimate blocker → **View** plus the actual blocker
- never **Allocate Serial**

READY action (zero serials): **Allocate Serial**

SERIALS_ALLOCATED and shipment stages are unchanged.

## Safety

P-147 recovered-Commerce authorization preserved. Frozen / HOLD / RIN / RDE318400 / RDE318437 protections preserved. RIN Hardware still uses the same `markReady()` gates.

This prompt does **not** READY existing production rows.

## Tests

Hardware Fulfilment feature+unit + Channel Ingest: **387 passed, 1 skipped**.  
New `HardwareFulfilmentIngestReadyTest`: 13 passed.  
Vitest `hardware-action-dialog.test.js`: 19 passed.  
Vite: not run (no JS/CSS asset change).  
Pint: dirty PHP formatted.  
PHP syntax: passed on changed PHP.

## Production mutation boundary

RDE318517 / RDE318388 / RIN3512344 / RIN3512331 / frozen seven / RDE318438 / RDE318400 / RDE318437: **NO — Not mutated.**

Serial, invoice, parcel, shipment, AWB, label, pickup, manifest, Shiprocket, outbox: **NO — Not performed.**
