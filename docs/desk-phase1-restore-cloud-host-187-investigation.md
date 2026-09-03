# Phase-1 restore host investigation — Hostinger Cloud `187.127.183.72`

**Project:** Radium Desk  
**Repository:** `git@github.com:radium-foundation/radium-desk.git`  
**Worktree:** `/Users/ravi/RadiumWebsites/radium-desk-phase1-clean`  
**Prompt ID:** **RadiumDesk-P-03-09-28**  
**Date:** 2026-09-03  
**Branch:** `feat/rdservice-net-phase1-clean`

**Verdict:** **CLOUD HOST 187.127.183.72 REJECTED — DEDICATED RESTORE HOST REQUIRED**

Read-only SSH verification was performed. **No host configuration, databases, backups, or services were modified.**

---

## Question

Can the existing Hostinger Cloud host `187.127.183.72` (documented backup/rsync target) be used **instead of** provisioning a new isolated VM for the Radium Desk Phase-1 MariaDB restore rehearsal?

**Answer: No.**

Prior gate docs classified this host as “storage only.” Live inspection shows it is **shared Hostinger web hosting** with a **legacy production Desk application**, a **live shared MariaDB database**, multiple product vhosts, and **public MySQL port exposure**. It does **not** meet isolation, database-boundary, or non-production requirements for restore rehearsal.

---

## Inspection method

| Source | Use |
|--------|-----|
| `docs/backup-runbook.md` | Documented SSH host/port/user |
| `tools/config.sh` | Legacy shared-hosting Desk paths |
| `docs/litespeed-php-infra-audit.md` | Prior shared-hosting Desk/MariaDB context |
| SSH `u215544208@187.127.183.72:65002` | Read-only live verification (operator default key) |
| External `nc` from operator Mac | Public port reachability |

**Not performed:** backup decrypt/restore, MariaDB install, database create (successful), firewall/DNS change, passphrase read, production KVM change, Stocky inspection.

---

## 1. Host identity and ownership

| Field | Verified value |
|-------|----------------|
| IP | `187.127.183.72` |
| PTR / hostname | `in-mum2-web2219.main-hosting.eu` (short: `in-mum2-web2219`) |
| Provider | **Hostinger** shared hosting (same family as legacy `desk.radiumbox.com` origin documented in `docs/litespeed-php-infra-audit.md`) |
| SSH user | `u215544208` (documented in `docs/backup-runbook.md`) |
| SSH port | `65002` (documented) |
| Account type | Shared hosting account — **not** a dedicated KVM/VM |

---

## 2. Documented purpose vs verified workloads

| Role | Documentation | Verified live state |
|------|---------------|---------------------|
| Desk backup upload target | `docs/backup-runbook.md` | **YES** — `/home/u215544208/backups/radium-desk/` with 32 encrypted DB artifacts |
| Storage-only | Prior gate/inventory (P-03-09-26/27) | **NO** — host runs web + DB workloads |
| Legacy Desk deploy | `tools/config.sh` `LEGACY_REMOTE_PUBLIC`, `INDEX_*` paths | **YES** — `/home/u215544208/laravel/radium-desk` present |
| Public web | `docs/litespeed-php-infra-audit.md` | **YES** — LiteSpeed/`lsphp` workers; `APP_ENV=production`, `APP_URL=https://desk.radiumbox.com` |
| Other vhosts | Partially documented | **YES** — 27 domains under `/home/u215544208/domains/` including `desk.radiumbox.com`, `admin.avimehna.com`, `rdserviceonline.in`, `media.radiumbox.com`, others |

**Current workloads (observed):** `lsphp` PHP workers, legacy Laravel Desk tree (670 MiB), multiple domain document roots with `.env` files, shared MariaDB used by Desk (81 open connections on shared server).

---

## 3. SSH access

| Check | Result |
|-------|--------|
| Documented credentials | `u215544208@187.127.183.72:65002` |
| Operator Mac SSH | **SUCCESS** (default key; no `~/.ssh/config` alias documented) |
| KVM backup key path | `/root/.ssh/radium_cloud_backup` on production KVM — not used from Mac |

---

## 4. CPU, RAM, disk

