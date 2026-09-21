# Phase 2A — Cloud Backup Prune Enumeration Fix

**Prompt ID:** `RadiumDesk-P-21-09-01`  
**Date:** 2026-09-21  
**Type:** Investigation + script fix + dry-run validation only  
**Scope:** `bin/backup-prune-cloud.sh` enumeration bug; no cloud deletion

## Root cause

`backup-prune-cloud.sh` enumerates leaf backup directories into a bash `while read` loop fed by a here-string. Each iteration calls `remote_ssh_exec` (OpenSSH `ssh`). **OpenSSH reads stdin by default** when stdin is not a TTY, so the first `ssh` call consumed the remaining here-string lines. Only the first path was classified (production dry-run: **1/87**).

## Fix

Add `ssh -n` in `remote_ssh_exec()` to disable stdin forwarding. Same fix applied to `bin/backup-cloud-inventory.sh`, which uses the same enumerate/classify loop pattern.

## Validation (production dry-run, fixed script in `/tmp` only)

| Metric | Before fix | After fix |
|---|---:|---:|
| Completed classified | 1 | 87 |
| Skipped | 0 | 0 |
| KEEP | 1 | 40 |
| DELETE candidates | 0 | 47 |
| Bytes to free (dry-run) | 0 | ~17.2 GB |

Cloud `--execute` remains **not scheduled**. Owner must review dry-run DELETE list before any execution.

## Deployment gate

Production `/var/www/radium-desk/bin/backup-prune-cloud.sh` is unchanged until a separate deploy step copies the committed fix.
