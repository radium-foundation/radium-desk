# Production deploy overlay backup safety — P-07-09-164

**Project:** Radium Desk  
**Prompt ID:** `RadiumDesk-P-07-09-164`  
**Date:** 2026-09-14  
**Classification:** VERIFIED = evidenced on KVM during this prompt. INFERRED = consistent with docs/history. UNKNOWN = not established.

---

## Objective

Establish a reliable, project-specific, least-privilege backup location for named-file KVM overlay deployments without weakening encrypted scheduled backups or changing application architecture.

---

## Pre-change verification

| # | Item | Value | Class |
|---|------|-------|-------|
| 1 | Repository path | `/agent/repos/radium-desk` | VERIFIED |
| 2 | Branch (agent worktree) | `cursor/production-backup-safety-0a1f` from `main` | VERIFIED |
| 3 | HEAD SHA (before) | `2ddf3b44f364b563fc8e3b6ab2b50799add95c99` | VERIFIED |
| 4 | Worktree | Clean at start | VERIFIED |
| 5 | Remote | `origin` → `github.com/radium-foundation/radium-desk` | VERIFIED |
| 6 | Production branch | `main` (deploy gate in `deploy-kvm.sh`) | VERIFIED — `tools/config.sh` |
| 7 | Production path | `/var/www/radium-desk` | VERIFIED — `tools/config.sh` |
| 8 | Production server | `187.127.129.16`, SSH user `ravi` | VERIFIED — `tools/config.sh` + SSH |
| 9 | Deploy mechanism | KVM named-file overlay / rsync (`DEPLOY_MODE=kvm`) | VERIFIED |
| 10 | Backup directory (staging root) | `/var/backups/radium-desk` | VERIFIED |
| 11 | Staging root ownership | `root:root` | VERIFIED |
| 12 | Staging root permissions | `710` + ACL `user:ravi:--x` | VERIFIED |
| 13 | Deployment user | `ravi` (`uid=1000`, group `sudo`) | VERIFIED |
| 14 | Disk (`/var/backups`) | 193G total, ample free space | VERIFIED |
| 15 | Existing backups | `runs/` scheduled encrypted backups; legacy `p-07-09-*` and `pre-rbp94-recovery-*` dirs at staging root; in-app `storage/app/private/overlays/` | VERIFIED |
| 16 | Rollback procedure | Copy pre-change files from overlay backup dir back to live paths; feature flags per deploy report | VERIFIED — deploy ledger + `cashfree-box-desk-handoff-reliability-p-07-09-158.md` |
| 17 | Alternative mechanism | Legacy in-app `storage/app/private/overlays/` (ravi-writable) | VERIFIED — historical deploy reports; **not** used for new deploys after this gate |

---

## Problem (VERIFIED)

- `ravi` could **traverse** `/var/backups/radium-desk/` (`--x` ACL) but **not list, create, or write** at the staging root.
- Recent deploys placed overlay backups at the staging root via `sudo` (e.g. `pre-rbp94-recovery-20260914T150415`, `p-07-09-225-*`), while the deployment user alone could not create those directories — **incomplete pre-change backup protection** when sudo was skipped or failed.

Scheduled encrypted backups (`bin/backup-run.sh` → `runs/`) were **not** broken; they run as `root` via cron/sudo.

---

## Fix applied (production, 2026-09-14)

**Smallest change:** dedicated deploy-only subdirectory; no broad chmod/chown on staging root or `runs/`.

```bash
sudo install -d -o ravi -g ravi -m 750 /var/backups/radium-desk/overlays
```

| Path | After fix | Notes |
|------|-----------|-------|
| `/var/backups/radium-desk` | `root:root` `710`, ACL `user:ravi:--x` | Unchanged |
| `/var/backups/radium-desk/overlays` | `ravi:ravi` `750` | **New** — deploy user writable |
| `/var/backups/radium-desk/runs` | `root:root` `750` | Unchanged |
| `/var/backups/radium-desk/work` | `root:root` `700` | Unchanged |
| `/var/backups/radium-desk/config-backups` | `root:root` `700` | Unchanged |

**Not used:** `/var/backups/radiumbox-prod/` or any other project path.

---

## Validation (VERIFIED on KVM)

| Test | Result |
|------|--------|
| `ravi` lists/writes under `overlays/` | PASS |
| SHA-256 match after copying `config/radiumbox.php` into test backup dir | PASS |
| Deployment-style multi-file backup (`radiumbox.php`, `bootstrap/app.php`) | PASS |
| Rollback copy from backup dir restores identical SHA-256 | PASS |
| Test artifact delete | PASS |
| `ravi` still **cannot** `mkdir` at staging root | PASS (denied) |
| Scheduled backup areas ownership/mode | Unchanged |

No production application files were modified. No rollback executed. No deploy performed.

---

## Operator usage (future named-file deploys)

```bash
BACKUP_ID="p-07-09-XXX-$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP_DIR="/var/backups/radium-desk/overlays/${BACKUP_ID}"
mkdir -p "$BACKUP_DIR"
# Example: preserve paths
install -D /var/www/radium-desk/config/radiumbox.php "$BACKUP_DIR/config/radiumbox.php"
# ... overlay deploy ...
# Rollback (when approved): install -m 644 "$BACKUP_DIR/config/radiumbox.php" /var/www/radium-desk/config/radiumbox.php
```

Legacy backups at staging root and in `storage/app/private/overlays/` remain for historical rollback; do not delete without operator review.

---

## Remaining limitations

| Limitation | Class |
|------------|-------|
| Creating `overlays/` itself requires one-time `sudo` (already done) | VERIFIED |
| `ravi` cannot create sibling dirs at staging root (by design) | VERIFIED |
| Encrypted DB restore still manual per `docs/backup-runbook.md` | VERIFIED |
| `last-run-status.json` on production showed last success `20260912T131418Z` at inspection time — scheduled backup recency is a separate ops concern | VERIFIED read-only |

---

## Repository changes

- `docs/backup-runbook.md` — deploy overlay layout and permission model
- `docs/architecture/hub-spoke-payment-order-recovery.md` — rollback row upgraded to VERIFIED
- `docs/cursor-prompt-ledger.md` — this prompt
- This report

No application code, config, migration, or production deploy.
