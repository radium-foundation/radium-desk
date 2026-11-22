# RadiumWebsites — Central Backup & Recovery Policy (Proposed)

**Global ID:** P-22-09  
**Owner:** Radium Desk (central policy); each spoke project retains its own DB, repo, and backup integration.  
**Status:** **PROPOSED** — review-only in Phase A; **not** active production configuration until Owner acceptance and merge.  
**Cursor ledger ID:** `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef`  
**Evidence date:** 2026-11-22 (IST)

This document defines the **three backup layers** plus **Release Integrity Protocol (RIP)** for the RadiumWebsites hub. Facts are labelled **VERIFIED**, **INFERRED**, **UNKNOWN**, or **BLOCKED**.

---

## 1. Scope and principles

| Principle | Detail |
|-----------|--------|
| **Hub policy, spoke execution** | Desk owns this policy and financial/statutory recovery authority. Each project implements its own backup jobs and verification. |
| **No shared-database assumption** | Production databases are independent (`radium_desk`, `radiumbox_prod`, `rdservice_in_prod`, `rdservice_net_prod`, `radiumsign_prod`). |
| **No financial rollback via restore** | Never restore a database to reverse a posted wallet credit, completed refund, or statutory ledger entry. Use authorized Desk ledger reversal/revoke coordinators only. |
| **Encryption** | Desk production DB backups use symmetric GPG (`database.sql.gz.gpg`). Passphrases and SSH keys are **not** in git. |
| **Same provider exposure** | KVM **1910783** (`187.127.129.16`) and Cloud backup host **`187.127.183.72`** (`u215544208`) are both Hostinger — correlated outage risk (**VERIFIED** addressing; schedule/retention **UNKNOWN** from API alone). |

---

## 2. Layer 0 — Release Integrity Protocol (RIP)

**Purpose:** Immutable **application** recovery points (code/runtime), not database or customer data.

| Item | Status | Evidence |
|------|--------|----------|
| Pre-deploy gate | **VERIFIED** | `RIP_REQUIRE_PRE_DEPLOY_CHECKPOINT=1`; `rip:verify-pre-deploy-gate` vs live `release.json` build |
| Post-deploy capture | **VERIFIED** | `capture-release-recovery-checkpoint.sh`; example post **v4.1.111**: `RIP-221126-1046-radium-desk-post-p22-08-deploy-5b9cf72` |
| Checkpoint storage | **VERIFIED** | `storage/app/private/release-checkpoints/<id>/` on KVM |
| Off-server RIP replication | **UNKNOWN** | Not configured in repo |
| Rollback boundary | **VERIFIED** | RIP + `deploy-kvm` rsync = **application only**; does not reverse MariaDB rows or wallet activity |

**Policy:**

- Tier A/B production deploys require a **verified pre-RIP** on the **current** production build.
- Post-deploy **deployed** checkpoint required before claiming recovery readiness; **smoke-verified** / **known-good** require manifest evidence per `docs/rip-release-checkpoint.md`.
- KVM authorized application rollback (P-22-06) uses allowlisted builds only; still **no** DB/financial reversal.

---

## 3. Layer 1 — Hostinger KVM automated full-VM backup

**VM:** **1910783**, `srv1910783.hstgr.cloud`, plan **KVM 8**, IPv4 **187.127.129.16** (**VERIFIED** via API).

| Item | Status | Notes |
|------|--------|-------|
| Purchased plan / billing | **UNKNOWN** | Requires hPanel billing export (not available in this audit) |
| Daily/weekly schedule | **UNKNOWN** | Hostinger API lists backups but not cron schedule |
| Retention | **INFERRED** | API returned **4** backup points (2026-11-09 through 2026-11-21) — not a confirmed retention rule |
| Encryption at rest (customer-managed) | **INFERRED** | Hostinger-managed; no customer KMS |
| Restore mechanism | **INFERRED** | Whole-VM restore per Hostinger docs — **all co-hosted apps** affected |
| Encryption / billing evidence | **BLOCKED** | Owner hPanel verification required |

**Policy:**

- Treat KVM backups as **last-resort whole-stack** recovery, not per-app or per-DB rollback.
- Before any VM restore, require explicit Owner approval and cross-project impact assessment (Desk, Box, RDIN, RDNET, RadiumSign, UAT).

---

## 4. Layer 2 — Encrypted Cloud database backups (per database)

### 4.1 `radium_desk` (automated — **VERIFIED** production path)

