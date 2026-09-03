# Isolated restore environment gate — rdservice.net Phase 1

**Project:** Radium Desk  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk`  
**Worktree:** `/Users/ravi/RadiumWebsites/radium-desk-phase1-clean`  
**Prompt IDs:** **RadiumDesk-P-03-09-17** (discovery), **RadiumDesk-P-03-09-18** / **P-03-09-19** (re-verification), **RadiumDesk-P-03-09-20** (capacity), **RadiumDesk-P-03-09-21** (target verify), **RadiumDesk-P-03-09-23** (gate resolution), **RadiumDesk-P-03-09-24** (148.113.8.82 investigation)  
**Date:** 2026-09-03  
**Type:** Isolated restore-environment discovery / capacity assessment / gate resolution. **No production migrate, decrypt, import, or invented infrastructure.**  
**Latest verdict (P-03-09-24):** `148.113.8.82` investigated read-only — **NOT VERIFIED** as restore host. Restore rehearsal still **BLOCKED**. Production migrate still **BLOCKED**. See [`desk-phase1-restore-host-148-investigation.md`](desk-phase1-restore-host-148-investigation.md).

**Canvas:** [`desk-rdservice-net-phase1-restore-environment-gate.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-RadiumWebsites-radium-desk-phase1-clean/canvases/desk-rdservice-net-phase1-restore-environment-gate.canvas.tsx)

Predecessor: [`desk-rdservice-net-phase1-backup-restore-gate.md`](desk-rdservice-net-phase1-backup-restore-gate.md) (RadiumDesk-P-03-09-16). Ciphertext integrity for `20260903T083001Z` was already VERIFIED there and was re-confirmed here.

Classification used throughout:

| Label | Meaning |
|-------|---------|
| **VERIFIED** | Observed in this ticket (git, live SSH, public HTTPS, or local filesystem/process list) |
| **INFERRED** | Consistent with verified facts; not independently proven here |
| **UNKNOWN** | Not established. Not upgraded from prior tickets. |

---

## Verify-first (this ticket)

| # | Item | Value | Class |
|---|------|-------|-------|
| 1 | Worktree | `/Users/ravi/RadiumWebsites/radium-desk-phase1-clean` | VERIFIED |
| 2 | Repository | `/Users/ravi/RadiumWebsites/radium-desk` (`origin` `git@github.com:radium-foundation/radium-desk.git`) | VERIFIED |
| 3 | Branch | `feat/rdservice-net-phase1-clean` (ahead of `origin/main` by 2) | VERIFIED |
| 4 | HEAD | `6e8b8642eb815a04460f7bc61420d743ec4084bf` | VERIFIED |
| 5 | Worktree status | Clean application tree before this docs-only update | VERIFIED |
| 6 | Production host / path | `srv1910783` / `187.127.129.16` · `/var/www/radium-desk` | VERIFIED |
| 7 | Production database | `DB_CONNECTION=mysql` `127.0.0.1:3306` database **`radium_desk`** user `radium_desk`. Client **11.8.8-MariaDB**. Live `mariadbd` on the KVM | VERIFIED (names only; password never printed) |
| 8 | Backup artifact | `/var/backups/radium-desk/runs/20260903T083001Z/` | VERIFIED |
| 9 | Backup timestamp | `2026-09-03T08:30:01Z` (14:00 IST scheduled slot) | VERIFIED |
| 10 | Backup integrity | On-disk SHA-256 of both `.gpg` files equals the manifest. See below | VERIFIED |
| 11 | Documented restore | [`docs/backup-runbook.md`](backup-runbook.md) § “How to restore (manual)”. No restore CLI in this repo | VERIFIED |
| 12 | Non-production options | See candidate table. None is a running, conclusively isolated MariaDB | VERIFIED as a search result; isolation of each candidate classified separately |

Also re-verified this ticket:

| Item | Value | Class |
|------|-------|-------|
| Live release | `storage/app/private/release.json`: version `4.0.64`, tag `v4.0.64`, build `0d734f85`, deployed_at `2026-08-31T13:19:03+05:30` | VERIFIED |
| `APP_ENV` / debug / URL | `production` / `false` / `https://desk.radiumbox.com` | VERIFIED |
| Queue worker | `radium-desk-queue-worker` **RUNNING** (pid `2034196` at inspect). This ticket did not restart it | VERIFIED |
| Public `GET /up` | HTTP **200** | VERIFIED |
| Public `GET /login` | HTTP **200** | VERIFIED |
| Dirty worktree | `/Users/ravi/RadiumWebsites/radium-desk` still `feat/rd-fresh-01-inventory-pos` `b9bd2f43`. **Not modified** | VERIFIED |

