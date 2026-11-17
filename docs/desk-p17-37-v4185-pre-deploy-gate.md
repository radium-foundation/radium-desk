# P-17-37 — v4.1.85 pre-deployment gate (prepare only)

**Status:** Prepared — **no production deploy** in P-17-37.

## Release identity (target)

| Item | Value |
|------|--------|
| Version / tag | **v4.1.85** (metadata commit atop **`2cd08018a`**) |
| App merge | **#325** — implementation **`7d5da02b8`**, merge **`2cd08018abab1c9fc6f779f09dae7e8210c091d8`** |
| Delta | Hardware dashboard: defer cold nav counts on progressive chunk 0; `RefreshHardwareDashboardNavCountsJob`; `GET /dashboard/hardware/counts`; 4+6 page-one chunks; idle page-2 prefetch; hardware KPI pending/workspace fix; `Server-Timing` on rows |
| Production baseline | **v4.1.84** / **`c4ed21f90`** (tag **`v4.1.84`** @ **`c4ed21f90af0fc9656483f55663e409f2f8da44b`**) |
| Migrations | **None** — keep **`DEPLOY_RUN_MIGRATIONS=0`** unless Owner explicitly overrides |

## Production identity verification

| Check | Result |
|-------|--------|
| Git tag **`v4.1.84`** → **`c4ed21f90`** | **VERIFIED** (local) |
| Live KVM **`release.json`** read in-session | **NOT VERIFIED** — `desk ssh` non-interactive session returned no payload (re-verify immediately before deploy) |

## Rollback

- Code/tag: **v4.1.84** / **`c4ed21f90`**.
- Schema: application-only (no new migrations).
- Cache: optional — cold nav-count keys are self-healing; no production cache flush required for rollback.

## Queue worker requirements

| Item | Requirement |
|------|-------------|
| Job class | `App\Jobs\HardwareFulfilment\RefreshHardwareDashboardNavCountsJob` |
| Interface | `ShouldQueue` + `ShouldBeUnique` (`uniqueFor = 60` seconds, id = date range + search) |
| Dispatch | `afterResponse()` from `HardwareDashboardCountsCache::queueBackgroundRefresh` on cold presentation miss |
| Queue name | **Default** (no `onQueue()` — same as `RefreshHardwareStuckOrdersSnapshotJob` pattern) |
| Worker | **`radium-desk-queue-worker`** must be **RUNNING** and processing the configured default queue (`database` on KVM per ops docs) |
| Failure impact | Rows remain usable with `counts_meta.stale` / nav `…` labels until warm cache, last-known snapshot, or client `/dashboard/hardware/counts` refresh |

**Pre-deploy verify (Owner/ops):** `systemctl status radium-desk-queue-worker`; confirm pending `jobs` table drain after a cold hardware dashboard load in staging or post-deploy smoke.

## Deploy procedure (when Owner-authorized)

1. **Fresh encrypted backup** with `cloud_uploaded` attestation for the **current** production era (**v4.1.84**). Do not reuse P-17-30/P-17-33 ceremony timestamps without manifest verification.
2. **Hostinger snapshot gate:** refresh or replace baseline per policy (P-17-30 reference snapshot **`459755`** — verify age/eligibility before deploy).
3. Pre-deploy RIP: `RIP-DDMMYY-HHMM-radium-desk-v4185-predeploy-<prod-build7>` (prod build from live `release.json`, expected **`c4ed21f90`** or newer if drift).
4. Clean worktree @ **`v4.1.85`** tag; `npm run build`; `./tools/desk deploy --yes` with `RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT=1`, `HOSTINGER_SNAPSHOT_GATE=1`, `HOSTINGER_SNAPSHOT_GATE_BASELINE_ID` as per current runbook.
5. Post-deploy RIP @ release build; smoke `/up`, `/login`, queue worker **RUNNING**; hardware dashboard journeys per UAT doc (no order/payment/inventory mutations beyond normal ops).

## Operational / dashboard note

- **No** change to order completion, serial allocation, payment verification, Cashfree authority, or customer data writes.
- Risk is **read-path latency** and **nav count freshness** — permissions unchanged (`incidents.view` + `canViewHardwareOrders` on rows/counts endpoints).

## Operator acceptance

See `docs/desk-p17-37-hardware-dashboard-perf-uat.md` and P-17-35 access-log methodology for post-deploy performance evidence.