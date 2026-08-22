# Radium Desk — Backup Runbook

**Prompts:** P18-08-023 (local staging), P18-08-025 (Cloud upload), P18-08-034 (Cloud retention)  
**Scripts:** [`bin/backup-run.sh`](../bin/backup-run.sh), [`bin/backup-prune-cloud.sh`](../bin/backup-prune-cloud.sh)

---

## Implemented phases

| Phase | Status | Scope |
|-------|--------|--------|
| 1 — Local staging | **Implemented** | Encrypt + manifest + local `runs/` |
| 2 — Cloud upload | **Implemented** | SSH/rsync to Hostinger Cloud (opt-in) |
| 3 — Scheduling | **Implemented** | KVM cron twice daily (02:00 and 14:00 IST) |
| 4 — Remote retention | **Implemented** | Standalone Cloud prune (`backup-prune-cloud.sh`); dry-run default |
| 5 — Desk read-only status | **Implemented** | Administration → Backups (`backups.view`, Super Admin only) |
| 5A — Cloud inventory index | **Implemented** | `bin/backup-cloud-inventory.sh` writes sanitized local JSON; Desk shows read-only Cloud table |
| 5B — Backup failure alerting | **Implemented** | `bin/backup-schedule.sh` + ProductionWatchdogService → Telegram critical alerts |
| 6 — Manual backup UX | **Future** | Trigger `backup-run.sh` from Desk (not implemented) |
| 7 — Restore drill + alerting | **Partial** | Restore drill proven on operator Mac; automated restore CLI still future |

---

## Architecture

```
KVM production (/var/www/radium-desk)
  │
  ├─ mysqldump --single-transaction --quick
  │     → gzip → encrypt (gpg or age)
  │
  ├─ tar.gz (.env + storage/app/google/*.json only)
  │     → encrypt
  │
  ├─ manifest.json (SHA-256 per artifact)
  │
  ├─ local staging: BACKUP_STAGING_ROOT/runs/<backup_id>/
  │
  └─ [optional] SSH/rsync → Hostinger Cloud
        work/uploading-<backup_id>/   (temporary)
          → verify sizes
          → YYYY/MM/DD/<backup_id>/   (final)
          → upload-complete.json
```

**Not backed up:** application code, `vendor/`, logs, cache, sessions, `storage/framework/`, `storage/app/private/db-sync/`, finance receipts in `storage/app/public/` (none on production today).

**Hostinger Cloud storage quota:** **Unknown** from codebase/SSH alone — confirm in hPanel before relying on long retention.

---

## Local staging layout

Default: `/var/backups/radium-desk` (`BACKUP_STAGING_ROOT`).

```
/var/backups/radium-desk/
  work/                         # transient local work
  runs/
    20260818T141800Z/
      manifest.json
      database.sql.gz.gpg       # or .age
      secrets.tar.gz.gpg        # or .age
```

Permissions: `700` directories, `600` artifacts.

---

## Cloud upload (Phase 2)

### Behaviour

- **Disabled by default.** Set `BACKUP_CLOUD_UPLOAD_ENABLED=true` to run after local staging succeeds.
- Uploads **only** `manifest.json`, encrypted database artifact, and encrypted secrets bundle.
- Never uploads plaintext `.sql`, `.sql.gz`, `.tar.gz`, logs, cache, or application files.
- Remote flow:
  1. `rsync` artifacts to `{REMOTE_ROOT}/work/uploading-<backup_id>/`
  2. Verify remote file sizes match local
  3. Move into `{REMOTE_ROOT}/YYYY/MM/DD/<backup_id>/`
  4. Upload `upload-complete.json` marker
  5. Update local `manifest.json` with `upload` metadata (artifact SHA-256 values unchanged)

### Remote directory structure

Default root: `/home/u215544208/backups/radium-desk` (example — set via env).

```
backups/radium-desk/                    # BACKUP_CLOUD_REMOTE_ROOT
  work/
    uploading-<backup_id>/            # temporary; removed after promote
  2026/
    08/
      18/
        20260818T141800Z/
          manifest.json
          database.sql.gz.gpg
          secrets.tar.gz.gpg
          upload-complete.json
```