---

## Backup re-confirmation

| Field | Value | Class |
|-------|-------|-------|
| Backup ID | **`20260903T083001Z`** | VERIFIED |
| Files | `database.sql.gz.gpg` (401508879 bytes), `secrets.tar.gz.gpg` (6806 bytes), `manifest.json` (1223 bytes) | VERIFIED |
| Encryption | `gpg-aes256-symmetric` | VERIFIED (manifest) |
| Manifest `phase` | `cloud_uploaded` | VERIFIED |
| Upload | `status=completed` at `2026-09-03T08:32:02Z`; remote `187.127.183.72:/home/u215544208/backups/radium-desk/2026/09/03/20260903T083001Z` | VERIFIED as a **manifest claim** |
| Application in manifest | `4.0.64` / `0d734f85` | VERIFIED |
| Database in manifest | `radium_desk` / mariadb / `11.8.8-MariaDB-ubu2404` | VERIFIED |

Ciphertext SHA-256 (sudo, no decrypt) matched the manifest exactly:

| Artifact | SHA-256 |
|----------|---------|
| `database.sql.gz.gpg` | `03091ad39adf407b57d98705f823b909c52183db085568f4a658f62a4811ef43` |
| `secrets.tar.gz.gpg` | `03f0a17236c056a74b0e1242f03c982840a0df3b674b142098d9312cec58252c` |

Decryptability, gzip validity, and SQL importability remain **UNKNOWN**.

---

## Documented restore procedure

From [`docs/backup-runbook.md`](backup-runbook.md):

1. Copy the bundle off local staging or Cloud.
2. **Decrypt offline** on a trusted machine with the operator-held GPG passphrase. Never paste the passphrase into Desk, tickets, or chat.
3. Import the decrypted dump into a **clean non-production MariaDB**.
4. Verify row counts, critical tables, and recent samples.
5. Restore secrets only onto the intended target, with restricted permissions.

There is still **no** Desk restore UI and **no** restore CLI in this repository. **VERIFIED.**

Phase 7 remains documented as Partial (“Restore drill proven on operator Mac”). That prior drill is **not** re-verified for this backup ID.

---

## Isolated restore target discovery

Prompt rule: do not assume a name containing `local`, `test`, or `dev` is isolated. Verify. If no conclusively isolated target **already exists**, stop. Do not invent infrastructure. Do not create an ad-hoc database on production MariaDB.

### Candidate A — Production KVM MariaDB

| Question | Answer | Class |
|----------|--------|-------|
| Host | `srv1910783` / `187.127.129.16` | VERIFIED |
| Service | `/usr/sbin/mariadbd` (live) | VERIFIED |
| Desk database | `radium_desk` | VERIFIED |
| Definitely non-production? | **No** | VERIFIED |
| Isolated from production? | **No** | VERIFIED |
| Could using it modify production data? | **Yes** — same `mariadbd`, same datadir, same disk | VERIFIED |
| Storage | `/` 193G, **146G free** | VERIFIED |

The same production datadir also contains sibling schemas: `radiumbox_prod`, `radiumsign_prod`, `rdservice_net`, `rdservice_net_prod`, `rdserviceonline`, `beta_admin`, `beta_radiumbox`, `beta_radiumsign`, `beta_rdservice_in`. **VERIFIED** by directory listing of `/var/lib/mysql` only. Creating a “restore” schema on this instance would share InnoDB, buffer pool, and disk with live products.

**Usable? No. Forbidden.**

### Candidate B — Operator Mac Homebrew `mariadb@11.8`

| Question | Answer | Class |
|----------|--------|-------|
| Host | This Mac (`127.0.0.1` if started) | VERIFIED (install path) |
| Service identity | Formula `mariadb@11.8` **11.8.8**, keg-only. `brew services`: **none**. Nothing listening on 3306/3307 | VERIFIED |
| Datadir in plist | `/opt/homebrew/var/mysql` | VERIFIED |
| Definitely non-production? | The Mac is not the KVM | VERIFIED as a host fact |
| Isolated from production? | No live connection to KVM `radium_desk` was observed. Isolation **if started** is not proven | UNKNOWN for a running session (service is down) |
| Could using it modify production? | Not while stopped. Starting it does not write the KVM | INFERRED |
| Running target? | **No** | VERIFIED |

