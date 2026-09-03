# Phase-1 release preparation — v4.0.65

**Project:** Radium Desk  
**Repository:** `git@github.com:radium-foundation/radium-desk.git`  
**Worktree:** `/Users/ravi/RadiumWebsites/radium-desk-phase1-clean`  
**Prompt ID:** **RadiumDesk-P-03-09-25**  
**Date:** 2026-09-03  
**Branch:** `feat/rdservice-net-phase1-clean`  
**Application commit:** `b76a1c8c` (Phase-1 code) + docs commits through `db7a15e8`  
**Baseline:** `origin/main` `21fc11c5` (post-`v4.0.64` docs tip; tag `0d734f85`)

**Status:** **RELEASE PREPARED — WAITING FOR RESTORE GATE**

This ticket prepares the clean Phase-1 branch for a later controlled merge/release. **No merge, tag, push, deploy, production migrate, secrets, or spoke enablement** was performed.

---

## Release scope (verified)

| Capability | Present | Notes |
|------------|---------|-------|
| HMAC `POST /api/v1/channel-orders` | Yes | Channels: `rdservice_in`, `rdservice_net`, others in enum |
| HMAC GET status + document | Yes | Document GET for spoke PDF proxy |
| Idempotency | Yes | `statutory:{channel}:{source_type}:{source_id}` |
| Commerce persist | Yes | `commerce_orders`, items, `channel_ingest_attempts` |
| Manual statutory issue | Yes | Finance pending + `issueFromCommerceOrder()` |
| Private PDF | Yes | `statutory_invoice_documents` on local disk |
| B2C | Yes | Number + PDF; IRN skipped |
| B2B | Yes | `e_invoice_records` + outbox; **Null** gateway |
| September-1 gate | Yes | Commercial date boundary |
| Auto-issue OFF | Yes | `auto_issue_invoice=false`, `worker_may_mint=false` |
| IRN HTTP OFF | Yes | `einvoice.provider=none`, `NullEInvoiceGateway` bound |

**Excluded** (not in this branch): Shiprocket, WhiteBooks HTTP, seller-profile numbering, POS operational UI, dirty `feat/rd-fresh-01-inventory-pos` WIP.

---

## Diff review

| Metric | Value |
|--------|-------|
| Files vs `origin/main` | **73** |
| Insertions | ~6008 |
| Application code commit | `b76a1c8c` |
| Unrelated application changes | **None found** |
| Secrets in diff | **None** (`.env.example` placeholders only) |

All changed paths are Phase-1 hub, statutory foundation, tests, config, five migrations, Finance views, and gate/release documentation.

---

## Five expected production migrations (additive)

Run order on production **only after restore gate clears** and owner approves deploy:

| # | File | Purpose |
|---|------|---------|
| 1 | `2026_09_01_120000_create_inventory_and_pos_foundation_tables.php` | Empty inventory tables (FK chain for statutory) |
| 2 | `2026_09_01_140000_add_inventory_branch_assignments_and_sale_idempotency.php` | Branch assignment helper table |
| 3 | `2026_09_01_160000_create_statutory_invoice_foundation_tables.php` | Statutory + sequence + e-invoice tables |
| 4 | `2026_09_01_180000_create_channel_order_ingest_tables.php` | Commerce + ingest attempt tables |
| 5 | `2026_09_02_130000_create_statutory_invoice_documents_table.php` | Private PDF document rows |

**Does not alter** live `orders` or `outbox_events` structure. All `up()` methods are `Schema::create` / additive columns only.

---

## Validation (this ticket)

| Check | Result |
|-------|--------|
| Phase-1 focused tests | **41/41 passed** |
| `php -l` on changed PHP | **Passed** |
| Pint on changed PHP files | **1 pre-existing style note** on `AppServiceProvider.php` (import ordering across full file; Phase-1 diff is 3 lines only) |
| Repo-wide Pint | Pre-existing failures outside this branch — **not introduced by Phase-1** |
| MariaDB restore rehearsal | **Cannot perform** — restore gate blocked |
| MariaDB migration rehearsal on backup copy | **Cannot perform** — restore gate blocked |

