# New Admin → KVM8 `radiumbox_prod` access-contract audit

**Project:** Radium Desk  
**Ticket:** RadiumDesk-P-04-09-02  
**Date:** 2026-09-04  
**Type:** Audit / design only. No application, `.env`, grant, database, AWS, or deploy change.  
**Canvas:** [`desk-radiumbox-prod-access-contract-audit.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-RadiumWebsites-radium-desk/canvases/desk-radiumbox-prod-access-contract-audit.canvas.tsx)

**Classification:** **VERIFIED** = observed in this repository or read-only KVM8 `SHOW`/`information_schema`. **INFERRED** = logically supported by those sources, not an ADR. **UNKNOWN** = cannot be established without an owner decision or out-of-scope inspect.

---

## Verdict

The **only implemented and documented** Desk/Admin access to RadiumBox data is **HTTP** to Old Admin:

`GET {RADIUMBOX_BASE_URL}/api/search/order?orderid=`

Default: `RADIUMBOX_ENABLED=true`, `RADIUMBOX_BASE_URL=https://admin.radiumbox.com`, via `RadiumBoxClient`. No auth header. Response mapped into `RadiumBoxOrderEnrichment` and persisted on **`radium_desk.orders`**.

KVM8 `radiumbox_prod` is a **refreshed historical/legacy replica** (P-04-09-01). It is **not** wired to Desk. `config/database.php` has no secondary connection. No Eloquent model uses RadiumBox tables. Finance ADR forbids direct DB coupling. Test gates **refuse** `radiumbox_prod` as a MySQL target.

**Do not connect Desk to `radiumbox_prod` in the next ticket** until an owner decision names the use case. Existence of the replica is not an access contract.

**P-04-09-04 follow-up:** a scoped, default-off, read-only application API now exists (`radiumbox.read` + named connection `radiumbox_read`). It does **not** replace this HTTP contract, does **not** merge schemas, and must not use the full-privilege `radiumbox_prod` user. See [desk-radiumbox-read-api.md](desk-radiumbox-read-api.md).

---

## 1. VERIFIED — existing RadiumBox integration

| Item | Value |
|------|--------|
| Client | `app/Services/RadiumBox/RadiumBoxClient.php` |
| Config | `config/radiumbox.php` + `.env.example` |
| Enable flag | `RADIUMBOX_ENABLED` (default **true**) |
| Base URL | `RADIUMBOX_BASE_URL` (default `https://admin.radiumbox.com`) |
| Timeouts | 5s request / 3s connect |
| HTTP | `Http::baseUrl(...)->acceptJson()->get('/api/search/order', ['orderid' => $orderId])` |
| Auth / signing | **None** on the Desk client (no token, HMAC, or Basic) |
| Other RadiumBox HTTP endpoints | **None** in Desk |
| Persistence target | Desk `orders` on the **default** connection (`radium_desk` in production) |
| Preference order | Desk-native → RDService HTTP if enabled → Admin HTTP → unresolved ([P-31-08-09](desk-admin-order-independence.md)) |
| Production RDService | `RDSERVICE_ENABLED` default **false**; empty `DESK_ORDER_API_TOKEN` |

Shared interactive/background lookup: `OrderEnrichmentLookupService` (RDService then `RadiumBoxClient`).  
Workspace / Cashfree / jobs: `RadiumBoxService` (RDService then Admin; **not** routed through the shared lookup).

There is **no** RadiumBox gateway/port that can swap HTTP for a local DB adapter (unlike `ShiprocketGateway` / `EInvoiceGateway`).

---

## 2. VERIFIED — screens / features that consume it

| Surface | Mechanism |
|---------|-----------|
| Order workspace `orders.show` | `RadiumBoxService::enrichOrderForWorkspace()` |
| Customer 360 manual sync | `POST …/customer-360/radiumbox-sync` → `RadiumBoxOrderEnrichmentService::manualSync()` |
| Operations batch recover | `POST /admin/operations/radiumbox/batch-recover` |
| Cashfree paid webhook (deferred) | `RadiumBoxOrderEnrichmentJob` |
| Auto-sync / recovery | `RadiumBoxAutoSyncTriggerService`, `RadiumBoxSyncRecoveryService`, `radiumbox:recover-sync` |
| Backfill | `radiumbox:backfill-orders`, `radiumbox:backfill-sync`, `radiumbox:reconcile` |
| Ready Queue backfill | `BackfillReadyQueueCommand` |
| Intake search miss / legacy create | `LegacyOrderLookupService`, `CustomerIntakeService::createLegacyFromRadiumBox` |
| One-click legacy import | `LegacyOrderImportService` |
| Global search fallback | intake lookup chain |
| Identity repair | `orders:repair-identity` via shared background lookup |
| Missed-call recovery / missing-serial | enrichment dispatch |

Hardware `RDE`/`RIN` and `INQ-` stay on Admin HTTP (RDService ineligible or Desk-native). Cashfree payment columns are never written by enrichment.

---

## 3. VERIFIED — data / endpoint consumed

**Single endpoint:** `GET /api/search/order?orderid=`

Expected JSON (mapper): `status === 200`, `data.rd_order` required, `data.order` optional billing overlay.

Fields pulled into `RadiumBoxOrderEnrichment` (then fill-missing onto Desk `orders`):

serial, product/device model, activation/purchase year, warranty, AMC status/year/details, payment/order status, customer name/phone/email, GST, invoice number, service history, legacy status/date.

Admin JSON keys (`amc_status`, `amc_year`, `amc_details`, `invoice_number`, …) are **API-shaped**. They are **not** a 1:1 `SELECT *` from `radiumbox_prod.order_rdservice` (that table has `amc_service_id` / `amc_service_name`, not `amc_status`).

---

## 4. VERIFIED — secondary / read-only database support

| Check | Result |
|-------|--------|
| `config/database.php` connections | Laravel defaults only: `sqlite`, `mysql`, `mariadb`, `pgsql`, `sqlsrv`. **No** `radiumbox` / `legacy` / `repository` connection |
| Models `$connection` / `DB::connection('…')` | Driver-name checks only; no second schema |
| Production Desk `.env` (P-04-09-01) | `DB_DATABASE=radium_desk` |
| `radium_desk` MariaDB grants | Own schema only; cannot see `radiumbox_prod` |
| Dedicated `radiumbox_prod` DB user | Exists; **no** app `.env` uses it |
| `InventoryPosMysqlGate` | Explicitly **rejects** `radiumbox_prod` |
| Desk models for Admin tables | **None** (`order_rdservice`, `product_stock`, `radium_branch`, …) |

`CHANNEL_INGEST_SECRET_RADIUMBOX_COM` is spoke → Desk HMAC ingest, not Desk reading `radiumbox_prod`.

---

## 5. VERIFIED — `radiumbox_prod` structures relevant to New Admin

Read-only KVM8 2026-09-04 (post P-04-09-01 refresh). 102 tables. Not an application schema.

Tables that **would** matter **if** a future historical/catalog/inventory feature were approved:

| Table | Role vs current Desk HTTP |
|-------|---------------------------|
| `order_rdservice` | Closest raw source for `data.rd_order` (`rdorderid`, `serial_no`, `product_name`, `userdetails`, `gst_no`, `rd_service_name`, `amc_service_name`, `status`) |
| `orders` | Billing overlay (`ordercode`, `invoicecode`, `rdservice_order_id`, `gst_no`, `payment_status`) |
| `invoice` | Invoice numbers (`invoice_number`) — Admin API may join this; Desk mapper also reads `invoicecode` |
| `users` / `users_address` | Customer identity if not only JSON `userdetails` |
| `order_details` | Lines / serial JSON — **not** in current Desk enrichment DTO |
| `products`, `product_stock`, `radium_branch` | Catalog / stock / GST locations — used by **investigation/import docs**, not by `RadiumBoxClient` |
| `desk_channel_*` | Present on the replica; **not** Desk application tables |

Prior docs: [inventory investigation](rd-fresh-01-radiumbox-inventory-investigation.md), [catalog dictionary](radiumbox-catalog-product-service-data-dictionary.md), [P-04-09-01 refresh](kvm8-radiumbox-prod-refresh-p-04-09-01.md).

---

## 6. INFERRED

1. P-04-09-01’s phrase “accessible to the new Radium Desk/Admin” described **placing a current replica on the Desk host**, not an approved application connection.
2. Live enrichment will keep calling **Old Admin HTTP** (and/or RDService HTTP after activation) even though KVM8 now has a full dump — unless a later ADR says otherwise.
3. A DB adapter behind `RadiumBoxClient` would have to **reimplement** Admin `/api/search/order` joins; the mapper is written for that JSON, not for MariaDB rows.
4. Inventory/POS “New Admin” work is intended to live on **Desk inventory tables**, with `radiumbox_prod` as a **one-time import source after owner confirmation** — not a live secondary DB.

---

## 7. UNKNOWN

- Whether the owner wants New Admin to **browse** historical RadiumBox orders/invoices/stock in Desk UI, or only to keep a SQL replica for ops/CA/import.
- Whether Old Admin `GET /api/search/order` reads the same live `radiumbox` that was dumped (AWS not inspected; this ticket must not call Admin).
- Whether the replica will stay current (no sync job exists).
- Whether a future SELECT-only grant for Desk is acceptable (not created; must not invent).
- Hardware enrichment after Admin HTTP is retired (P-31-08-09 criteria unmet).

---

## 8. RECOMMENDATION — next Cursor task (do not implement here)

| Option | Already documented? | Use now? |
|--------|---------------------|----------|
| **A. Continue HTTP RadiumBox API** | **Yes** — implemented + P-31-08-09 | **Yes — default** |
| **B. Read-only secondary DB to `radiumbox_prod`** | **No** — finance ADR says no direct coupling | **No** until owner + ADR override |
| **C. New internal API over `radiumbox_prod`** | **No** | **No** — extra surface, same decision needed |
| **D. Other documented mechanism** | Human/SQL replica; future inventory import; spoke HMAC ingest | **Not** a live Desk connection |

**Safest next ticket:** owner decision pack (docs only):

1. Name the job-to-be-done (live enrichment vs historical browse vs one-time import).
2. If live enrichment: keep **A**; optional later RDService activation per P-31-08-09; do not point `RADIUMBOX_BASE_URL` at KVM8.
3. If historical browse: write an ADR that **supersedes** “no direct DB coupling”, then a later ticket may add a **SELECT-only** named connection, new models, and **no** migrations against `radiumbox_prod`.
4. If inventory import: follow existing owner-gated import plan; still not a permanent app connection.

---

## 9. BLOCKERS / GATES before any implementation

1. Owner use-case decision (A vs browse vs import).
2. If B/C: ADR override of [central finance non-negotiable #3](rd-central-finance-invoice-architecture.md).
3. If B: new SELECT-only MariaDB user (do not widen `radium_desk`); fail-closed connection name; never run Desk migrations on `radiumbox_prod`.
4. If replacing Admin HTTP: re-spec `/api/search/order` vs raw tables; hardware `RDE`/`RIN` source; P-31-08-09 soak criteria.
5. Do not treat the replica as live SoT without a refresh/sync policy.
6. Do not call `admin.radiumbox.com` or AWS to “validate” this contract.

---

## 10. NO CHANGES

| Action | |
|--------|--|
| Application code | **NO** |
| `.env` / production config | **NO** |
| Database / grants | **NO** (read-only `information_schema` / `SHOW COLUMNS` only) |
| AWS / `admin.radiumbox.com` | **NO** |
| Sibling apps / DBs | **NO** |
| Deploy / tag / push | **NO** |

Ledger + this report + canvas only.
