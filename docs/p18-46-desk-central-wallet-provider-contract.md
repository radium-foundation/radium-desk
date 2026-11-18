# P-18-46 — Desk Central Wallet provider contract (three spokes)

**Authority:** Radium Desk **v4.1.91** / **`966567336`** (re-verified 2026-11-18 read-only).

## HTTP access

| Requirement | Value |
|-------------|--------|
| Preferred consumer route (KVM) | `CENTRAL_WALLET_API_BASE_URL=http://127.0.0.1` |
| Virtual host | `CENTRAL_WALLET_API_HOST_HEADER=desk.radiumbox.com` |
| Public HTTPS | `https://desk.radiumbox.com/api/central-wallet/v1/*` (Bearer required; P-18-24 path ACL **not** deployed) |

## Authentication

- Header: `Authorization: Bearer <integration_token>` (per-spoke secret; not in git).
- Header: `X-Site-Code` ∈ `{radiumbox.com, rdservice.in, rdservice.net}`.

## Refund execution (Desk)

| Config key | Prod (2026-11-18) |
|------------|-------------------|
| `desk_first_enabled` | **true** |
| `desk_first_spoke_allowlist` | **rdservice.in, radiumbox.com, rdservice.net** |

Desk posts authoritative ledger credit first, then spoke audit with `central_wallet_ledger_entry_id`.

## Spoke audit callback

`POST /api/integrations/v1/wallet-refunds` on each spoke (Desk integration Bearer).

Required when desk-first: `source_system=radium_desk`, `desk_refund_reference`, `order_id`, `amount`, `currency`, **`central_wallet_ledger_entry_id`**.

## Idempotency

Desk ledger: `business_reference` unique constraint + replay on race (P-18-43). Spoke audit: reference-keyed idempotent rows.

**No Desk API changes in P-18-46.**