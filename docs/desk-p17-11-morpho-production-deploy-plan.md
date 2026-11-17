# P-17-11 — Morpho production deploy and activation plan

**Risk:** HIGH  
**Deploy target:** tag **`v4.1.79`** on **`main`** (includes **`v4.1.78`** application **`9d3d21107`**, PRs **#302–#305**, release metadata **#305** docs).  
**Production pre-deploy:** **`v4.1.77`** / **`da7081cf8`**.

## Recovery evidence (pre-flight 2026-11-17 IST)

| Gate | Evidence |
|------|-----------|
| Hostinger KVM | VM **`1910783`** / **`srv1910783.hstgr.cloud`** / **`187.127.129.16`** — preflight **PASS** |
| VPS snapshot | ID **`459755`**, created **`2026-11-17T02:50:15Z`**, expires **`2026-11-18T02:50:15Z`**, **`recovery-point-verified`** (fresh create **skipped** — valid snapshot present; overwrite **not** Owner-authorized) |
| DB backup | **`20261117T034121Z`**, phase **`cloud_uploaded`**, DB SHA256 **`19b99a002299e0bf4f2659710eb62a3fd611950c1bff2bd1bcb4207294efc935`** |
| GPG decrypt probe | **NOT RUN** in this session (non-interactive gpg on operator path); backup script completed **without error** |
| Pre-deploy RIP | **`RIP-171126-0913-radium-desk-pre-p17-11-morpho-v4179-da7081c`** — **`rip:verify-checkpoint` PASS** |

## Rollback

| Step | Action |
|------|--------|
| Application | Redeploy tag **`v4.1.77`** @ **`da7081cf8`** from clean worktree (`./tools/desk deploy --yes`) |
| Morpho flag | Remove or set **`DASHBOARD_OEM_HANDOFF_MORPHO_CAPTURE=false`**; **`php artisan config:cache`** |
| OTBS | Keep **`MORPHO_OTBS_LIVE_ENABLED=false`** (unchanged) |
| Mantra | **Do not** change **`DASHBOARD_OEM_HANDOFF_MANTRA_CAPTURE`** |
| RIP | Capture post-rollback checkpoint referencing **`da7081cf8`** |

## Deployment sequence (after separate Owner final approval)

1. Clean worktree on **`main`**, checkout tag **`v4.1.79`** exactly (`git describe --exact-match`).
2. `npm run build`; `./tools/desk deploy --yes` with **`DEPLOY_TIER=A`**, **`HOSTINGER_SNAPSHOT_GATE=1`**, **`HOSTINGER_SNAPSHOT_GATE_BASELINE_ID=459755`** (or successor snapshot ID if refreshed).
3. Confirm **`release.json`** → **`4.1.79`** / target build SHA; **`php artisan migrate:status`** — OEM migrations already **Ran** on prod (expect **Nothing to migrate**).
4. **`/up`** and **`/login`** HTTP **200**; queue worker **RUNNING**.
5. **Only then** set on production `.env`: **`DASHBOARD_OEM_HANDOFF_MORPHO_CAPTURE=true`** ( **`DASHBOARD_OEM_HANDOFF_ENABLED`** already true).
6. **`php artisan config:cache`**; restart **`radium-desk-queue-worker`**; **`systemctl reload lshttpd`** (per standard deploy).
7. **Verify flags:** `MORPHO_OTBS_LIVE_ENABLED` **absent or false**; Mantra capture **still true**.
8. Post-deploy RIP with new build short SHA.

## Owner live validation (Desk + extension)

**Prerequisites:** Staff Chrome with unpacked OEM extension **`v0.3.11`** (`tools/oem-warranty-extension` — run `npm test` before load).

1. Authenticate to production Desk; open a service case for the Owner’s **real Morpho device** (serial entered only in Desk UI — **not** logged).
2. Click **Morpho** warranty handoff; confirm portal tab: `https://rdserviceonline.com/pages/172iserialvalidatenumber`.
3. Confirm serial prefill matches the case row (session token correlated in network/UI).
4. Operator: manual CAPTCHA + **Check** on OEM site; extension reads **displayed** result only.
5. Return to Desk; confirm draft accepted, whitelist classification, and optional **`AW-DD-MM-YYYY`** REF prefill when expiry ≥ today + **3** calendar days (**Asia/Kolkata**).
6. **Do not** complete assignment (green tick) unless Owner explicitly confirms device and operation safety.
7. Negative paths: invalid / mismatched serial → no eligible AW prefill (server + client rules).

## Protected invariants

- No OTBS HTTP, no private AJAX replay, no CAPTCHA automation.
- Desk remains payment authority; no Cashfree or order/payment data changes in this deploy.
- **`MORPHO_OTBS_LIVE_ENABLED=false`** permanently for this rollout.

## Smoke (automated, no customer serial)

- Mantra handoff path unchanged (flags untouched).
- Morpho draft API returns **403/422** when capture flag **off**; after flag **on**, session start returns token (Owner validation uses real serial).