# Radium Desk — Paired rdservice.net rehearsal feasibility (P-18-09-54)

**Companion:** RDServiceNet-P-18-09-04  
**Date:** 2026-09-18  
**Mode:** Read-only. No staging platform creation, deploy, or production change.

## Verified repository state (inspection start)

| Item | Value |
|------|--------|
| Path | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` |
| Branch | `main` |
| HEAD | `c145168af3959e196181ebd3d2d7e284695c4195` |
| Remote | `git@github.com:radium-foundation/radium-desk.git` |
| Production runtime | v4.0.88 (not re-deployed; contract code at `c6ce97da` not on production) |

## Radium Desk staging

**NONE documented or observed.**

| Check | Result |
|-------|--------|
| `/var/www/radium-desk-staging` | Absent |
| Second Desk vhost | Not found on KVM inspection |
| `tools/config.sh` non-prod target | **Absent** — only `REMOTE_PROJECT=/var/www/radium-desk` (production) |
| Restore inventory P-03-09-26 | **Approved isolated host: NONE** |
| Legacy cloud host `187.127.183.72` | Backup storage + legacy production-adjacent — **not** isolated rehearsal |

**NO EXISTING PAIRED DESK STAGING ENVIRONMENT.**

## Existing disposable local environment (candidate B)

Documented in `docs/local-development.md`:

| Attribute | Operator Mac state |
|-----------|-------------------|
| Config | `.env` → `DB_CONNECTION=sqlite`, `APP_ENV=local` |
| DB file | `database/database.sqlite` (disposable) |
| HTTP | `php artisan serve` / `composer dev` |
| PDF assets | `public/brand/logo.png`, `stamp-bgr.png` present |
| Imagick (Homebrew PHP 8.5) | **Absent** — blocks PDF in Step 1 |
| Imagick (Herd shim) | Extension listed but Herd runtime **broken** (missing `herd-84-arm64.so`) |
| Production data | **Not used** |
| Authorized for testing | **Local contract rehearsal only** — not designated owner staging |

Step 1 (`RadiumDesk-P-18-09-53`) verified real HTTP ingest, HMAC, idempotency, manual mint, net outbox deliver on this pattern.

## Production KVM (reference only — not rehearsal target)

| Attribute | Value |
|-----------|--------|
| Host | `187.127.129.16` |
| Path | `/var/www/radium-desk` |
| DB | `radium_desk` (shared `mariadbd` with `rdservice_net`, `rdservice_net_prod`, betas, etc.) |
| PHP Imagick | **YES** on `lsphp84` |
| Queue | `radium-desk-queue-worker` RUNNING |
| Storage | ~2.2G under `storage/` (production data) |
| Rehearsal use | **FORBIDDEN** — would consume production invoice sequences and mutate live commerce/statutory tables |

## Paired connectivity with test.rdservice.net

| Question | Answer |
|----------|--------|
| Can test net reach a Desk API over HTTP? | **Technically yes** (same KVM + public URL) |
| Is there an isolated Desk endpoint to reach? | **NO** |
| Safe to configure test net → production Desk? | **NO** |

## Final feasibility classification

| Outcome | Applies |
|---------|---------|
| **1. Existing isolated environment available** | **NO** (for paired Desk) |
| **2. Local/disposable rehearsal only** | **YES** |
| **3. No safe paired environment available** | **YES** |

## Owner provisioning minimum (if paired rehearsal required)

See `docs/desk-phase1-restore-host-provisioning-spec.md`. Summary:

- New VM **or** new KVM vhost + **separate MariaDB schema** (not `radium_desk`).
- MariaDB 11.8.x, ≥20 GiB free, SSH documented, disposable schema name.
- Isolated Desk checkout at known path; Imagick-enabled PHP; no production queue on live supervisor program name without isolation review.
- Staging-only HMAC secret pair; **no** production secret copy.
- Explicit written approval before import/rehearsal tickets.

**Do not create in this ticket.**