Starting this formula would be **inventing a running service**. It is also configured to use the **same datadir** as Homebrew MySQL 9.6 (see Candidate C). Starting MariaDB 11.8 against a MySQL 9 datadir is a destructive engine-mismatch risk.

**Usable? No — not a running target. Must not be started in this ticket.**

### Candidate C — Operator Mac Homebrew MySQL 9.6 + existing local schemas

| Question | Answer | Class |
|----------|--------|-------|
| Host | This Mac | VERIFIED |
| Service identity | Linked `mysql` **9.6.0**. `brew services`: **none**. Last shutdown `2026-08-21T18:39:55Z` as `mysqld 9.6.0` | VERIFIED (`Ravis-MacBook-Air.err`) |
| Bind | `/opt/homebrew/etc/my.cnf` dated 2026-08-21: `bind-address = 127.0.0.1` with comment “Radium Desk restore drill — local Mac MySQL bind. Not a production host.” | VERIFIED |
| Datadir | `/opt/homebrew/var/mysql` — **6.7G** | VERIFIED |
| Existing schemas in that datadir | `radium_desk_local` (15M, mtime 2026-08-09), `radium_desk_restore_drill` (3.7G, mtime 2026-08-22), plus `radiumbox`, `radiumsign_local`, `stocky`, `stocky_local`, `stocky_testing`, `avimehna_local` | VERIFIED (directory names and sizes; contents not queried — server down) |
| Definitely non-production? | Host is not the KVM. Schema **names** are not proof of isolation | VERIFIED host; isolation of named schemas **not** upgraded |
| Isolated? | **No.** Shared datadir with Stocky / RadiumSign / Radiumbox / AviMehna local copies. Shared InnoDB redo/undo with those schemas | VERIFIED |
| Could using it modify production? | Not the KVM. **Could** overwrite or starve disk for other local product databases | VERIFIED (shared datadir + 11Gi free) |
| Storage | Data volume **11Gi free** (95% used). Prior production-sized drill already used 3.7G. Current production schema was ~5.2G on 2026-08-18 | VERIFIED free space; production size from prior audit (not re-measured) |
| Local copy of `20260903T083001Z` | **Not found** | VERIFIED |

`radium_desk_local` is documented in [`docs/local-development.md`](local-development.md) as a local clone. On disk it is 15M and has no `commerce_*` / `statutory_*` table files. It is **not** a current production-sized isolated restore target.

`radium_desk_restore_drill` is evidence of a **prior** Mac drill (MySQL 9.6, 2026-08-21). It is not a running service. Reusing it would overwrite 3.7G of existing local data. Importing this backup ID into that name is a **destructive** owner decision.

Engine mismatch: production backup is MariaDB 11.8.8; this datadir last ran **MySQL 9.6**. The runbook asks for a clean MariaDB instance. Starting either Homebrew formula against this datadir is not a clean MariaDB and is not isolated from other local products.

**Usable? No.** Ambiguous identity, not MariaDB, not running, not isolated, insufficient free disk for a safe 5G-class import, and starting it would invent infrastructure.

### Candidate D — Docker / container runtime

| Question | Answer | Class |
|----------|--------|-------|
| Docker / Colima / Podman / docker-compose | Binaries **not found**; daemon not available | VERIFIED |
| Usable? | **No** | VERIFIED |

### Candidate E — Hostinger Cloud backup host

| Question | Answer | Class |
|----------|--------|-------|
| Host | Manifest upload target `187.127.183.72` (shared-hosting backup storage) | VERIFIED as manifest claim |
| Database/service | Storage path only. Not documented as a MariaDB restore host | VERIFIED (docs + manifest role) |
| Usable as restore target? | **No** | VERIFIED |

### Candidate F — Documented second Desk / staging MariaDB

No second Desk application host appears in `tools/config.sh`. The only current production SSH target is `187.127.129.16`. **VERIFIED.** No named staging MariaDB is documented as live.

---

## GPG / decryptability

| Check | Result | Class |
|-------|--------|-------|
| Local `BACKUP_ENCRYPTION_PASSPHRASE` | Unset in this shell | VERIFIED |
| Local `BACKUP_ENCRYPTION_PASSPHRASE_FILE` | Unset | VERIFIED |
| Well-known local passphrase files | Absent under `$HOME` | VERIFIED |
| Local GPG secret keys | None listed | VERIFIED |
| KVM `/root/.radium-backup-passphrase` | **File exists** (contents not read) | VERIFIED |
| KVM `/root/.radium-backup.env` | Exists. Key names include `BACKUP_ENCRYPTION_PASSPHRASE_FILE` (values not read) | VERIFIED |

