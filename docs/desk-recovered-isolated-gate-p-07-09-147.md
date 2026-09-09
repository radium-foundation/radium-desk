# Authorization-aware isolated recovered-Commerce gate — RadiumDesk-P-07-09-147

**Date:** 2026-09-09  
**Prompt ID:** `RadiumDesk-P-07-09-147`  
**Mode:** Code fix + named-file overlay. No ingest, READY, serial, invoice, parcel, or shipment.

## Root cause

P-07-09-143 made `isFrozenForFulfilment()` authorization-aware. Isolated recovered ingest (`desk:fulfil-hardware {id} --step=ingest` without `--payload`) used that path to open HF19 for RDE318388 / CO-000755.

`HardwareFulfilmentEligibility::assertIsolatedTarget()` still used `isFrozenSourceId()`. Dry-run recovered ingest called `assertRecoveredCommerceIngest()` only, so it passed. Execute created the HF, then `assertIsolatedTarget()` rejected the same authorized frozen source. CLI exited 1 after the write. HF19 remained `ingested` (no rollback).

## Fix

- `assertIsolatedTarget()` now uses `isFrozenForFulfilment($sourceId, $order)`.
- Order-side gates live in `assertIsolatedCommerceOrder()` and run **before** `ensureIngestedFromExistingCommerce()`.
- Dry-run recovered ingest calls the same pre-write assertion.
- Channel ingest still uses `isFrozenSourceId()` (`shouldOpenRecord()`). `--payload` on recovered sources still fails closed. `FROZEN_SOURCE_IDS` is unchanged.

## Not changed

`isFrozenSourceId()` remains the Box/channel-ingest and recovered-path *membership* check (non-frozen sources still require `--payload`; payload ingest still refuses frozen sources). HOLD and blocked-until-authorized lists are unchanged.

## Tests

`HardwareRecoveredFulfilmentAuthorizationTest` plus Hardware Fulfilment feature+unit: 339 passed, 1 skipped. Pint `--test` and `php -l` on changed PHP passed.

## Production

HF19 must remain the single RDE318388 fulfilment at `ingested`. This prompt must not ingest again or run READY.
