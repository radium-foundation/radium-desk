# Phase-1 isolated restore host — owner provisioning specification

**Project:** Radium Desk  
**Repository:** `git@github.com:radium-foundation/radium-desk.git`  
**Worktree:** `/Users/ravi/RadiumWebsites/radium-desk-phase1-clean`  
**Prompt ID:** **RadiumDesk-P-03-09-27**  
**Date:** 2026-09-03  
**Purpose:** Owner-facing specification to provision **one** dedicated, isolated MariaDB host for restore rehearsal of backup **`20260903T083001Z`** before Phase-1 production migrate/deploy.

**Status:** Specification only. **No host was provisioned in this ticket.**

Predecessors: P-03-09-16…26 (restore gates). Inventory: [`desk-phase1-restore-environment-inventory.md`](desk-phase1-restore-environment-inventory.md).

Classification key: **VERIFIED** = observed in prior gate tickets; **INFERRED** = derived from verified facts; **UNKNOWN** = not established — owner must confirm.

---

## 1. Host requirements

| Requirement | Specification | Class |
|-------------|---------------|-------|
| Purpose | **Dedicated restore-rehearsal only** for Radium Desk backup `20260903T083001Z` and Phase-1 migration validation. Not production, not staging for live traffic, not shared dev. | Required |
| Forbidden hosts | **`187.127.129.16`** (production Desk KVM), operator Mac Homebrew datadir, **`148.113.8.82`** (not verified), any Stocky / rdservice.in / rdservice.net / radiumsign.com / Admin production database host | VERIFIED forbidden list |
| Provider / IP | **Owner must choose and document.** Do not reuse undocumented aliases. Record hostname, public IP (if any), and provider name in ops docs before Cursor inspects. | Owner-supplied |
| OS | **Linux** with `systemd` (or equivalent service manager). Ubuntu 24.04 LTS or same major lineage as production KVM is **recommended**; exact image is owner choice. | INFERRED from production `MariaDB-ubu2404` in backup manifest |
| CPU | **Minimum 2 vCPU.** **Recommended 4 vCPU** for import + five Phase-1 migrations without excessive wall time. | INFERRED (no import benchmark exists) |
| RAM | **Minimum 4 GiB.** **Recommended 8 GiB** (production `mariadbd` uses multi-GB buffer pool on KVM). | INFERRED from production observation |
| Disk — absolute minimum | **≥20 GiB free** on the filesystem holding MariaDB datadir **and** temporary decrypt workspace. | VERIFIED operational floor (P-03-09-20 materialized path) |
| Disk — recommended | **≥32 GiB free** (20 GiB floor + headroom for logs, `gzip -l` probe, Phase-1 migration tables, and post-import `du`). | INFERRED |
| Disk — streamed-import floor | **≥12 GiB free** only if operator commits to **streamed** `gpg -d \| gunzip \| mysql` with **no** retained plaintext `.sql` and immediate deletion of ciphertext copy after import. | INFERRED (P-03-09-20); **20 GiB remains safer default** |
| MariaDB version | **MariaDB 11.8.x** matching production backup manifest `11.8.8-MariaDB-ubu2404`. Minor patch difference acceptable; **major version must match 11.8**. MySQL 9.x or other majors are **not acceptable**. | VERIFIED from backup manifest |
| Application software | **MariaDB server only.** No Desk PHP/Laravel deployment, no web server, no queue worker on this host. | Required |
| Network | Outbound access to copy backup from KVM staging or Cloud storage. **No inbound public application ports required.** SSH may be restricted to operator IP/VPN. | Required |

### Restore workload sizing (evidence)

| Item | Value | Class |
|------|-------|-------|
| Ciphertext `database.sql.gz.gpg` | 401508879 bytes (~0.37 GiB) | VERIFIED |
| Ciphertext SHA-256 | `03091ad39adf407b57d98705f823b909c52183db085568f4a658f62a4811ef43` | VERIFIED |
| Production `radium_desk` InnoDB datadir at inspect | ~5.66 GiB | VERIFIED (`du` on KVM) |
| Uncompressed SQL dump size | **UNKNOWN** until `gzip -l` after decrypt | UNKNOWN |
| Phase-1 migrations | Five additive migrations; empty inventory tables + commerce/statutory schema | VERIFIED |

---

## 2. Isolation requirements

The host **must** satisfy **all** rows. Any failure disqualifies the host.