| Resource | Observed | Notes |
|----------|----------|-------|
| CPU cores visible to session | **64** | Whole **shared node**, not a dedicated allocation |
| RAM visible | **502 GiB total**, ~247 GiB available | Node-wide; not an isolated restore VM budget |
| Load average | **~20** | Shared node under load |
| Mount `/home/u215544208` | 21 TiB total, 16 TiB avail (28% used) | Filesystem-level; **per-account quota UNKNOWN** (`quota` unavailable) |
| User home usage | **36 GiB** used | Includes **11 GiB** Desk backups |
| Legacy Desk app | **670 MiB** | Active codebase |
| `desk.radiumbox.com` vhost | **2 MiB** | Public web root |

**Restore disk headroom:** Mount-level free space is ample, but the account already holds 11 GiB of backup artifacts and a 4.71 GiB live DB on shared MySQL. Decrypt + materialized import (~5.7 GiB inferred from production datadir) would compete with existing backup retention in the same home tree. **Per-account ceiling UNKNOWN.**

---

## 5. OS

| Item | Value |
|------|-------|
| Kernel | `Linux 5.14.0-611.42.1.el9_7.x86_64` |
| Uptime | ~160 days |
| `/etc/os-release` | Not readable from account shell |
| Hosting stack | CloudLinux + LiteSpeed (`lsphp`) |

---

## 6. MariaDB / MySQL

| Item | Verified |
|------|----------|
| Client | `/usr/bin/mysql` / `mariadb` — **11.8.8-MariaDB** |
| Server binary | `/usr/local/sbin/mariadbd` — **11.8.8-MariaDB-log** |
| Server process | **Not visible** in user `ps` (managed shared service) |
| Local socket | `/var/lib/mysql/mysql.sock` exists; datadir **not accessible** to account |
| Version match to production backup | **YES** (11.8.8) |

---

## 7. Existing database instances and datadirs

| Item | Verified |
|------|----------|
| Datadir | Shared `/var/lib/mysql` — **account cannot access or isolate** |
| Databases visible to Desk DB user | `information_schema`, **`u215544208_desk` only** |
| Live Desk schema size | **4.71 GiB**, **92 tables** (`php artisan db:show`) |
| Separate datadir / second `mariadbd` instance | **NOT possible** from this account (shared hosting model) |

The live schema name **`u215544208_desk`** is the legacy shared-hosting Desk database. Production KVM backup source schema is **`radium_desk`** — different name, same product lineage.

---

## 8. Production / sibling-project databases

| Check | Result |
|-------|--------|
| Live Desk DB on this host | **YES** — `u215544208_desk` (production Laravel env) |
| Production KVM `radium_desk` | **NO** — not on this host (different server `187.127.129.16`) |
| Other schemas in account | **Only one** user schema visible |
| Sibling products on same account | **YES** — multiple domains with `.env` (e.g. `admin.avimehna.com`, `media.radiumbox.com`, `radiuminfo.com`, `radiumlink.com`) |
| Stocky | **Not inspected** (per safety rule) |
| rdservice.in / rdservice.net / RadiumSign dedicated hosts | **Not on this IP**; `rdserviceonline.in` vhost **is** on this account |

**Conclusion:** Host is **shared multi-product web hosting**, not a Desk-only sandbox.

---

## 9. Shared-product isolation

| Product / surface | On `187.127.183.72`? |
|-------------------|----------------------|
| Legacy Desk (`desk.radiumbox.com`) | **YES** — live app + DB |
| RadiumBox-related vhosts | **YES** (`desk.radiumbox.com`, `media.radiumbox.com`, …) |
| Admin (`admin.avimehna.com`) | **YES** — vhost + `.env` |
| rdserviceonline.in | **YES** — vhost |
| rdservice.in / rdservice.net production | **NO** (different infra) |
| RadiumSign | **Not observed** on this account |
| Stocky | **Not inspected** |

---

## 10. Backup / rsync services and storage

| Item | Verified |
|------|----------|
| Remote root | `/home/u215544208/backups/radium-desk` |
| Target run | `/home/u215544208/backups/radium-desk/2026/09/03/20260903T083001Z/` |
| Artifacts | `database.sql.gz.gpg` (401508879 bytes), `secrets.tar.gz.gpg`, `manifest.json`, `upload-complete.json` |
| SHA-256 (`database.sql.gz.gpg`) | **`03091ad39adf407b57d98705f823b909c52183db085568f4a658f62a4811ef43`** — **matches** production gate |
| Total encrypted backup runs on host | **32** `database.sql.gz.gpg` files |
| Rsync/backup daemon config | **Not modified or inspected beyond paths** |