The documented operator mechanism **exists on the production KVM** as a root-only passphrase file. Using it from this ticket would mean either:

- decrypting on the production host (plaintext dump beside live data — unsafe), or
- copying the passphrase off production (secret extraction — owner authority).

The runbook requires **offline** decrypt on a trusted machine. That passphrase is **not** already safely available on this Mac. This ticket did not read, copy, or guess it.

**Decryptability: UNKNOWN. Rehearsal stopped for credentials.**

### What the operator must provide

1. The GPG symmetric passphrase for `20260903T083001Z`, entered on a **trusted non-production machine** (file or pinentry). Do not paste it into chat.
2. Authorization to copy the ciphertext bundle off the KVM (or Cloud) to that machine.
3. A **named, already-running, dedicated MariaDB** that is not the KVM `mariadbd` and does not share a datadir with Stocky / RadiumSign / Radiumbox / production Desk.
4. Enough free disk for ciphertext (~383 MiB) + uncompressed SQL + InnoDB import (plan on **≥20 GiB** free; this Mac currently has **11 GiB**).
5. Explicit acceptance if any existing local schema (`radium_desk_restore_drill`, `radium_desk_local`) may be dropped — this ticket will not.

---

## Restore rehearsal

**NO — Not performed.**

Stop conditions hit:

- No conclusively isolated **running** MariaDB target exists.
- Local Homebrew identity is ambiguous (MySQL 9.6 datadir vs MariaDB 11.8 formula; shared with other products).
- Decrypt requires a passphrase that is not safely available on this Mac.
- Using production MariaDB would affect live Desk and sibling products.
- Starting Homebrew or creating a new schema would invent infrastructure and/or destroy existing local data.
- Mac free space is not sufficient to call a 5G-class import safe.

---

## Verdict

| Check | Result |
|-------|--------|
| Isolated restore target already exists | **NO** |
| Target isolation | **UNKNOWN / BLOCKED** — no candidate is both running and conclusively isolated |
| Restore rehearsal | **NO — Not performed** |
| Decryptability | **UNKNOWN** — passphrase not used |
| Production DB touched | **NO** (read-only inspect: hostname, `.env` names, manifest, checksums, datadir **names**) |
| Production migrate | **Still BLOCKED** |

A later ticket may rehearse restore only after the owner names a dedicated isolated MariaDB and supplies the GPG passphrase on that machine.

---

## What this ticket did not do

| Action | Status |
|--------|--------|
| Start Homebrew MariaDB or MySQL | **NO — Not performed** |
| Create a restore schema on the KVM | **NO — Not performed** |
| Decrypt GPG artifacts | **NO — Not performed** |
| Read or copy `/root/.radium-backup-passphrase` | **NO — Not performed** |
| Import SQL | **NO — Not performed** |
| Copy the backup bundle to this Mac | **NO — Not performed** |
| Production migrate / `deskd` / `.env` | **NO — Not performed** |
| Invoice / ingest / worker mint | **NO — Not performed** |
| Push / deploy | **NO — Not performed** |
| Dirty feature-branch change | **NO — Not performed** |
| rdservice.net / Admin / Sign / Stocky application change | **NO — Not performed** |

---

## P-03-09-18 re-verification (2026-09-03 17:54 IST)

This section does **not** rewrite P-03-09-17 findings. It records a second inspect to see whether a dedicated, already-running, isolated MariaDB had become available.

`docs/cursor-prompt-log.md` is **absent** in both worktrees. The live ledger remains `docs/cursor-prompt-ledger.md`. This ticket did **not** create `cursor-prompt-log.md` in the dirty worktree.