---

## Production deployment mapping (reviewed, not executed)

| Item | Value |
|------|-------|
| Server | `187.127.129.16` (`tools/config.sh`) |
| Path | `/var/www/radium-desk` |
| Mechanism | `DEPLOY_MODE=kvm` → `./tools/desk deploy` / `deskd` |
| Required branch | **`main`** |
| Required tag | Semver matching `CHANGELOG.md` |
| Required worktree | Clean (one allowed untracked doc optional) |
| Post-sync | `composer install`, **`migrate --force`**, `RolePermissionSeeder`, queue worker restart |
| Current production | **`v4.0.64`** / `0d734f85` |
| Current route | `POST /api/v1/channel-orders` → **404** |

---

## Changelog / release readiness for v4.0.65

| Requirement | Status |
|-------------|--------|
| `CHANGELOG.md` entry for `4.0.65` | **Absent** — must be drafted and **owner-approved** before tag |
| Git tag `v4.0.65` | **Not created** |
| Merge to `main` | **Not performed** |
| Push | **Not performed** |
| `npm run build` + `release:snapshot` | Required at deploy time; **not run** in this ticket |

### Suggested changelog bullets (draft — not committed)

Owner must approve before adding to `CHANGELOG.md`:

```markdown
## 4.0.65 — YYYY-MM-DD — Channel Order Hub (Phase 1)

- Desk can receive paid channel orders from rdservice.net and rdservice.in via HMAC-authenticated ingest (disabled until secrets are configured).
- Commerce orders persist for manual statutory tax-invoice issuance; automatic invoice minting remains off.
- Finance can issue statutory invoices and private PDFs for eligible September-2026 onward orders.
- B2B invoices queue IRN foundation work only; no live IRN provider is enabled.
```

---

## Restore gate (authoritative)

**RESTORE HOST NOT VERIFIED — STILL BLOCKED** (P-03-09-23, P-03-09-24).

Production migrate and `deskd` **must not** proceed until:

1. Isolated MariaDB restore rehearsal of backup `20260903T083001Z` succeeds.
2. Five migrations verified on rehearsal copy.
3. Owner approves changelog `4.0.65`.
4. Merge `feat/rdservice-net-phase1-clean` → `main`, tag, push.
5. Post-deploy: verify route returns **401** (not 404) with missing HMAC; schema present; **do not** enable spoke ingestion until HMAC reject/accept passes.

---

## Later release gate checklist

- [ ] Restore gate cleared
- [ ] `CHANGELOG.md` `4.0.65` approved
- [ ] Merge to `main`
- [ ] Tag `v4.0.65` on merge commit
- [ ] Push `main` + tag
- [ ] Clean worktree on tagged `main`
- [ ] `npm run build`
- [ ] `deskd`
- [ ] Verify `release.json`, `/up`, `/login`
- [ ] Verify `POST /api/v1/channel-orders` auth behavior
- [ ] Verify five migrations applied; Phase-1 tables exist
- [ ] Owner installs matching `CHANNEL_INGEST_SECRET_*` (not in git)
- [ ] Spoke enablement remains separate gate

---

## Related documentation

| Doc | Purpose |
|-----|---------|
| [`desk-rdservice-net-phase1-clean-release.md`](desk-rdservice-net-phase1-clean-release.md) | Clean branch contents |
| [`desk-phase1-production-deployment-gate.md`](desk-phase1-production-deployment-gate.md) | P-03-09-22 deployment gate |
| [`desk-phase1-restore-gate-resolution.md`](desk-phase1-restore-gate-resolution.md) | P-03-09-23 restore gate |
| [`desk-phase1-restore-host-148-investigation.md`](desk-phase1-restore-host-148-investigation.md) | P-03-09-24 host probe |

---

## What this ticket did not do

Merge, tag, push, deploy, production migrate, secrets, spoke enablement, live payment, backfill, Admin/spoke changes, restore rehearsal, CHANGELOG commit — all **NO — Not performed.**