| Item | Evidence (2026-11-22) |
|------|------------------------|
| Mechanism | `bin/backup-run.sh` → local staging → optional Cloud upload |
| Schedule | **VERIFIED** cron **02:00** and **14:00 IST** on KVM (`backup-schedule.sh`) |
| Staging root | **VERIFIED** `/var/backups/radium-desk` |
| Encryption | **VERIFIED** GPG symmetric; passphrase file **present** on KVM (`/root/.radium-backup-passphrase` — path only, no secret) |
| Cloud upload | **VERIFIED** enabled; last status `cloud_uploaded`, id `20261122T042248Z` (pre-v4.1.111 manifest; newer KVM run may lag until next cron) |
| Cloud destination | **VERIFIED** `u215544208@187.127.183.72:65002`, root `/home/u215544208/backups/radium-desk` |
| Remote footprint | **VERIFIED** ~**75G** under `backups/radium-desk`; **96** November `upload-complete.json` markers (SSH find) |
| Watchdog stale threshold | **VERIFIED** default **26h** (`BACKUP_STALE_HOURS` in runbook / `config/backup.php`) |
| Local retention | **VERIFIED** `backup-prune-local.sh --execute` daily 03:30 IST |
| Cloud retention / prune | **DOCUMENTED** GFS in `backup-prune-cloud.sh`; cron runs **`--dry-run` only** Sundays 04:15 IST |
| Desk UI | **VERIFIED** `/admin/backups` read-only (`backups.view`) |
| Restore drill | **VERIFIED** P-22-09: schema `radium_desk_p2209_drill`, **282s**, **200** tables, **229** migrations, canonical index present; backup snapshot **pre** `2026_11_21` migration row |

**BLOCKED / gap — Cloud inventory & prune enumeration:**

- `backup-cloud-inventory.sh` reported **1** completed Cloud backup in local JSON while SSH **find** shows many completed markers (**VERIFIED** discrepancy).
- `backup-prune-cloud.sh --dry-run` classified only **`20260822T203001Z`** as completed (**VERIFIED**).
- **INFERRED:** validation (manifest / `upload-complete.json` / `manifest_sha256` rules) may reject newer uploads — **Phase B** must root-cause before any `--execute` prune.

### 4.2 `radiumbox_prod`

| Item | Status |
|------|--------|
| Automated schedule on KVM | **UNKNOWN** — no dedicated backup cron found in user crontab |
| Manual dumps | **VERIFIED** path `/var/backups/radiumbox-prod/` (ad hoc `.sql.gz` + SHA sidecars) |
| Cloud encrypted pipeline | **NOT RUN** for Box in Desk tooling |
| Off-server copy | **UNKNOWN** |

### 4.3 `rdservice_in_prod`

| Item | Status |
|------|--------|
| Desk `backup-run.sh` | **NOT APPLICABLE** |
| KVM evidence | **VERIFIED** directory `/var/backups/rdservice-in/` with dated pre-deploy / RIP artefacts |
| Automated encrypted Cloud | **UNKNOWN** |

### 4.4 `rdservice_net_prod`

| Item | Status |
|------|--------|
| Desk automation | **NOT APPLICABLE** |
| KVM evidence | **VERIFIED** `/var/backups/rdservice-net-prod/` (manual dumps + RIP-related files) |
| Automated encrypted Cloud | **UNKNOWN** |

### 4.5 `radiumsign_prod`

| Item | Status |
|------|--------|
| Dedicated `/var/backups/*sign*` tree | **UNKNOWN** — no `radiumsign-prod` directory observed; DB exists on shared MariaDB |
| Backup mechanism | **UNKNOWN** — requires spoke project audit (Phase B) |

### 4.6 Cloud account `u215544208` (quota)

| Item | Status |
|------|--------|
| Total usage / quota | **BLOCKED** — hPanel quota not retrieved; remote `du` shows **75G** Desk tree only |
| Legacy data | **VERIFIED** other paths under `/home/u215544208/backups/` (e.g. `rdserviceonline`, `makelinkit-mirrors`) — **do not delete** without Owner review |

**Policy (Layer 2):**

- Each production database must have a documented **schedule**, **encryption**, **off-server copy**, and **restore drill** at least annually or before Tier A deploys.
- Desk pipeline is the **reference implementation** for `radium_desk` only.
- **No `--execute` cloud prune** until enumeration bug is fixed and dry-run output is Owner-reviewed.
- Passphrase escrow: **Owner-held**; production uses `/root/.radium-backup-passphrase` + `/root/.radium-backup.env` (**VERIFIED** presence).

---

## 5. Layer 3 — Single-slot Hostinger VPS snapshot

