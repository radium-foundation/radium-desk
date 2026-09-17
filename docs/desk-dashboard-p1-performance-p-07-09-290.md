# P1 Dashboard performance — Hardware chip and workspace

**Prompt ID:** RadiumDesk-P-07-09-290  
**Date:** 2026-09-15  
**Repository:** `radium-foundation/radium-desk`  
**Branch:** `cursor/dashboard-p1-performance-ade8`  
**Type:** Evidence-based SSR performance fix. No feature removal. No dashboard data cache. No production overlay.

## Pre-change verification

| Item | Value |
|------|--------|
| Ledger next ID | RadiumDesk-P-07-09-290 (after P-07-09-289) |
| Repo | `github.com/radium-foundation/radium-desk` |
| Base branch | `main` @ `c738d6355bfd6793e663ef0b3cdea93dfecf0999` |
| Production overlay | Named-file overlay to KVM `/var/www/radium-desk`. Live remains a superset of `main` (purchasing / historical-search). Wholesale `rsync --delete` remains unsafe. |
| Production login TTFB | `https://desk.radiumbox.com/login` ~0.76s (HEAD, unauthenticated) |
| Production `/dashboard` unauth | 302 to login, TTFB ~0.69s |
| SSH to KVM | Host key verification failed in this cloud agent — live authenticated SSR not measured |

Production-only overlays (Purchasing, Historical Orders) are **not** on `main` and are **not** loaded by `DashboardController` for `/dashboard`. C360 is lazy (drawer). B2B/date filters on Hardware use the existing `from`/`to` query params; default range is cutoff→now for awaiting/RIN only. Fulfilment rows remain unwindowed (same as before).

## Bottleneck (measured)

Same 24 Hardware fulfilment + 24 service-case fixture, admin SSR, Vite stubbed:

| Surface | Queries | Wall | HTML |
|---------|---------|------|------|
| `/dashboard?queue=hardware` **before** | **192** | **106.9 ms** | 141 KB |
| `/dashboard` Ready Queue **after** | 18 | 40.7 ms | 83 KB |
| `/dashboard?queue=hardware` **after** | **20** | **44.0 ms** | 141 KB |

Hardware-page query mix **before** (top):

- 48× `shipments where hardware_fulfilment_id = ?` (24 rows × chip+workspace)
- 48× `hardware_fulfilment_serials` allocated pluck
- 48× `statutory_invoices where id = ?`
- 2× unbounded `hardware_fulfilments` + eager commerce/items
- 6× `pluck source_id` / 4× `pluck support_order_id`

**Largest bottleneck:** `HardwareFulfilmentWorkQueue::allRows()` ran a full `HardwareShipmentEligibility::inspect()` for every fulfilment, including per-row SQL, and the Hardware **chip** (`workspaceTotal`) used that path on **both** Ready Queue and Hardware workspace. Hardware workspace then ran it **again**.

Not the majority:

- Snapshot/KPI path (still present; small vs Hardware inspect at N=24)
- Blade HTML (Hardware 141 KB unchanged)
- Live JS (Hardware already disables live merge)
- Purchasing / Historical Orders / C360 SSR (not on this request)

## Why the change is safe

Chip count is `COUNT(hardware_fulfilments) + awaiting work candidates + windowed RIN without fulfilment`. `allRows()` previously included every fulfilment plus those two extra sets and did not drop fulfilment rows. Awaiting/RIN filters are the same SQL predicates, with `whereNotExists` instead of `pluck` + `whereNotIn` (equivalent, avoids loading all source IDs into PHP).

Inspect still runs for the Hardware table. Serials/invoices/shipments use already-eager-loaded relations when present. `existingShipment()` treats a loaded **null** HasOne as “no shipment” instead of querying again.

No schema/index change. Rollback is revert of this commit.

## Remaining bottleneck

`allRows()` still loads **all** `hardware_fulfilments` and inspects each one when the Hardware table renders. That is O(rows) CPU, now O(1) SQL for inspect. If production fulfilment volume grows large, next step is paging/windowing the table (that **would** change which rows appear; not done here). Snapshot hydrate of all active incidents remains the Ready Queue data path.

## Production

**NO — Not performed.** Do not overlay until this branch is reviewed.