| # | Requirement | Verification method (Cursor, pre-restore) |
|---|-------------|----------------------------------------|
| I1 | **Not** `187.127.129.16` and **not** sharing production `/var/lib/mysql` | Compare IP/hostname; inspect `datadir` path |
| I2 | **No production Desk application** at `/var/www/radium-desk` or equivalent | `test ! -f /var/www/radium-desk/artisan` or owner attestation + empty path |
| I3 | **No schema named `radium_desk`** containing live production data unless explicitly disposable rehearsal copy | `SHOW DATABASES`; rehearsal DB must use **different name** (see §6) |
| I4 | **Dedicated MariaDB datadir** — no other product schemas (Stocky, rdservice*, radiumbox, radiumsign, Admin, etc.) | List datadir contents / `SHOW DATABASES` |
| I5 | **No Stocky** databases or Stocky application paths | Owner attestation + empty `SHOW DATABASES` |
| I6 | **No network route** from rehearsal DB to production `127.0.0.1:3306` on KVM (separate machine satisfies this) | Host identity ≠ production IP |
| I7 | **No public web exposure** — ports 80/443/8080 closed or firewalled; no customer-facing vhost | `ss -tlnp` / cloud firewall rules |
| I8 | **MariaDB bind** — `127.0.0.1` only **or** firewalled private IP; **not** `0.0.0.0:3306` on the public internet | `ss -tlnp`, `my.cnf` |
| I9 | Host is **disposable** — owner authorizes `DROP DATABASE` + optional VM delete after rehearsal | Written owner approval |

**Explicitly forbidden as restore targets:** production KVM MariaDB, shared Homebrew datadir, `148.113.8.82` until independently re-verified, Cloud storage host `187.127.183.72` (storage only).

---

## 3. Access requirements

| Requirement | Specification |
|-------------|---------------|
| SSH user | Dedicated ops user (e.g. `ravi`) or `root` — owner choice; document in ops config |
| Authentication | **Public key only** for Cursor automation; disable password auth if possible |
| SSH config | Add **`Host desk-restore-rehearsal`** (or agreed name) to operator `~/.ssh/config` with **correct** `HostName`, `Port`, `User`, `IdentityFile` |
| Documentation | Record in this repo (restore runbook section or `tools/config.sh` comment block): hostname/IP, provider, SSH alias, purpose, owner approval date |
| Credentials | **Never** commit private keys, DB passwords, or GPG passphrase to Git |
| Terminal output | Cursor must **not** print secrets, passphrases, or `.env` contents in reports |

Cursor will **not** guess SSH credentials. If login fails, rehearsal stops.

---

## 4. Backup transfer requirements

### Source (choose one; do not alter source)

| Source | Path | Class |
|--------|------|-------|
| **Primary (recommended)** | KVM staging: `/var/backups/radium-desk/runs/20260903T083001Z/` on **`187.127.129.16`** | VERIFIED |
| Alternate | Cloud: `/home/u215544208/backups/radium-desk/2026/09/03/20260903T083001Z/` on **`187.127.183.72`** (port **65002**, user **`u215544208`**) | VERIFIED manifest claim |

### Files to transfer (ciphertext only)

| File | SHA-256 (must match after transfer) |
|------|-------------------------------------|
| `database.sql.gz.gpg` | `03091ad39adf407b57d98705f823b909c52183db085568f4a658f62a4811ef43` |
| `secrets.tar.gz.gpg` | `03f0a17236c056a74b0e1242f03c982840a0df3b674b142098d9312cec58252c` |
| `manifest.json` | Compare `backup_id`, `application.version`, artifact sizes |

### Transfer rules

1. Copy **ciphertext only** — use `rsync -av` or `scp`; **do not decrypt on production KVM** beside live data.
2. Destination on isolated host: e.g. `/var/backups/radium-desk-rehearsal/20260903T083001Z/` (owner choice; document path).
3. After transfer, run **`sha256sum`** on isolated host and compare to manifest — **must match exactly**.
4. **Do not delete, overwrite, or modify** the source backup on KVM or Cloud.
5. `secrets.tar.gz.gpg` is **not required** for DB restore rehearsal; transfer optional unless app-level rehearsal is planned.

---

## 5. GPG / passphrase requirements

| Rule | Detail |
|------|--------|
| Encryption | `gpg-aes256-symmetric` per backup manifest | VERIFIED |
| Passphrase location today | `/root/.radium-backup-passphrase` on production KVM (root-only); **not** on operator Mac | VERIFIED |
| Approved mechanisms on isolated host | (a) Operator enters passphrase via **`GPG_TTY` + pinentry** at decrypt time — **preferred**; or (b) root-only file **`/root/.radium-desk-backup-passphrase`** mode **`600`**, never copied to Git/chat | Owner implements |
| Forbidden | Pasting passphrase into Cursor chat, tickets, Slack, or shell history (`set +o history` / space-prefix if typing); storing in Git; decrypting on production KVM; printing in completion reports |
| Cursor behavior | Verify **passphrase availability** by confirming mechanism exists (file path present **or** owner confirms pinentry); **never read or display** passphrase contents |
| Decrypt location | **Isolated host only**, in dedicated workspace directory; delete plaintext artifacts when no longer needed |

---

## 6. Database requirements

