# Isolated one-order hardware fulfilment — RadiumDesk-P-07-09-32

Date: 2026-09-07  
Repository: `/Users/ravi/RadiumWebsites/radium-desk-pos-release`  
Branch: `main`  
Before SHA: `4c2d28e73aa2740328c1d7b9101c56ec4d86c392`

## What this adds

Operator-only artisan path for **exactly one** explicit identifier:

```bash
php artisan desk:fulfil-hardware <RDE-source-id-or-fulfilment-id>
  --dry-run
  --step=status|ingest|ready|allocate|invoice|ship|awb|shipped|sync
  --through=ready|allocate|invoice|ship|awb|shipped|sync
  --serials=SN1,SN2
  --payload=/path/to/one-handoff.json
  --actor=<user-id>
  --live-shipping
  --force-callback
```

It never discovers a batch. Missing id, `all`, `*`, or comma-separated ids fail closed.

## Gates

- Paid commerce `payment_status=paid`
- Business `ordered_at >= 2026-09-05 00:00:00 Asia/Kolkata` (created_at is not substituted)
- Hardware physical line / mapped `model_id`
- Not already shipped/synced (except status/sync)
- Owner-HOLD: `RDE318438` plus `HARDWARE_FULFILMENT_HOLD_SOURCE_IDS`
- Frozen seven unchanged
- `RDE318400` blocked until separately authorized
- Existing locks/idempotency on allocate, invoice, shipment, callback outbox

## Isolated writers

- `INGESTED → READY_FOR_FULFILMENT` via `HardwareFulfilmentWorkflowService::markReady()`
- Hardware invoice via `HardwareFulfilmentInvoiceService` only (Finance Issue is not used)
- `HttpShiprocketGateway` implements the existing contract
- Global bind remains `NullShiprocketGateway` unless `shipping.enabled` + `provider=shiprocket` + `SHIPROCKET_HTTP_ENABLED` + credentials
- Isolated `--live-shipping` instantiates HTTP for that process only; credentials are required and never invented
- `AWB_ASSIGNED → SHIPPED` via `markShipped()` only when fulfilment AWB, provider AWB, and shipment AWB match
- Isolated `--force-callback` sends pending `hardware.box.callback` rows for that fulfilment only
- `POST /api/desk/fulfilment-status` exists and is **disabled** (`HARDWARE_FULFILMENT_CALLBACK_INBOUND_ENABLED=false`)

## Safety left off

| Flag / path | State |
|---|---|
| Channel ingest enable | unchanged / off |
| Cashfree correlate | false |
| Auto-issue invoice | false |
| Worker mint | false |
| `SHIPROCKET_ENABLED` / `SHIPROCKET_HTTP_ENABLED` | false |
| Box callback enabled | false |
| Inbound fulfilment-status | false |
| `outbox:process` | unchanged; no `--force`; workflow does not call it |

## Not performed

- Production workflow against a real order
- RDE318400 / handoff 30
- RDE318438
- Frozen seven
- Pre-5-Sep orders
- Global Shiprocket / Box callback / ingest enable
- Push, deploy, migrate, `.env` change

## Tests

Focused: `HardwareFulfilmentIsolatedOneOrderTest` + `HttpShiprocketGatewayTest`.  
Related P1–P6 + P0-M2 also passed after the change.
