# Fulfilment parcel snapshot + country overlay — RadiumDesk-P-07-09-64

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-64`  
**Mode:** Implementation. No live Shiprocket. No production data write. No deploy.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-63**. This ticket: **P-07-09-64**.

Implements P-07-09-62 snapshot contract and the minimum P-07-09-63 Hardware UI.

---

## Git (before)

| Item | Value |
|------|-------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` |
| Remote | `git@github.com:radium-foundation/radium-desk.git` |
| Branch | `main` |
| Before SHA | `b6b6568c3921ba1a7048ab6166bf50631e24fe64` |

---

## What landed

1. Additive `hardware_fulfilments` columns: `parcel_snapshot`, `shipping_country_overlay`, `_at`, `_by_user_id`, `_context`.
2. `HardwareFulfilmentParcelSnapshotService` — qty=1, one physical SKU, verified kg/cm catalog pack, incomplete ingest parcel, no bound shipment. Idempotent. Catalog edit does not replace. GET/inspect never writes.
3. Eligibility precedence: complete ingest parcel → fulfilment snapshot → fail closed.
4. Create Shipment attaches inside the existing lock via `attachIfEligible()`, then `require()`.
5. `shipments.create_snapshot` now copies parcel + source + country used at open.
6. Admin-only `hardware.fulfilment.correct-country` fill-if-absent overlay. Not granted to `hardware_team`. Does not rewrite ingest JSON or billing.
7. Create-shipment request **prohibits** `country` as well as parcel/pickup/branch/provider ids.
8. Hardware show page renders `inspect()`: payment, country missing, parcel source, catalog pack (labeled catalog), AWB, blockers, attach + country actions.

`commerce_orders.parcel` is never written. Shiprocket flags stay off. Null gateway remains the default bind.

---

## Production write gate — STOPPED

Do **not** run these against production until a later authorized step. They are the exact mechanisms after this code is deployed and migrated.

### 1. Attach product-28 pack to fulfilment 1

UI (user with `hardware.fulfilment.operate`):

`GET /inventory/hardware-fulfilments/1` → **Attach parcel snapshot** (empty POST, no dimensions).

Tinker / service (same rules; no catalog live-read at later create):

```php
$actor = \App\Models\User::query()->findOrFail(/* authorized operator id */);
$fulfilment = \App\Models\HardwareFulfilment::query()->findOrFail(1);
app(\App\Services\HardwareFulfilment\HardwareFulfilmentParcelSnapshotService::class)
    ->attachFromCatalog($fulfilment, $actor);
```

Expected copy: packaging for product 28 (0.240 kg / 14×9×7 cm). Order 369 `parcel` stays NULL.

### 2. Set country for RDE318421

UI (user with `hardware.fulfilment.correct-country`):

`GET /inventory/hardware-fulfilments/1` → type the country → **Record country**.

Service:

```php
app(\App\Services\HardwareFulfilment\HardwareFulfilmentCountryCorrectionService::class)
    ->correct($fulfilment, 'India', $actor); // only if that exact string is authorized
```

Do not infer India. The string must be supplied at write time.

### 3. Re-run inspect

```php
app(\App\Services\HardwareFulfilment\HardwareShipmentEligibility::class)
    ->inspect(\App\Models\HardwareFulfilment::query()->findOrFail(1));
```

Or reload the show page. Create Shipment must stay hidden while Shiprocket is disabled / Null-bound, even after snapshot + country.

---

## Validation (this ticket)

- Hardware fulfilment tests: 161 passed, 1 skipped
- Focused snapshot / country / shipment UI: 22 passed
- Pint: passed
- `php -l` on new/changed services + controller: passed
- PHPStan/Larastan: not configured — not run
- Secrets: no credentials added

---

## Not performed

- Create Shiprocket shipment: **NO — Not performed.**
- Enable live shipping / bind HTTP / set `SHIPROCKET_CHANNEL_ID`: **NO — Not performed.**
- Assign AWB: **NO — Not performed.**
- Production attach / country write for RDE318421: **NO — Not performed.**
- Modify `commerce_orders.parcel` / ingest / invoice / serial / payment / callback: **NO — Not performed.**
- Deploy: **NO — Not performed.**
