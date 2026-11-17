# P-17-29 — v4.1.83 pre-deployment gate (prepare only)

**Status:** Prepared — **no production deploy** in P-17-29.

## Release identity (target)

| Item | Value |
|------|--------|
| Version / tag | **v4.1.83** (metadata commit atop **`ec3450d30`**) |
| App merge | **#321** (`b7985751d`) on **#320** / **#319** stack |
| Production baseline | **v4.1.82** / **`15fd092e3`** |
| Migrations | **None** — keep **`DEPLOY_RUN_MIGRATIONS=0`** |

## Deploy procedure (when authorized)

1. Fresh **Hostinger** snapshot VM **1910783** (P-17-27 snapshot **459755** expires **2026-11-18T08:47:29Z** — **replace before deploy**).
2. Verify encrypted backup **`cloud_uploaded`** for current prod era (latest known **`20261117T083001Z`** @ **4.1.81** app stamp — **new backup after v4.1.82 era recommended**).
3. Pre-deploy RIP naming: `RIP-DDMMYY-HHMM-radium-desk-v4183-predeploy-<prod-build7>`.
4. Worktree @ **`v4.1.83`** tag; `npm run build`; `./tools/desk deploy --yes` with `RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT=1`, `HOSTINGER_SNAPSHOT_GATE=1`, `HOSTINGER_SNAPSHOT_GATE_BASELINE_ID` after initial gate.
5. Post-deploy RIP @ release build; smoke `/up`, `/login`, queue worker, **no** real ref assign to customer orders.

## Rollback

- Code/tag: **v4.1.82** / **`15fd092e3`** + pre-deploy RIP.
- Schema: application-only (no new migrations).

## Queue / notifications

- Worker: `radium-desk-queue-worker` — queues `critical,notifications,default,maintenance`.
- Follow-up: `ProcessAssignReferenceBatchFollowUpJob` on **notifications** (unchanged routing; now also used for single inline assign).

## Operator smoke (no customer impact)

See `docs/desk-p17-29-single-inline-performance-acceptance.md`.

## Single-order statutory note

**Synchronous** `ServiceStatutoryInvoiceIssuer::issueAfterWorkflowCommit` remains on the HTTP thread for inline assign — expect statutory log lines before JSON response returns.