Date folders derive from `backup_id` (`YYYYMMDD` prefix).

### Configuration variables

| Variable | Required when upload enabled | Default | Description |
|----------|------------------------------|---------|-------------|
| `BACKUP_CLOUD_UPLOAD_ENABLED` | — | **off** | `true` / `1` / `yes` to enable |
| `BACKUP_CLOUD_SSH_HOST` | **yes** | — | Cloud SSH host (e.g. shared hosting IP) |
| `BACKUP_CLOUD_SSH_USER` | **yes** | — | SSH user (e.g. `u215544208`) |
| `BACKUP_CLOUD_SSH_PORT` | no | `65002` | SSH port (Hostinger shared hosting convention) |
| `BACKUP_CLOUD_SSH_IDENTITY_FILE` | no | SSH default | Path to private key for KVM→Cloud |
| `BACKUP_CLOUD_REMOTE_ROOT` | **yes** | — | Remote base path (e.g. `/home/u215544208/backups/radium-desk`) |

**Not in git:** SSH private keys, encryption passphrases, DB credentials.

Example production values (from deployment tooling — verify before use):

| Setting | Example |
|---------|---------|
| Host | `187.127.183.72` |
| Port | `65002` |
| User | `u215544208` |
| Remote root | `/home/u215544208/backups/radium-desk` |

LCDS uses the same SSH/rsync pattern (`ExtractFileTransporter.php`, `tools/config.sh`).

### Manifest after Cloud upload

`phase` becomes `cloud_uploaded`. New `upload` block:

```json
"upload": {
  "status": "completed",
  "uploaded_at": "2026-08-18T14:18:00Z",
  "remote_host": "<host>",
  "remote_path": "/home/.../backups/radium-desk/2026/08/18/<backup_id>",
  "artifacts_verified": true
}
```

Remote `upload-complete.json` contains `backup_id`, `uploaded_at`, `remote_path`, `manifest_sha256`, `status`.

---

## Cloud retention (Phase 4)

**Script:** [`bin/backup-prune-cloud.sh`](../bin/backup-prune-cloud.sh)

Standalone KVM script. It does **not** run backups, does **not** change `bin/backup-run.sh`, and is **not** scheduled.

### Policy (UTC, completed backups only)

| Age | Keep |
|-----|------|
| 0–7 days | Both successful backups |
| 8–30 days | Latest successful backup per UTC day |
| 31–90 days | Latest successful Sunday per UTC week |
| >90 days | Delete (if completed and not protected) |

Always keep the newest completed backup. Never delete incomplete, unknown, or malformed directories. Never touch `work/` or `uploading-*`.

A Cloud directory is **completed** only after all of the following succeed:

- Path is `{REMOTE_ROOT}/YYYY/MM/DD/<backup_id>/` with matching date prefix
- `upload-complete.json` is valid JSON with `status=completed`
- Marker `backup_id` and `remote_path` match the directory
- `manifest_sha256` matches the remote `manifest.json`
- Encrypted database + secrets artifacts are present
- No plaintext `.sql` / `.sql.gz` / `.tar.gz` artifacts
- No extra files, subdirectories, or symlinks

Remote `manifest.json` `phase` stays `local_staging` by design and is **not** used as a completion signal.

### Behaviour

- **Dry-run by default** (no flags or `--dry-run`): classify and print KEEP / DELETE / SKIP; no remote `rm`
- **`--execute`**: delete only pre-validated allowlist files, then `rmdir` the leaf (and empty `DD` / `MM` / `YYYY` parents)
- `--dry-run` and `--execute` together: error
- Deletion uses explicit file names — never `rm -rf`, never follows symlinks
- First deletion failure aborts the run
- Optional `BACKUP_PRUNE_AS_OF=YYYYMMDDTHHMMSSZ` freezes “now” for tests

### Manual invocation

**Not scheduled. Do not `--execute` on production until a dry-run candidate list is reviewed.**

```bash
cd /var/www/radium-desk
set -a
source /root/.radium-backup.env
set +a
export PHP_BIN=/usr/local/lsws/lsphp84/bin/php
sudo -E ./bin/backup-prune-cloud.sh --dry-run
# sudo -E ./bin/backup-prune-cloud.sh --execute
```