| Item | P-03-09-18 result | Class |
|------|-------------------|-------|
| Clean HEAD | `c42e79d210f1c7f142d4de0028b27ac486659ee0` | VERIFIED |
| Worktree status | Clean before this docs update; ahead of `origin/main` by 3 | VERIFIED |
| Dirty tree | Still `feat/rd-fresh-01-inventory-pos` `b9bd2f43`. Not modified | VERIFIED |
| Backup `20260903T083001Z` | Still at `/var/backups/radium-desk/runs/20260903T083001Z/` | VERIFIED |
| Manifest | `phase=cloud_uploaded`; upload `completed` `2026-09-03T08:32:02Z` | VERIFIED |
| Ciphertext SHA-256 | Database and secrets hashes still match the manifest | VERIFIED |
| Homebrew `mariadb@11.8` / `mysql` | `brew services`: **none**. Nothing on 3306/3307/33060 | VERIFIED |
| Docker / Colima / Podman | Binaries still absent | VERIFIED |
| Local copy of this backup ID | Still not found | VERIFIED |
| Local GPG passphrase | Env unset; no `$HOME` passphrase file | VERIFIED |
| KVM `/root/.radium-backup-passphrase` | File still exists; contents not read | VERIFIED |
| Mac free disk | **10 GiB** (was 11 GiB at P-03-09-17) | VERIFIED |
| Isolated running MariaDB | **NONE** | VERIFIED as a search result |
| Target host / socket / datadir / schema / user | **UNKNOWN** — no eligible target | UNKNOWN |
| Restore rehearsal | **NO — Not performed** | VERIFIED |
| Decryptability | **UNKNOWN** | UNKNOWN |
| Production migrate | **Still BLOCKED** | VERIFIED |

No candidate became a dedicated, already-running, isolated MariaDB. Critical restore-target identity remains UNKNOWN. Per prompt rule: **STOP.** Do not decrypt, copy, import, start, stop, or create a database service.

Production migration may **not** advance past this restore gate.

---

## P-03-09-19 re-verification (2026-09-03 18:05 IST)

This section does **not** rewrite P-03-09-17 or P-03-09-18 findings. It records a third inspect after the owner had another chance to provide a dedicated already-running isolated MariaDB.

| Item | P-03-09-19 result | Class |
|------|-------------------|-------|
| Clean HEAD | `0a9db9d33f78f5c58046a4612fd22edec9f3a269` | VERIFIED |
| Worktree status | Clean before this docs update; ahead of `origin/main` by 4 | VERIFIED |
| Dirty tree | Still `feat/rd-fresh-01-inventory-pos` `b9bd2f43`. Not modified | VERIFIED |
| Backup `20260903T083001Z` | Still at `/var/backups/radium-desk/runs/20260903T083001Z/` | VERIFIED |
| Manifest | `phase=cloud_uploaded`; upload `completed` | VERIFIED |
| Ciphertext SHA-256 | Database and secrets hashes still match the manifest | VERIFIED |
| Homebrew `mariadb@11.8` / `mysql` | `brew services`: **none**. Nothing on 3306/3307/33060 | VERIFIED |
| Docker / Colima / Podman | Binaries still absent | VERIFIED |
| Local copy of this backup ID | Still not found | VERIFIED |
| Local GPG passphrase | Env unset; no `$HOME` passphrase file | VERIFIED |
| KVM `/root/.radium-backup-passphrase` | File still exists; contents not read | VERIFIED |
| Mac free disk | **10 GiB** (unchanged from P-03-09-18; below the 20 GiB requirement) | VERIFIED |
| Isolated running MariaDB | **NONE** | VERIFIED as a search result |
| Target host / socket / datadir / schema / user | **UNKNOWN** — no eligible target | UNKNOWN |
| Restore rehearsal | **NO — Not performed** | VERIFIED |
| Decryptability | **UNKNOWN** | UNKNOWN |
| Production migrate | **Still BLOCKED** | VERIFIED |

No owner-provided isolated target appeared. Eligibility items that remain UNKNOWN or failed: dedicated running instance, host/socket/datadir/schema/version, isolation from other products, ≥20 GiB free, safe local GPG mechanism.

**BLOCKED — restore rehearsal not performed; production migration remains BLOCKED.**

---

## P-03-09-20 capacity assessment (2026-09-03 18:10 IST)

Evidence-only. **No decrypt, no import, no MariaDB start, no restore.** Production migrate remains **BLOCKED**.

### Evidence inspected

