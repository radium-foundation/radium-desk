# P-17-46 — v4.1.86 pre-deployment gate (prepare only)

**Status:** Prepared — **no production deploy** in P-17-46.

## Release identity (target)

| Item | Value |
|------|--------|
| Version / tag | **v4.1.86** (metadata commit atop **`5dff026aa`**) |
| Application merges | **#328** P-17-40 (`c452967d3`); **#329** / **#330** P-17-43 RBP gap recovery (`5dff026aa`) |
| Delta (P-17-40) | Retire package-photo upload UI, `package_photo_pending` filter, `POST` package-evidence; remove photo gates on mark shipped / external dispatch; preserve historical `GET` + 301 evidence/files/audit events |
| Delta (P-17-43) | `desk:recover-rbp-hardware-fulfilment-gaps`, gap monitor limit fix, Desk Cashfree authority gate (already hotpatched on prod — deploy must replace mixed tree with single artifact) |
| Production baseline | **v4.1.85** / **`b87b10c6f`** + partial P-17-43 file rsync (**mixed**) |
| Migrations | **None** — keep **`DEPLOY_RUN_MIGRATIONS=0`** unless Owner explicitly overrides |

## Production identity (pre-deploy)

| Check | Result |
|-------|--------|
| Live `release.json` | **v4.1.85** / **`b87b10c6f`** @ 2026-11-17T17:06:21+05:30 (**VERIFIED**) |
| `POST` package-evidence on prod | **Still registered** — P-17-40 **not** deployed |
| P-17-43 hotpatch vs `5dff026aa` | Recovery PHP files **match** main; `release.json` still **b87b10c6f** |
| Package evidence inventory | **301** rows / **301** files / **301** audit events (**VERIFIED** read-only) |

## Backup and recovery (mandatory before deploy)

| Check | P-17-46 result |
|-------|----------------|
| Latest attested backup | **`20261117T083001Z`** `cloud_uploaded` (~08:33 UTC) |
| Covers post–v4.1.85 deploy + P-17-43 HF writes + admin serial work | **NO** — **BLOCKED** until fresh `backup-run.sh` |
| P-17-38 backup `20261117T113248Z` | **Not** revalidated in P-17-46; do not rely without fresh manifest |
| Hostinger snapshot | Re-verify baseline per runbook (reference **`459755`**) immediately pre-deploy |
| GPG decrypt / restore drill | **NOT RUN** — Owner policy |

## Rollback

- Code/tag: **v4.1.85** / **`b87b10c6f`** (restores upload workflow; does **not** remove P-17-43 HF rows).
- Schema: application-only.
- Mixed-state note: rollback deploy alone does **not** undo P-17-43 data recovery; hotpatched files removed when full `v4.1.86` deploy succeeds.

## Deploy procedure (Owner-authorized only)

1. **Fresh encrypted backup** with `cloud_uploaded` for **current** mixed production state.
2. Snapshot gate refresh per P-17-30 / P-17-38 pattern.
3. Pre-deploy RIP from live `release.json` build **`b87b10c6f`** (or document drift).
4. Clean worktree @ **`v4.1.86`** tag; `npm run build`; `./tools/desk deploy --yes` with standard gates.
5. Post-deploy: `release.json` **4.1.86** / metadata build; `/up`, `/login`; queue worker **RUNNING**; UAT per `docs/desk-p17-46-v4186-uat.md`.
6. Confirm **no** `POST` package-evidence route; historical **GET** smoke on sample evidence id.

## Operational safeguards

- **No** bulk fulfilment DB updates; UI/SQL classifier changes only.
- **No** payment, Cashfree, inventory allocation, or customer data changes in this release.
- Administrator serial/shipping work on P-17-43 cohort must not be interrupted during deploy window (coordinate with Owner).

## Operator acceptance

See `docs/desk-p17-46-v4186-uat.md`. Package-photo retirement visibility is the primary acceptance surface.