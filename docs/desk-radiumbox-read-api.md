# RadiumBox Read API foundation

**Ticket:** RadiumDesk-P-04-09-04  
**Date:** 2026-09-04  
**Type:** Application foundation. Feature default **OFF**. No KVM8 grants created. Live HTTP Admin contract unchanged.

## Purpose

A controlled, authenticated, read-only Desk API and application service so New Admin (and later authorized agents) can look up RadiumBox repository data **without** merging that schema into `radium_desk` and **without** using `RadiumBoxClient`.

This is a scoped read-only access path. It is **not** a general direct-DB coupling and **not** a replacement for live Admin enrichment.

## Ownership

| Database | Role |
|----------|------|
| `radium_desk` | Desk operational source of truth. Unchanged. No RadiumBox tables are copied here. |
| `radiumbox_prod` | RadiumBox historical/current replica on KVM8. Desk never writes it. Desk never migrates it. |

The application/API layer is the only allowed boundary between them.

```
Radium Desk (auth + radiumbox.read)
    → RadiumBoxReadService / RadiumBoxReadRepository
    → named connection radiumbox_read (SELECT only)
    → radiumbox_prod
```

## Live vs read

| Path | Mechanism | Consumers |
|------|-----------|-----------|
| **LIVE** (unchanged) | `RadiumBoxClient` → `RADIUMBOX_BASE_URL` → `GET /api/search/order` | Desk order workspace, enrichment jobs, global-search fallback |
| **READ** (this ticket) | `RadiumBoxReadService` → Query Builder on `radiumbox_read` | This API now; History UI later; authorized agents later |

The read path **never** falls back to HTTP Admin. `config/radiumbox.php` and `RADIUMBOX_BASE_URL` are not reused. Missing or disabled read configuration returns 503, not a live lookup.

## Authentication and permission

- Routes use existing Desk `web` + `auth` + `active` middleware.
- There is no public unauthenticated RadiumBox API.
- Permission: **`radiumbox.read`** (`RolePermissionSeeder::PERMISSION_RADIUMBOX_READ`).
- Gate: `RadiumBoxReadAccess`.
- Granted to admin / operations_admin / superadmin only.
- **`orders.view` is not sufficient.** Agents with `orders.view` receive 403.

Use this same permission for the future History UI. Do not invent a second query path or a second replica connection.

## HTTP contract

Session-authenticated:

- `GET /api/v1/radiumbox/orders?identifier_type=&identifier=&page=&per_page=`
- `GET /api/v1/radiumbox/orders/{commercialId}` (`orders.id` only)

Additional read endpoints can be added beside these routes without changing the repository architecture.

`identifier_type` values are **not interchangeable**:

| Type | Column | Example |
|------|--------|---------|
| `commercial_id` | `orders.id` | `318017` |
| `ordercode` | `orders.ordercode` | `RD318017` |
| `rdservice_order_id` | `orders.rdservice_order_id` | `3510864` |
| `rd_id` | `order_rdservice.id` | `3510864` |
| `rdorderid` | `order_rdservice.rdorderid` | `RD3510864` |

`RD318017` is a commercial `ordercode`. `RD3510864` is an RD `rdorderid`. Looking up one type with the other value returns an empty page, not a guessed match.

Duplicate `orders.rdservice_order_id` groups return **all** commercial rows. Results are ordered by commercial id and paginated (`per_page` max 50).

Fail-closed:

| Condition | HTTP |
|-----------|------|
| Unauthenticated | 401 |
| Missing `radiumbox.read` | 403 `forbidden` |
| Feature disabled | 503 `radiumbox_read_disabled` |
| Missing/invalid DSN | 503 `radiumbox_read_misconfigured` |
| Unknown commercial id | 404 `not_found` |
| Invalid identifier | 422 |

## Response-field policy

Explicit allowlist only. No `SELECT *`. No generic SQL endpoint.

Included because they are justified by identifier lookup / History requirements:

- Commercial identifiers and status (`orders.id`, `ordercode`, `rdservice_order_id`, invoice code, payment/status, dates, branch, GST)
- RD identifiers, serial, product, website, AMC/RD service **names** (`amc_service_name`, `rd_service_name`)
- Customer name / phone / email / GST / company
- Invoice number + branch + service type
- Order lines (product name / product id / invoice code)
- Order history status events

Omitted as unjustified or sensitive:

| Field | Reason |
|-------|--------|
| `orders.userdetails` / `order_rdservice.userdetails` | Blob; not an allowlisted snapshot |
| `order_rdservice.paid_amount` | Owner/PII decision; not required for identifier lookup |
| `order_details.label` | Serial JSON / unrelated payload |
| Invoice file-path columns | Not needed for lookup |
| Wallets, stock, carts, OAuth, admin activity | Unrelated |

If a later History screen needs a field, add it to the allowlist and document why.

## Read-only behavior

- Query Builder only. No Eloquent models on replica tables.
- Application `beforeExecuting` guard allows `SELECT` / `PRAGMA` only and throws `RadiumBoxReadWriteAttemptException` before any write SQL runs.
- Connection name must not be the default Desk connection, and the MySQL/MariaDB database name must not be the Desk database.
- Production enablement still requires a **SELECT-only** MariaDB user. The application guard is not a substitute for least-privilege grants.

## Configuration

Default off. Dedicated DSN only — never `DB_DATABASE` / `DB_USERNAME` / the full-privilege `radiumbox_prod` user:

```
RADIUMBOX_READ_ENABLED=false
DB_RADIUMBOX_READ_CONNECTION=radiumbox_read
DB_RADIUMBOX_READ_DRIVER=mariadb
DB_RADIUMBOX_READ_HOST=
DB_RADIUMBOX_READ_PORT=3306
DB_RADIUMBOX_READ_DATABASE=
DB_RADIUMBOX_READ_USERNAME=
DB_RADIUMBOX_READ_PASSWORD=
```

PHPUnit forces the feature off and the DSN empty.

## KVM8 grant prerequisite (not created)

Production enablement requires a **SELECT-only** MariaDB user (suggested name `radiumbox_read`) on `radiumbox_prod` from the Desk host.

Do **not**:

- use the existing full-privilege `radiumbox_prod` user
- widen `radium_desk`
- create grants from this application ticket

Until that user exists, leave `RADIUMBOX_READ_ENABLED=false`.

## Audit

`radiumbox.read.lookup` and `radiumbox.read.show` on the viewer `User` via `AuditLogService`.

Stored: identifier type/column/value, counts, found flag. Not row payloads, passwords, tokens, or `userdetails`.

## History UI

The History UI is **not implemented** yet (P-04-09-03 was design only). When built, it must call `RadiumBoxReadService` — the same repository as this API. Do not add a second SQL path or reuse `RadiumBoxClient`.

## Future agent consumption

Agents should use this same authenticated contract (or a later token in front of these routes). Do not expose the replica, a generic query endpoint, or Desk DB credentials to agents.

## Security limitations

- Default OFF. Empty DSN. No production grant created.
- Application read-only guard is in-process; the DB user must also be SELECT-only.
- Session auth is Desk-user auth, not a public machine token.
- Phone / email / GST of looked-up customers are returned to authorized viewers. Treat as PII.
- Replica freshness is whatever KVM8 `radiumbox_prod` currently holds. This API does not sync or refresh it.
- Live Admin HTTP remains the operational enrichment path.

## Related documents

- [desk-radiumbox-prod-access-contract-audit.md](desk-radiumbox-prod-access-contract-audit.md) — live HTTP is still the operational contract
- [desk-radiumbox-historical-browse-design.md](desk-radiumbox-historical-browse-design.md) — History UI should consume this service
- [rd-central-finance-invoice-architecture.md](rd-central-finance-invoice-architecture.md) — scoped exception to “no direct DB coupling”
