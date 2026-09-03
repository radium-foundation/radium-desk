# Restore host investigation — 148.113.8.82

**Project:** Radium Desk  
**Repository:** `git@github.com:radium-foundation/radium-desk.git`  
**Worktree:** `/Users/ravi/RadiumWebsites/radium-desk-phase1-clean`  
**Prompt ID:** **RadiumDesk-P-03-09-24**  
**Date:** 2026-09-03  
**Type:** Read-only investigation of candidate restore host. **No SSH login, no MariaDB connection, no decrypt/import, no install, no production change.**

Predecessor: P-03-09-23 (restore gate still blocked; owner asked to evaluate `148.113.8.82`).

---

## Verdict

**RESTORE HOST NOT VERIFIED — STILL BLOCKED**

`148.113.8.82` cannot be conclusively established as a safe, isolated restore-rehearsal host from evidence available in this ticket. **No modification** was made to the host. **No restore rehearsal** was performed.

---

## Evidence summary

### Network / identity (external, VERIFIED)

| Item | Evidence | Class |
|------|----------|-------|
| IP | `148.113.8.82` | VERIFIED |
| PTR | `ns5022270.ip-148-113-8.net` | VERIFIED (`host` / `dig -x`) |
| Provider | OVH / OVHTech R&D (India) — Mumbai hosting range `148.113.8.0/24` | INFERRED from PTR naming + public WHOIS patterns for adjacent IPs in same `/24`; **not** independently ARIN-verified for `.82` in this ticket |
| Reachability | Ping 0% loss (~10 ms RTT from operator Mac) | VERIFIED |
| HTTP | Port **80** open; `Server: Caddy`; redirects to HTTPS | VERIFIED |
| HTTPS | Port **443** open; TLS handshake fails from operator Mac (`tlsv1 alert internal error`) | VERIFIED |
| SSH (config) | `~/.ssh/config` aliases `radium-1` (root) and `rvs` (ravi) → port **20097** | VERIFIED (config file) |
| SSH (live) | Port **20097** → **connection refused** | VERIFIED |
| SSH (live) | Port **22** → open; `Permission denied (publickey,password)` for `ravi` and `root` with default keys | VERIFIED |
| MariaDB port | Port **3306** → **open** from operator Mac and from production KVM `187.127.129.16` | VERIFIED (TCP connect only; **no** DB login) |
| Port 8080 | Open | VERIFIED |

Production Desk KVM is **`187.127.129.16`** (Hostinger, documented in `tools/config.sh`). **`148.113.8.82` is a different IP and provider** — therefore **not the same host** as production Desk. That alone does **not** prove non-production or isolation.

### SSH configuration mismatch (VERIFIED)

| Alias | Config | Live |
|-------|--------|------|
| `radium-1` | `root@148.113.8.82:20097` | Port 20097 **refused** |
| `rvs` | `ravi@148.113.8.82:20097` | Port 20097 **refused** |
| (implicit) | — | Port **22** accepts SSH but **rejects** operator `id_ed25519` |

Operator workstation offered `~/.ssh/id_ed25519`; authentication failed. **No shell access** was obtained. Contents of the host are **UNKNOWN**.

### What could NOT be verified (UNKNOWN — stop conditions)

Without authenticated SSH (or owner-approved DB credentials on a verified isolated schema), the following remain **UNKNOWN**:

| Required property | Status |
|-------------------|--------|
| Hostname / role (standby, staging, prod mirror, other product) | **UNKNOWN** |
| OS / kernel | **UNKNOWN** |
| RAM / CPU | **UNKNOWN** |
| Filesystem free space | **UNKNOWN** |
| MariaDB installed / running | **INFERRED** (3306 open) — process/version **UNKNOWN** |
| MariaDB version | **UNKNOWN** |
| Datadir path | **UNKNOWN** |
| Datadir shared with other applications | **UNKNOWN** |
| Existing schemas (`radium_desk`, sibling products) | **UNKNOWN** |
| `/var/www/radium-desk` or other app trees | **UNKNOWN** |
| Production vs non-production classification | **UNKNOWN** |
| Safe disposable restore schema | **UNKNOWN** |
| Backup copy path from KVM `20260903T083001Z` | **UNKNOWN** |
| GPG passphrase mechanism on this host | **UNKNOWN** |

### Prior chat context (not upgraded to VERIFIED)

Local conversation index snippets (not re-executed in this ticket) mention `148.113.8.82` as a **stubbed VPS standby** with `/var/www/radium-desk` and SSH port **20097**, and one snippet references database name **`radium_desk`**. These are **historical hints only**. They are **not** hard evidence in this ticket and do **not** prove current isolation, emptiness, or suitability.

### Production boundary (VERIFIED — not crossed)

| Action | Status |
|--------|--------|
| Production KVM MariaDB modified | **NO** |
| Production `radium_desk` schema touched | **NO** |
| Backup decrypted | **NO** |
| GPG passphrase read/printed | **NO** |
| Software installed on `148.113.8.82` | **NO** |
| MariaDB client login to `148.113.8.82:3306` | **NO** (no credentials guessed) |

---

## Why the host remains unsuitable (for this gate)

1. **No authenticated access** — cannot inventory datadir, schemas, disk, or MariaDB version.
2. **Stale SSH config** — documented port **20097** is closed; live SSH is on **22** with failing key auth.
3. **MariaDB 3306 exposed to network** — even if credentials existed, role and data boundaries are unverified; could hold live or replica data.
4. **Not documented in Desk ops** — absent from `tools/config.sh`, backup runbook, or restore gate docs as an approved isolated target.
5. **Isolation checklist incomplete** — every required row from P-03-09-21 remains **UNKNOWN** or **failed**.

---

## Owner actions required before this host can be reconsidered

1. **Confirm intended role** of `148.113.8.82` in writing (Desk standby? empty VPS? other product?).
2. **Restore SSH access** — fix port/user/key (update `~/.ssh/config` to match live SSH on port **22** or reopen **20097**) and verify login without sharing private keys in chat.
3. **Authenticated inventory** — hostname, OS, `df -h`, `free -h`, MariaDB version, datadir, schema list, `/var/www/*` layout, running services.
4. **Prove isolation** — dedicated datadir or disposable schema with **no** shared production traffic; confirm whether existing `radium_desk` data (if any) may be dropped.
5. **Disk** — confirm **≥12 GiB** free (streamed restore) or **≥20 GiB** (materialized path).
6. **Backup + passphrase path** — how ciphertext reaches this host and how GPG decrypt runs **without** exposing passphrase to Desk/tickets.
7. **Update ops docs** — add host to restore runbook only after checklist passes.

---

## What this ticket did not do

| Action | Status |
|--------|--------|
| Restore rehearsal | **NO — Not performed** |
| Migration rehearsal | **NO — Not performed** |
| Production migration / deploy | **NO — Not performed** |
| Merge / tag / spoke / secrets / payment / backfill | **NO — Not performed** |
| Modify `148.113.8.82` | **NO — Not performed** |
| Modify production KVM | **NO — Not performed** |