| Source | What was read | Class |
|--------|----------------|-------|
| This worktree | `feat/rdservice-net-phase1-clean` `f77cbf32`; ahead of `origin/main` by 5 | VERIFIED |
| Dirty tree | Still `feat/rd-fresh-01-inventory-pos` `b9bd2f43`. Not modified | VERIFIED |
| Artifact `stat` | `database.sql.gz.gpg` **401508879** bytes; `secrets.tar.gz.gpg` **6806**; `manifest.json` **1223** | VERIFIED |
| SHA-256 | Both `.gpg` files still match the manifest | VERIFIED |
| Manifest | Stores **ciphertext** `size_bytes` only. No gzip size, no SQL size, no InnoDB size | VERIFIED |
| `bin/backup-run.sh` | Pipeline is `mysqldump --single-transaction --quick` → plaintext `database.sql` → `gzip -c` → GPG → delete plaintext and `.gz` | VERIFIED |
| `docs/backup-runbook.md` | Restore is manual: copy bundle, decrypt offline, import dump into a clean non-production MariaDB | VERIFIED |
| GPG packet list (no decrypt) | `symkey enc packet` + encrypted data packet **length 401508858**. Pinentry failed; ciphertext was not decrypted | VERIFIED |
| Live schema dir `du` | `/var/lib/mysql/radium_desk` **6079525526** bytes (**5.7G** / **5.66 GiB**). Read-only `du`; no `mysql` client | VERIFIED as of this inspect (~10 h after the backup) |
| Live datadir `du` | `/var/lib/mysql` **12G** (includes sibling product schemas; **not** the restore footprint) | VERIFIED |
| P18-08-001 (2026-08-18) | Then `radium_desk/` ibd **5.2 GB**; `information_schema` **5.02 GB**; unbounded growth **~78 MB/day** | Prior VERIFIED; **not** re-measured as table breakdown here |
| Local Homebrew datadir | Still **6.7G** total; `radium_desk_restore_drill` **3.7G** (2026-08-21 MySQL 9.6). Not this backup ID | VERIFIED size; **not** a restore of `20260903T083001Z` |
| This Mac free disk | **10 GiB** | VERIFIED |

`gpg --list-packets` launched pinentry and failed with “Bad session key”. **No passphrase was supplied, printed, or copied.**

### What can and cannot be established without decrypt

| Question | Result | Class |
|----------|--------|-------|
| Encrypted database artifact size | 401508879 bytes (382.92 MiB / 0.374 GiB) | VERIFIED |
| Encrypted secrets size | 6806 bytes | VERIFIED |
| GPG overhead vs payload | File is 21 bytes larger than the encrypted-data packet (401508858). AES256+MDC overhead is small | VERIFIED packet length; gzip size **INFERRED** ≈ 401.5 MiB |
| `database.sql.gz` exact size | Not in the manifest. Approximately the GPG payload | INFERRED |
| Uncompressed `database.sql` size | gzip ISIZE is **inside** the ciphertext. Manifest does not record it | **UNKNOWN** |
| InnoDB restore footprint at backup time | Not recorded. Live schema dir is 5.66 GiB now | INFERRED ≈ 5.7 GiB (hours after backup; not a point-in-time `du` at 08:30Z) |
| SQL dump vs InnoDB ratio | Text-heavy log tables often gzip well; ratio not measured | UNKNOWN |
| Decryptability / gzip validity | Not tested | UNKNOWN |

Additional evidence that would replace the UNKNOWN SQL size, still without importing:

1. On a trusted isolated machine, decrypt to `database.sql.gz` only.
2. Run `gzip -l database.sql.gz` (compressed / uncompressed / ratio).
3. Delete or avoid keeping the plaintext `.sql` unless needed.
4. Future backups: record plaintext SQL bytes and gzip bytes in `manifest.json`.

### Restore storage stages (documented procedure)

The runbook decrypts offline, then imports. Inverse of `backup-run.sh` is three layers. Peak disk depends on whether intermediates are kept.

| Stage | What occupies disk | Size |
|-------|--------------------|------|
| A. Ciphertext copy | `database.sql.gz.gpg` (+ tiny secrets/manifest) | **0.37 GiB** VERIFIED |
| B. Decrypt | `database.sql.gz` | **≈ 0.37 GiB** INFERRED |
| C. `gunzip` | `database.sql` | **UNKNOWN** |
| D. Import | Isolated `radium_desk` datadir | **≈ 5.66 GiB** INFERRED from live `du` |
| E. Import working space | InnoDB redo/undo/tmp, sort files | **1–2 GiB** INFERRED (P-03-09-17 local drill saturated a 100 MiB redo) |
| F. Sibling products on a shared datadir | Must be **zero** on the isolated target | Required; not optional |

**Streamed path** (not written as a CLI in the runbook, but compatible): `gpg -d \| gunzip \| mysql`. Peak ≈ A (if retained) + D + E. SQL file is not materialized.

**Materialized path** (runbook “decrypt offline” then import): peak ≈ A + B + C + D + E at once if nothing is deleted between steps.

