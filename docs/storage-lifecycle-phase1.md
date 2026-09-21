# Phase 1 — Safe Server Storage Lifecycle (KVM8)

**Scope:** Radium Desk backup/log/metrics infrastructure on shared KVM8 VPS (`187.127.129.16`).

## Changes

| Component | Script / config | Purpose |
|---|---|---|
| Local backup retention | `bin/backup-prune-local.sh` | Delete cloud-verified Desk runs older than 7 days locally |
| Cloud backup retention review | `bin/backup-prune-cloud.sh` | Existing GFS dry-run scheduled; `--execute` requires owner approval |
| Desk Laravel log rotation | `deploy/logrotate-radium-desk-laravel.conf` | 14-day compressed rotation via logrotate |
| Storage metrics | `bin/storage-metrics-collect.sh` | Daily JSON snapshots, 365-day gzip retention |
| Storage alerts | `bin/storage-metrics-alert.sh` | Threshold evaluation → `last-status.json` (Telegram gap documented) |

## Local backup retention policy

- **Keep locally:** all runs ≤ 7 days old (by backup_id UTC date).
- **Delete locally (only with `--execute`):** runs > 7 days old **only if**:
  - `manifest.json` phase = `cloud_uploaded`
  - upload.status = `completed`, artifacts_verified = true
  - encrypted database + secrets artifacts present locally
  - remote `upload-complete.json` + manifest SHA-256 verified over SSH
  - not in active `work/backup-{id}-*` temp directory
- **Default mode:** dry-run (no deletions).

## Rollback

### Local prune cron

Remove the `backup-prune-local` cron line from `ravi` crontab. Deleted local runs are recoverable from Hostinger Cloud (`187.127.183.72`).

### Logrotate

```bash
sudo rm -f /etc/logrotate.d/radium-desk-laravel
```

Historical rotated logs remain in `/var/www/radium-desk/storage/logs/`.

### Metrics

Remove `storage-metrics-*` cron lines. Optional: delete `/var/backups/storage-metrics/`.

## Production cron (after deploy)

```
30 3 * * * sudo bash -c 'set -a; source /root/.radium-backup.env; set +a; cd /var/www/radium-desk && ./bin/backup-prune-local.sh --execute' >> /var/www/radium-desk/storage/logs/backup-prune-local.log 2>&1
15 4 * * 0 sudo bash -c 'set -a; source /root/.radium-backup.env; set +a; cd /var/www/radium-desk && ./bin/backup-prune-cloud.sh --dry-run' >> /var/www/radium-desk/storage/logs/backup-prune-cloud.log 2>&1
5 4 * * * /var/www/radium-desk/bin/storage-metrics-collect.sh >> /var/www/radium-desk/storage/logs/storage-metrics-collect.log 2>&1
10 4 * * * /var/www/radium-desk/bin/storage-metrics-alert.sh >> /var/www/radium-desk/storage/logs/storage-metrics-alert.log 2>&1
```

Cloud `--execute` is **not** scheduled in Phase 1.

## Alerting gap

`storage-metrics-alert.sh` writes `/var/backups/storage-metrics/last-status.json` only. Telegram delivery requires a future ProductionWatchdog integration (not in Phase 1).
