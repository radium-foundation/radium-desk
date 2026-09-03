# Production backup / restore gate — rdservice.net Phase 1

**Project:** Radium Desk  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk`  
**Worktree:** `/Users/ravi/RadiumWebsites/radium-desk-phase1-clean`  
**Prompt ID:** **RadiumDesk-P-03-09-16**  
**Date:** 2026-09-03  
**Type:** Backup/restore gate only. **No production migrate, deploy, `.env` edit, invoice, or restore.**

**Canvas:** [`desk-rdservice-net-phase1-backup-restore-gate.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-RadiumWebsites-radium-desk-phase1-clean/canvases/desk-rdservice-net-phase1-backup-restore-gate.canvas.tsx)

Classification used throughout:

| Label | Meaning |
|-------|---------|
| **VERIFIED** | Observed in this ticket (git, live SSH, public HTTPS, or local process list) |
| **INFERRED** | Consistent with verified facts; not independently proven here |
| **UNKNOWN** | Not established. Not upgraded from prior tickets. |

---

## Inspect (this ticket)

| Item | Value | Class |
|------|-------|-------|
| Clean worktree | `/Users/ravi/RadiumWebsites/radium-desk-phase1-clean` | VERIFIED |
| Repository | `/Users/ravi/RadiumWebsites/radium-desk` (`origin` `git@github.com:radium-foundation/radium-desk.git`) | VERIFIED |
| Branch | `feat/rdservice-net-phase1-clean` (ahead of `origin/main` by 1) | VERIFIED |
| HEAD | `b76a1c8c3165fedc69fefc8031dc45edd7de68d2` | VERIFIED |
| Worktree status | Clean for application files. No Phase 1 code change in this ticket | VERIFIED |
| Dirty tree | `/Users/ravi/RadiumWebsites/radium-desk` still `feat/rd-fresh-01-inventory-pos` `b9bd2f43`. **Not modified** | VERIFIED |
| Production host | `srv1910783` / `187.127.129.16` (`tools/config.sh`) | VERIFIED |
| Production path | `/var/www/radium-desk` | VERIFIED |
| Production DB | `DB_CONNECTION=mysql` `127.0.0.1:3306` database **`radium_desk`**. Engine from latest manifest: MariaDB **11.8.8** | VERIFIED (names only; password never printed) |
| Live release | `storage/app/private/release.json`: version `4.0.64`, tag `v4.0.64`, build `0d734f85`, deployed_at `2026-08-31T13:19:03+05:30` | VERIFIED |
| Queue worker | `radium-desk-queue-worker` **RUNNING** | VERIFIED |
| Public `GET /up` | HTTP **200**, body “Application up” | VERIFIED |
| Public `GET /login` | HTTP **200** | VERIFIED |
| Loopback `http://127.0.0.1/up` | HTTP **404** (vhost; not used as health SoT) | VERIFIED |

`APP_ENV=production` `APP_DEBUG=false` `APP_URL=https://desk.radiumbox.com`. **VERIFIED.**

---

## Latest identified backup artifact

Staging root: `/var/backups/radium-desk` (`BACKUP_STAGING_ROOT`). **VERIFIED.**

| Field | Value | Class |
|-------|-------|-------|
| Backup ID | **`20260903T083001Z`** | VERIFIED |
| Created | `2026-09-03T08:30:01Z` (14:00 IST, scheduled slot) | VERIFIED |
| Local path | `/var/backups/radium-desk/runs/20260903T083001Z/` | VERIFIED |
| Files | `database.sql.gz.gpg` (401508879 bytes), `secrets.tar.gz.gpg` (6806 bytes), `manifest.json` (1223 bytes) | VERIFIED |
| Encryption | `gpg-aes256-symmetric` | VERIFIED (manifest) |
| Manifest `phase` | `cloud_uploaded` | VERIFIED |
| Manifest `upload.status` | `completed` | VERIFIED |
| Manifest `upload.uploaded_at` | `2026-09-03T08:32:02Z` | VERIFIED |
| Manifest `upload.artifacts_verified` | `true` | VERIFIED as a **manifest claim** |
| Application in manifest | `4.0.64` / `0d734f85` | VERIFIED |
| Database in manifest | `radium_desk` / `mariadb` / `11.8.8-MariaDB-ubu2404` | VERIFIED |
| Previous completed run | `20260902T203002Z` (`cloud_uploaded` / upload `completed`) | VERIFIED |
| Next scheduled slot | 2026-09-04 02:00 IST (`203001Z`) — not due at inspect | INFERRED from cron docs |

