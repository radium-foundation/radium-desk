# Dashboard Select Courier advances to Create Shipment — RadiumDesk-P-07-09-119

**Date:** 2026-09-09  
**Prompt ID:** `RadiumDesk-P-07-09-119`  
**Mode:** Fix the Hardware Dashboard Start Shipment next-action classifier. Do not create a shipment.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-118**. This ticket: **P-07-09-119**.

## Root cause

`HardwareFulfilmentOperationalClassifier::stageAndAction()` treated still-valid courier options as “operator must select a courier.” After `POST …/courier` persisted a valid selection (`canCreate = true`), `nextAction` stayed `Select Courier`. The Dashboard modal reopened the same dialog and never rendered Create Shipment.

## Change

When the shipment is not yet created, next action order is:

1. `$ready->canCreate` → `$ready->actionLabel` (Create Shipment / Reconcile Shipment)
2. options exist and no valid selection → Select Courier
3. fetchable and no valid selection → Get Courier Options
4. else `$ready->actionLabel`

No Shiprocket, invoice, serial, parcel, AWB, pickup, or manifest changes.

## Tests

64 hardware classifier/courier/work-queue/dashboard/shipment tests passed (771 assertions). New coverage: selected courier advances to Create Shipment; options-only stays on Select Courier; no options asks Get Courier Options; reconcile/AWB unchanged; JSON select returns `next_action=Create Shipment` and the action dialog renders Create Shipment without calling create.

## Not performed in the implementation

Create Shipment for HF12 / RDE318434. AWB/label/pickup/manifest. Other orders. `deskd` full tree rsync. Vite rebuild.
