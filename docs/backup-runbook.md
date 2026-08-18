# Radium Desk — Backup Runbook (Phase 1: Local Staging)

**Prompt:** P18-08-023  
**Status:** Phase 1 implemented — local generation only  
**Script:** [`bin/backup-run.sh`](../bin/backup-run.sh)

This document covers **local backup generation on the KVM** only. Cloud upload, scheduling, remote retention, alerting, and restore drills are **future phases**.

---

## Architecture (implemented)

```
KVM production (/var/www/radium-desk)
  │
  ├─ mysqldump --single-transaction --quick
  │     → gzip
  │     → encrypt (gpg symmetric or age public-key)
  │
  ├─ tar.gz (.env + storage/app/google/*.json only)
  │     → encrypt
  │
  ├─ SHA-256 manifest.json
  │
  └─ stage under BACKUP_STAGING_ROOT/runs/<backup_id>/
```

**Not backed up in this phase:** application code, `vendor/`, logs, cache, sessions, `storage/framework/`, `storage/app/private/db-sync/`, finance receipts in `storage/app/public/` (none on production today), or other disks.

**Not implemented:** rsync/SFTP upload to Hostinger Cloud, cron, Laravel scheduler hooks, remote retention, alerting.

---

## Staging layout

Default root: `/var/backups/radium-desk` (override with `BACKUP_STAGING_ROOT`).

```
/var/backups/radium-desk/
  work/                         # transient; cleaned after each run
  runs/
    20260818T141800Z/
      manifest.json
      database.sql.gz.gpg       # or .age
      secrets.tar.gz.gpg        # or .age
```

Permissions: `700` on directories, `600` on artifacts and manifest.

---

## Encryption (mandatory, fail-closed)

The script **never** leaves a completed backup in plaintext. If encryption is not configured, the run aborts before producing final artifacts.

### Option A — GPG symmetric (KVM has `gpg`)

Provide passphrase at runtime via **one** of:

| Variable | Description |
|----------|-------------|
| `BACKUP_ENCRYPTION_PASSPHRASE` | Passphrase string (avoid in production cron; use file) |
| `BACKUP_ENCRYPTION_PASSPHRASE_FILE` | Path to root-readable file containing passphrase |

Recommended production pattern (not yet deployed):

```text
/root/.radium-backup-passphrase   # chmod 600, root-only
```

Set `BACKUP_ENCRYPTION_PASSPHRASE_FILE=/root/.radium-backup-passphrase` when invoking the script.

### Option B — age public-key

| Variable | Description |
|----------|-------------|
| `BACKUP_AGE_RECIPIENT` | age public key (encryption only; decryption key kept offline) |

Requires `age` on PATH.

### Explicit method

Set `BACKUP_ENCRYPTION_METHOD=gpg` or `BACKUP_ENCRYPTION_METHOD=age` to force a method.

Auto-detection when unset:

1. `BACKUP_AGE_RECIPIENT` → age  
2. passphrase env/file → gpg  
3. otherwise → **fail**

**Do not** commit passphrases or private keys to git.

---

## Database credentials

Read from application `.env` (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`). Credentials are passed to `mysqldump` via a temporary `chmod 600` defaults file; passwords are **not** echoed to stdout/stderr.

Engine: MariaDB on production KVM (`mariadb` connection in Laravel).

---

## Manifest

Each run writes `manifest.json` with:

- `backup_id`, `created_at`, `phase` (`local_staging`)
- Application `version`, `build`, `deployed_at` from `storage/app/private/release.json` when present
- Database name and `engine_version` from `SELECT VERSION()`
- Per-artifact: `role`, `filename`, `size_bytes`, `sha256`, `encryption`

---

## Manual invocation (when approved for production)

**Not scheduled in this phase.** Example only:

```bash
cd /var/www/radium-desk
export BACKUP_ENCRYPTION_PASSPHRASE_FILE=/root/.radium-backup-passphrase
export PHP_BIN=/usr/local/lsws/lsphp84/bin/php
sudo -E env BACKUP_ENCRYPTION_PASSPHRASE_FILE="$BACKUP_ENCRYPTION_PASSPHRASE_FILE" \
  ./bin/backup-run.sh
```

Adjust paths for KVM PHP binary. Do not run until encryption passphrase storage is established and ops approves.

---

## Failure behaviour

- `set -euo pipefail` — any failed step aborts the run.
- Incomplete work under `work/` is removed on failure.
- **Existing successful runs under `runs/` are never deleted** by a failed run.
- Plaintext `.sql`, `.sql.gz`, or `.tar.gz` must not remain after encryption; the script verifies this.

---

## Tests

```bash
bash tests/scripts/backup-run.test.sh
```

Uses mock `mysqldump`, `mysql`, and `gpg` under `tests/scripts/fixtures/backup-mocks/`. Does not connect to production.

---

## Future phases (not implemented)

| Phase | Scope |
|-------|--------|
| 2 | Encrypted upload to Hostinger Cloud via SSH/rsync |
| 3 | KVM cron (twice daily) + `flock` |
| 4 | Remote retention on Cloud destination |
| 5 | Restore verification drill + alerting |

See also: [`docs/infrastructure-readiness.md`](infrastructure-readiness.md) §12 (high-level asset list).

---

## Related inspection

Pre-implementation findings: backup inspection conversation (P18-08-023 predecessor), [`docs/p18-08-001-database-growth-investigation.md`](p18-08-001-database-growth-investigation.md) for DB size context.
