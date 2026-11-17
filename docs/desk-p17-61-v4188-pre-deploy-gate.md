# P-17-61 — v4.1.88 pre-deployment gate (P-17-60 serial Excel export)

**Status:** Prepared for Owner-authorized deploy.

## Release identity

| Item | Value |
|------|--------|
| Version / tag | **v4.1.88** (metadata atop **`b1c12bc70`** PR **#334**) |
| Application merge | **#334** P-17-60 Assign Service Reference serial Excel export |
| Production baseline | **v4.1.87** / **`72041beb9`** |
| Migrations | **None** — **`DEPLOY_RUN_MIGRATIONS=0`** |

## Rollback

- Code/tag: **v4.1.87** / **`72041beb9`** (removes export route/UI; no schema).

## Deploy procedure

1. Fresh encrypted backup `cloud_uploaded` for **v4.1.87** prod.
2. Verify or refresh Hostinger snapshot **459755** (Owner overwrite if stale vs backup/deploy).
3. Pre-deploy RIP from live `release.json` build **`72041beb9`**.
4. `release/v4.1.88` @ tag **`v4.1.88`**; `npm run build`; `./tools/desk deploy --yes` with standard gates.
5. Post-deploy RIP; `/up`, `/login`; queue worker **RUNNING**; modal Excel icon + export GET smoke.