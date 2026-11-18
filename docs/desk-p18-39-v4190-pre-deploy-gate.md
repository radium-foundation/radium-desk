# Desk P-18-39 — v4.1.90 pre-deploy gate (refund completion follow-up)

**Risk:** HIGH — financial workflow  
**Target tag:** `v4.1.90`  
**App build:** merge **#338** @ `ad42a57a4b26fa35405f054a9b4ca3990109f646` (+ changelog commit on `main` when tagged)  
**Production baseline:** `v4.1.89` / `41ccf222d`

## Recovery (20261118T083004Z)

| Check | Result |
|-------|--------|
| KVM ciphertext SHA vs manifest | **PASS** (root `sha256sum`) |
| Cloud ciphertext SHA vs KVM | **PASS** (P-15-28 preflight) |
| GPG decrypt + gzip structural | **PASS** (no plaintext retained) |
| SQL dump header (mysqldump 11.8.8) | **PASS** |
| P-15-28 isolated preflight (port 33328, disk, mariadbd) | **PASS** |
| Full isolated import drill | **NOT RUN** (Owner ceremony) |

**Note:** Backup timestamp **2026-11-18 14:00 IST** predates RN357/RN377 closure (**~14:05–14:08 IST**). Adequate for **code rollback**; for **DB point-in-time** including those refunds, run a **fresh backup** before deploy if Owner requires DB-aligned recovery.

## Deploy flags (Tier A)

```bash
export DEPLOY_RUN_MIGRATIONS=0
export DEPLOY_RUN_ROLE_PERMISSION_SEED=0
export DEPLOY_TIER=A
export HOSTINGER_SNAPSHOT_GATE=1
export RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT=1
# RIP_PRE_DEPLOY_CHECKPOINT_ID=<capture before deploy>
```

## Snapshot

Hostinger snapshot **459755** expires **2026-11-18T17:41:53Z** — refresh if deploy slips past expiry or after fresh backup (Owner auth).

## Post-deploy smoke

- `/up` + `/login` **200**
- `release.json` **4.1.90**
- Prod file `app/Jobs/Refunds/RefundCompletionFollowUpJob.php` present
- Log watch: `[Refunds] Completion follow-up failed after financial commit`
- **No** test refund execution