Uses the same Cloud SSH variables as upload (`BACKUP_CLOUD_SSH_*`, `BACKUP_CLOUD_REMOTE_ROOT`). Does not need the encryption passphrase.

---

## Desk read-only status (Phase 5)

**Permission:** `backups.view` (Super Admin only)

**Route:** `/admin/backups` (`admin.backups.index`)

**Service:** `App\Services\Backup\BackupStatusService` reads `manifest.json` files from local staging (`BACKUP_STAGING_ROOT/runs/`). It never executes `backup-run.sh`, never exposes encrypted artifacts, passphrases, SSH credentials, or Cloud paths.

**Metadata shown:** backup ID, created timestamp, application version/build, database and secrets artifact sizes, cloud upload status, manifest integrity.

**KVM note:** staging under `/var/backups/radium-desk` is root-owned (`700`). The web process must be able to **read** the `runs/` directory and `manifest.json` files for live status. After each successful run, `backup-run.sh` restores the required traversal ACL masks (which `chmod 700` would otherwise clear) and grants read-only access to `manifest.json` only:

| Path | Desk (`ravi`) access |
|------|----------------------|
| Staging root | Traverse only (`u:ravi:--x`, mask `--x`) |
| `runs/` | List + traverse (`u:ravi:r-x`, mask `r-x`) |
| `runs/<backup_id>/` | Traverse (`u:ravi:r-x`, mask `r-x`) |
| `manifest.json` | Read only (`u:ravi:r`, mask `r`) |
| Encrypted `.gpg` artifacts | **No ACL** — remain `600 root:root` |

Encrypted `.gpg` artifacts are never granted a web-readable ACL. Directory traverse/list ACLs on `runs/` may still be configured separately on the KVM for pre-existing trees.

Optional env overrides:

| Variable | Default | Description |
|----------|---------|-------------|
| `BACKUP_MANIFEST_ACL_ENABLED` | `true` | Set `false` to skip manifest ACL (tests / non-KVM) |
| `BACKUP_MANIFEST_ACL_USER` | `ravi` | PHP/web user granted read on `manifest.json` |

**Future:** manual backup trigger (`backups.manage`), restore CLI.

---

## Scheduled backup wrapper + failure alerting (Phase 5B)

**Script:** [`bin/backup-schedule.sh`](../bin/backup-schedule.sh)

Thin KVM cron wrapper around unchanged [`bin/backup-run.sh`](../bin/backup-run.sh). It does **not** modify backup behaviour, credentials, or remote storage.

### Behaviour

- Acquires a **non-blocking** `flock` on `BACKUP_LOCK_FILE` (default `/var/lock/radium-backup.lock`).
- If the lock is held, records `outcome=lock_overlap` and exits **0** (skip without pile-up).
- Otherwise runs `bin/backup-run.sh`, classifies the result, and writes sanitized `last-run-status.json` (default `{BACKUP_STAGING_ROOT}/last-run-status.json`).
- Atomic write: `last-run-status.json.tmp.$$` → `mv`.
- Status file mode `600`; staging parent `700`.

- Restores staging traversal ACLs and grants read-only access to `last-run-status.json` for the web user (`u:ravi:r`), matching the manifest/inventory ACL model.
- Sets `watchdog_accessible` in the status JSON. If the read ACL cannot be applied, the wrapper logs an explicit error and leaves `watchdog_accessible: false` (watchdog failure/lock alerts are suppressed; stale detection via manifests still works).

### Status outcomes

| `outcome` | Meaning |
|-----------|---------|
| `success` | Local staging succeeded (`local_staging` or `cloud_uploaded` phase recorded) |
| `local_failure` | Local staging did not complete |
| `cloud_upload_failure` | Local staging succeeded but Cloud upload failed when upload is enabled |
| `lock_overlap` | Another run still holds the schedule lock |

The status JSON includes only: `version`, `generated_at`, `outcome`, `exit_code`, `duration_seconds`, `lock_acquired`, `cloud_upload_enabled`, `watchdog_accessible`, `backup_id`, `phase`, `error_summary`.

It never includes passphrases, API keys, SSH credentials, database passwords, remote paths, artifact hashes, or other secret-bearing paths.

