# Hardware Allocate Serial popup — RadiumDesk-P-07-09-110

**Date:** 2026-09-09  
**Prompt ID:** `RadiumDesk-P-07-09-110`  
**Mode:** Client-side popup only. Existing `HardwareSerialAllocationService::allocate()` payload unchanged.

## Change

The Operations **Allocate Serial** modal now uses the same line-based contract as the fulfilment show page:

- One picker per physical `commerce_order_item_id`
- Exact qty per line
- Selected list with Remove
- Cross-line duplicate block
- Mixed Delhi/Mumbai block
- Submit disabled until every line is complete
- Payload remains `serials[itemId][]`

Qty 1 / one product stays compact (`Allocate Serial`). Multiple units or lines use `Allocate Serials`.

## Not changed

Allocator service, schema, invoice, shipment, parcel, inventory, production data.
