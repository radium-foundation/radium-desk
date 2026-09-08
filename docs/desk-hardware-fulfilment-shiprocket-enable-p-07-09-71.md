# Enable production Shiprocket provider — RadiumDesk-P-07-09-71

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-71`  
**Type:** Production configuration enablement only. No application overlay. No shipment create. No AWB. No courier selection. No parcel attach.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-70**. This ticket: **P-07-09-71**.

Credential values are not recorded in this file.

---

## Verdict

**Shiprocket is enabled and usable on Radium Desk production.** HTTP gateway is bound. Authentication succeeded. Read-only serviceability returned courier options. **RDE318421 remains unshipped.** Parcel snapshot is still NULL. Create Shipment / Get Courier Options / Select Courier / Assign AWB stay gated by existing readiness.

Did **not** create a shipment. Did **not** assign an AWB. Did **not** select a courier. Did **not** attach a parcel snapshot.

---

## Git / production boundary (before change)

| Item | Value | Class |
|------|-------|-------|
| Project | Radium Desk | VERIFIED |
| Repository | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Branch | `main` = `origin/main` | VERIFIED |
| Local / origin HEAD | `1575ed35c4905c7dfd24377321d85d8d497dfc87` | VERIFIED |
| Worktree | Unrelated statutory docs dirty + untracked investigation markdown. Application tree not used. | VERIFIED |
| Server | KVM `srv1910783` / `187.127.129.16` | VERIFIED |
| Hostname | `srv1910783` / `srv1910783.hstgr.cloud` | VERIFIED |
| App path | `/var/www/radium-desk` | VERIFIED |
| PHP | `/usr/local/lsws/lsphp84/bin/php` | VERIFIED |
| Database | `radium_desk` @ `127.0.0.1` | VERIFIED |
| Public URL | `https://desk.radiumbox.com` | VERIFIED |
| `release.json` | ABSENT (overlay-on-`v4.0.67` / `5d14a582`) | VERIFIED |
| Binding before | `NullShiprocketGateway`; `enabled=false`; `provider=none`; `http_enabled=false` | VERIFIED |
| Official `./tools/desk deploy` | **NO — Not performed.** Dirty tree, untagged HEAD, rsync `--delete`, unscoped `migrate --force` would run UPI `220000` / `220100` / `220200`. | VERIFIED |
| UPI migrations | still Pending | VERIFIED |
| `130000` / `150000` | batch 11 / 12 Ran | VERIFIED |

No other project was inspected or modified.

---

## Shiprocket configuration inventory

| Item | Finding | Class |
|------|---------|-------|
| Base URL | Config default `https://apiv2.shiprocket.in/v1/external`. `SHIPROCKET_BASE_URL` KEY_ABSENT in `.env`. | VERIFIED |
| Auth endpoint | `POST /auth/login` (email + password only) | VERIFIED |
| Credential source | Production `/var/www/radium-desk/.env` → `config/shipping.php` | VERIFIED |
| API email | PRESENT_NON_EMPTY | VERIFIED |
| API password | PRESENT_NON_EMPTY | VERIFIED |
| `SHIPROCKET_CHANNEL_ID` | KEY_ABSENT before and after | VERIFIED |
| Shipping enabled (before) | false (`SHIPROCKET_ENABLED` KEY_ABSENT) | VERIFIED |
| Provider (before) | `none` (`SHIPROCKET_PROVIDER` KEY_ABSENT) | VERIFIED |
| HTTP flag (before) | false (`SHIPROCKET_HTTP_ENABLED` KEY_ABSENT) | VERIFIED |
| HTTP bind rule | `enabled` + `provider=shiprocket` + `http_enabled` + email/password. Channel id **not** in the bind predicate. | VERIFIED |
| Null gateway | Bound when HTTP bind is false | VERIFIED |
| Pickup Delhi | `RADDELHI` | VERIFIED |
| Pickup Mumbai | `RADIUMUM` | VERIFIED |
| Delhi pincode | `110019` | VERIFIED |
| Mumbai pincode | `400104` | VERIFIED |
| Timeout | 15s (env KEY_ABSENT; config default) | VERIFIED |
| Connect timeout | 5s (env KEY_ABSENT; config default) | VERIFIED |
| Courier options TTL | 900s (env KEY_ABSENT; config default) | VERIFIED |
| Serviceability | `GET /courier/serviceability/` | VERIFIED |
| Shipment create | `POST /orders/create/adhoc` | VERIFIED |
| AWB assign | `POST /courier/assign/awb` | VERIFIED |

