# Phase-1 restore gate resolution — RadiumDesk-P-03-09-23

**Project:** Radium Desk  
**Repository:** `git@github.com:radium-foundation/radium-desk.git`  
**Worktree:** `/Users/ravi/RadiumWebsites/radium-desk-phase1-clean`  
**Branch:** `feat/rdservice-net-phase1-clean` @ `db7a15e8`  
**Prompt ID:** **RadiumDesk-P-03-09-23**  
**Date:** 2026-09-03  
**Type:** Restore/migration gate resolution only. **No production migrate, deploy, decrypt, import, or invented infrastructure.**

Predecessors: P-03-09-16…21 (backup/restore gate), P-03-09-22 (deployment gate).

---

## Verdict

**RESTORE GATE STILL BLOCKED**

The prior blocker was **not safely resolvable** in this ticket. No isolated restore rehearsal or Phase-1 migration rehearsal against backup `20260903T083001Z` was performed. Production `radium_desk` was not modified.

---

## Restore source (VERIFIED)

| Field | Value |
|-------|-------|
| Backup ID | **`20260903T083001Z`** |
| Path | `/var/backups/radium-desk/runs/20260903T083001Z/` on KVM `187.127.129.16` |
| Application | `4.0.64` / build `0d734f85` |
| Database in manifest | `radium_desk` / MariaDB `11.8.8-MariaDB-ubu2404` |
| Ciphertext size | 401508879 bytes |
| SHA-256 `database.sql.gz.gpg` | `03091ad39adf407b57d98705f823b909c52183db085568f4a658f62a4811ef43` |
| On-disk SHA-256 (2026-09-03 re-check) | **Matches manifest** |
| Cloud copy | Manifest claims upload completed to `187.127.183.72` |
| Local Mac copy | **Not present** |

Decryptability, gzip validity, and SQL importability remain **UNKNOWN** (no decrypt attempted).

---

## Restore target (BLOCKED)

| Candidate | Status | Isolated? | Usable? |
|-----------|--------|-----------|---------|
| Production KVM MariaDB `127.0.0.1:3306` / `radium_desk` | **Running** (single `mariadbd`) | **No** | **Forbidden** — same engine as live Desk + sibling product schemas |
| Disposable schema on production KVM | Not created | **No** | **Forbidden** — shared InnoDB, buffer pool, disk I/O with production |
| Operator Mac Homebrew `mariadb@11.8` / `mysql` 9.6 | **Stopped** (`brew services`: none) | Shared datadir with Stocky/RadiumSign/Radiumbox local copies | **No** — starting would invent infrastructure; engine mismatch vs backup |
| Mac `radium_desk_restore_drill` | 3.7G on disk; MySQL 9.6 era | Not isolated (shared datadir) | **No** — wrong engine; destructive reuse; 10 GiB free disk |
| Docker / Colima / Podman | **Absent** | — | **No** |
| Hostinger Cloud `187.127.183.72` | Storage only | — | **No** |
| SSH alias `148.113.8.82` (`radium-1` / `rvs`) | Not owner-named; not inventoried | **UNKNOWN** | **INVALID** until owner documents and verifies checklist |
| Documented second Desk/staging MariaDB | **None** in `tools/config.sh` or docs | — | **No** |

**Restore target:** **NONE** (no conclusively isolated, running MariaDB).

---

## Production boundary (VERIFIED)

| Boundary | Value |
|----------|-------|
| Production server | `srv1910783` / `187.127.129.16` |
| Application path | `/var/www/radium-desk` |
| Production DB host/port | `127.0.0.1:3306` |
| Production DB name | **`radium_desk`** |
| Production DB user | `radium_desk` (password not read) |
| Live release | `v4.0.64` / `0d734f85` |
| Public health | `GET /up` **200**, `GET /login` **200** |
| Phase-1 tables on production | **Absent** (`commerce_orders`, `statutory_invoices`, etc.) |
| Production data modified this ticket | **NO** |

Production could be **overwritten** if a restore were imported into schema `radium_desk` on the KVM. A different schema name would avoid overwrite but would **not** satisfy isolation (same `mariadbd`).

---

## Database boundary (VERIFIED)

| Item | Production | Rehearsal |
|------|------------|-----------|
| Server | KVM `127.0.0.1:3306` | **None provisioned** |
| Schema | `radium_desk` | **UNKNOWN** — no target |
| User | `radium_desk` | **UNKNOWN** |
| Application config | `/var/www/radium-desk/.env` | N/A |