### Watchdog / Telegram alerts

`App\Services\Backup\BackupWatchdogService` is collected by `ProductionWatchdogService` and delivered through the existing `watchdog:send-critical-alerts` → `WatchdogCriticalAlertGate` → Telegram critical-alert pipeline.

| Alert key | When |
|-----------|------|
| `backup:local_failure` | Last scheduled run ended `local_failure` |
| `backup:cloud_upload_failure` | Last scheduled run ended `cloud_upload_failure` |
| `backup:lock_overlap` | Last scheduled attempt was skipped due to lock overlap |
| `backup:stale` | No successful backup within `BACKUP_STALE_HOURS` (default **26**) |

Alerts dedupe via fingerprint gate (notify once, suppress repeats, clear on resolve, notify again when state returns).

### Configuration

| Variable | Default | Description |
|----------|---------|-------------|
| `BACKUP_LOCK_FILE` | `/var/lock/radium-backup.lock` | Schedule mutex (set to production path below) |
| `BACKUP_SCHEDULE_SKIP_LOCK` | off | Set `true` when outer cron `flock` already holds `BACKUP_LOCK_FILE` |
| `BACKUP_STATUS_PATH` | `{BACKUP_STAGING_ROOT}/last-run-status.json` | Sanitized last-run status |
| `BACKUP_ENV_FILE` | — | Optional shell env file sourced before `backup-run.sh` (e.g. `/root/.radium-backup.env`) |
| `BACKUP_MANIFEST_ACL_ENABLED` | `true` | Apply read ACL for status file (disable in tests only) |
| `BACKUP_MANIFEST_ACL_USER` | `ravi` | Web user granted read on `last-run-status.json` |
| `BACKUP_WATCHDOG_ENABLED` | `true` | Enable backup alerts in ProductionWatchdogService |
| `BACKUP_STALE_HOURS` | `26` | Stale threshold (twice-daily schedule + buffer) |

Laravel config: `config/backup.php` (`watchdog.*`, `status_path`, `schedule_lock_path`).

### Production lock path and double-flock behaviour

Production KVM cron today uses:

- Outer lock: `/usr/bin/flock -n /var/lock/radium-desk-backup.lock`
- Log: `/var/www/radium-desk/storage/logs/backup-run.log`

GNU `flock` holds the lock in the **flock parent process** for the lifetime of the cron command. A child `backup-schedule.sh` that calls `flock -n` on the **same** lock file will fail while the outer lock is held, and the wrapper would record `lock_overlap` without running the backup.

**Safe production invocation (keep existing outer flock unchanged):**

```bash
0 2 * * * /usr/bin/flock -n /var/lock/radium-desk-backup.lock sudo bash -c 'set -a; source /root/.radium-backup.env; set +a; cd /var/www/radium-desk && BACKUP_LOCK_FILE=/var/lock/radium-desk-backup.lock BACKUP_SCHEDULE_SKIP_LOCK=true ./bin/backup-schedule.sh' >> /var/www/radium-desk/storage/logs/backup-run.log 2>&1

0 14 * * * /usr/bin/flock -n /var/lock/radium-desk-backup.lock sudo bash -c 'set -a; source /root/.radium-backup.env; set +a; cd /var/www/radium-desk && BACKUP_LOCK_FILE=/var/lock/radium-desk-backup.lock BACKUP_SCHEDULE_SKIP_LOCK=true ./bin/backup-schedule.sh' >> /var/www/radium-desk/storage/logs/backup-run.log 2>&1
```

- `BACKUP_LOCK_FILE` must match the existing production lock path (`/var/lock/radium-desk-backup.lock`).
- `BACKUP_SCHEDULE_SKIP_LOCK=true` tells the wrapper to skip its inner flock because the outer cron flock already serializes runs.
- Keep `storage/logs/backup-run.log` (do not switch to `/var/log/radium-backup.log`).
- Manual runs without an outer flock should **not** set `BACKUP_SCHEDULE_SKIP_LOCK`.

### Recommended KVM cron (ops — not applied by deploy)

Replace bare `backup-run.sh` cron entries with the production-safe wrapper lines above.

### Manual invocation (when ops approves)