---

## Channel ID

| Question | Answer | Class |
|----------|--------|-------|
| Required to bind `HttpShiprocketGateway`? | No | VERIFIED |
| Required for `POST /auth/login`? | No. Auth succeeded with KEY_ABSENT. | VERIFIED |
| Required for `GET /courier/serviceability/`? | No. Serviceability listed options with KEY_ABSENT. | VERIFIED |
| Included in Desk create payload when empty? | No. `HardwareShipmentMapper` + `ShiprocketCreateOrderRequest::toAdhocPayload()` omit empty `channel_id`. Operator requests prohibit posting `channel_id`. | VERIFIED |
| Required by live Shiprocket create/adhoc for this account? | Not tested. This ticket forbids create. | UNKNOWN |
| Value located in Desk production config? | KEY_ABSENT. Not invented. Not copied from another project. | VERIFIED |

Enablement proceeded because the live **provider enablement / auth / serviceability** path does not require a channel id. Create-time requirement remains a **later** risk, not an enablement blocker.

---

## Configuration change

Mechanism: append-only production `.env` (same documented secret path as P-07-09-35 / P-07-09-68). Then `artisan optimize:clear` + `optimize`.

Appended only:

- `SHIPROCKET_ENABLED=true`
- `SHIPROCKET_PROVIDER=shiprocket`
- `SHIPROCKET_HTTP_ENABLED=true`

Not changed: credentials, channel, pickups, pincodes, timeouts, base URL, invoice/payment/serial/order/parcel/fulfilment rows.

| Item | Before | After |
|------|--------|-------|
| `SHIPROCKET_ENABLED` | KEY_ABSENT → config false | `true` |
| `SHIPROCKET_PROVIDER` | KEY_ABSENT → config `none` | `shiprocket` |
| `SHIPROCKET_HTTP_ENABLED` | KEY_ABSENT → config false | `true` |
| Gateway | `NullShiprocketGateway` | `HttpShiprocketGateway` |
| Channel | KEY_ABSENT | KEY_ABSENT |
| Credentials | PRESENT_NON_EMPTY | PRESENT_NON_EMPTY (unchanged) |

`.env` length delta +166 bytes. Application PHP / Blade SHA-256 for the P-07-09-70 show page still MATCH `d1385752`.

---

## Read-only provider verification

| Test | Result | Class |
|------|--------|-------|
| `HttpShiprocketGateway::acquireToken()` | `OK_TOKEN_PRESENT` | VERIFIED |
| In-process `listCourierOptions()` | `STATUS=listed`; 8 options; recommendation PRESENT; not retryable | VERIFIED |
| Inputs | pickup `110019`; delivery pincode from stored structured address `721130`; weight `0.24` (verified catalog, not attached); `cod=0`; no `order_id` | VERIFIED |
| Persist to fulfilment | `courier_options_snapshot` still NULL | VERIFIED |
| `POST /orders/create/adhoc` | **NO — Not performed.** | VERIFIED |
| `POST /courier/assign/awb` | **NO — Not performed.** | VERIFIED |
| Fulfilment `courier-options.store` | **NO — Not performed.** Would persist a quote. | VERIFIED |

---

## Application / readiness gates after enablement

`HardwareShipmentEligibility::inspect()` for fulfilment `1` (user 2 Avinash Jha / `admin,hardware_team`):

| Field | After |
|-------|-------|
| Country | India |
| Status | Not created |
| Courier | null / UI **Not selected** |
| AWB | null / UI **Not assigned** |
| Blockers | `Parcel packaging not attached` only |
| `Shipping is not enabled` | gone |
| `canFetchCourierOptions` | false |
| `canSelectCourier` | false |
| `canCreate` | false |
| `canAssignAwb` | false |
| `canAttachSnapshot` | true (form visible; **not submitted**) |

