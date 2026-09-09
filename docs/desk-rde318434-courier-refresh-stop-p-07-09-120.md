# HF12 courier refresh and Create Shipment gate — RadiumDesk-P-07-09-120

**Date:** 2026-09-09  
**Prompt ID:** `RadiumDesk-P-07-09-120`  
**Mode:** Operate existing Get Courier Options + Select Courier on HF12 / RDE318434 only. Stop before Create Shipment.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-119**. This ticket: **P-07-09-120**.

## Preflight

HF12 / RDE318434: `invoice_issued`, INV-67536, serial 10553584, parcel 0.24 kg / 14×9×7 cm, DELHI-RETAIL / RADDELHI, Prepaid, destination India. Shipment/AWB null. Cached options expired (`canFetch=true`, next **Get Courier Options**). Prior selection 15084 / Delhivery_Surface still stored but not valid.

## Operations

1. `HardwareShipmentCourierOptionsService::fetch()` as user 2 — same path as Dashboard Get Courier Options. 10 returned options with `courier_company_id` values. **15084 / Delhivery_Surface · 84** present. Recommended 15106 Blue Dart Advantage Surface. Fetch cleared selected courier (existing behaviour).
2. `select(15084)` as user 2 — same path as Dashboard Select Courier. Persisted `15084` / `Delhivery_Surface`.

## After

| Check | Result |
|-------|--------|
| `canCreate` | true |
| `next_action` | **Create Shipment** |
| Action dialog | `hardware-action-create-shipment-form` present; Select Courier form absent; Delhivery_Surface displayed |
| State | `invoice_issued` |
| Shipment / AWB | null / none |
| Global shipments | still 1 (HF1 / RDE318421 only) |
| Create Shipment click | **NO — Not performed** |

Options expire **2026-09-09 10:52:57 IST**. Other fulfilments `updated_at` unchanged.

## Not performed

Create Shipment, Shiprocket create-order, AWB, label, pickup, manifest, serial/invoice/parcel/payment edits, other orders, application code, `deskd`.