```bash
cd /var/www/radium-desk
sudo bash -c 'set -a; source /root/.radium-backup.env; set +a; cd /var/www/radium-desk && BACKUP_LOCK_FILE=/var/lock/radium-desk-backup.lock ./bin/backup-schedule.sh'
```

Do not set `BACKUP_SCHEDULE_SKIP_LOCK` for manual runs unless an outer flock is already held.

---

## Cloud inventory index (Phase 5A)

**Script:** [`bin/backup-cloud-inventory.sh`](../bin/backup-cloud-inventory.sh)

Standalone KVM script. It does **not** run backups, does **not** prune, and does **not** delete remote files.

### Behaviour

- Enumerates completed Cloud backups over SSH using the same validation model as [`bin/backup-prune-cloud.sh`](../bin/backup-prune-cloud.sh).
- Writes a sanitized local JSON index (default: `{BACKUP_STAGING_ROOT}/cloud-inventory.json`).
- Index entries include only: `backup_id`, `timestamp_utc`, `total_size_bytes`, `manifest_present`, `upload_complete`.
- Never writes remote paths, hosts, usernames, credentials, artifact filenames, hashes, or encryption details.
- Restores staging traversal ACLs and grants read-only access to `cloud-inventory.json` for the web user, matching the manifest ACL model used by `backup-run.sh`.

### Desk display

**Service:** `App\Services\Backup\BackupCloudInventoryService` reads the sanitized index. If the index is missing or unreadable, local backup status continues to work unchanged.

Administration → Backups shows the latest 10 Cloud entries in IST with a read-only notice. This is inventory metadata only — not download, restore, or manual backup.

### Manual invocation (when ops approves)

```bash
cd /var/www/radium-desk
sudo bash -c 'set -a; source /root/.radium-backup.env; set +a; ./bin/backup-cloud-inventory.sh'
```

Recommended after successful Cloud uploads or on a short ops schedule so the Desk table stays current.

---

## How to restore (manual)

<a id="how-to-restore-manual"></a>

**Restore is not available from Desk.** There is no one-click restore, no automated restore CLI in this release, and no Desk action that decrypts or imports backup data.

Use this section when an operator must recover from a known backup ID shown in Administration → Backups.

### Before you start

1. **Confirm the backup ID** from Administration → Backups (local staging and Cloud inventory tables).
2. **Choose a target environment** — always rehearse on a non-production clone first unless an approved emergency production recovery is in progress.
3. **Confirm application compatibility** — note the application version/build before importing data. For backups still present on the KVM, read this from the local staging manifest. For **Cloud-only recovery**, the operator must read `application.version` and `application.build` from the remote `manifest.json` for that backup ID (Desk inventory does not expose these fields).
4. **Plan rollback** — document the current production state and how you will abort if verification fails.

### Recovery outline

1. **Obtain the backup bundle** using operator access to local staging (`BACKUP_STAGING_ROOT/runs/<backup_id>/`) or Hostinger Cloud storage via your existing ops access. Desk does not expose download links or storage paths.
2. **Verify inventory flags** — the Cloud inventory index lists whether the remote copy had a manifest and upload-complete marker when the index was generated. Prefer backups where both are present.
3. **Decrypt offline** — use the operator-held encryption passphrase or age private key on a trusted machine. Never paste passphrases into Desk, tickets, or chat.
4. **Restore the database** — import the decrypted database dump into a clean MariaDB instance (staging first). Verify row counts, critical tables, and recent case/order samples.
5. **Restore critical secrets** — decrypt the secrets bundle and restore only the required application secrets (environment configuration and Google service account JSON) onto the target host. Restrict file permissions to the application user.
6. **Align application code** — deploy or checkout the application version that matches the backup era when possible; run migrations only after operator review if schema drift exists.
7. **Verify before cutover** — log in, run `/up`, smoke-test intake, payments, and queue processing on staging; only then repeat on production during a maintenance window.

### Safety rules

