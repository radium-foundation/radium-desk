# Phase-1 production deployment gate — channel-order hub

**Project:** Radium Desk  
**Repository:** `git@github.com:radium-foundation/radium-desk.git`  
**Worktree:** `/Users/ravi/RadiumWebsites/radium-desk-phase1-clean`  
**Prompt ID:** **RadiumDesk-P-03-09-22**  
**Date:** 2026-09-03  
**Branch:** `feat/rdservice-net-phase1-clean` @ `db7a15e8`

Readiness + deployment-gate inspection only. **No production deploy, migrate, `.env`, secret, spoke enablement, or invoice.**

---

## Verdict

**NOT READY — BLOCKED** for production deploy and spoke enablement.

The clean Phase-1 implementation is **code-ready** locally (focused tests pass, diff is scoped, no secrets). Production still runs **`v4.0.64`** without channel routes or Phase-1 schema. Deployment is blocked by release-process gates (not on `main`, not tagged, not pushed, no `4.0.65` changelog) and by the documented **restore-rehearsal / production-migrate** gate (P-03-09-16…21).

---

## Repository / worktree (VERIFIED)

| Item | Value |
|------|-------|
| Worktree path | `/Users/ravi/RadiumWebsites/radium-desk-phase1-clean` |
| Branch | `feat/rdservice-net-phase1-clean` |
| HEAD | `db7a15e8b2b8750dd2431ee8e9a416fbe35b917b` |
| Base | `origin/main` `21fc11c5` (7 commits ahead) |
| Worktree | **Clean** |
| Remote | `origin` → `git@github.com:radium-foundation/radium-desk.git` |
| Remote `origin/main` | `21fc11c5` (unchanged; feature branch **not pushed**) |

Feature commits (oldest → newest):

1. `b76a1c8c` — Add clean rdservice.net Phase 1 ingest and manual statutory invoice path  
2. `6e8b8642` … `db7a15e8` — Backup / restore gate documentation only  

Diff vs `origin/main`: **73 files**, ~6008 insertions. Scope matches [`desk-rdservice-net-phase1-clean-release.md`](desk-rdservice-net-phase1-clean-release.md). No Shiprocket, WhiteBooks HTTP, seller-profile, or dirty inventory/POS WIP.

---

## Production mapping (VERIFIED)

| Item | Value |
|------|-------|
| Server | `srv1910783` / `187.127.129.16` (`tools/config.sh`) |
| SSH user | `ravi` |
| App path | `/var/www/radium-desk` |
| Public URL | `https://desk.radiumbox.com` |
| Deploy mechanism | `DEPLOY_MODE=kvm` → `./tools/desk deploy` / `deskd` (local rsync; **no remote git**) |
| Required deploy branch | `main` + clean worktree + semver tag matching `CHANGELOG.md` |
| Production version | **`4.0.64`** (`release.json` build `0d734f85`, deployed 2026-08-31) |
| PHP | `/usr/local/lsws/lsphp84/bin/php` |
| Database | `mysql` `127.0.0.1:3306` **`radium_desk`** |

---

## Production route / schema status (VERIFIED)

| Check | Result |
|-------|--------|
| `POST https://desk.radiumbox.com/api/v1/channel-orders` | HTTP **404** |
| `GET /up` | HTTP **200** |
| `GET /login` | HTTP **200** |
| `routes/api.php` on KVM | **No** `channel-orders` routes |
| `php artisan route:list --path=channel` | **Empty** |
| `commerce_orders` table | **Absent** |
| `statutory_invoices` table | **Absent** |
| `statutory_invoice_documents` table | **Absent** |
| `channel_ingest_attempts` table | **Absent** |
| `2026_09_*` migrations on production | **None registered** |

---

## Production configuration (names only; values not printed)

| Key | Status |
|-----|--------|
| `CHANNEL_INGEST_SECRET_RDSERVICE_NET` | **KEY_ABSENT** |
| `CHANNEL_INGEST_SECRET_RDSERVICE_IN` | **KEY_ABSENT** |
| `STATUTORY_INVOICE_SERIES_CODE` | **KEY_ABSENT** |

Empty secrets fail closed (401). Secrets were **not** invented in this ticket.

---

## Phase-1 implementation review (clean branch)

