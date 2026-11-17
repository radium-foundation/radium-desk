# P-17-32 — v4.1.84 pre-deployment gate (prepare only)

**Status:** Prepared — **no production deploy** in P-17-32.

## Release identity (target)

| Item | Value |
|------|--------|
| Version / tag | **v4.1.84** (metadata commit atop **`18975cc25`**) |
| App merge | **#323** — implementation **`fe40bd65a`**, merge **`18975cc250ba005bd5d3c850933d0a232967c8d9`** |
| Delta | POS bulk serial validation via **POST** `pos.serials.match.post`; counter textarea + **Validate and add**; `parsed_count` in JSON |
| Production baseline | **v4.1.83** / **`033d93b9f`** (deployed **2026-11-17** per P-17-30) |
| Migrations | **None** — keep **`DEPLOY_RUN_MIGRATIONS=0`** |

## Rollback

- Code/tag: **v4.1.83** / **`033d93b9f`** (or last known-good **`release.json`** build).
- Schema: application-only (no new migrations).

## Deploy procedure (when Owner-authorized)

1. **Fresh encrypted backup** with `cloud_uploaded` attestation for the **current** production era (**v4.1.83**). Last ceremony backup: **`20261117T094406Z`** (P-17-30) — **do not assume still current**; verify manifest before deploy.
2. **Hostinger snapshot gate:** baseline snapshot suitable for rollback (P-17-30 used **`459755`** refreshed **2026-11-17T09:44:19Z**). **Refresh or replace** if expired or older than policy.
3. Pre-deploy RIP: `RIP-DDMMYY-HHMM-radium-desk-v4184-predeploy-<prod-build7>`.
4. Deploy worktree @ **`v4.1.84`** tag; `npm run build`; `./tools/desk deploy --yes` with `RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT=1`, `HOSTINGER_SNAPSHOT_GATE=1`, `HOSTINGER_SNAPSHOT_GATE_BASELINE_ID` (+ workaround for subshell baseline if needed).
5. Post-deploy RIP @ release build; smoke `/up`, `/login`, queue worker; **no** real POS completion against customer orders until UAT passes.

## POS / inventory note

- **No** change to `PosSaleService::completeSale`, payment verification, or Cashfree paths.
- Risk is **serial pre-validation only**; allocation invariants unchanged.

## Operator acceptance

See `docs/desk-p17-32-pos-bulk-serial-uat.md`.