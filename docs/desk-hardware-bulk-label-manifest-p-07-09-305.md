# RadiumDesk-P-07-09-305 — Bulk Ready-to-Ship label + manifest PDF workflow

## Objective

Operators on the Hardware **Pickup** dashboard queue can select multiple fulfilments and:

1. **Download Labels** — one combined Shiprocket label PDF (`POST /courier/generate/label` with `shipment_id` array).
2. **Download Manifest** — one combined manifest PDF (`POST /manifests/generate` with `shipment_id` array).

## Non-goals preserved

- No shipment create, AWB assign, courier change, pickup request, or cancel from bulk actions.
- Single-order label/manifest routes unchanged.
- Classifier / Needs Action queue semantics unchanged — bulk download does not mark ready-for-pickup or clear Pickup queue rows.
- P-301/P-302 overlay SQL not modified on this branch (awb-reliability worktree).

## Implementation

| Area | Change |
|------|--------|
| Gateway | `generateLabelBatch()` / `generateManifestBatch()` on `ShiprocketGateway`; single-id methods delegate. |
| Service | `HardwareShipmentBulkDocumentsService` — per-id authorization, eligibility, ordered batch provider call, persist `label_url` / `manifest_url` only when previously empty. |
| HTTP | `POST inventory/hardware-fulfilments/bulk/labels` and `.../bulk/manifest`. |
| UI | Pickup queue selection bar: Download Labels / Download Manifest; checkboxes use fulfilment ids; select-all visible page only. |

## Needs Action / Shipping semantics (verified)

Production P-293/P-302 (`matchesWorkspaceFilter` on overlay branch) defines:

| Operator surface | Label-pending | Manifest-pending |
|------------------|---------------|------------------|
| **Needs Action** (`needs_action`) | **No** — only mapping / awaiting serial / AWB pending / package photo | **No** |
| **Shipping** (`shipping`) | **Yes** — `LabelPackingPending` | **Yes** — `PickupManifestPending` (and later pickup stages) |

This branch (pre-overlay) maps the same classifier stages to dashboard queues **Ready** (label pending) and **Pickup** (manifest / pickup work). Bulk actions follow that navigation:

- **Download Labels** — Ready + Pickup queues (label pending or re-download while still in shipping workflow).
- **Download Manifest** — Pickup queue only (pickup requested).

There is no persisted `label_downloaded_at` / `manifest_downloaded_at`; download GETs do not mutate state. Bulk POST uses Shiprocket generate (same as single-order generate) and only persists URLs when empty. It does **not** set `ready_for_pickup_at` or move rows to Completed.

Generating a label advances `LabelPackingPending` → `PickupManifestPending` (existing single-order behavior), keeping the row in Shipping / Pickup — not incorrectly clearing work.

## Tests

- `tests/Feature/HardwareFulfilment/HardwareFulfilmentBulkDocumentsTest.php`
- `tests/Feature/Shipping/HttpShiprocketGatewayTest.php` (batch payload assertions)
- `tests/js/hardware-dashboard-selection.test.js`
