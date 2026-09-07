# Hardware serial allocation UI — RadiumDesk-P-07-09-40

**Date:** 2026-09-07  
**Prompt ID:** `RadiumDesk-P-07-09-40`  
**Mode:** Desk UI around the existing P-07-09-18 / P-07-09-32 allocation operation.

Ledger file `docs/cursor-prompt-log.md` does not exist. Authoritative ledger: `docs/cursor-prompt-ledger.md`. Last used ID: `RadiumDesk-P-07-09-39`. This ticket: **`RadiumDesk-P-07-09-40`**.

## Source of truth

Existing `HardwareSerialAllocationService::allocate()` / `searchAvailable()` remain authoritative. The UI does not invent a second stock allocator, invoice, shipment, AWB, or Box callback.

| Surface | Value |
|---------|--------|
| Page | `GET /inventory/hardware-fulfilments/{fulfilment}` |
| Search | `GET /inventory/hardware-fulfilments/{fulfilment}/serials/search` |
| Allocate | `POST /inventory/hardware-fulfilments/{fulfilment}/serials` |
| Permission | `hardware.fulfilment.operate` via `HardwareFulfilmentAccess` (also requires `inventory.view`) |

## Operator contract

Avinash (or any user with the existing hardware-fulfilment permission) can:

1. Open a fulfilment and see order, commerce number, support reference, product/SKU/model, required quantity, and state.
2. Search available Desk serials server-side. Results include the serial's physical branch.
3. Select exactly the required quantity (one serial for the current one-unit test).
4. Confirm Product / SKU / Serial / Physical branch / Quantity.
5. Submit. The allocation transaction locks stock, marks sold, writes `SERIALS_ALLOCATED`, and derives `fulfilment_branch_id` from `inventory_serials.branch_id`.

The operator cannot choose or override the physical branch. HTTP `claimed_branch` is prohibited. Refresh/retry of the same serials is idempotent.

## Safety

This page does not issue invoices, create Shiprocket shipments, assign AWBs, or send RadiumBox callbacks. The existing allocation transaction may persist a pending P6 `hardware.box.callback` outbox row; this UI does not process or deliver it. Shipment/AWB routes remain for later isolated steps and are not shown on the allocation page.

RDE318421 was not allocated by this prompt. Frozen seven / RDE318400 are unchanged.