| Item | Specification |
|------|---------------|
| Engine | MariaDB **11.8.x** standalone instance |
| Datadir | Dedicated path (e.g. `/var/lib/mysql` on **empty** VM — no pre-existing schemas) |
| Restore database name | **`radium_desk_restore_rehearsal_20260903`** (or owner-named; must **not** be production `radium_desk` on KVM) |
| DB user | Dedicated user e.g. **`radium_desk_rehearsal`** with grants **only** on rehearsal schema |
| Password | Owner-generated; stored in root-only file on isolated host — **not** in Git |
| Connectivity | Localhost import only; **no** replication link, FEDERATED, or SSH tunnel to production `radium_desk` |
| Post-restore | Run five Phase-1 migrations from `feat/rdservice-net-phase1-clean` against rehearsal schema only |
| Disposal | Owner approves `DROP DATABASE radium_desk_restore_rehearsal_20260903;` and optional VM termination after gate passes |

### Five Phase-1 migrations (rehearsal only)

1. `2026_09_01_120000_create_inventory_and_pos_foundation_tables.php`  
2. `2026_09_01_140000_add_inventory_branch_assignments_and_sale_idempotency.php`  
3. `2026_09_01_160000_create_statutory_invoice_foundation_tables.php`  
4. `2026_09_01_180000_create_channel_order_ingest_tables.php`  
5. `2026_09_02_130000_create_statutory_invoice_documents_table.php`

---

## 7. Restore-rehearsal readiness criteria

Cursor must **VERIFIED** every row before starting decrypt/import. Any **UNKNOWN** → **STOP**.

| # | Check | Pass criterion |
|---|-------|----------------|
| R1 | Host identity | Documented IP/hostname ≠ `187.127.129.16` and ≠ `148.113.8.82` |
| R2 | Ownership | Provider and owner recorded in ops docs |
| R3 | Non-production | No live Desk app, no production `radium_desk` traffic, owner written approval |
| R4 | Disk | `df -h` shows **≥20 GiB** free on datadir filesystem (or **≥12 GiB** with streamed-import plan documented) |
| R5 | MariaDB version | `mariadb --version` → **11.8.x** |
| R6 | Datadir boundary | `SHOW DATABASES` empty or only rehearsal schemas; no Stocky/sibling product DBs |
| R7 | Network isolation | MariaDB not exposed on public `0.0.0.0:3306`; no production app ports |
| R8 | SSH access | Cursor can `ssh` using documented alias without password prompt |
| R9 | Backup present | Ciphertext files on isolated host; **`sha256sum` matches manifest** |
| R10 | Passphrase mechanism | Pinentry or root-only passphrase file confirmed; contents **not** read by Cursor |
| R11 | DB/user boundary | Rehearsal database/user created; no access to external production DB |
| R12 | Rollback/disposal | Documented: `DROP DATABASE …`; remove ciphertext; optional destroy VM |

After import + migrations, additional checks (separate ticket):

- Row counts / `MAX(id)` on `orders`, `incidents`, etc. vs expectations  
- Five new tables exist; production tables on KVM unchanged  
- Phase-1 migration log shows five `Ran` entries on rehearsal DB only  

---

## 8. Explicit owner action (smallest concrete step)

**Provision one new empty Linux VM** (or dedicated server) with:

1. **≥32 GiB** disk free, **4 vCPU**, **8 GiB RAM** (minimum **20 GiB** disk / 2 vCPU / 4 GiB if constrained).  
2. **MariaDB 11.8.x** installed with an **empty** dedicated datadir.  
3. **SSH key access** for the operator/Cursor user; provide **`HostName`**, **`Port`**, **`User`** for a new `~/.ssh/config` entry (suggested alias: **`desk-restore-rehearsal`**).  
4. **Written confirmation** (email/ticket comment) that the host is **restore-rehearsal only**, disposable, and **not** production / not Stocky / not `148.113.8.82`.  
5. **Passphrase availability** on that host via pinentry or a root-only file the operator will use at decrypt time — **without sending the passphrase to Cursor**.

After the host exists, open a **follow-up Cursor ticket** with:

- SSH alias name and IP  
- Provider name  
- MariaDB version output  
- `df -h` summary  
- Confirmation that the host is empty and approved for rehearsal  

Cursor will then run checklist §7 before any decrypt/import.

---

## What this specification did not authorize

Provision VM, install MariaDB, transfer backup, decrypt, import, migrate production, deploy Desk, merge/tag/push, read GPG passphrase, modify any existing host — all **NO — Not performed.**

---

## Related documents

| Document | Role |
|----------|------|
| [`desk-phase1-restore-environment-inventory.md`](desk-phase1-restore-environment-inventory.md) | Why no current host qualifies |
| [`desk-rdservice-net-phase1-restore-environment-gate.md`](desk-rdservice-net-phase1-restore-environment-gate.md) | Full gate history |
| [`docs/backup-runbook.md`](backup-runbook.md) | Manual restore procedure |
| [`desk-phase1-release-preparation.md`](desk-phase1-release-preparation.md) | v4.0.65 release waiting on this gate |
