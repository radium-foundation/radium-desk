# Manual Shiprocket courier workflow — RadiumDesk-P-07-09-66

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-66`  
**Mode:** Implementation. No live Shiprocket. No RDE318421 write. No deploy.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-65**. This ticket: **P-07-09-66**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

---

## 0. Repository verification (before modify)

| Item | Value | Class |
|------|-------|-------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Branch | `main` = `origin/main` | VERIFIED |
| Before SHA | `20a0d5bdd8738cb45679c39fef031f22fc05d737` | VERIFIED |
| Worktree | Unrelated statutory/search docs dirty; not committed | VERIFIED |
| Production overlay | P-07-09-65 snapshot/country on `32b97ece` | VERIFIED |
| Bound gateway | `NullShiprocketGateway` unless enabled + provider + http + credentials | VERIFIED |

---

## 1. Shiprocket contract discovered from the project

Do not assume a public Shiprocket brochure. Sources: Desk `HttpShiprocketGateway`, Desk mapper, historical Admin `ShipRocketController` (the P-07-09-35 production write path).

| Capability | Mechanism | Class |
|------------|-----------|-------|
| Auth | `POST /auth/login` email/password | VERIFIED |
| Shipment create | `POST /orders/create/adhoc` — no courier id in Desk mapper or Admin `GenrateOrder` | VERIFIED |
| Search / reconcile | `GET /orders?search=` | VERIFIED |
| Courier options | Admin `FetchCourier`: `GET /courier/serviceability/?pickup_postcode=&delivery_postcode=&order_id=&cod=&weight=` | VERIFIED |
| Courier fields Admin stored | `courier_company_id`, `courier_name`, `freight_charge`, `coverage_charges` | VERIFIED |
| Provider recommendation | Admin does **not** read a recommended id | VERIFIED |
| Official recommendation keys | Parsed only if present: `recommended_courier_company_id`, `shiprocket_recommended_courier_id`, or a row `recommended` flag. Not ranked locally. | INFERRED from response-shape parsing; live presence UNKNOWN |
| Courier selection | Admin selects after create; passes `courier_id` to AWB | VERIFIED |
| Create with courier | Desk/Admin adhoc payload has no courier field | VERIFIED — courier is **not** required by create/adhoc |
| AWB | `POST /courier/assign/awb` with `shipment_id` and optional `courier_id` | VERIFIED |
| Pickup postcode | Admin used `radium_branch.pincode`. Desk branches have **no** pincode column | VERIFIED |
| Serviceability **before** create (no `order_id`) | Same Admin endpoint; `order_id` omitted until a provider order exists | INFERRED |
| Live adhoc requires `SHIPROCKET_CHANNEL_ID` | Mapper omits empty. Admin hardcoded a channel id. Live requirement | UNKNOWN |
| Default / best courier ranking | Not in Admin; not invented | VERIFIED absent |

Desk now implements `ShiprocketGateway::listCourierOptions()` against that serviceability path.

---

## 2. Operating model implemented

Workspace remains `/inventory/hardware-fulfilments`. No `/inventory/shipments` route.

1. Admin opens one fulfilment.
2. `inspect()` blockers are the authority. Courier UI is hidden unless local gates + provider + pickup nickname + pickup postcode pass.
3. **Get Courier Options** (explicit POST) calls Shiprocket serviceability using server-derived pickup postcode, delivery pincode, weight, `cod=0` (hardware mapper is Prepaid).
4. Options are stored on the fulfilment. If Shiprocket returns a recommendation flag/id, that option is labeled **Shiprocket Recommended**. Otherwise the page says Shiprocket returned options without a recommendation.
5. Admin selects one returned courier id. Arbitrary ids are rejected.
6. Options expire (`SHIPROCKET_COURIER_OPTIONS_TTL_SECONDS`, default 900) and are invalidated when pickup/parcel/country/pincode/provider order fingerprint changes.
7. **Create Shipment** requires `inspect().canCreate` **and** a fresh selected courier. Create still uses adhoc without sending courier id. The selected courier is copied onto the local `shipments` row.
8. Explicit confirm + existing lock / search-before-create / no retry of `provider_rejected`.
9. **Assign AWB** is shown only after a bound shipment, `shipment_created`, no AWB, and a stored courier. Lifecycle matches Admin. AWB values are never typed.

No automatic create. No automatic AWB.

---

## 3. Configuration (still disabled)

| Key | This ticket |
|-----|-------------|
| `SHIPROCKET_ENABLED` / `PROVIDER` / `HTTP_ENABLED` | Unchanged. Default false / none / false |
| `SHIPROCKET_CHANNEL_ID` | Still empty. Not invented |
| `SHIPROCKET_PICKUP_PINCODE_DELHI` / `_MUMBAI` | New. Empty. Courier options fail closed until set |
| `SHIPROCKET_COURIER_OPTIONS_TTL_SECONDS` | New. Default 900 |
| Production `.env` | **Not written** |

Live HTTP bind still requires enabled + provider `shiprocket` + `http_enabled` + email/password.

---

## 4. Permissions

Shipment / courier / AWB stay behind `hardware.fulfilment.operate`.

`hardware_team` **already** has that permission (serial allocation). This ticket does **not** add a grant and does **not** invent `hardware.fulfilment.ship`.

If product wants courier/create/AWB to be Admin-only, a new permission granted only to admin-team is justified. **Not changed here.**

Packaging verify and country overlay permissions unchanged.

---

## 5. Isolated CLI

`desk:fulfil-hardware --step=ship` now fails closed until a current courier selection exists on the fulfilment. Isolated does **not** auto-pick a courier.

---

## 6. RDE318421 / production data

**Not modified.** No snapshot attach, country write, Shiprocket call, shipment, or AWB.

The workspace will support that order once parcel snapshot, country overlay, pickup postcodes, and live Shiprocket enablement are separately authorized.

---

## 7. Tests / validation

Fake/Null only. `Http::preventStrayRequests()` in UI/workflow tests.

Coverage: readiness block, courier fetch, recommendation present/absent, selection, arbitrary id, stale fingerprint, create with selected courier, client courier_id prohibited, double-submit + timeout search-before-create, AWB persist/display, permission 403, list filters, HTTP serviceability parsing.

Hardware fulfilment suite + `HttpShiprocketGatewayTest`: **173 passed**, 1 skipped. Pint on dirty PHP.

---

## 8. Not performed

Enable live Shiprocket. Write production `.env` pickup postcodes. Deploy. Create RDE318421 shipment/AWB. Invent channel id or warehouse pins. Broaden permissions. Change payment/invoice/serial/ingest/`commerce_orders.parcel`.