`last-run-status.json` is **stale and not SoT**: `generated_at` `2026-08-22T07:57:35Z`, sentinel `backup_id` `20991231T000000Z`, `exit_code` 1, `cloud_upload_enabled` false. **VERIFIED.** Do not use it to judge this backup.

`cloud-inventory.json` is **absent**. **VERIFIED.** Cloud presence is therefore known only from the local manifest upload block, not from a re-enumerated Cloud index.

---

## Backup integrity (ciphertext)

`sudo -n sha256sum` on the two encrypted artifacts (contents not printed) matched the manifest SHA-256 values exactly:

| Artifact | Manifest SHA-256 | On-disk SHA-256 | Size match |
|----------|------------------|-----------------|------------|
| `database.sql.gz.gpg` | `03091ad39adf407b57d98705f823b909c52183db085568f4a658f62a4811ef43` | **same** | 401508879 |
| `secrets.tar.gz.gpg` | `03f0a17236c056a74b0e1242f03c982840a0df3b674b142098d9312cec58252c` | **same** | 6806 |

The web user cannot read the `.gpg` files (`600 root:root`). Checksums used passwordless sudo and did **not** decrypt. **VERIFIED ciphertext integrity.**

This is **not** a restore. Decryptability, gzip validity, and SQL importability remain **UNKNOWN**.

---

## Documented restore mechanism

[`docs/backup-runbook.md`](backup-runbook.md) § “How to restore (manual)”:

- No Desk restore UI and **no automated restore CLI**. **VERIFIED** in runbook + no `*restore*` script in this repo.
- Operator must copy the bundle, decrypt offline with the operator-held GPG passphrase, import into a **clean non-production MariaDB**, then verify.
- Phase 7 is documented as **Partial**: “Restore drill proven on operator Mac; automated restore CLI still future.” That prior drill is **not** re-executed here and is **not** upgraded to VERIFIED for `20260903T083001Z`.

---

## Isolated restore target

| Candidate | Status this ticket | Safe to use? |
|-----------|--------------------|--------------|
| Production `radium_desk` on KVM `127.0.0.1:3306` | Live production | **No** |
| Local Homebrew `mariadb@11.8` / `mysql` | `brew services`: **none** (not running). No mysqld pid/socket | **No — not a running target** |
| Documented `radium_desk_local` | Template in `docs/local-development.md` only. No live local server, no local copy of this backup ID | **No — would require improvising start + import** |
| Docker MariaDB | None running | **No** |
| Local copy of `20260903T083001Z` | **Not found** on this workstation | **No** |

Prompt rule: if no conclusively isolated non-production restore target exists, **STOP**. Do not improvise one. Do not start MariaDB. Do not decrypt (passphrase would be exposed). Do not import onto production.

**Restore target: NONE. Restore rehearsal: BLOCKED.**

---

## Verdict

| Check | Result |
|-------|--------|
| Backup artifact exists | **VERIFIED** (`20260903T083001Z`) |
| Ciphertext SHA-256 vs manifest | **VERIFIED** |
| Manifest upload completed / size match | **VERIFIED** |
| Decrypt + SQL import | **UNKNOWN** |
| Restore rehearsal this ticket | **NO — Not performed** |
| Isolated restore target | **UNKNOWN/BLOCKED** — none running and none may be invented |
| Production migrate | **BLOCKED** until an owner provides a named non-production MariaDB and a restore rehearsal of this backup ID (or explicitly accepts residual restore risk) |

Production Desk was left up. No schema or data write. Dirty inventory worktree was not modified.

---

## What this ticket did not do

| Action | Status |
|--------|--------|
| Decrypt GPG artifacts | **NO — Not performed** |
| Import SQL | **NO — Not performed** |
| Start local MariaDB / create a clone | **NO — Not performed** |
| Production migrate / `deskd` / `.env` | **NO — Not performed** |
| Invoice / ingest / worker mint | **NO — Not performed** |
| Push / deploy | **NO — Not performed** |
| Dirty feature-branch change | **NO — Not performed** |
| rdservice.net / Admin / Sign / Stocky | **NO — Not performed** |