| Rule | Why |
|------|-----|
| Never restore onto live production without a maintenance window | Active users and webhooks can write data that conflicts with the imported snapshot |
| Never skip the staging rehearsal for unfamiliar backup IDs | Encryption, size, and schema surprises surface on the clone, not in production |
| Never expose decrypted SQL, `.env`, or key material in Desk or logs | Backups contain credentials and customer data |
| Never delete the current production data until the restore is verified | Rollback requires the pre-restore state |
| Treat Cloud and local copies independently | Confirm which copy you are restoring; incomplete uploads must not be used |

### After restore

- Re-run `./bin/backup-cloud-inventory.sh` if Cloud inventory should reflect post-recovery state.
- Record the recovery in audit/ops notes (backup ID, operator, target environment, verification outcome).
- Schedule a fresh backup once production is healthy again.

Automated restore verification drills remain a future phase (see Future phases below).

---

Local encryption runs **before** any Cloud upload. Unencrypted artifacts are never uploaded.

### GPG symmetric (KVM has `gpg`)

| Variable | Description |
|----------|-------------|
| `BACKUP_ENCRYPTION_PASSPHRASE` | Passphrase (avoid in cron; use file) |
| `BACKUP_ENCRYPTION_PASSPHRASE_FILE` | Path to passphrase file (`chmod 600`) |

### age public-key

| Variable | Description |
|----------|-------------|
| `BACKUP_AGE_RECIPIENT` | Public key for encryption |

Set `BACKUP_ENCRYPTION_METHOD=gpg` or `age` to force a method.

---

## Database credentials

Read from `.env` (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`). Passed to `mysqldump` via temporary `chmod 600` defaults file; never logged.

Engine: MariaDB 11.8.x on production KVM.

---

## Failure behaviour

### Local staging failure

- Exit non-zero; remove incomplete `work/` artifacts.
- **Never deletes** existing `runs/` directories.

### Cloud upload failure (after local success)

- Exit non-zero.
- **Local `runs/<backup_id>/` preserved** with `phase: local_staging`.
- **Does not delete** remote successful backups.
- **Does not prune** remote or local history.
- Incomplete remote `work/uploading-*` may remain until manual cleanup or a later successful run.

---

## Security considerations

| Topic | Notes |
|-------|-------|
| Encryption before upload | Cloud receives only gpg/age artifacts |
| SSH transport | Same pattern as LCDS; prefer key auth (`BatchMode=yes`) |
| Passphrase / keys | Not in git; passphrase file root-only on KVM |
| Cloud account access | hPanel can read uploaded files if not encrypted — encryption is mandatory |
| Same provider | KVM + Cloud are both Hostinger — not geographic DR |
| Quota | Unknown without hPanel — monitor usage |

---

## Manual invocation (when ops approves)

**Not scheduled.** Example:

```bash
cd /var/www/radium-desk
export BACKUP_ENCRYPTION_PASSPHRASE_FILE=/root/.radium-backup-passphrase
export BACKUP_CLOUD_UPLOAD_ENABLED=true
export BACKUP_CLOUD_SSH_HOST=187.127.183.72
export BACKUP_CLOUD_SSH_USER=u215544208
export BACKUP_CLOUD_SSH_PORT=65002
export BACKUP_CLOUD_REMOTE_ROOT=/home/u215544208/backups/radium-desk
export BACKUP_CLOUD_SSH_IDENTITY_FILE=/root/.ssh/radium_cloud_backup
export PHP_BIN=/usr/local/lsws/lsphp84/bin/php
sudo -E ./bin/backup-run.sh
```

Do not run until encryption passphrase and SSH key are provisioned.

---

## Tests

```bash
bash tests/scripts/backup-run.test.sh
bash tests/scripts/backup-schedule.test.sh
bash tests/scripts/backup-prune-cloud.test.sh
bash tests/scripts/backup-cloud-inventory.test.sh
php artisan test --filter=BackupWatchdog
```

Mock `mysqldump`, `mysql`, `gpg`, `rsync`, and `ssh` under `tests/scripts/fixtures/backup-mocks/`. **Does not connect to production or Hostinger Cloud.**

---

## Future phases (not implemented)

- Restore verification automation + operator restore CLI
- Manual backup trigger from Desk

See also: [`docs/infrastructure-readiness.md`](infrastructure-readiness.md) §12, [`docs/p18-08-001-database-growth-investigation.md`](p18-08-001-database-growth-investigation.md).
