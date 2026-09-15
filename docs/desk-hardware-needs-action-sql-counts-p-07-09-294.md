# Hardware Needs Action SQL counts (P-07-09-294)

**Prompt ID:** RadiumDesk-P-07-09-294  
**Date:** 2026-09-15  
**Type:** Presentation counts + bounded row retrieval. No fulfilment state-machine change. **Not deployed.**

## Ledger / git

| Item | Value |
|------|--------|
| Ledger next ID | RadiumDesk-P-07-09-294 (after P-07-09-293) |
| Repo | `github.com/radium-foundation/radium-desk` |
| Branch | `cursor/hardware-needs-action-queue-ade8` |
| Before SHA | `ca2ebea4` (P-293) |
| Production path | `/var/www/radium-desk` (KVM, `desk.radiumbox.com`) |

## Classifier rules (unchanged)

PHP `HardwareFulfilmentOperationalRow::matchesWorkspaceFilter` is still the row classifier. SQL counts are allowed only where they match that rule on Active / non-ingested data.

| Queue | Existing rule | SQL equivalent | Converted? |
|-------|---------------|----------------|------------|
| Product Mapping Required | `statusLabel` product mapping, or RIN support row without fulfilment | Active HF: no allocated serials + missing channel SKU map; plus cashfree-paid RDE without fulfilment that has commerce physical lines missing a map; plus windowed RIN without fulfilment | **Yes** |
| Awaiting Serial | stage `AwaitingSerial` (no allocated serials, mapping present) | Active HF: no allocated serials + SKU map exists | **Yes** |
| AWB Pending | stage `AwbPending` (serials + invoice + bound shipment, AWB empty) | `EXISTS` allocated serials, statutory invoice number, bound shipment (`external_order_id` + `external_shipment_id`), HF AWB empty and no shipment AWB | **Yes** |
| Package Photo Pending | next action `Upload Package Photo` or status `Package photo pending` | Active HF: serials + invoice + bound + AWB + label URL + pickup requested + manifest URL/id + no package evidence (before-label or label-applied) | **Yes, Active only** |

**Not converted to inspect-free SQL:**

- Shipping **subfilters** (Out for Pickup / Ready for Pickup / In Transit / Picked Up) still inspect SQL shipping-candidate IDs because git-main `HardwareShipmentReadiness` has no persisted `providerTrackNormalized` (production overlay does). Parent Shipping **count** uses the post-AWB / not-photo-pending Active set, which matches git-main shipping stages (label / pickup / ready / track-less).
- All / Ready / Exceptions / Pickup / Scheduled still use `allRows()` inspect.
- Awaiting handoff (no commerce) is **not** mapping-required and stays off Needs Action (same as P-293).

**Intentional Active-scope difference vs P-293 inspect of shipped-without-photo:** shipped/synced rows are excluded from Package Photo Pending and Needs Action SQL counts, per this gate (“do not count Completed/Shipped”). They remain on Completed / All as before.

Git-main classifier reads `$ready->providerTrackNormalized ?? ''`, which is always empty on this HEAD. SQL therefore matches git-main, not a production overlay that stores track. Overlay deploy is a separate gate.

## Implementation

- `HardwareNeedsActionSqlQuery`: `COUNT` / `EXISTS` / `NOT EXISTS` for chip counts; identifier `OFFSET`/`LIMIT` (HF-only) or `UNION ALL` + `fromSub` (`hardware_identifier_page`) for mapping/RIN mixed pages. Search (`q`) still pages across Active non-ingested + work-candidates + windowed RIN.
- `HardwareFulfilmentWorkQueue::dashboard`: SQL path for Needs Action / shipping parent / completed; `hydratePage` inspects **page IDs only**. Request-scoped `HardwareFulfilmentWorkQueue` + `HardwareNeedsActionSqlQuery`.
- `hw_filter` still preferred over `hw_queue`.
- Pagination remains 40. No schema / index / Ably broadcast / Shiprocket mapping change.

## Tests

- `HardwareDashboardNeedsActionQueueTest` (counts, inspect budget, date/search, exceptions, 72+4 SSR)
- `HardwareFulfilmentDashboardNavigationTest`
- `DashboardHardwareSsrPerformanceTest` (serials EXISTS budget 16)
- `HardwareFulfilmentWorkQueueTest`
- `HardwareFulfilmentOperationalClassifierTest`
- Pint on dirty PHP
- Vitest: `live-dashboard-reverb`, `hardware-action-dialog`, `live-dashboard`

## Performance

**P-292 production** (`/dashboard?queue=hardware`, inspect-all): wall 3637 ms, SQL 407 ms, PHP 3230 ms, HTML ~2.12 MB, 936 rows, ~938 fulfilments inspected.

**P-293:** candidate set ~76 non-ingested; counts still inspect that set. Production not re-measured (not deployed).

**P-294 local SQLite** (72 ingested + 4 Needs Action, `/dashboard?queue=hardware`):

| Metric | Value |
|--------|--------|
| wall | 111.67 ms |
| SQL | 7.03 ms |
| PHP | 104.64 ms |
| queries | 88 (full dashboard including chip overlay) |
| HTML | 87 889 bytes |
| fulfilments inspected | 4 |
| rows rendered | 4 |
| Mapping / Serial / AWB / Photo | 1 / 1 / 1 / 1 |

SQLite is **not** proof of production wall time. Production-like validation of this SHA was **not** run against `radium_desk` / KVM.

## Deployment

**STOP — not deployed.** Production still has live overlays that differ from git-main (P-291 WorkQueue Active/Shipped chip, ConfigurableVariant + provider track). Named-file overlay of this SHA would replace overlay WorkQueue/classifier semantics unless those overlay files are re-merged. No `rsync --delete`. No `deploy-kvm.sh`. No indexes/migrations.

## Remaining bottlenecks

- Opening **All** still inspects every fulfilment.
- Shipping subfilters inspect all SQL shipping candidates (track not in git-main schema).
- Dashboard chip overlay still runs other service-case count queries (88 queries on the 76-row fixture page).
- No production re-profile until a dedicated overlay gate.