### Calculated requirement

| Model | Arithmetic | Class |
|-------|------------|-------|
| Streamed, 25% headroom | (0.37 + 5.66 + 2.0) × 1.25 ≈ **10.0 GiB** | INFERRED |
| Streamed, recommended | Round up for UNKNOWN spool-if-pipe-breaks | **12 GiB** INFERRED |
| Materialized, if SQL were 5 GiB | (0.37 + 0.37 + 5.0 + 5.66 + 2.0) × 1.25 ≈ **16.8 GiB** | INFERRED illustration only — SQL size UNKNOWN |
| Materialized, conservative floor while C is UNKNOWN | Keep a single number that still covers a large SQL file + import | **20 GiB** operational floor |

This Mac’s **10 GiB** free is at or below the streamed estimate and **below** the materialized floor. This ticket does **not** try to make the Mac suitable for restore.

### Is 20 GiB still appropriate?

**Yes, as a conservative operational floor for a non-streamed restore while uncompressed SQL size is UNKNOWN.** It is **not** a measured restore size for `20260903T083001Z`.

| Claim | Assessment |
|-------|------------|
| 20 GiB is empirically required | **No.** Not measured. SQL size UNKNOWN |
| 20 GiB is too low | **Unlikely** if the operator streams and the live 5.66 GiB `du` is representative; **not proven** if a large `.sql` is materialized |
| 20 GiB is unnecessarily high | **Possibly** for a streamed import (12 GiB INFERRED). **Not** unnecessarily high until `gzip -l` exists |
| 20 GiB as planning floor | **Still appropriate** for the runbook’s decrypt-then-import path |

### Recommended minimum free disk (eventual isolated environment)

| Restore method | Recommended free disk | Why |
|----------------|----------------------|-----|
| Streamed `gpg -d \| gunzip \| mysql`, delete intermediates | **12 GiB** | Live schema 5.66 GiB + ciphertext 0.37 GiB + import working space + margin. SQL not kept |
| Runbook materialized decrypt + import | **20 GiB** | SQL size UNKNOWN. 20 GiB remains the conservative floor |
| After `gzip -l` is known | Recompute: A + B + C + D + E, then × 1.25 | Replaces the 20 GiB floor with a measured number |

Do **not** use production KVM `mariadbd`, the 12G shared `/var/lib/mysql` (sibling products), or the 6.7G Homebrew datadir.

This assessment does **not** authorize a restore. Restore rehearsal remains **NO — Not performed**. Production migration remains **BLOCKED**.

---

## P-03-09-21 isolated-target verification (2026-09-03 19:02 IST)

Verify gate only. **No provision, start, decrypt, or import.** This ticket does **not** mark any target READY FOR RESTORE.

| Item | P-03-09-21 result | Class |
|------|-------------------|-------|
| Clean HEAD | `901209effb9facd777cc2d3e24e5dfb43f1a3cbd` | VERIFIED |
| Worktree | Clean before this docs update; ahead of `origin/main` by 6 | VERIFIED |
| Dirty tree | Still `feat/rd-fresh-01-inventory-pos` `b9bd2f43`. Not modified | VERIFIED |
| Backup `20260903T083001Z` | Still at `/var/backups/radium-desk/runs/20260903T083001Z/` | VERIFIED |
| Ciphertext SHA-256 | Database hash still `03091ad39adf407b57d98705f823b909c52183db085568f4a658f62a4811ef43` | VERIFIED |
| Homebrew `mariadb@11.8` / `mysql` | `brew services`: **none**. Nothing on 3306/3307/33060 | VERIFIED |
| Docker / Colima / Podman / Lima | Binaries still absent | VERIFIED |
| This Mac free disk | **10 GiB** (below the 20 GiB materialized floor from P-03-09-20) | VERIFIED |
| Local GPG / local backup copy | Env unset; no local passphrase file; no local copy of this backup ID | VERIFIED |
| Production KVM | `srv1910783` / `187.127.129.16` — live Desk. **Not a restore target** | VERIFIED |
| SSH alias `deskvps` | Same host as production Desk KVM | VERIFIED |
| SSH aliases `radium-1` / `rvs` | `HostName 148.113.8.82` exists in `~/.ssh/config`. **Not** documented as a Desk restore host. **Not** probed (may be another product; schema inventory would violate isolation rules) | VERIFIED as an alias only; restore eligibility **UNKNOWN** → treated as **INVALID** |

### Eligibility checklist

