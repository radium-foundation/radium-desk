# Isolated hardware fulfilment production readiness — RadiumDesk-P-07-09-33

Date: 2026-09-07  
Type: Readiness / configuration gate only. No real order processed.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

---

## Verdict

**NOT READY** for isolated live fulfilment.

Stop conditions hit:

1. Production Desk does not have `desk:fulfil-hardware` (`caec412f` is local only).
2. Production Shiprocket API email/password are **KEY_ABSENT**. Values were not invented.
3. radiumbox.com has **no** `POST /api/desk/fulfilment-status` receiver.

No production `.env` keys were written. Global flags were left off.

---

## 1. Git / repositories

### Radium Desk — VERIFIED

| Item | Value |
|------|--------|
| Path | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` |
| Branch | `main` |
| HEAD | `caec412f9c6f3d176291b71abcc5839ccab534ee` |
| Remote | `git@github.com:radium-foundation/radium-desk.git` |
| `origin/main` | `e4c3aec328a2a132d3763c115aeb259021bc2585` |
| Ahead | 10 local commits |
| Worktree | Unrelated statutory/search docs dirty; isolated path commit is clean |

### radiumbox.com — VERIFIED

| Item | Value |
|------|--------|
| Path | `/Users/ravi/RadiumWebsites/radiumbox.com` |
| Branch | `launch/real-production` |
| HEAD | `be3cab15cb26430ce5369ec29ea2770b8aa75a06` |
| Remote | `origin` GitHub; `makelinkit` push disabled |
| Ahead | 9 local commits vs `008dff8` |
| Worktree | `.DS_Store` + compiled Blade views only |

---

## 2. Production mapping

Same Hostinger KVM for both apps. **VERIFIED.**

### Radium Desk

| Item | Value | Class |
|------|--------|--------|
| Server | `srv1910783` / `187.127.129.16` | VERIFIED |
| Path | `/var/www/radium-desk` (no `.git`) | VERIFIED |
| DB | `radium_desk` | VERIFIED (prior gates + this host) |
| `release.json` | 4.0.67 / tag `v4.0.67` / build `5d14a582` / 2026-09-05 | VERIFIED |
| Isolated command | ABSENT (`There are no commands defined in the "desk" namespace.`) | VERIFIED |
| `markReady` / `markShipped` | ABSENT | VERIFIED |
| `HttpShiprocketGateway` | ABSENT | VERIFIED |
| P0-M2 `establishFulfilmentBranch` | PRESENT | VERIFIED |
| P5 search-before-create | PRESENT | VERIFIED |
| Public `/up` `/login` | 200 / 200 | VERIFIED |

### radiumbox.com

| Item | Value | Class |
|------|--------|--------|
| Server | same KVM8 `187.127.129.16` | VERIFIED |
| Path | `/var/www/radiumbox.com` (no `.git`) | VERIFIED |
| DB | `radiumbox_prod` | VERIFIED (prior gates + this host) |
| P7A builder | `DeskOutboxService.php` SHA `47acac7a…` contains `model_id` + `physical_merchandise` | VERIFIED |
| Stored handoffs | total 48, pending 48, payloads with `model_id` = 0 | VERIFIED |
| Isolated Box commands | `desk:deliver-handoff`, `desk:rebuild-handoff` present | VERIFIED |
| Global `desk:process-outbox` | inert unless `--force` | VERIFIED |

---

## 3. Global safety — VERIFIED intact

| Control | Production |
|---------|------------|
| Desk `outbox:process` | Exists; `--limit` only; 0 pending; 0 `hardware.box.callback` rows |
| Hardware callback HTTP | `NullBoxFulfilmentCallbackGateway`; enabled=false; URL EMPTY; secret EMPTY |
| Auto invoice | `channel_ingest.auto_issue_invoice=false`; POS auto-issue false |
| Worker mint | `statutory_invoices.worker_may_mint=false` |
| Cashfree hardware correlate | false |
| Shiprocket | `enabled=false`; `provider=none`; `NullShiprocketGateway` |
| Box ingest | `desk.enabled=false`; `DESK_BASE_URL` EMPTY; ingest secret EMPTY |
| Box vendor Shiprocket | `beta.shiprocket_enabled=false` |
| Channel ingest Box secret on Desk | KEY_ABSENT / NULL |

Desk `outbox:process` is not a `--force` no-op like Box. It is still safe: no hardware callback rows, Null gateway, callback flag off. **VERIFIED.**

---

## 4. Shiprocket configuration

Documented Desk mechanism: `.env` → `config/shipping.php`.

| Item | Result | Class |
|------|--------|--------|
| Credentials present | **NO** (`SHIPROCKET_API_EMAIL` / `PASSWORD` KEY_ABSENT) | VERIFIED |
| Credentials valid | UNKNOWN — cannot authenticate without credentials | UNKNOWN |
| Base URL | `https://apiv2.shiprocket.in/v1/external` (code default; env KEY_ABSENT) | VERIFIED default / not live-called |
| Auth | POST `/auth/login` email+password in `HttpShiprocketGateway` (local `caec412f` only) | VERIFIED in source |
| Delhi pickup | `RADDELHI` | VERIFIED |
| Mumbai pickup | `RADIUMUM` | VERIFIED |
| Timeouts | 15s / connect 5s (code defaults) | VERIFIED |
| Search-before-create | Production `HardwareShipmentService::shouldReconcileFirst` + `searchOrders` | VERIFIED |
| Isolated live path ready | **NO** | VERIFIED |

