# P-17-65 — v4.1.89 pre-deployment gate (P-17-64 compact KPI strip)

**Status:** Owner-authorized deploy (P-17-65).

## Release identity

| Item | Value |
|------|--------|
| Version / tag | **v4.1.89** @ **`1bf5eff2a`** + CHANGELOG commit |
| Application merge | **#335** P-17-64 compact admin KPI strip |
| Production baseline | **v4.1.88** / **`6d0cc4008`** |
| Migrations | **None** — **`DEPLOY_RUN_MIGRATIONS=0`** |

## Rollback

- Code/tag: **v4.1.88** / **`6d0cc4008`** (`desk deploy` from tag **`v4.1.88`**).

## Deploy procedure

1. Fresh encrypted backup **`cloud_uploaded`** for **v4.1.88** prod.
2. Verify Hostinger snapshot **459755** post-backup (refresh only with Owner overwrite env if stale).
3. Pre-deploy RIP from live `release.json` build **`6d0cc4008`**.
4. Tag **`v4.1.89`**; `npm run build`; `./tools/desk deploy --yes` with **`DEPLOY_TIER=A`**, **`HOSTINGER_SNAPSHOT_GATE=1`**, **`RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT=1`**.
5. Post-deploy RIP; `/up`, `/login`; queue worker **RUNNING**; compact strip markers on dashboard HTML.