Backup copy on this host is **valid as a ciphertext source**. That does **not** make the host a safe **restore target**.

---

## 11. Separate MariaDB instance / datadir feasibility

| Requirement | Feasible on this host? |
|-------------|------------------------|
| Dedicated empty datadir | **NO** — shared `/var/lib/mysql`, not account-writable |
| Second `mariadbd` process under account control | **NO** |
| Additional rehearsal schema | **NO** — `CREATE DATABASE` **denied** for `u215544208_desk` user |
| Restore without touching live `u215544208_desk` | **NO** — no permission boundary for a second schema |
| Restore by overwriting live schema | **FORBIDDEN** — would destroy legacy production data and violate isolation rules |

---

## 12. Network exposure

| Port | External reachability (operator Mac `nc`) | Notes |
|------|---------------------------------------------|-------|
| **3306** | **OPEN** | **Critical isolation failure** for a restore-rehearsal DB host |
| 80 | OPEN | Expected — public shared hosting |
| 443 | OPEN | Expected — public shared hosting |
| 65002 | Used for SSH | Documented admin path |

MariaDB is reachable on the public IP even though Desk connects via `localhost` + socket. This fails the “no public application/database exposure” requirement for restore rehearsal.

---

## 13. Restore-rehearsal resource capacity

| Need | Assessment |
|------|------------|
| MariaDB 11.8.x | **YES** (version match) |
| ~20–32 GiB working space for decrypt/import | Mount avail **YES**; **account quota UNKNOWN**; home already 36 GiB used |
| CPU/RAM for import | Node has capacity, but **shared** — not reserved for rehearsal |
| Isolated disposable DB | **NO** |
| Migration rehearsal after restore | **Blocked** — no isolated schema |

---

## 14. Backup copy/decrypt/restore without harming existing backups

| Action | Safe on this host? |
|--------|-------------------|
| Copy ciphertext to temp dir (leave source intact) | **Likely YES** (disk permitting) — **not performed** |
| Verify SHA-256 after copy | **YES** — already verified in place |
| Decrypt to user-writable path | **Likely YES** (`gpg` present) — **not performed** |
| Import to isolated rehearsal DB | **NO** — no isolated DB; CREATE denied |
| Import without risk to `u215544208_desk` | **NO** |
| Avoid modifying 32 existing backup runs | Possible if import never runs — **restore step itself is blocked** |

---

## 15. GPG passphrase mechanism

| Check | Result |
|-------|--------|
| `gpg` on host | **YES** — GnuPG 2.3.3 |
| `/root/.radium-backup-passphrase` | **Absent** (expected — not production KVM) |
| User passphrase file | **Absent** |
| Approved secure mechanism ready | **NO** — owner would need to establish pinentry or root-only file **without** placing passphrase in Git/chat/history |

Passphrase availability alone does not overcome DB isolation failures.

---

## Classification

### REJECTED — NEW ISOLATED HOST REQUIRED

**Primary rejection reasons:**

1. **Not storage-only** — live legacy Desk production app and shared MariaDB.
2. **Not non-production** — `APP_ENV=production`, public `desk.radiumbox.com`.
3. **Shared multi-product account** — multiple Radium/Admin vhosts on one Hostinger account.
4. **No isolated database boundary** — single schema; `CREATE DATABASE` denied; shared datadir.
5. **Public MySQL (3306)** exposed on the host IP.
6. **Cannot satisfy** “completely separate MariaDB instance/datadir without touching existing services/data.”

**Secondary notes:**

- Backup ciphertext on this host is **verified** (SHA-256 match) and remains a valid **alternate download source** for a **future dedicated restore host**.
- MariaDB **11.8.8** version match is favorable but insufficient without isolation.

---

## Owner guidance (unchanged)

Provision **one dedicated isolated Linux VM** per [`desk-phase1-restore-host-provisioning-spec.md`](desk-phase1-restore-host-provisioning-spec.md) (P-03-09-27). Do **not** repurpose `187.127.183.72` as the restore-rehearsal database host.

---

## Related documents

- [`desk-phase1-restore-host-provisioning-spec.md`](desk-phase1-restore-host-provisioning-spec.md)
- [`desk-phase1-restore-environment-inventory.md`](desk-phase1-restore-environment-inventory.md)
- [`desk-rdservice-net-phase1-restore-environment-gate.md`](desk-rdservice-net-phase1-restore-environment-gate.md)
- [`backup-runbook.md`](backup-runbook.md)