`SHIPROCKET_HTTP_ENABLED`, `SHIPROCKET_ENABLED`, and `SHIPROCKET_PROVIDER` were **not** written. Enabling them would be a global flag change and is forbidden here.

Old Admin / Box credential copies were not used. **VERIFIED** they were not copied. Whether another vault holds a Shiprocket login is **UNKNOWN**.

---

## 5. Box callback

| Item | Result | Class |
|------|--------|--------|
| `POST /api/desk/fulfilment-status` | **NO** — route list shows only `POST api/desk/invoice-status` | VERIFIED |
| HMAC on invoice-status | `X-Desk-Channel` + `X-Desk-Timestamp` + `X-Desk-Signature`; hex HMAC-SHA256 of `{timestamp}{rawBody}`; secret `DESK_CALLBACK_SECRET` falling back to ingest secret | VERIFIED in Box source |
| Production callback secret | EMPTY | VERIFIED |
| Production URL to configure on Desk | Not set; must not invent a receiver URL | VERIFIED |
| Expected fulfilment payload | Desk P6 `hardware.fulfilment.{state}` contract exists locally; Box does not accept it | VERIFIED |
| Production receiver ready | **NO** | VERIFIED |

`invoice-status` stores invoice display copies. It is **not** a fulfilment-status receiver. Do not treat it as one.

---

## 6. Isolated command on production

`php artisan desk:fulfil-hardware` is **ABSENT**.

Therefore production could not execute missing-id / `all` / wildcard / cutoff / HOLD / RDE318400 / dry-run checks against the live app.

Those gates exist only in local `caec412f` tests. **VERIFIED** locally in P-07-09-32; **ABSENT** on production.

No dry-run against a real production order was attempted.

---

## 7. Configuration changes

**None.** Required live values are missing or would enable global processing.

Not written:

- `SHIPROCKET_*` credentials or enable flags
- `HARDWARE_FULFILMENT_CALLBACK_*`
- `DESK_CALLBACK_SECRET`
- `CHANNEL_INGEST_SECRET_RADIUMBOX_COM`
- `DESK_BASE_URL` / `DESK_INGEST_ENABLED` on Box

---

## 8. Production mutation check

| Surface | Count after probe |
|---------|-------------------|
| `hardware_fulfilments` | 0 |
| `hardware_fulfilment_serials` | 0 |
| hardware-linked `shipments` | 0 |
| `commerce_orders` `radiumbox_com` | 0 |
| hardware callback outbox | 0 |
| Box pending handoffs | 48 (unchanged; not delivered) |

No invoice, serial, shipment, AWB, or shipped transition was performed.

---

## Remaining blockers (in order)

1. Overlay or otherwise deploy isolated Desk path (`caec412f`) without a full `desk deploy` / UPI migrate — **separate deploy gate**.
2. Owner-supplied Shiprocket API email/password in Desk `.env` **without** setting `SHIPROCKET_ENABLED=true`.
3. Implement and overlay Box `POST /api/desk/fulfilment-status` (or explicitly defer SYNCED), plus a dedicated callback secret. Do not reuse an invented URL.
4. Isolated Box payload rebuild + Desk ingest secret/URL remain a later ingest gate. Not authorized here.
5. Only then consider one-order processing of RDE318400. **Not this prompt.**
