# Hardware Dashboard fulfilment navigation — RadiumDesk-P-07-09-67

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-67`  
**Mode:** Implementation. No ingest, shipment, Shiprocket, or RDE318421 writes. No deploy.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-66**. This ticket: **P-07-09-67**.

---

## Navigation

Hardware Dashboard remains a support-case queue. Row click still opens Customer 360.

When a Desk order resolves to an existing `HardwareFulfilment` **and** `HardwareFulfilmentAccess` allows the user **and** branch scope would allow show:

- Row shows `Fulfilment / Shipment` → `inventory.hardware-fulfilments.show`
- 360 Related menu shows the same destination after `Open Order`

No `/inventory/shipments` or `/fulfilment/shipments` route was added.

## Resolution

1. `hardware_fulfilments.support_order_id = orders.id`
2. else `hardware_fulfilments.source_id = orders.order_id`

No record → no link. No invented ID. No create from the dashboard.

## Permissions

Unchanged. Link hidden without `inventory.view` + `hardware.fulfilment.operate`. Existing `InventoryBranchScope` on show is unchanged; the dashboard/360 link is also withheld when that scope would deny.