A valid target needs every row VERIFIED. Any UNKNOWN makes the target INVALID.

| Required property | Status |
|-------------------|--------|
| Dedicated host/VM/container, demonstrably isolated | **UNKNOWN** — none named and running |
| Explicit host identity | **UNKNOWN** |
| Dedicated MariaDB/MySQL instance | **UNKNOWN** — none running locally; production KVM forbidden |
| Dedicated datadir | **UNKNOWN** |
| Dedicated restore schema | **UNKNOWN** |
| Known MariaDB version | **UNKNOWN** |
| ≥20 GiB free (materialized floor) | Failed on this Mac (**10 GiB**). No other target measured |
| Not production / not KVM `/var/lib/mysql` | Production exists and is forbidden |
| Not shared Homebrew datadir | Homebrew datadir still shared and **not running** |
| Safe GPG on the target | **UNKNOWN** on any non-production host. KVM file exists; using it there is unsafe |
| Backup accessible to the target | Artifact is on the KVM only. No isolated-host copy |
| Disposable after validation | **UNKNOWN** — no target |
| No production application traffic | **UNKNOWN** — no target |

**Restore target status: INVALID / BLOCKED.** Not READY FOR RESTORE. Decrypt/import **NO — Not performed.**

### Owner still must provide

1. A named dedicated host/VM/container that is not `187.127.129.16` and not this Mac’s Homebrew datadir.
2. An already-running MariaDB on a dedicated datadir, with a disposable restore schema.
3. ≥20 GiB free (or a measured floor after `gzip -l`).
4. Ciphertext copy of `20260903T083001Z` on that host.
5. GPG passphrase already safely available **on that host** (not pasted into chat).
6. Explicit cleanup permission for the temporary datadir/schema.

`148.113.8.82` is **not** accepted as the restore target unless the owner names it and the full checklist can be VERIFIED without inspecting other products.

Production migration remains **BLOCKED**.

---

## P-03-09-23 gate resolution (2026-09-03 20:24 IST)

Gate-resolution ticket. **No decrypt, import, start, provision, or production write.**

| Item | P-03-09-23 result | Class |
|------|-------------------|-------|
| Clean HEAD | `db7a15e8b2b8750dd2431ee8e9a416fbe35b917b` | VERIFIED |
| Backup `20260903T083001Z` SHA-256 | Still `03091ad39adf407b57d98705f823b909c52183db085568f4a658f62a4811ef43` | VERIFIED |
| Homebrew / Docker / local ports | Unchanged — none running; Docker absent | VERIFIED |
| Mac free disk | **10 GiB** | VERIFIED |
| KVM MariaDB | Single `mariadbd` on `127.0.0.1:3306` only | VERIFIED |
| KVM `/root/.radium-backup-passphrase` | EXISTS (sudo; not read) | VERIFIED |
| Isolated running target | **NONE** | VERIFIED |
| Restore rehearsal | **NO — Not performed** | VERIFIED |
| Migration rehearsal on backup copy | **NO — Not performed** | VERIFIED |
| SQLite Phase-1 migration tests | 37 passed (partial; not backup restore) | VERIFIED |

Prior blocker **not safely resolved**. Full report: [`desk-phase1-restore-gate-resolution.md`](desk-phase1-restore-gate-resolution.md).

Production migration remains **BLOCKED**.

---

## P-03-09-24 restore-host investigation — 148.113.8.82 (2026-09-03 20:30 IST)

Read-only external + SSH-handshake probe. **No login, no MariaDB client, no host modification.**

| Item | Result | Class |
|------|--------|-------|
| PTR | `ns5022270.ip-148-113-8.net` | VERIFIED |
| SSH config | `radium-1` / `rvs` → port **20097** | VERIFIED (config) |
| SSH live port 20097 | **Connection refused** | VERIFIED |
| SSH live port 22 | Open; **Permission denied** for operator keys | VERIFIED |
| TCP 3306 | Open from Mac and from production KVM | VERIFIED (connect only) |
| HTTP | Caddy on port 80 | VERIFIED |
| Host OS / disk / MariaDB version / datadir / schemas | **UNKNOWN** (no shell) | UNKNOWN |
| Isolation / non-production role | **UNKNOWN** | UNKNOWN |
| Restore host suitability | **NOT VERIFIED** | VERIFIED verdict |

Full report: [`desk-phase1-restore-host-148-investigation.md`](desk-phase1-restore-host-148-investigation.md).

Production migration remains **BLOCKED**.
