# Hardware fulfilment / shipment UI design — RadiumDesk-P-07-09-63

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-63`  
**Mode:** Read-only investigation and UI design. No application code, schema write, production data, Shiprocket call, shipment, permission change, deploy, commit, or push.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-62**. This ticket: **P-07-09-63**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**. Never upgraded.

Companion parcel-bridge design (must stay consistent): `docs/desk-hardware-shipment-packaging-snapshot-design-p-07-09-62.md`.

Concrete case: **RDE318421** / fulfilment **1** / commerce **369** / product **28** / `RBMFS110L1`.

---

## 0. Pre-verification

| Item | Value | Class |
|------|-------|-------|
| Project | Radium Desk | VERIFIED |
| Repository | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Branch | `main` (tracks `origin/main`) | VERIFIED |
| HEAD | `b6b6568c3921ba1a7048ab6166bf50631e24fe64` | VERIFIED |
| Describe | packaging Stage 1 overlay recorded on this SHA | VERIFIED |
| Worktree | Dirty unrelated statutory docs + untracked investigation markdown. No application edits in this ticket. | VERIFIED |
| Sibling worktrees | `radium-desk` (`feat/rd-fresh-01-inventory-pos`); `radium-desk-phase1-clean` (detached) — unused | VERIFIED |
| Local `.env` | not used | — |

Relevant history (this workspace, newest first): packaging overlay `b6b6568c`; packaging master `4ff93325`; isolated shipment search-before-create `32717e8f`; serial allocation UI `29c4b26a`; shipment UI `P-07-09-42`.

---

## 1. Existing fulfilment / order / shipment pages and routes

**VERIFIED.** Blade + vanilla JS. No Livewire / Inertia / Vue / React.

### Hardware fulfilment (the only shipment UI)

| Method | URL | Route | Controller | Permission |
|--------|-----|-------|------------|------------|
| GET | `/inventory/hardware-fulfilments` | `inventory.hardware-fulfilments.index` | `HardwareFulfilmentSerialController@index` | `hardware.fulfilment.operate` + `inventory.view` |
| GET | `/inventory/hardware-fulfilments/{fulfilment}` | `…show` | `@show` | same + branch scope if `fulfilment_branch_id` set |
| GET | `…/serials/search` | `…serials.search` | `@search` | JSON picker |
| POST | `…/serials` | `…serials.store` | `@store` | allocate |
| POST | `…/shipment` | `…shipment.store` | `@storeShipment` | create / reconcile |
| POST | `…/awb` | `…awb.store` | `@storeAwb` | **route exists; no UI button** |

Parent middleware: `auth` + `active` only. Controllers `abort_unless`.

### Inventory Stock / Product (packaging lives here)

| URL | Route | Permission |
|-----|-------|------------|
| `/inventory/stock` | `inventory.stock.index` | `inventory.view` |
| `/inventory/stock/packaging/{product}` GET/PUT | `inventory.stock.packaging.edit/update` | `inventory.packaging.verify` |
| `/inventory/products` | `inventory.products.index` | `inventory.products.manage` |
| `/inventory/serials` | `inventory.serials.index` | `inventory.view` |

### Not fulfilment shipment

| Surface | Why excluded |
|---------|----------------|
| `/orders` support-order workspace | Service cases, not hardware fulfilment |
| `/finance/invoices/*` | Statutory issue / view |
| `/pos/*` | Counter sales |
| Dashboard Hardware queue | Support-order queue (`dashboard.hardware.view`); placeholder unused |
| `POST /api/desk/fulfilment-status` | Inbound Box callback |
| `/inventory/shipments`, `/fulfilment/shipments` | **Do not exist** |

**No dedicated Shiprocket settings, label, track, or cancel page.**

---

## 2. Current fulfilment page structure and actions

**List** (`resources/views/inventory/hardware-fulfilments/index.blade.php`):

- Columns: Order (`source_id`), Commerce (`order_no`), Status (uppercase state), Physical branch, link.
- Link text: **Allocate serial** if `ready_for_fulfilment`, else **Open**.
- Paginate 40. **No search, filters, or `inspect()`.**
- States included: `ingested`, `ready_for_fulfilment`, `serials_allocated`, `invoice_issued`, `shipment_created`, `awb_assigned`.
- **Omitted:** `paid`, `shipped`, `synced`, `failed`, `retry_pending`.
- **No `InventoryBranchScope` on the list** (scope is only on show/search/store).

**Detail** (`show.blade.php`, max-width 40rem):

1. Header: source id, commerce no, support id, state pill, fulfilment id, physical branch.
2. Allocated serials (if any).
3. Physical line cards: SKU, model, catalog SKU, bundled RD, available qty by Delhi/Mumbai, SKU-map blocker.
4. Allocate form only if `$canAllocate` (`ready_for_fulfilment`, not frozen, every line `map_ready`).
5. Shipment card from `HardwareShipmentEligibility::inspect()`: status, pickup branch/location, ship-to, parcel, invoice, serial, provider. Red blocker list. Create/Reconcile only if `$shipment->canCreate`.
6. AWB: text only (“later isolated step”). No button.

**No invoice-issue button. No country field. No parcel inputs. No event timeline.**

---

## 3. Inventory Stock / Product UI and permissions

**VERIFIED** (P-07-09-60/61 on this HEAD):

- Stock table shows Product ID, SKU, name, variant, branch, available, reserved, Pack (`Verified` / `Not verified`), weight, L×B×H, units, Record/Edit pack if `inventory.packaging.verify`.
- Record pack is `/inventory/stock/packaging/{product}`: gross kg, L/B/H cm, notes, re-attest on save.
- `hardware_team` can **see** pack status; **cannot** write (403).
- Product master is admin-team only (`inventory.products.manage`).
- Packaging is **catalog**, not an order parcel. Eligibility still ignores it (P-07-09-62).

---

## 4. Roles / permissions

**VERIFIED** — Spatie strings in `RolePermissionSeeder`. No Inventory / Fulfilment policy class.

| Permission | admin / operations_admin / superadmin | hardware_team |
|------------|---------------------------------------|---------------|
| `inventory.view` | yes | yes |
| `inventory.packaging.verify` | yes | **no** |
| `inventory.products.manage` | yes | **no** |
| `inventory.branches.operate-all` | yes | **no** |
| `hardware.fulfilment.operate` | yes | yes |
| `finance.invoices.issue` | admin + superadmin only (`operations_admin` view, not issue) | **no** |
| `finance.invoices.view` | yes | **no** |

`HardwareFulfilmentAccess` = `inventory.view` **and** `hardware.fulfilment.operate`. Not a hardcoded user id.

**Avinash Jha** (user 2, from P-07-09-61 docs): roles `admin` + `hardware_team`. Packaging write and all-branch operate come from **`admin`**, not a named grant.

**Sushant** (user 8, same docs): `hardware_team` only → fulfilment operate yes; packaging 403.

Branch-scoped `hardware_team` users operate only assigned branches once `fulfilment_branch_id` is set.

---

## 5. Services the UI must consume (not duplicate)

| Service / DTO | Role | Class |
|---------------|------|-------|
| `HardwareShipmentEligibility::inspect()` | Operator-facing readiness. **Authoritative UI source.** Never write. | VERIFIED |
| `HardwareShipmentReadiness` | `canCreate`, `blockers[]`, status, pickup, ship-to, parcel string, invoice, serials, actionLabel | VERIFIED |
| `HardwareShipmentEligibility::require()` | Create path fail-closed. Same gates as inspect, throws. | VERIFIED |
| `HardwareShipmentService::createShipment()` | Lock, bound-return, `provider_rejected` no-retry, search-before-create, mapper | VERIFIED |
| `HardwareShipmentService::assignAwb()` | State must be `shipment_created` | VERIFIED |
| `HardwareSerialAllocationService` | Requirements + allocate | VERIFIED |
| `HardwarePickupResolver` | `DELHI-RETAIL` → `RADDELHI`; `MUMBAI` → `RADIUMUM` | VERIFIED |
| `HardwareFulfilmentWorkflowService` | State asserts | VERIFIED |
| `InventoryProductPackaging` | Catalog pack on Stock | VERIFIED |
| Planned `HardwareFulfilmentParcelSnapshotService` | Attach snapshot; inspect precedence | P-07-09-62 design |
| Planned `HardwareFulfilmentCountryCorrectionService` | Admin fill-if-absent country overlay | P-07-09-62 design |

UI must render `inspect()` (and later extended fields). It must **not** re-implement payment/invoice/serial/parcel/country/provider rules in Blade/JS.

---

## 6. Existing UX patterns to reuse

| Pattern | Where | Reuse |
|---------|-------|-------|
| State pill `.hf-alloc-status` | fulfilment show | Keep; add shipment-status as a **second** pill (already distinct: lifecycle vs local shipment state) |
| Red `<ul class="hf-ship-blockers">` | shipment card | Keep as the only blocker source (`inspect().blockers`) |
| Confirm dl + `window.confirm` + disable submit | Create Shipment | Keep for ship / AWB / country |
| Client confirm box (no modal) | Allocate serial | Keep |
| Flash `alert-success` / first validation error | fulfilment show | Keep |
| Stock GET filters `row g-2` | stock / serials / movements | Add to fulfilment **list** |
| Workspace tabs + `table-responsive` | inventory | Keep Hardware tab |
| Serial movement history table | `/inventory/serials/{id}` | Model for fulfilment history **read** of existing events |
| Bootstrap modal | orders serial lock | Optional later; current fulfilment uses confirm, not modal |

---

## 7–8. Recommended UI location

**Enhance the existing Inventory Hardware workspace.** Do **not** add `/inventory/shipments` or `/fulfilment/shipments`.

| Option | Verdict | Why |
|--------|---------|-----|
| Enhance `/inventory/hardware-fulfilments` | **Recommended** | Only existing shipment UI; Hardware tab already permission-gated; serial → invoice display → ship is one order; parcel-bridge snapshot is fulfilment-scoped |
| New `/inventory/shipments` | Reject | Splits allocate from ship; invents a second list of the same rows; sidebar already treats Hardware as a workspace tab, not a domain |
| New `/fulfilment/shipments` | Reject | No `/fulfilment` prefix exists; would orphan Stock packaging and serial picker |

**INFERRED:** Rename the workspace tab label from **Hardware** to **Hardware fulfilment** if operators confuse it with the dashboard Hardware queue. Route names stay `inventory.hardware-fulfilments.*`.

**INFERRED:** Add a sidebar Inventory item pointing at the same index once the list shows readiness (today Hardware is tabs-only; sidebar Inventory highlights Stock).

---

## 9. Recommended list / table columns

Consume a **list projection of `inspect()`** (or a batch helper that calls the same methods). Do not invent a second eligibility.

| Column | Source | Why |
|--------|--------|-----|
| Order | `source_id` | Operator key (RDE…) |
| Commerce | `order_no` | Desk id |
| Product / SKU | first physical line / allocated product | Warehouse identity |
| Fulfilment state | `state` | Lifecycle |
| Shipment status | `inspect().status` | Local shipment, not lifecycle |
| Payment | paid / not from same `isPaid` used by inspect | Visible gate |
| Invoice | `inspect().invoice` | Visible gate |
| Serial | count or first serial | Visible gate |
| Branch | `inspect().pickupBranch` | Derived |
| Address | complete / incomplete from inspect ship-to / blocker | Country gap visible |
| Parcel source | **new inspect field** after P-07-09-62: ingest / snapshot / unavailable | Must not show catalog as “parcel ready” |
| Catalog pack | verified / missing (read-only) | Explains why snapshot can/cannot attach |
| Provider | ready / incomplete from existing blocker | Null gateway visible |
| AWB | shipment.awb or empty | Post-create |
| Blockers | `inspect().blockers` (count + first) | Why Open vs Create is hidden |
| Action | Allocate / Open / Reconcile — from state + `canCreate` / `actionLabel` | No invented verbs |

Filters (GET, Stock pattern): `q` (source_id / order_no / serial), state, branch, readiness (`canCreate` / blocked / already created). Include `shipped` / `synced` / `failed` so operators do not lose orders after AWB.

Apply `InventoryBranchScope` on the list (**VERIFIED gap** today).

---

## 10. Recommended detail-page sections

Keep one page. Widen beyond 40rem for the readiness grid; keep allocate picker compact.

1. **Identity** — source, commerce, fulfilment id, support id, state pill, shipment-status pill.
2. **Readiness** — exact `inspect().blockers`. No extra client-side gates.
3. **Payment** — status, `paid_at` / `paid_recognized_at`. Display only.
4. **Invoice** — number, issued at, link to Finance **if** `finance.invoices.view`. No Issue button.
5. **Serial / product / branch** — allocated serials, inventory product id, SKU, Box model if mapped, physical branch. Allocate form only when `$canAllocate`.
6. **Verified product packaging** — catalog row (measures, units, verified by/at). Link to Stock Record pack if `inventory.packaging.verify`. Label: **catalog**, not shippable parcel.
7. **Order parcel snapshot** — after P-07-09-62: ingest parcel vs fulfilment `parcel_snapshot` vs unavailable. Show source, measures, snapshotted by/at. No inputs.
8. **Shipping address** — structured keys present/missing. Highlight missing `country`. Do not substitute billing. Country overlay (if later implemented) shown as overlay, not as ingest JSON.
9. **Shipment configuration** — derived pickup branch, pickup nickname, provider name, enabled/Null, channel id **presence only** (never print the value).
10. **Shipment / AWB / callback** — local shipment state, provider ids after bind, AWB, `HardwareFulfilmentEvent` / outbox projection (read-only). AWB button only when a future `inspectAwb()` (or equivalent service flag) says so.
11. **History** — existing `hardware_fulfilment_events` + `shipment_events` tables. Do **not** write parcel into events (callback side effect).

RDE318421 today (P-07-09-62 inspect): blockers **Shipping address incomplete**, **Parcel dimensions unavailable**, **Shiprocket configuration incomplete**. After snapshot design: parcel may become snapshot-ready; country + disabled Shiprocket remain.

---

## 11. Actions and exact eligibility gates

UI shows a button **only** when the named service says so.

| Action | Permission | Backend gate (do not re-code in Blade) | UI |
|--------|------------|----------------------------------------|----|
| View list / show | `hardware.fulfilment.operate` + branch scope | — | Always for authorized |
| Allocate serial | same | `state === ready_for_fulfilment`, not frozen, SKU map ready, `assertCanAllocateSerials` | Existing form |
| Record / re-verify pack | `inventory.packaging.verify` | Stock product page, not fulfilment POST | Link out |
| Attach parcel snapshot | same as operate **or** implicit inside create (P-07-09-62) | qty=1, one physical SKU, verified kg/cm pack, order parcel incomplete, no bound shipment | Optional explicit button **or** create-does-attach; GET inspect never writes |
| Correct country | **new admin-only** (see §12) | Structured country empty; explicit string; fill-if-absent | Confirm; no other address fields |
| Create / Reconcile shipment | operate | `inspect().canCreate` / `require()`: not frozen, `invoice_issued`, paid, serials match qty + invoice lock, one physical branch, pickup derived, complete structured address (country present or overlay), parcel ingest **or** snapshot, provider not Null, not `provider_rejected` | Existing confirm; empty body; prohibited overrides |
| Assign AWB | operate | `assignAwb()` requires `shipment_created` + bound provider shipment | Hide until service-ready inspect exists |
| Issue invoice | Finance / CLI | `assertCanIssueInvoice` = `serials_allocated` | **No button** on this page |
| Cancel / recover | none today | No cancel service | **No button** |

`CreateHardwareFulfilmentShipmentRequest` already prohibits branch, pickup, parcel measures, provider order id, channel id. **`country` is not currently prohibited** — add it when country overlay ships so operators cannot POST country on create.

---

## 12. Permission boundaries

| Concern | Who | Recommendation | Class |
|---------|-----|----------------|-------|
| Viewing fulfilments | admin-team + `hardware_team` | Keep `hardware.fulfilment.operate` | VERIFIED existing |
| Packaging verification | admin-team | Keep `inventory.packaging.verify` on **Stock** | VERIFIED |
| Fulfilment preparation (allocate) | operate | Unchanged | VERIFIED |
| Snapshot attach | operate | Same as ship; not packaging.verify (hardware must ship after Admin measured) | INFERRED from P-07-09-62 |
| Shipment create / reconcile / AWB | operate | Unchanged; still fail closed on Null gateway | VERIFIED |
| Country overlay | admin-team only | New `hardware.fulfilment.correct-country` **or** admin-team check. Do **not** give `hardware_team`. Do **not** reuse operate. | INFERRED |
| Invoice issue | admin/superadmin Finance or isolated CLI | Stay off this UI | VERIFIED |
| Cancellation | — | None until a cancel service exists | VERIFIED absent |

Do not change seeded grants in the UI ticket except to add the country-correct permission if that action is implemented.

---

## 13. How to represent each state

| Topic | Display | Must not |
|-------|---------|----------|
| Packaging source | Three lines: **Catalog** (verified/missing + link), **Ingest parcel** (present/null), **Fulfilment snapshot** (present/source ids) | Treat catalog as the Shiprocket parcel |
| Address | Key checklist; missing country called out | Infer India; use billing |
| Invoice | Number or “Not issued” | Issue / remint |
| Serial | Numbers + branch | Operator-chosen branch |
| Branch / pickup | Derived codes only | Editable |
| Parcel snapshot | Formatted kg/cm + source enum | Typed L/W/H |
| Shiprocket config | “Shiprocket (not called)” / incomplete / bound | Print credentials or raw channel id |
| Shipment | `inspect().status` | Equate to fulfilment state |
| AWB | Value or “Not assigned” | Fake AWB |
| Callback | Outbox / event state | Store parcel on events |
| Blockers | Exact `inspect()` strings | Client-invented reasons |

---

## 14. Manual overrides

**No** operator override of weight, dimensions, branch, pickup, provider order id, channel id, or other derived shipment values.

| Field | Policy |
|-------|--------|
| Weight / L / B / H | Catalog write on Stock (measured). Fulfilment copies via snapshot service. Create form stays prohibited. |
| Branch / pickup | Derived from allocated serials + env nicknames |
| Provider order id | Provider-assigned |
| Country | Admin fill-if-absent overlay only; never on Create Shipment |
| Historical Shiprocket 0.24/14×9×7 or 0.5/10×10×10 | Evidence only; Avinash already measured product 28 |

---

## 15. Audit / history

| Event | Where to store | UI |
|-------|----------------|----|
| State transitions | existing `hardware_fulfilment_events` | Read-only timeline |
| Box callback enqueue | same events (side effect) | Show synced/failed; **never** put parcel here |
| Shiprocket attempts | `shipments` + `shipment_events` | Show attempts, failure_class, last_error |
| Snapshot attach | fulfilment `parcel_snapshot` metadata (actor, packaging id, times) per P-07-09-62 | Show on parcel section |
| Country overlay | overlay columns + actor/at | Show on address section |
| Allocate | existing allocation + events | Already visible |

Do not use `HardwareFulfilmentEvent` as a parcel store.

---

## 16. Idempotency and double-click

**VERIFIED today:**

- Create key `hardware:shiprocket:create:{fulfilment_id}`; unique shipment per fulfilment/order.
- Transaction lock; bound shipment returned; `provider_rejected` not retried; ambiguous/retryable search-before-create.
- UI disables submit and sets “Creating shipment…” / “Allocating…”.
- Test: `test_duplicate_ui_submit_does_not_create_a_second_shipment`.

**After parcel-bridge:** attach is idempotent; catalog edit must not mutate an existing snapshot; inspect GET never writes.

Keep: disable button on submit; no second Create when `alreadyCreated`; Reconcile label only when `inspect().actionLabel` says so.

---

## 17. Mobile / warehouse

**VERIFIED:** tables are `table-responsive`; workspace tabs scroll; show is 40rem (usable on a phone); no dedicated mobile layout.

**Recommend (INFERRED):**

- List: stacked cards below `md` (Order, state, first blocker, action). Wide table on desktop.
- Detail: full-width readiness + blockers first (warehouse glance); allocate picker remains large tap targets (existing `.hf-alloc-serial`).
- Confirm actions stay `window.confirm` or a full-width sticky submit — no hover-only controls.
- Do not require a separate mobile app.

---

## 18. Files / classes / routes / views / tests for a later implementation

Depends on P-07-09-62 backend landing first (or in the same PR). UI must not ship a create button that assumes catalog-as-parcel.

### Modify (UI + consume extended inspect)

- `app/Http/Controllers/Inventory/HardwareFulfilmentSerialController.php` — list filters, branch scope, pass extended readiness
- `resources/views/inventory/hardware-fulfilments/index.blade.php`
- `resources/views/inventory/hardware-fulfilments/show.blade.php`
- `resources/views/inventory/partials/workspace-nav.blade.php` — label only if renamed
- `app/Support/Navigation/NavigationContextResolver.php` — optional sidebar item
- `app/Services/HardwareFulfilment/Data/HardwareShipmentReadiness.php` — parcel source, catalog pack, country overlay, payment, AWB flags (**display fields only**, still filled by eligibility)
- `app/Services/HardwareFulfilment/HardwareShipmentEligibility.php` — fill those fields from existing + snapshot precedence (P-07-09-62)
- `app/Http/Requests/Inventory/CreateHardwareFulfilmentShipmentRequest.php` — prohibit `country`
- `routes/web.php` — attach / country-correct if those actions are explicit
- `tests/Feature/HardwareFulfilment/HardwareFulfilmentShipmentUiTest.php`
- `tests/Feature/HardwareFulfilment/HardwareFulfilmentSerialAllocationUiTest.php` if list/show markup shared

### New (from P-07-09-62; UI consumes)

- `HardwareFulfilmentParcelSnapshotService`
- `HardwareFulfilmentCountryCorrectionService`
- `AttachHardwareFulfilmentParcelRequest` / `CorrectHardwareFulfilmentShippingCountryRequest`
- Additive fulfilment overlay migration
- `HardwareFulfilmentParcelSnapshotTest` / `HardwareFulfilmentCountryCorrectionTest`
- Optional: `resources/views/inventory/hardware-fulfilments/partials/readiness.blade.php`, `parcel-source.blade.php`, `history.blade.php`

### Do not modify for this UI

Payment services, statutory mint, serial allocation semantics, `ChannelIngestService` write, Shiprocket env, RDE318421 rows, `hardware.fulfilment.operate` grants, callback signer.

---

## Relationship to parcel-bridge (P-07-09-62)

The UI is a **projection** of that contract:

1. Display catalog pack and ingest parcel as **different** facts.
2. Eligibility order: complete ingest parcel → fulfilment snapshot → fail closed.
3. Never live-read catalog at create-click time in the browser.
4. Country is a separate admin overlay, not a packaging field and not a create-form field.
5. Create may attach-inside-lock; inspect never writes.
6. RDE318421 would still be **not ready** after snapshot until country overlay **and** a later Shiprocket enablement ticket.

---

## Implementation risks

1. Teaching the list/detail that “Verified pack = ready to ship” — **false** until snapshot + country + provider.
2. Implementing UI before snapshot/country services — create would still fail; operators would type or invent values.
3. Adding `/inventory/shipments` and forking eligibility.
4. Showing AWB / Create while Null gateway is bound (today correctly hidden via `canCreate`).
5. Using `HardwareFulfilmentEvent` for parcel (enqueues Box callback).
6. List without branch scope leaking other-warehouse rows to `hardware_team`.
7. Country overlay that overwrites a later spoke-supplied country.
8. Enabling Shiprocket or writing RDE318421 from a UI ticket.

---

## Safety — not performed

- Create shipment / call or enable Shiprocket: **NO**
- Modify RDE318421 / production data / permissions / application code / `.env`: **NO**
- Invent country, parcel, channel id, or provider data: **NO**
- Commit / push / deploy: **NO**
