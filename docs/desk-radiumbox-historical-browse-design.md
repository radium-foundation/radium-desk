# Historical RadiumBox browse — design only

**Project:** Radium Desk  
**Ticket:** RadiumDesk-P-04-09-03  
**Date:** 2026-09-04  
**Type:** Design / audit. No application, `.env`, grant, schema, AWS, or deploy change.  
**Canvas:** [`desk-radiumbox-historical-browse-design.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-RadiumWebsites-radium-desk/canvases/desk-radiumbox-historical-browse-design.canvas.tsx)  
**Prior contract:** [P-04-09-02](desk-radiumbox-prod-access-contract-audit.md)

**Classification:** **VERIFIED** = repository or read-only KVM8 `information_schema` / sample SELECT. **INFERRED** = recommended design, not implemented. **UNKNOWN** = owner decision.

Live contract is unchanged: Desk → HTTP `GET /api/search/order` → `admin.radiumbox.com`. Do not point `RADIUMBOX_BASE_URL` at KVM8. Do not reuse `RadiumBoxClient` for this feature.

---

## 1. VERIFIED — New Admin architecture relevant to browsing

| Surface | What it is | Relation to history |
|---------|------------|---------------------|
| Four hubs | Mission Control, Operations, Workforce, Administration | Historical browse is **not** a live ops case tool |
| Operations sidebar | Cash Book + Learning Center only. `orders.index` is **not** a sidebar primary (tests forbid `operations.orders`) | Do **not** fold replica rows into Desk Orders |
| `orders.index` / `orders.show` | Desk `radium_desk.orders`. Show calls `RadiumBoxService::enrichOrderForWorkspace()` (live HTTP) | Mixing replica here would combine LIVE + HISTORICAL |
| Header `/search` | `GlobalSearchService` on Desk cases; miss → `CustomerIntakeSearchService` → **HTTP** Admin/RDService | Do **not** add replica lookup here |
| Finance Invoices | Desk statutory invoices (`FinanceAccess` + `StatutoryInvoiceRegisterReadModel`) | Different SoT; do not list Admin `invoice` there |
| Administration workspace tabs | Permission-gated tabs (`BackupAccess`, `PlatformConfigurationAccess`, …) | Closest home for a restricted read-only tool |
| Customer 360 / radiumbox-sync | Live HTTP enrichment | Out of scope |

There is no existing “historical RadiumBox” screen.

**P-04-09-04 follow-up:** the shared read service/API now exists (`RadiumBoxReadService`, permission `radiumbox.read`, connection `radiumbox_read`, default OFF). History UI must consume that service. Do not add a second replica query path, `radiumbox.history.view`, or `RadiumBoxClient` fallback. See [desk-radiumbox-read-api.md](desk-radiumbox-read-api.md).

---

## 2. VERIFIED — authorization / navigation to reuse

- Spatie permissions + `*Access` helpers (`FinanceAccess`, `InventoryAccess`, `BackupAccess`). Checks use `$user->can(...)`, not role-name branching in controllers (Administration nav uses `ADMIN_TEAM_ROLES` only to show the hub).
- Workspace tab nav: `resources/views/navigation/administration-workspace-nav.blade.php`.
- Sidebar wiring: `NavigationContextResolver::sidebar()` + `sidebarItem()`.
- Controller middleware abort 403 (`StatutoryInvoiceController` pattern).
- `RolePermissionSeeder` constants + role maps. `ADMIN_TEAM_ROLES` = admin, operations_admin, superadmin.
- `orders.view` is given to agents — **too wide** for a PII-heavy replica browser.

---

## 3. VERIFIED — minimum historical tables / columns

KVM8 `radiumbox_prod` 2026-09-04, read-only. **No foreign keys** among these tables (only Spatie permission FKs exist). Joins below are **observed key usage**, not constraints.

### Join keys (sample + types)

| From | To | Evidence |
|------|----|----------|
| `orders.rdservice_order_id` (varchar, indexed) | `order_rdservice.id` (int PK) | Recent `id>=317000`: 933/933 set values match `CAST` to `id`. Example: `orders.id=318017` → `rdservice_order_id=3510864` |
| `order_rdservice.rdorderid` | Own RD label (`RD`+id), **not** `orders.ordercode` | `id=3510865` → `rdorderid=RD3510865`; commercial code is `RD318017` |
| `orders.ordercode` | Typically `RD`+`orders.id` | Recent rows |
| `invoice.orderid` (varchar, **no index**) | `orders.id` | `invoice.id=261302` `orderid=317765` |
| `order_details.orderid` (varchar, indexed) | `orders.id` | `318017` has 4 lines |
| `order_history.orderid` (int, indexed) | `orders.id` | `318017` has 6 events |
| `orders.userid` (varchar) / `order_rdservice.userid` (int) | `users.id` | Recent orders join |
| `order_details.productid` (varchar) | `products.id` | Line `1235` → product name match |

`orders.rdservice_order_id` can duplicate (P-04-09-01: 20,927 groups). Browser must show **all** commercial rows, not pick a winner.

### V1 search / display columns

| Table | Columns | Why |
|-------|---------|-----|
| `orders` | `id`, `ordercode`, `ordertype`, `rdservice_order_id`, `invoicecode`, `userid`, `userdetails`, `gst_no`, `payment_status`, `status`, `orderdate`, `branch`, `created_at` | Commercial header + search |
| `order_rdservice` | `id`, `rdorderid`, `userid`, `userdetails`, `gst_no`, `product_name`, `serial_no`, `status`, `website`, `paid_amount`, `created_at` | RD header + serial search |
| `order_details` | `id`, `orderid`, `product_name`, `productid`, `invoicecode`, `label`, `created_at` | Lines / serial JSON |
| `order_history` | `id`, `orderid`, `updated_by`, `status`, `description`, `created_at` | Status timeline |
| `invoice` | `id`, `orderid`, `invoice_number`, `branch`, `service_type`, `created_at` | Admin invoices (number format is mixed: `1888` vs `261300`) |
| `users` | `id`, `name`, `phone`, `email`, `gst_no`, `company_name`, `created_at` | Customer snapshot |
| `products` | `id`, `product_name` | Optional name overlay only |

**Not v1:** `product_stock`, wallets, carts, OAuth, `desk_channel_*`, admin activity logs.

**Index limits (do not add indexes in implementation unless a later ops ticket says so):** `users.phone` unindexed; `invoice.orderid` / `invoice.invoice_number` unindexed; `serial_no` only appears as a non-leading HASH part. Prefer searches that hit `orders.id` / `ordercode` / `invoicecode` / `rdservice_order_id` / `order_rdservice.rdorderid` / `users.email`.

---

## 4. VERIFIED — code patterns to reuse

| Pattern | Where | Use |
|---------|--------|-----|
| `*Access` + Spatie | `FinanceAccess`, `BackupAccess` | New `RadiumBoxHistoryAccess` |
| Workspace tabs | Administration nav | New tab, permission-gated |
| Read model | `StatutoryInvoiceRegisterReadModel` | Server-side query + paginate; **do not** bind Eloquent to replica tables |
| Query-only DTO | `RadiumBoxOrderEnrichment` (shape only) | New **separate** history DTOs — do not reuse enrichment |
| Audit | `AuditLogService::log` | Event on the **viewer** `User` (service requires a Desk `Model`; replica rows are not Desk models) |
| Config | `config/radiumbox.php` is HTTP-only | New `config/radiumbox_history.php` — never reuse `RADIUMBOX_BASE_URL` |
| DB config | Single default connection | Add **named** connection later; not `DB_DATABASE` |
| Test gate | `InventoryPosMysqlGate` rejects `radiumbox_prod` | Keep rejecting as **default** Desk DB; history tests use a disposable name |

No RadiumBox gateway/port exists to swap HTTP for SQL. Do not add one onto `RadiumBoxClient`.

---

## 5. INFERRED — recommended architecture

Scoped **exception** to finance ADR “no direct DB coupling”: **read-only named connection for this Administration screen only.** Enrichment, invoicing, payments, and Desk `orders` stay decoupled.

```
LIVE (unchanged):
  New Admin → RadiumBoxClient → admin.radiumbox.com /api/search/order
  → persist fill-missing on radium_desk.orders

HISTORICAL (new, separate):
  New Admin Administration tab
  → RadiumBoxHistoryAccess
  → RadiumBoxHistoryRepository (Query Builder on connection radiumbox_history)
  → SELECT-only MariaDB user → radiumbox_prod
  → History DTOs → Blade
```

Do not: change `RADIUMBOX_*`, call `RadiumBoxClient` from the history controller, write Desk orders from history, or run migrations on `radiumbox_prod`.

Prefer Query Builder over Eloquent models on replica tables so `migrate` / `save()` cannot touch them.

---

## 6. UNKNOWN — owner decisions

1. Who may view (P-04-09-04 implemented `radiumbox.read` for admin team; History UI should reuse it).
2. Whether accountants get access (`finance.accountant.access` is a different SoT).
3. Whether `paid_amount` / full `userdetails` JSON is in v1 (PII).
4. Identifier when `RD*` could mean `ordercode` or `rdorderid`.
5. Whether v1 includes `products` overlay.
6. Replica freshness expectation (no sync job exists).
7. Whether an ADR override text must be approved before any connection code.

---

## 7. PROPOSED UI (smallest useful)

**Place:** Administration workspace tab **RadiumBox History** (not Orders, not Finance Invoices, not global search).

1. Banner: “Historical replica on KVM8. Not live Admin. Not a Desk order. Do not invoice or enrich from this page.”
2. Search: one `q` + optional type (order / RD / invoice / serial / phone / email). Empty `q` → empty list (do not dump 308k orders).
3. Result table: commercial `orders.id` / `ordercode`, RD id, invoice code, status, customer name/phone, created. Duplicate RD groups listed separately.
4. Detail: commercial header, RD header, customer snapshot, invoices, lines, history. Read-only. No sync / import / create-case / issue-invoice.
5. If Desk `orders.order_id` equals `ordercode` or `rdorderid`, optional link to Desk workspace (Desk DB only; **do not** trigger enrichment on that click).

---

## 8. PROPOSED DATA FLOW

```
GET /admin/radiumbox-history?q=
  → auth middleware
  → RadiumBoxHistoryAccess::allows() else 403
  → parse q (do not call OrderEnrichmentLookupService)
  → if connection disabled / missing: fail-closed empty + flash (no HTTP Admin fallback)
  → repository SELECT … LIMIT/paginate on radiumbox_history
  → map to DTOs (no Order::update)
  → AuditLogService event radiumbox_history.searched / .viewed on viewer User
  → Blade
```

Show: `GET /admin/radiumbox-history/orders/{commercialId}` using replica `orders.id` only.

---

## 9. PROPOSED SECURITY MODEL (do not create)

| Control | Design |
|---------|--------|
| DB user | New `radiumbox_history`@`localhost`/`127.0.0.1` — **SELECT** on `radiumbox_prod` only. Not `radium_desk`. Not the existing full-priv `radiumbox_prod` user. |
| Grants | Out-of-band ops ticket. Never in git. |
| Config | `RADIUMBOX_HISTORY_ENABLED=false` default. `DB_RADIUMBOX_HISTORY_*` (host/database/username/password). Password in production `.env` only. |
| Connection | `config/database.php` name `radiumbox_history`. `sticky` off. No write methods in repository. |
| App | Server-side only. No browser DSN. No `RADIUMBOX_BASE_URL` reuse. |
| Authz | New permission; not `orders.view`. |
| Fail-closed | Missing config → 403/empty, never Admin HTTP. |
| PII | Audit viewer + query type; do not log full row dumps. |

---

## 10. PROPOSED VALIDATION (future implementation ticket)

- Feature tests: 403 without permission; enabled+fake repo returns rows; disabled connection does not call `RadiumBoxClient` (spy).
- Unit tests: identifier parse (`RD318017` vs `RD3510865`); duplicate `rdservice_order_id` returns multiple commercials.
- PHPUnit must not use production `radiumbox_prod` as default `DB_DATABASE` (`InventoryPosMysqlGate` stays).
- Production verify after a later deploy: history tab visible to allowed role; `RADIUMBOX_BASE_URL` still `https://admin.radiumbox.com`; order workspace still HTTP-enriches; sibling DBs unchanged; `SHOW GRANTS` is SELECT-only; sample `RD318017` / invoice `261302` match replica.

---

## 11. NON-GOALS

Unchanged: live HTTP RadiumBox, `RadiumBoxClient`, enrichment jobs, invoicing, payments, Cashfree, hardware `RDE`/`RIN` source, Desk order writes, global-search intake fallback, inventory import, replica schema/indexes, AWS, Old Admin, spoke DBs.

---

## 12. NEXT CURSOR TASK (do not run here)

**Title:** Implement Administration RadiumBox History (read-only), live HTTP contract unchanged.

**Gates before code:** owner answers §6.1–6.4; ops creates SELECT-only user (or implementation stays behind `RADIUMBOX_HISTORY_ENABLED=false` with no DSN); ADR note recording the scoped coupling exception.

**Then, in order:**

1. `config/radiumbox_history.php` + named `database.connections.radiumbox_history`. Defaults off. Do not touch `config/radiumbox.php`.
2. Reuse `RadiumBoxReadAccess` + `radiumbox.read`. Do not create a second permission.
3. Reuse `RadiumBoxReadService` / `RadiumBoxReadRepository`. Add History-only fields to the existing allowlist if justified.
4. Controller + Administration tab + index/show Blade + banner.
5. Audit events on viewer User.
6. Tests in §10. Pint.
7. No `deskd` until changelog + owner approve. No `RADIUMBOX_BASE_URL` change. No grants in the app ticket unless a paired ops step is explicit.

---

## NO CHANGES (this ticket)

Application **NO**. Config **NO**. DB/grants **NO** (read-only inspect only). AWS **NO**. Deploy **NO**.