| Item | Status | Evidence |
|------|--------|----------|
| Tools | **VERIFIED** | `desk hostinger-vps-preflight`, `hostinger-vps-snapshot-create --execute`, `hostinger_vps_snapshot_gate.py` |
| Slot count | **INFERRED** | One snapshot; new create replaces prior (P-14-16 design) |
| Overwrite guard | **VERIFIED** | `HOSTINGER_SNAPSHOT_OVERWRITE_OWNER=1` + ledger auth for create |
| Expiry | **VERIFIED** | API `expires_at` (e.g. snapshot **470114** ~24h) |
| Scope | **VERIFIED** | Whole VM — all sites on **1910783** |
| Deploy gate | **VERIFIED** | `HOSTINGER_SNAPSHOT_GATE=1`, `DEPLOY_TIER=A|B`; final gate needs baseline id |

**Proposed daily snapshot governance (trigger):**

1. **First qualifying production-mutating event of the calendar day** (Tier A/B deploy, production migration, or other Owner-defined production mutation) — create or refresh snapshot with **separate Owner authorization** when slot occupied.
2. **Later same-day qualifying events** may **reuse** the verified snapshot **only if** Owner accepts **data-loss window** since snapshot time (MariaDB, uploads, financial activity).
3. **Never** silently replace a snapshot; **never** treat an old same-day snapshot as safe for late-day production data without explicit assessment.
4. **Never** use snapshot restore for routine app rollback — prefer Layer 0 + Layer 2.

---

## 6. Monitoring and alerts

| Signal | Mechanism | Status |
|--------|-----------|--------|
| Backup failure / stale | `BackupWatchdogService` → Telegram critical | **VERIFIED** wired |
| Lock overlap | `backup-schedule.sh` → outcome `lock_overlap` | **VERIFIED** |
| Cloud growth unchecked | Prune **dry-run only** | **VERIFIED** — growth risk **INFERRED** |
| Snapshot expiry | Hostinger gate + deploy abort | **VERIFIED** on Tier A when enabled |

---

## 7. Incident recovery order (default)

1. **Stop** further mutation; preserve logs and checkpoint IDs.
2. **Assess** financial/statutory impact — Desk authority; **no DB restore** for financial undo.
3. **Layer 0:** Redeploy known-good application build (RIP / authorized rollback).
4. **Layer 2:** Restore **affected database only** from encrypted backup to isolated instance first; verify; then controlled cutover (project-specific runbook).
5. **Layer 3:** Whole-VM snapshot only with Owner approval and cross-app impact sign-off.
6. **Layer 1:** Hostinger KVM backup restore only if Layers 0–2 insufficient — full outage posture.

---

## 8. Responsibility matrix

| Role | Responsibility |
|------|----------------|
| **Hostinger** | KVM automated backup service (schedule/retention per contract — **UNKNOWN** in-repo) |
| **KVM cron + Desk watchdog** | `radium_desk` scheduled backups, status JSON, stale alerts |
| **Cursor / engineering** | Backup tooling, audits, authorized drills, deploy gates |
| **Radium Desk Owner** | Central policy, encryption escrow, snapshot create/overwrite approval, financial recovery |
| **Spoke projects** | Own DB backup integrations, manifests, and verification evidence |

---

## 9. Phase B recommended sequence (not authorized in P-22-09)

1. Root-cause **`backup-prune-cloud.sh` / `backup-cloud-inventory.sh`** completion validation vs remote tree (**BLOCKED** until fixed).
2. Owner hPanel capture: KVM backup **schedule, retention, billing**; Cloud **quota** for `u215544208`.
3. Per-spoke backup matrix completion (`radiumbox_prod`, `rdservice_*`, `radiumsign_prod`) with schedules and last-success timestamps.
4. Post-deploy **`radium_desk`** Cloud backup freshness verification after **v4.1.111** (await **14:00** cron or manual read-only check).
5. Optional: fix cloud prune cron from `--dry-run` to reviewed `--execute` with Owner approval.
6. RIP schema extension for Hostinger snapshot metadata at capture time (design in P-14-16).
7. **Known-good** classification workflow for releases (protected journeys + Owner sign-off).

---

## 10. References

- `docs/backup-runbook.md`
- `docs/rip-release-checkpoint.md`
- `docs/P-14-16-hostinger-snapshot-deployment-gate-design.md`
- `bin/backup-run.sh`, `bin/backup-schedule.sh`, `bin/backup-prune-cloud.sh`, `bin/backup-cloud-inventory.sh`
- `tools/lib/hostinger_vps_snapshot_create.py`, `tools/lib/hostinger_vps_snapshot_gate.py`