Kernel GET `/inventory/hardware-fulfilments/1` as user 2: **200**. Visible copy: Country India; Parcel Not attached; Shipment Not created; Courier Not selected; AWB Not assigned; invoice `INV-67275`; serial `10532319`; Attach verified packaging present. Operator labels **Get Courier Options / Select Courier / Create Shipment / Assign AWB** absent. Button IDs exist only in defensive JS that no-ops when the forms are missing.

Enabling Shiprocket did **not** bypass the parcel readiness gate.

Public: `/up` 200; `/login` 200; unauth show 302 → `/login`; CF `DYNAMIC`; `no-cache, private`. Interactive Cloudflare session: **NO — Not performed.**

---

## RDE318421 data (before = after)

| Field | Value |
|-------|-------|
| Fulfilment | `1` |
| Source | `RDE318421` |
| State | `invoice_issued` |
| Serial | `10532319` |
| Invoice | `294` / `INV-67275` |
| Parcel snapshot | NULL |
| Country overlay | NULL |
| Structured country | KEY_ABSENT |
| Order parcel | NULL |
| Shipment | none |
| AWB | none |
| Selected courier | none |
| Courier snapshot | NULL |
| `updated_at` | `2026-09-08 09:59:30` |

---

## Logs

`storage/logs/laravel.log` is large. Last ~2 MB after enablement:

| Check | Result |
|-------|--------|
| `/orders/create/adhoc` | 0 |
| `/courier/assign/awb` | 0 |
| `/auth/login` / `serviceability` / `shiprocket` strings | 0 (in-process HTTP is not written to this log) |
| Hardware fulfilment ERROR | 0 |
| Credential / Bearer / password leakage | 0 |
| Unrelated Bonvoice outbox ERRORs | 14:04 / 14:08 IST; before this change |

---

## Backup / rollback

Backup: `/var/www/radium-desk/storage/app/private/overlays/p-07-09-71-20260908T085433Z/env-before.env`

`desk rollback` remains disabled on KVM.

To restore the previous disabled state only:

1. Copy `env-before.env` over `/var/www/radium-desk/.env` (or remove the three appended keys).
2. `php artisan optimize:clear && php artisan optimize`.

Do not restore unrelated overlay directories. Rollback was **not** required.

---

## Not performed

- Application code change / overlay: **NO — Not performed.**
- `./tools/desk deploy`: **NO — Not performed.**
- Any migration, including UPI `220000` / `220100` / `220200`: **NO — Not performed.**
- Vite build: **NO — Not performed.**
- `.env` committed to Git: **NO — Not performed.**
- `SHIPROCKET_CHANNEL_ID` set: **NO — Not performed.**
- Parcel snapshot attach: **NO — Not performed.**
- Country overlay / structured country write: **NO — Not performed.**
- Order parcel write: **NO — Not performed.**
- Courier select for RDE318421: **NO — Not performed.**
- Create shipment / Shiprocket create/adhoc: **NO — Not performed.**
- Assign AWB / Shiprocket assign/awb: **NO — Not performed.**
- Persist courier-options snapshot: **NO — Not performed.**
- New `/inventory/shipments` namespace: **NO — Not performed.**
- Permission `hardware.fulfilment.ship`: **NO — Not performed.**
- New tag: **NO — Not performed.**
- Inspection or reuse of Admin / RadiumBox / rdservice Shiprocket config: **NO — Not performed.**

---

## Remaining risks / blockers

1. `SHIPROCKET_CHANNEL_ID` is still KEY_ABSENT. Live create/adhoc may still reject an omitted channel id. **UNKNOWN** until an authorized create is attempted.
2. RDE318421 still cannot fetch courier options or create a shipment until the parcel snapshot is attached by an authorized operator.
3. Official `./tools/desk deploy` remains unsafe (dirty tree, untagged HEAD, pending UPI migrations).
