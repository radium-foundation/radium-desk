# Phase-1 restore environment inventory — documented candidates

**Project:** Radium Desk  
**Repository:** `git@github.com:radium-foundation/radium-desk.git`  
**Worktree:** `/Users/ravi/RadiumWebsites/radium-desk-phase1-clean`  
**Prompt ID:** **RadiumDesk-P-03-09-26**  
**Date:** 2026-09-03  
**Branch:** `feat/rdservice-net-phase1-clean` @ `dd9d6ca0`

**Verdict:** **RESTORE ENVIRONMENT NOT AVAILABLE — OWNER PROVISIONING REQUIRED**

No already-approved, isolated MariaDB restore environment exists in documented Radium Desk infrastructure. **No host was modified.** **No restore was performed.**

---

## Inspection method

Reviewed:

- `docs/backup-runbook.md` (restore procedure; Cloud storage only)
- `docs/local-development.md` (local MySQL dev clone — not restore infra)
- `docs/desk-rdservice-net-phase1-restore-environment-gate.md` (P-03-09-17…24 candidate matrix)
- `docs/desk-phase1-restore-host-148-investigation.md` (P-03-09-24)
- `tools/config.sh` / `tools/README.md` (production SSH target only)
- Operator `~/.ssh/config` (three Desk-related aliases)
- Fresh read-only re-check: Mac disk/Homebrew, backup SHA-256 on production KVM

**Did not inspect:** Stocky, rdservice.in/net, Admin, radiumsign.com, undocumented servers.

---

## Backup source (unchanged, VERIFIED)

| Field | Value |
|-------|-------|
| Backup ID | `20260903T083001Z` |
| Path | `/var/backups/radium-desk/runs/20260903T083001Z/` on KVM `187.127.129.16` |
| SHA-256 (re-checked) | `03091ad39adf407b57d98705f823b909c52183db085568f4a658f62a4811ef43` |
| Application | `4.0.64` / `0d734f85` |
| Cloud copy (manifest claim) | `187.127.183.72:/home/u215544208/backups/radium-desk/2026/09/03/20260903T083001Z` |

---

## Candidate hosts inspected

### A — Production Desk KVM `187.127.129.16`

| Check | Result |
|-------|--------|
| Documented in | `tools/config.sh` as **production** app host |
| Classification | **Production** — live Desk + sibling product schemas on same `mariadbd` |
| SSH | `deskvps` / `ravi@187.127.129.16:22` — authorized for ops |
| MariaDB | Single live `mariadbd` pid 1976167; schema `radium_desk` |
| Restore acceptable? | **NO — Forbidden** (explicit gate rule; shared production datadir) |

### B — Hostinger Cloud `187.127.183.72` (re-verified P-03-09-28)

| Check | Result |
|-------|--------|
| Documented in | `docs/backup-runbook.md` as **Cloud upload target** (port 65002, user `u215544208`) |
| Live hostname | `in-mum2-web2219.main-hosting.eu` — **shared Hostinger hosting**, not dedicated VM |
| Classification | **Backup storage + legacy shared web/DB host** — **not** an isolated restore target |
| Legacy Desk | `/home/u215544208/laravel/radium-desk`; `APP_ENV=production`; `desk.radiumbox.com` |
| MariaDB | **11.8.8** shared service; live schema `u215544208_desk` (**4.71 GiB**); `CREATE DATABASE` **denied** |
| Public 3306 | **OPEN** from operator network (P-03-09-28) |
| Backup SHA-256 on host | **VERIFIED** matches `20260903T083001Z` gate |
| Restore acceptable? | **NO** — shared production-adjacent host; no isolated datadir/schema; see [`desk-phase1-restore-cloud-host-187-investigation.md`](desk-phase1-restore-cloud-host-187-investigation.md) |

### C — Operator Mac Homebrew MySQL/MariaDB

| Check | Result |
|-------|--------|
| Documented in | `docs/local-development.md` as **`radium_desk_local` dev clone** |
| Classification | **Local development** — not designated restore infrastructure |
| SSH | N/A (localhost) |
| Services | `brew services`: mariadb@11.8 **none**, mysql **none**; port 3306 **closed** |
| Disk free | **10 GiB** (below 12 GiB streamed / 20 GiB materialized floor) |
| Datadir | `/opt/homebrew/var/mysql` — shared with `stocky*`, `radiumbox`, `radiumsign_local`, `avimehna_local`, `radium_desk_restore_drill` (prior gate) |
| MariaDB version | Would be MySQL 9.6 or MariaDB 11.8 on **shared** datadir — mismatch vs backup MariaDB 11.8.8 |
| Restore acceptable? | **NO** — not isolated, not running, insufficient disk, starting would invent infrastructure |

### D — Docker / Colima

| Check | Result |
|-------|--------|
| Documented | **Not** in Desk ops docs as restore target |
| Status | Binaries **absent** |
| Restore acceptable? | **NO** — not provisioned |

### E — `148.113.8.82` (`radium-1` / `rvs` SSH aliases)

| Check | Result |
|-------|--------|
| Documented in Desk repo | **NO** — not in `tools/config.sh` or backup runbook as restore host |
| P-03-09-24 | SSH port 20097 **refused**; port 22 **auth denied**; role/datadir **UNKNOWN** |
| Restore acceptable? | **NO** — not conclusively verified; per prompt rule treat as **INVALID** |

### F — Named Desk staging / second MariaDB host

| Check | Result |
|-------|--------|
| Documented | **NONE** in `tools/config.sh`, `tools/README.md`, backup runbook, or restore gates |
| Restore acceptable? | **NO** — does not exist in documentation |

---

## Approved isolated host

**NONE.**

---

## Owner provisioning requirements

**Full specification:** [`desk-phase1-restore-host-provisioning-spec.md`](desk-phase1-restore-host-provisioning-spec.md) (RadiumDesk-P-03-09-27).

Summary — the owner must **provision and document** a dedicated environment that satisfies **all** of:

1. **Named host** — VM/container/bare metal **not** `187.127.129.16`, **not** shared Homebrew datadir, **not** `148.113.8.82` until independently verified, **not** another product’s production DB.
2. **MariaDB 11.8.x** (or owner-approved match to production backup engine) on a **dedicated datadir**.
3. **Disposable restore schema** (e.g. `radium_desk_restore_rehearsal`) with explicit drop permission after validation.
4. **≥12 GiB free** (streamed import) or **≥20 GiB** (materialized decrypt path).
5. **SSH access** documented in ops config with authorized keys (update stale `~/.ssh/config` if needed).
6. **Backup transfer path** — copy ciphertext from KVM staging or Cloud to the isolated host (rsync/scp); no decrypt on production KVM beside live data.
7. **GPG passphrase** available **on the isolated host only** via operator pinentry or restricted file — never pasted into chat; `/root/.radium-backup-passphrase` on production KVM must **not** be the default decrypt location.
8. **Ops documentation** — add host to restore runbook / `tools/config.sh` (or separate restore-host doc) **before** rehearsal ticket runs import.
9. **Explicit written approval** that the environment is for **restore rehearsal only**.

Suggested provisioning patterns (owner choice; **not executed here**):

- New OVH/Hostinger VM with dedicated MariaDB 11.8 and empty datadir.
- Dedicated MariaDB Docker volume on a **new** host with ≥20 GiB free (requires owner to provision Docker host first).

---

## What this ticket did not do

Restore rehearsal, migration rehearsal, MariaDB install, backup copy, GPG decrypt, production DB change, merge/tag/push/deploy, secrets, spoke enablement — all **NO — Not performed.**