KVM `/` has **146 GiB free** — sufficient for a future isolated or KVM-side rehearsal, but **isolation** is the blocker, not KVM disk.

---

## Previous blocker

From P-03-09-16…21:

1. No **already-running**, **conclusively isolated** non-production MariaDB.
2. GPG passphrase **not safely available** on this Mac (exists only as root file on production KVM).
3. Mac **10 GiB free** — at/below streamed-restore estimate (12 GiB INFERRED) and below materialized floor (20 GiB).
4. Homebrew MySQL/MariaDB **stopped**; shared datadir with other local products.
5. Docker **absent**.
6. Production KVM MariaDB **forbidden** as rehearsal target.

---

## Resolution attempt (this ticket)

| Action | Result |
|--------|--------|
| Re-verify worktree / branch / HEAD | VERIFIED (`db7a15e8`, clean except prior uncommitted docs) |
| Re-verify backup ciphertext SHA-256 | **Still matches** manifest |
| Re-scan local Docker / Homebrew / ports | **No change** — still blocked |
| Re-check Mac disk | **10 GiB** free (95% used on Data volume) |
| Re-check KVM MariaDB instances | **One** `mariadbd` on `127.0.0.1:3306` only |
| Confirm root passphrase file on KVM | **`/root/.radium-backup-passphrase` EXISTS** (sudo; contents not read) |
| Provision isolated MariaDB | **NOT performed** — would invent infrastructure |
| Decrypt backup | **NOT performed** — no safe offline target; Mac lacks passphrase |
| Import SQL | **NOT performed** |
| Run Phase-1 migrations on rehearsal copy | **NOT performed** — no rehearsal DB |
| Modify production `radium_desk` | **NOT performed** |

**Resolution:** Blocker **unchanged**. Gate **not cleared**.

---

## Migration rehearsal (NOT performed)

The five additive Phase-1 migrations (inventory/statutory/commerce/documents chain) were **not** run against a restored production backup copy.

**Partial validation only:** PHPUnit `RefreshDatabase` on SQLite — **37 passed** (ingest + Phase-1 clean + statutory service filters). This confirms migration **code** runs in test isolation; it is **not** a substitute for backup restore rehearsal on MariaDB 11.8.8.

### Expected production migration set (for later, after gate clears)

| # | Migration file |
|---|----------------|
| 1 | `2026_09_01_120000_create_inventory_and_pos_foundation_tables.php` |
| 2 | `2026_09_01_140000_add_inventory_branch_assignments_and_sale_idempotency.php` |
| 3 | `2026_09_01_160000_create_statutory_invoice_foundation_tables.php` |
| 4 | `2026_09_01_180000_create_channel_order_ingest_tables.php` |
| 5 | `2026_09_02_130000_create_statutory_invoice_documents_table.php` |

Expected new tables include: `commerce_orders`, `commerce_order_items`, `channel_ingest_attempts`, `statutory_invoices`, `statutory_invoice_items`, `statutory_invoice_documents`, `e_invoice_records`, `invoice_sequences`, plus empty inventory foundation tables required by FK chain.

Rollback: application redeploy to `v4.0.64` does **not** drop new tables. `migrate:rollback` of foundation migrations is **not** casual on production.

---

## Owner decisions required to clear the gate

1. **Name a dedicated isolated MariaDB host** — not `187.127.129.16` production `mariadbd`, not the shared Homebrew datadir on this Mac.
2. **Confirm target is running** with dedicated datadir, disposable restore schema, MariaDB **11.8.x** (or owner-approved compatible version).
3. **Provide ≥12 GiB free** (streamed import) or **≥20 GiB** (materialized decrypt path) on that host.
4. **Copy ciphertext** `20260903T083001Z` to that host (or authorize Cloud fetch).
5. **Supply GPG passphrase on that host only** — via operator pinentry or secure file; do not paste into chat. Alternatively authorize a documented KVM-side decrypt-to-pipe import into a **non-production** host only.
6. **Authorize cleanup** of temporary datadir/schema after validation.
7. If using `148.113.8.82` or any new host: document it in ops config and pass the full isolation checklist before restore.

---

## What this ticket did not do

| Action | Status |
|--------|--------|
| Production migration | **NO** |
| Production deployment | **NO** |
| Decrypt / import | **NO** |
| Start Homebrew / Docker MariaDB | **NO** |
| Create schema on production KVM | **NO** |
| Read/copy GPG passphrase | **NO** |
| Spoke ingestion / secrets / live payment / backfill | **NO** |
| rdservice.in / net / Sign / Admin / Stocky change | **NO** |