| Capability | Status |
|------------|--------|
| `POST /api/v1/channel-orders` HMAC ingest | Present |
| `GET /api/v1/channel-orders/{type}/{id}` status | Present |
| `GET /api/v1/channel-orders/{type}/{id}/document` HMAC PDF proxy | Present |
| Idempotency key `statutory:{channel}:{source_type}:{source_id}` | Present |
| Commerce persist (`commerce_orders`, items, attempts) | Present |
| Manual issue (`issueFromCommerceOrder` + Finance UI) | Present |
| B2C: number + PDF; IRN skipped | Present |
| B2B: `e_invoice_records` + outbox; **Null** gateway (no HTTP) | Present |
| September-1 commercial-date gate | Present |
| `auto_issue_invoice` / `worker_may_mint` / IRN provider | Hardcoded **OFF** / `none` |

### Migrations in clean release (5 additive)

1. `2026_09_01_120000_create_inventory_and_pos_foundation_tables.php`  
2. `2026_09_01_140000_add_inventory_branch_assignments_and_sale_idempotency.php`  
3. `2026_09_01_160000_create_statutory_invoice_foundation_tables.php`  
4. `2026_09_01_180000_create_channel_order_ingest_tables.php`  
5. `2026_09_02_130000_create_statutory_invoice_documents_table.php`  

Does **not** alter live `orders` or `outbox_events` structure.

---

## Spoke compatibility (source-level; spokes not modified)

| Spoke | Compatibility |
|-------|----------------|
| **rdservice.net Phase 1** | **Compatible.** Clean branch includes POST ingest, GET status, GET document, Sept-1 gate, `rdservice_net` channel secret slot. Matches net spoke outbox + `/myaccount/orders/{id}/invoice` proxy contract. |
| **rdservice.in Phase 1** | **Compatible at API layer.** `rdservice_in` channel + same HMAC contract supported. Production activation still requires separate secret (`CHANNEL_INGEST_SECRET_RDSERVICE_IN`) and owner cutover — out of scope here. |

Spoke `DESK_CHANNEL_INGEST_ENABLED` was **not** enabled on either spoke.

---

## Validation (this ticket)

| Check | Result |
|-------|--------|
| Focused Phase-1 tests (ingest + statutory + Phase 1 clean) | **41 passed** |
| `php -l` on all changed PHP files | **Passed** |
| Pint on changed PHP files only | **Passed** |
| Repo-wide Pint | Pre-existing failures in unrelated files (not introduced by this branch) |
| Full baseline suite | Not completed (long-running; stopped). Focused suite is the release gate used in P-03-09-15. |
| Secrets in diff | **None** (`.env.example` placeholders only) |

---

## Deployment blockers

1. **Not on `main`.** `deploy-kvm.sh` requires `DEFAULT_BRANCH=main` and tagged HEAD.  
2. **Not pushed.** Branch exists only locally (ahead of `origin/main`, no remote feature branch).  
3. **No release tag.** Latest tag `v4.0.64`; no `4.0.65` in `CHANGELOG.md`.  
4. **Production migrate blocked.** Restore rehearsal of backup `20260903T083001Z` is **BLOCKED** (no isolated restore target; P-03-09-16…21). `deskd` runs `migrate --force`.  
5. **Route/schema absent on production.** Expected until approved deploy + migrate.  
6. **Shared secrets absent.** Required for spoke enablement after deploy; not created here.

---

## Required path to production (owner actions; not executed here)

1. Resolve restore-rehearsal gate **or** owner explicitly accepts migrate risk after backup.  
2. Merge `feat/rdservice-net-phase1-clean` → `main`.  
3. Approve and add `CHANGELOG.md` entry **`4.0.65`**.  
4. Tag `v4.0.65`, push `main` + tag.  
5. `npm run build`, checkout tag on clean `main`, run `deskd`.  
6. Post-deploy verify: `release.json`, `/up`, `/login`, route returns **401** (not 404) with missing HMAC, schema tables present.  
7. Owner installs matching `CHANNEL_INGEST_SECRET_*` on Desk and spoke(s).  
8. HMAC reject/accept with non-production fixture **before** enabling spoke ingestion.

---

## What this ticket did not do

| Action | Status |
|--------|--------|
| Production deploy / `deskd` | **NO** |
| Production migrate | **NO** |
| Push / merge / tag | **NO** |
| Production `.env` / secrets | **NO** |
| Spoke ingestion enable | **NO** |
| Invoice / backfill / live payment | **NO** |
| rdservice.in / net / Sign / Admin / Stocky change | **NO** |
| Restore rehearsal | **NO** (explicitly not resumed) |
