# Hostinger Production Change Safety & VPS Snapshot Capability

**Canonical status:** Source-of-truth for Cursor agents evaluating Hostinger VPS snapshots as an **additional** production recovery layer across RadiumWebsites.  
**Companion:** [`hub-spoke-payment-order-recovery.md`](./hub-spoke-payment-order-recovery.md) (Hub/Spoke boundaries), [`../backup-runbook.md`](../backup-runbook.md) (Desk DB backup architecture).  
**Prompts:** `RadiumDesk-P-07-09-164` (Hostinger API/snapshot capability), `RadiumDesk-P-07-09-165` (production backup system audit)  
**Audit dates:** 2026-09-14  
**Classification key:** **VERIFIED** = evidenced in official Hostinger documentation or committed Radium repository docs. **INFERRED** = consistent but not independently re-executed in this audit. **UNKNOWN** = not established; do not assume.

---

## 1. Purpose

This document records a **read-only audit** of Hostinger API / Connector capabilities relevant to production change safety — especially VPS snapshots, backups, restore operations, VPS actions, and operational verification.

**Goals:**

- Determine whether Hostinger VPS snapshots can safely become an **additional** recovery layer for major production changes.
- Establish permanent memory so future Cursor agents do not re-ask questions already answered here.
- Propose (but **not implement**) a risk-based snapshot policy and future automation design.

**Explicit non-goals (this audit):**

- No snapshot creation, deletion, or restore was performed.
- No Hostinger, VPS, DNS, Cloudflare, firewall, SSH, application, database, or deployment configuration was changed.
- No automatic snapshot tooling was implemented.

---

## 2. Hostinger API / Connector capabilities (DOCUMENTED)

Sources reviewed: [Hostinger Connector overview](https://docs.hostinger.com/hostinger-connector/overview), [Hostinger API reference](https://developers.hostinger.com/), [Hostinger VPS backup/snapshot support article](https://www.hostinger.com/support/1583232-how-to-back-up-or-restore-a-vps-at-hostinger/), and the official [Hostinger API Python SDK](https://github.com/hostinger/api-python-sdk) endpoint documentation (OpenAPI-derived).

**Base URL:** `https://developers.hostinger.com`  
**Path pattern:** `/api/{product}/v1/...` (e.g. `/api/vps/v1/virtual-machines`)

### 2.1 Authentication (DOCUMENTED — VERIFIED)

| Method | Use case |
|--------|----------|
| **API token (Bearer)** | Direct API calls: `Authorization: Bearer <token>`. Tokens are created in hPanel → API; inherit creator permissions; may expire. |
| **OAuth 2.1** | Hostinger Connector / hosted MCP at `https://mcp.hostinger.com` — browser sign-in, no token management in editor. |
| **Local MCP** | `npx -y @hostinger/mcp` or product-scoped packages (`hostinger-vps-mcp`, etc.). |

**Security rule for Radium agents:** Never print, commit, or document API tokens, OAuth secrets, SSH keys, or backup passphrases.

### 2.2 VPS identification (DOCUMENTED — VERIFIED)

| Capability | Endpoint | Notes |
|------------|----------|-------|
| List all VPS instances | `GET /api/vps/v1/virtual-machines` | Returns array of `VPSV1VirtualMachineVirtualMachineResource` |
| Get one VPS | `GET /api/vps/v1/virtual-machines/{virtualMachineId}` | Detailed status and configuration |
| List data centers | `GET /api/vps/v1/data-centers` | Location options for deploy/placement verification |

**Fields exposed per VM (SDK model):** `id`, `hostname`, `state`, `data_center_id`, `plan`, `ipv4`, `ipv6`, `cpus`, `memory`, `disk`, `bandwidth`, `template`, `actions_lock`, `firewall_group_id`, `subscription_id`, `created_at`.

**Mapping to Radium projects:** API exposes `hostname`, `id`, and IP addresses — sufficient to **correlate** a VPS to a known production IP **if** the numeric `virtualMachineId` and hostname are recorded in a verified project→VPS registry. The API alone does not label which app runs on the VPS; that mapping must come from Radium deployment documentation and independent verification.

**Documented VM `state` values (INFERRED from SDK + DeepWiki):** `pending`, `running`, `stopped`, `starting`, `stopping`, `recovery`, `recreating`, `restoring`.

### 2.3 Snapshot capability (DOCUMENTED — VERIFIED)

| Operation | HTTP | Returns |
|-----------|------|---------|
| Get current snapshot | `GET .../virtual-machines/{id}/snapshot` | `VPSV1SnapshotSnapshotResource`: `id`, `createdAt`, `expiresAt`, `restoreTime` (estimated seconds) |
| Create snapshot | `POST .../virtual-machines/{id}/snapshot` | `VPSV1ActionActionResource` (async action) |
| Restore snapshot | `POST .../virtual-machines/{id}/snapshot/restore` | `VPSV1ActionActionResource` (async action) |
| Delete snapshot | `DELETE .../virtual-machines/{id}/snapshot` | `VPSV1ActionActionResource` (async action) |

**Critical lifecycle rules (official support + SDK — VERIFIED):**

| Rule | Implication for Radium |
|------|------------------------|
| **Only one snapshot per VPS** | Creating a new snapshot **overwrites** the previous one. Pre-change automation must record snapshot `id` + `createdAt` immediately; a later snapshot destroys the earlier checkpoint. |
| **Snapshots expire after 1 day** | Not a long-term DR layer. Use for **same-day** change windows only. |
| **OS reinstall / recreate deletes snapshot** | Infrastructure changes that rebuild the VM invalidate snapshot recovery. |
| **Restore from backup also deletes snapshot** | Hostinger backup restore and snapshot are separate; backup restore can remove an existing snapshot. |
| **Restore overwrites entire VPS** | All disk state reverts to capture time — all co-located apps and databases on that VPS are affected. |
| **Restore is irreversible** | Cannot cancel mid-restore; VPS is **locked** during snapshot/backup operations. |
| **Creation is asynchronous** | `POST` returns an **action ID**; poll action status until complete (see §2.6). |
| **No download via API** | Snapshots/backups cannot be downloaded to local disk through hPanel/API; use SFTP for file-level extraction if needed. |

**Pricing:** Snapshot and backup features may have plan/add-on costs (daily backups are a paid upgrade per support article). **UNKNOWN** for Radium account — verify in hPanel before budgeting automation.

### 2.4 Backup capability (DOCUMENTED — VERIFIED)

**Distinct from VPS snapshots.** Hostinger **automated VPS backups** are a separate product feature.

| Operation | HTTP | Notes |
|-----------|------|-------|
| List backups | `GET .../virtual-machines/{id}/backups?page=` | Paginated `VPSV1BackupListResponse` |
| Restore backup | `POST .../virtual-machines/{id}/backups/{backupId}/restore` | Async action; **overwrites all VM data** |

**No API to trigger on-demand backup creation** was found in the official VPS Backups API — backups are created on Hostinger's schedule (hPanel → Manage Schedule).

**Retention (support article — VERIFIED):**

- Default: **weekly** automated backups.
- Optional: **daily** backups (paid add-on).
- Up to **four** backup points retained: **two daily + two weekly** when daily is enabled; each new backup replaces the oldest of its type.
- Backup creation/restoration: **10 minutes to several hours** depending on size.
- Backups stored **separately** from the live server (unlike the 1-day snapshot).

**Distinction summary:**

| Feature | Trigger | Retention | Granularity | API create? |
|---------|---------|-----------|-------------|-------------|
| **VPS snapshot** | Manual (API/hPanel) | **1 day**; **1 slot** (overwrite) | Whole VPS disk | **Yes** (`POST .../snapshot`) |
| **VPS backup** | Automated schedule | Up to **4** points (2+2) | Whole VPS disk | **No** (schedule only) |
| **Desk DB backup** | `bin/backup-run.sh` cron | GFS prune on Cloud | **Desk DB + secrets only** | N/A (Radium-owned) |
| **Deployment overlay** | Per deploy prompt | Operator-defined | Named files / `.env` | N/A (Radium-owned) |
| **DNS snapshot** | Separate DNS API | Domain DNS history | DNS zone only | **Yes** (DNS API — not VPS) |

### 2.5 VPS power / lifecycle actions (DOCUMENTED — VERIFIED)

Start, stop, restart, and other lifecycle operations return `VPSV1ActionActionResource` and are asynchronous:

| Action | HTTP |
|--------|------|
| Start | `POST .../virtual-machines/{id}/start` |
| Stop | `POST .../virtual-machines/{id}/stop` |
| Restart | `POST .../virtual-machines/{id}/restart` |

Additional documented VPS APIs: firewall, recovery mode, PTR, public keys, metrics, recreate, hostname, panel/root password.

### 2.6 Action monitoring (DOCUMENTED — VERIFIED)

| Operation | HTTP |
|-----------|------|
| List actions | `GET .../virtual-machines/{id}/actions?page=` |
| Get action detail | `GET .../virtual-machines/{id}/actions/{actionId}` |

**Action resource fields:** `id`, `name`, `state`, `created_at`, `updated_at`.

**Completion verification pattern (DOCUMENTED — VERIFIED):**

1. Mutating call (snapshot create/restore, backup restore, start/stop) returns `action.id`.
2. Poll `GET .../actions/{actionId}` until `state` indicates completion (or VM `state` leaves transitional `restoring` / `creating` states).
3. For snapshots, follow up with `GET .../snapshot` to confirm `createdAt` / `expiresAt`.
4. hPanel → **Latest actions** shows `backup_create`, `backup_restore`, and snapshot-related entries (support article).

**INFERRED:** Failed actions should surface in action `state` or HTTP 4xx/5xx on poll; exact terminal state strings should be confirmed against live API responses when Connector access is available.

### 2.7 Metrics / health (DOCUMENTED — VERIFIED)

| Operation | HTTP |
|-----------|------|
| Historical metrics | `GET .../virtual-machines/{id}/metrics?date_from=&date_to=` |

**Metrics included (SDK — VERIFIED):** CPU usage, memory usage, disk usage, network usage, uptime.

**Use for production changes:** Compare pre/post deploy windows to detect resource regressions. **Not** a substitute for application health checks (`/up`, Desk Platform Health, storefront smoke tests).

### 2.8 Rate limits (DOCUMENTED — VERIFIED)

| Limit | Value |
|-------|-------|
| General API | **90 requests/minute** per authenticated user (shared across all machines using same token) |
| Response headers | `X-RateLimit-Limit`, `X-RateLimit-Remaining`; `429` includes `Retry-After` |
| Abuse | Repeated 429s may cause temporary IP block |

**Automation implication:** Snapshot create + action polling + VM list + metrics for multiple VPS instances must stay within 90 rpm or implement backoff.

### 2.9 Hostinger Connector (DOCUMENTED — VERIFIED)

The [Hostinger Connector](https://docs.hostinger.com/hostinger-connector/overview) exposes **372 tools** across product areas, including **63 VPS tools** (start/stop, firewalls, SSH keys, **snapshots**, metrics). Compatible with Cursor via extension or MCP (`https://mcp.hostinger.com` OAuth, or `@hostinger/mcp`).

Product areas can be enabled/disabled per assistant; destructive operations depend on editor confirmation settings.

---

## 3. Actual account / VPS verification (THIS AUDIT)

**Hostinger API capability: DOCUMENTED**  
**ACTUAL ACCOUNT ACCESS: UNKNOWN**

| Check | Result |
|-------|--------|
| Hostinger MCP / Connector namespace in this Cursor Cloud environment | **Not available** — no `hostinger` tools registered |
| Hostinger API token in environment | **Not verified** — no token was used; no live API calls made |
| Live VPS list / numeric IDs | **UNKNOWN** |
| Current snapshot state on any VPS | **UNKNOWN** |
| Current Hostinger backup state on any VPS | **UNKNOWN** |
| API token permissions for snapshot/restore | **UNKNOWN** |

No snapshot, backup, restore, or configuration operation was performed during this audit.

---

## 4. Radium VPS / project mappings (from repository evidence)

**Do not assume one VPS hosts all spokes.** Verify each mapping independently before any snapshot or restore.

### 4.1 VERIFIED from committed Radium documentation

| Host / alias | IP / hostname | Role | Evidence |
|--------------|---------------|------|----------|
| **KVM8 / deskvps** | `187.127.129.16`, `srv1910783.hstgr.cloud` | **Primary production KVM** — multi-app host | `tools/config.sh`, radiumbox.com ledger P-05-09-02 **VERIFIED** |
| `desk.radiumbox.com` | → KVM8 | Radium Desk | `REMOTE_PROJECT=/var/www/radium-desk` **VERIFIED** |
| `radiumbox.com` | → KVM8 | RadiumBox storefront | `/var/www/radiumbox.com`, DB `radiumbox_prod` **VERIFIED** (ledger) |
| `rdserviceonline.in` | → KVM8 | RDServiceOnline spoke | `rdserviceonline.in/tools/config.sh` → `/var/www/rdserviceonline` **VERIFIED** |
| **Hostinger Shared Cloud** | `u215544208`, SSH port `65002` | **Encrypted backup storage** for Desk DB backups (not app host) | `tools/README.md`, `docs/backup-runbook.md` **VERIFIED** |
| **media.radiumbox.com** | Separate Hostinger **shared** hosting (LiteSpeed/PHP 8.2) | Media / legacy assets; **not on KVM** | radiumbox.com ledger P-07-09-18 **VERIFIED** |

### 4.2 INFERRED (not re-verified live in this audit)

| Mapping | Notes |
|---------|-------|
| `test.rdservice.net` → same KVM8 | Design docs target `deskvps` / `187.127.129.16` for `rdservice_net` DB **INFERRED** |
| Multiple MariaDB schemas on KVM8 | Desk (`radium_desk`), Box (`radiumbox_prod`), RDServiceOnline, future `rdservice_net` — co-located, **separate schemas** **INFERRED** from schema docs |
| OpenLiteSpeed shared listener | All KVM vhosts share host resources; VPS snapshot affects **every** vhost on that machine **INFERRED** |

### 4.3 UNKNOWN (not established in this audit)

| Item | Status |
|------|--------|
| Hostinger numeric `virtualMachineId` for KVM8 | **UNKNOWN** — not recorded in Radium repos; must be fetched via API or hPanel |
| `rdservice.in` production host | **UNKNOWN** — reconciliation doc references Aug-2026 snapshot only |
| `rdservice.net` production host (non-test) | **UNKNOWN** |
| `radiumsign.com` production host | **UNKNOWN** |
| Whether Radium owns additional Hostinger VPS instances beyond KVM8 + media shared host | **UNKNOWN** |
| Daily backup add-on enabled on KVM8 | **UNKNOWN** |
| Current snapshot presence / expiry on KVM8 | **UNKNOWN** |

---

## 5. Current Radium recovery architecture

Radium uses **layered recovery**. Hostinger VPS snapshot is **Layer 4** — coarsest, highest blast radius.

| Layer | Mechanism | Scope | Typical use |
|-------|-----------|-------|-------------|
| **1 — Git** | Branch/tag revert; redeploy known SHA | Application source | Code rollback, config in repo |
| **2 — Deployment overlay backup** | `*.pre-<prompt>-<timestamp>` on server; `storage/app/rollback-pre-*` dirs | Named files / small dirs | Surgical production file rollback (radiumbox.com pattern) |
| **3 — Database backup** | `bin/backup-run.sh` → encrypted local staging → optional Hostinger Cloud | Per-app DB + selected secrets | Point-in-time DB restore; Desk runbook |
| **4 — Hostinger VPS snapshot / backup** | Whole-disk capture via Hostinger | **Entire VPS** — all apps, DBs, OLS, system config | Disaster recovery, bad system-level change, multi-app corruption |

**Desk KVM deploy rollback today:** `desk rollback` is **disabled** on KVM (no remote git). Recovery = redeploy a known-good tag via `desk deploy` **VERIFIED** — `tools/README.md`.

**Hostinger snapshot does NOT replace:**

- Git (precise, low blast radius)
- Named-file overlays (fast, app-scoped)
- DB backups (schema/data granularity without reverting OS or sibling apps)

---

## 6. Snapshot role — where it adds value

| Scenario | Git | Overlay | DB backup | VPS snapshot |
|----------|-----|---------|-----------|--------------|
| Bad PHP/Blade deploy | ✅ Primary | ✅ Primary | ❌ | ❌ Overkill |
| Bad `.env` change | ⚠️ Redeploy | ✅ If backed up | ❌ | ⚠️ Only if unrecoverable |
| DB migration mistake | ⚠️ Forward fix | ❌ | ✅ Primary | ⚠️ Last resort (loses post-migration data) |
| OpenLiteSpeed / PHP handler misconfig | ❌ | ⚠️ Partial | ❌ | ✅ Strong candidate |
| Firewall / system package change | ❌ | ❌ | ❌ | ✅ Strong candidate |
| Accidental multi-app / whole-server damage | ❌ | ❌ | ⚠️ Per-DB only | ✅ Primary |
| DNS / Cloudflare mistake | ❌ | ❌ | ❌ | ❌ Wrong layer (DNS API / CF) |
| Payment webhook misroute | ✅ Code/config | ✅ `.env` | ❌ | ❌ |

**Whole-server restore warning:** KVM8 hosts Desk, RadiumBox, RDServiceOnline, and potentially other schemas. Restoring a VPS snapshot **rolls back all co-located production apps**, not just the project being changed.

---

## 7. Risk-based snapshot policy (PROPOSAL — NOT IMPLEMENTED)

Account for **one snapshot slot**, **1-day expiry**, and **overwrite on recreate**.

### 7.1 Risk tiers

| Tier | Requirements | Hostinger snapshot? |
|------|--------------|-------------------|
| **LOW** | Git + tests; docs-only or cosmetic | **No** |
| **MEDIUM** | Git + tests + deployment overlay backup | **No** (unless system-level touch) |
| **HIGH** | Above + DB backup (+ migration plan) | **Consider** if shared VPS system config changes |
| **CRITICAL** | Above + Hostinger snapshot **before** change | **Yes** — mandatory for whole-VPS or OLS/PHP/firewall/OS changes |

### 7.2 Change-category matrix (PROPOSAL)

| Category | Snapshot required? | Rationale |
|----------|-------------------|-----------|
| Documentation-only | No | No production state change |
| CSS / frontend assets (app-only deploy) | No | Git + overlay sufficient |
| Normal backend code (single app deploy) | No | Git + overlay + app tests |
| Database migration / schema change | DB backup **yes**; snapshot **optional** | DB backup is precise; snapshot only if migration touches system or multi-DB risk |
| Payment / webhook / auth changes | DB backup **yes**; snapshot **no** unless infra | App-layer; overlay + DB |
| OpenLiteSpeed vhost / PHP version / handler | **Yes** | System-level; affects all vhosts |
| Firewall / network / SSH server config | **Yes** | Whole-VPS impact |
| DNS / Cloudflare | No | Out of Hostinger VPS scope |
| Major architecture / multi-app deploy | **Yes** | High blast radius on shared KVM |
| Emergency production hotfix | Overlay + DB as needed; snapshot if touching system | Match actual risk, not urgency alone |

### 7.3 Snapshot timing rules (PROPOSAL)

Because snapshots **expire in 24 hours** and **overwrite**:

1. Create snapshot **immediately before** the change window, not at start of day.
2. Record `virtualMachineId`, snapshot `id`, `createdAt`, `expiresAt`, action `id`, Git SHA, overlay path, DB backup `backup_id`.
3. Do **not** create a second snapshot before confirming the first is no longer needed — it destroys the prior checkpoint.
4. If change spans >24h, plan Hostinger **backup** schedule or Radium DB backup instead of relying on snapshot alone.

---

## 8. Future automation design (PROPOSAL — NOT IMPLEMENTED)

### 8.1 Pre-change workflow

```
Project
  → Repo + Branch + HEAD SHA
  → Production mapping (path, vhost, DB) — STOP if UNKNOWN
  → Risk classification (§7)
  → DB backup (if HIGH+)
  → Deployment overlay plan
  → Rollback plan documented
  → [If CRITICAL] Verify Hostinger virtualMachineId + VPS state=running
  → [If CRITICAL] POST snapshot → poll action → GET snapshot → record metadata
  → Proceed only when snapshot VERIFIED or explicitly waived by owner
```

### 8.2 Change workflow

```
Test → Fix → Re-test → Review → Deploy → Smoke / health / integration checks
```

### 8.3 Post-change workflow

```
Verify deployment (HTTP, /up, critical flows)
Verify health metrics (optional: VPS metrics API window)
Record release commit + snapshot metadata + backup IDs
Confirm rollback readiness (overlay paths, DB backup id, snapshot expiresAt)
```

### 8.4 Failure / rollback hierarchy

```
1. Application fix forward (if safe)
2. Git revert + redeploy (desk deploy / named-file overlay restore)
3. DB restore from Radium backup (if data affected)
4. Hostinger VPS snapshot restore — OPERATOR APPROVAL ONLY
   → Confirm affected project, server, all co-hosted apps, data loss window
5. Hostinger backup restore — even broader; older point-in-time
```

| Recovery type | Automation | Approval |
|---------------|------------|----------|
| Redeploy previous Git tag | Can automate with guards | Operator |
| Restore overlay files | Can automate | Operator |
| Restore DB from `backup-run.sh` artifact | Semi-automated | Operator |
| VPS snapshot restore | **Never fully automatic** | **Owner / explicit STOP gate** |
| VPS backup restore | **Never fully automatic** | **Owner** |

### 8.5 STOP → REPORT conditions

- `virtualMachineId` does not match verified project registry
- VPS hostname/IP does not match expected production mapping
- Snapshot create action failed or snapshot `expiresAt` < change window
- Multiple production apps on same VPS and change is app-scoped (snapshot too coarse)
- Co-hosted Desk must not be disturbed (cross-project isolation)
- Any architectural, security-sensitive, or destructive decision not covered by this document

Routine implementation/test failures: **FIX → RE-TEST**.  
Architecture/security/production-boundary conflicts: **STOP → REPORT**.

---

## 9. Safety rules for future Cursor agents

### 9.1 Hostinger is additional, not replacement

Never replace Git, deployment overlays, or DB backups with VPS snapshots.

### 9.2 Risk-based, not automatic-every-deploy

Do not snapshot on every small change. Follow §7.

### 9.3 Pre-snapshot verification checklist (mandatory before any future automation)

- [ ] Correct **project** and **repo**
- [ ] Correct **production VPS** (`virtualMachineId` + IP/hostname match registry)
- [ ] Correct **environment** (production vs staging)
- [ ] Correct **deployment boundary** (app path, not wrong spoke)
- [ ] Snapshot API permission confirmed
- [ ] No existing change-window snapshot about to expire
- [ ] `POST` snapshot → poll action → `GET` snapshot success
- [ ] Metadata recorded before proceeding

### 9.4 Cross-project isolation

- Radium Desk is the **Hub**; spokes (RadiumBox, rdservice.in, rdservice.net, radiumsign.com, RDServiceOnline.in) are **independent**.
- A snapshot of KVM8 affects **all** apps on that host.
- Never snapshot or restore the wrong project's VPS.
- Never modify another project's infrastructure during one project's automation.

### 9.5 Restore gate (mandatory before any restore)

Establish and document:

- Affected project(s) and **all** co-hosted apps
- Affected server (`virtualMachineId`, IP)
- Current production state vs snapshot `createdAt`
- Availability of Git, overlay, and DB backups
- Rollback impact (data loss after snapshot timestamp)
- Service/database impact on every co-located schema
- Written recovery plan and **owner approval**

### 9.6 Secret handling

Never expose API tokens, backup passphrases, or SSH private keys in logs, docs, commits, or PRs.

### 9.7 Required reading

Agents implementing production-safety automation **must** read this document and [`hub-spoke-payment-order-recovery.md`](./hub-spoke-payment-order-recovery.md) before writing code or executing infrastructure operations.

---

## 10. Remaining gaps / blockers

| Gap | Classification | Action needed |
|-----|----------------|---------------|
| Hostinger Connector not wired in Cloud Agent environment | **UNKNOWN** (access) | Owner: install/configure Connector or provide read-only API token scope |
| Numeric `virtualMachineId` for KVM8 | **UNKNOWN** | One-time read via API/hPanel; store in secure registry (not public repo) |
| Hostinger VPS **automated** backup enabled/schedule on KVM8 | **UNKNOWN** | Read-only hPanel or Hostinger API `GET .../backups` when access available |
| `cloud-inventory.json` absent on KVM8 | **VERIFIED** gap | Run `backup-cloud-inventory.sh` on schedule or fix ops process |
| Cron calls `backup-run.sh` not `backup-schedule.sh` | **VERIFIED** drift | Owner: align cron with runbook or accept stale `last-run-status.json` / watchdog blind spot |
| Only `radium_desk` in automated DB backup | **VERIFIED** gap | Owner: per-project DB backup policy for `radiumbox_prod`, `rdservice_in_prod`, etc. |
| `rdservice.in` / `radiumsign.com` production host mapping | **UNKNOWN** | Per-project infrastructure audit |
| Automated snapshot tooling | Not authorized | Separate implementation prompt after owner approves §7–§8 policy |
| VPS snapshot restore on multi-tenant KVM | Architectural | Owner decision: accept whole-VPS blast radius or split hosts |

---

## 11. Production backup system audit (`RadiumDesk-P-07-09-165`)

Read-only audit of existing backup layers before designing a pre-major-change Hostinger snapshot gate. **No backup configuration, scripts, cron, or storage was modified.**

### 11.1 Audit method

| Source | Used for |
|--------|----------|
| `docs/backup-runbook.md`, `bin/backup-run.sh`, `bin/backup-schedule.sh` | Documented design |
| Read-only SSH to KVM8 `187.127.129.16` (Sep 14 2026) | Live cron, manifests, logs, `/var/backups/*` |
| Hostinger API / hPanel | VPS automated backup state — **not available** in this environment |

### 11.2 Hostinger VPS automated backup

| Question | Status | Finding |
|----------|--------|---------|
| Daily VPS backup enabled? | **UNKNOWN** | No Hostinger API/Connector/hPanel access in this audit. **Do not assume** enabled because operators believe it is. |
| Which VPS? | **INFERRED** | KVM8 `srv1910783.hstgr.cloud` / `187.127.129.16` is the primary production KVM — if enabled, would apply to this VPS only. |
| Schedule / latest success / failures / retention | **UNKNOWN** | Requires `GET /api/vps/v1/virtual-machines/{id}/backups` or hPanel → Snapshots & Backups. |
| Storage separate from VPS? | **DOCUMENTED — VERIFIED** | Hostinger docs: backups stored separately from live server. **Actual state for Radium account: UNKNOWN.** |
| Restore scope | **DOCUMENTED — VERIFIED** | Whole-VPS overwrite only; async; irreversible; VPS locked during restore. Not selective per app/DB. |
| Restore readiness | **UNKNOWN** | No restore drill performed; no verified backup list for KVM8. |

**DOCUMENTED capability:** list backups, restore by `backupId`, up to four retained points (2 daily + 2 weekly when daily add-on enabled).  
**ACTUAL ACCOUNT STATE:** **UNKNOWN**

### 11.3 Radium Desk database backup (KVM8 — VERIFIED live)

| Attribute | Verified value |
|-----------|----------------|
| **Database** | `radium_desk` only |
| **Script** | `bin/backup-run.sh` (via cron; **not** `backup-schedule.sh`) |
| **Schedule** | **Twice daily:** `0 2` and `0 14` **IST** (user crontab on KVM8) |
| **Command flow** | `mysqldump` → gzip → **GPG AES-256 symmetric** → `manifest.json` + encrypted secrets bundle (`.env` + `storage/app/google/*.json`) |
| **Local staging** | `/var/backups/radium-desk/runs/<backup_id>/` |
| **Latest successful run** | `20260914T083001Z` — `created_at` **2026-09-14T08:30:01Z** (~14:00 IST); `phase` **cloud_uploaded** |
| **Encrypted DB size** | ~455 MB (`database.sql.gz.gpg`) |
| **Recent failure history** | **None found** in `storage/logs/backup-run.log` (no `backup-run.sh: ERROR` lines in tail grep) |
| **Warnings (non-fatal)** | Repeated `mysqldump` deprecation / `--databases` option warnings — backups still complete |
| **Cloud upload** | **Enabled** (`cloud_upload_enabled: true` in last status file; manifest `upload.status: completed`) |
| **Cloud destination** | Hostinger Shared Cloud — separate host from KVM8 (**VERIFIED** upload success in manifest; host identity redacted in this doc) |
| **Retention (local)** | 72 run directories under `runs/` in last 7 days (**VERIFIED** count); no automatic local prune documented in `backup-run.sh` |
| **Retention (cloud)** | GFS policy in `bin/backup-prune-cloud.sh` — **manual/dry-run default**; prune **not** scheduled (**VERIFIED** from runbook) |
| **Restore documentation** | `docs/backup-runbook.md` § “How to restore (manual)” — operator-driven; no automated restore CLI |
| **Restore drill** | **INFERRED** once proven on operator Mac per runbook; not re-run in this audit |

**Configuration drift (VERIFIED — requires owner decision):**

| Issue | Evidence |
|-------|----------|
| Cron uses `backup-run.sh` directly | Live crontab matches `backup-run.sh`, not runbook-recommended `backup-schedule.sh` with `BACKUP_SCHEDULE_SKIP_LOCK` |
| `last-run-status.json` stale | Last modified **2026-09-12**; shows `backup_id` `20260912T131418Z` while newer runs exist through `20260914T083001Z` |
| `cloud-inventory.json` missing | File absent on KVM8; Desk Cloud inventory table will be empty |
| `backup-schedule.sh` / `backup-cloud-inventory.sh` present but not wired into cron | Scripts exist on server; watchdog/Telegram path may not reflect current runs |

**Verdict for Desk DB pipeline:** **VERIFIED healthy execution** (schedule, encryption, local staging, cloud upload). **VERIFIED monitoring/inventory gap** (status file, cloud inventory, cron wrapper mismatch).

### 11.4 Hostinger Cloud storage (Desk DB path)

```
radium_desk (MariaDB on KVM8)
  → bin/backup-run.sh (cron 02:00 + 14:00 IST)
  → /var/backups/radium-desk/runs/<backup_id>/  (encrypted artifacts + manifest)
  → SSH/rsync → Hostinger Shared Cloud (separate account/host from KVM8)
  → YYYY/MM/DD/<backup_id>/ + upload-complete.json
```

| Check | Status |
|-------|--------|
| Storage independent from KVM8? | **VERIFIED** — upload targets separate Hostinger Shared Cloud host (not `187.127.129.16`) |
| Recent backup on Cloud? | **VERIFIED** — `20260914T083001Z` directory present on Cloud (Sep 14 2026) |
| Encryption before upload? | **VERIFIED** — only `.gpg` artifacts uploaded |
| Credentials in git/docs? | **VERIFIED absent** — `/root/.radium-backup.env` on KVM (not readable to audit user; keys not printed) |
| Logs expose secrets? | **VERIFIED** — log lines redact paths in `backup-schedule.sh` sanitizer; live log shows host/path in `backup-run.sh` upload lines (operational exposure — existing behaviour) |

### 11.5 Cross-project / multi-tenant scope (KVM8)

**VERIFIED databases on KVM8** (Sep 14 2026): `radium_desk`, `radiumbox_prod`, `rdservice_in_prod`, `rdserviceonline`, `rdservice_net`, `rdservice_net_prod`, `radiumsign_prod`, plus beta schemas.

| Project | App path | DB | Automated DB backup? | Other recovery artifacts |
|---------|----------|-----|----------------------|---------------------------|
| **Radium Desk** | `/var/www/radium-desk` | `radium_desk` | **YES** — twice daily encrypted + Cloud | — |
| **RadiumBox** | `/var/www/radiumbox.com` | `radiumbox_prod` | **NO** | Manual SQL dumps Sep 4–5 in `/var/backups/radiumbox-prod/`; named-file overlays |
| **RDServiceOnline** | `/var/www/rdserviceonline` | `rdserviceonline` | **NO** | **UNKNOWN** |
| **rdservice.in** | **UNKNOWN** path on KVM | `rdservice_in_prod` | **NO** | Prompt-specific dir `/var/backups/rdservice-in/p-07-09-287/` (Sep 12) |
| **rdservice.net** | **INFERRED** `/var/www/rdservice.net` | `rdservice_net` / `rdservice_net_prod` | **NO** | **UNKNOWN** |
| **radiumsign.com** | **UNKNOWN** | `radiumsign_prod` | **NO** | **UNKNOWN** |
| **media.radiumbox.com** | Hostinger shared (not KVM8) | **UNKNOWN** | **NO** | **UNKNOWN** |

**Blast-radius concerns:**

- Hostinger **VPS backup/snapshot** on KVM8 restores **entire server** — all projects and databases (**DOCUMENTED**).
- Desk **DB backup** covers **only** `radium_desk` — does **not** protect `radiumbox_prod` or other schemas (**VERIFIED**).
- RadiumBox relies on **ad-hoc** SQL dumps and deployment overlays, not the Desk backup pipeline (**VERIFIED**).

### 11.6 Restore readiness (read-only assessment)

| Scenario | Recovery source | Mechanism | Scope | Readiness | Limitations |
|----------|-----------------|-----------|-------|-----------|-------------|
| **A. App deploy failure** | Git + overlay files | Redeploy tag / restore `*.pre-*` backups | Single app | **VERIFIED** (pattern exists for Box) | Desk: no remote git rollback on KVM |
| **B. DB corruption (Desk)** | Encrypted Desk backup | Manual decrypt + import per runbook | `radium_desk` only | **INFERRED** — runbook + past drill cited | Staging rehearsal required per backup ID |
| **C. Accidental destructive DB change (Desk)** | Same as B | Point-in-time ≤ last backup (≤ ~12h) | `radium_desk` only | **INFERRED** | Data after backup timestamp lost |
| **C. Accidental destructive DB change (Box/other)** | Ad-hoc SQL dumps / VPS backup | Manual import or whole-VPS restore | Per DB or whole VPS | **UNKNOWN / weak** | No automated Box DB backup; dumps may be stale |
| **D. VPS/system config failure** | Hostinger VPS backup or snapshot | hPanel/API whole-VPS restore | **All KVM8 tenants** | **UNKNOWN** — backup state unverified | Coarse; owner approval mandatory |
| **E. Complete KVM8 failure** | Hostinger VPS backup + Desk Cloud DB copies | Provider restore + DB import | Whole platform | **PARTIAL** — Desk DB on Cloud **VERIFIED**; VPS backup **UNKNOWN** | Not geographic DR (same provider) |

**No restore was performed in this audit.**

### 11.7 Recovery layer comparison (for future snapshot gate)

| Layer | Protects | Does not protect | Retention | Recovery scope | Speed | Blast radius |
|-------|----------|------------------|-----------|----------------|-------|--------------|
| **Git** | Application source | DB data, server config, secrets not in repo | Unlimited (history) | Code/config in repo | Fast redeploy | Single app |
| **Deployment overlay** | Named files pre-change | DB, system config, untracked files | Until manually removed | Single app paths | Minutes | Single app |
| **Desk DB backup** | `radium_desk` data + selected secrets | Other DBs, code, OLS, OS | Local: many runs; Cloud: GFS if pruned | Single schema | Hours (manual) | Desk only |
| **Hostinger VPS daily backup** | Whole VPS disk (if enabled) | Nothing off-VPS; not granular | Up to 4 points (**DOCUMENTED**) | **Entire KVM8** | Hours | **All co-hosted projects** |
| **Pre-change snapshot (PROPOSED)** | Whole VPS at instant before change | Off-VPS; not granular; **1 slot, 24h** | 1 day | **Entire KVM8** | Hours | **All co-hosted projects** |

**Intended future model for MAJOR changes (PROPOSAL — NOT IMPLEMENTED):**

```
Git + deployment overlay + DB backup (project-appropriate)
+ Hostinger VPS backup health check (when verifiable)
+ NEW pre-change VPS snapshot
```

### 11.8 Future snapshot gate — required checks (PROPOSAL)

Before any automated pre-major-change snapshot, verify:

| # | Check | Source |
|---|-------|--------|
| 1 | Correct **project** and repo/HEAD SHA | Git + prompt ledger |
| 2 | Correct **production VPS** (`virtualMachineId`, IP, hostname) | Secure registry + API |
| 3 | Correct **environment** (production vs staging) | Deploy mapping |
| 4 | **Deployment overlay** backup completed for touched files | Deploy prompt / server paths |
| 5 | **DB backup health** for affected database(s) | Recent manifest + `phase: cloud_uploaded` |
| 6 | **Hostinger VPS backup health** (if major change) | API `GET .../backups` or hPanel — **currently UNKNOWN** |
| 7 | **Snapshot capability** and API permission | Hostinger API auth test (read-only) |
| 8 | **Snapshot slot** — existing snapshot noted; overwrite acknowledged | `GET .../snapshot` |
| 9 | **Snapshot action completion** | Poll `GET .../actions/{id}` |
| 10 | **Snapshot metadata** recorded (`id`, `createdAt`, `expiresAt`) | Gate output artifact |
| 11 | **Cross-project blast radius** acknowledged | All KVM8 co-tenants listed; owner sign-off for whole-VPS ops |

**Restore policy (PROPOSAL):** Snapshot restore remains **owner-approved only** — never automatic.

**Recommended risk threshold for snapshot:** §7 **CRITICAL** tier — system-level, multi-app, or irreversible infra changes only.

### 11.9 Audit verdict

| Component | Verdict |
|-----------|---------|
| Desk `radium_desk` backup execution | **VERIFIED healthy** (twice daily, encrypted, Cloud upload succeeding) |
| Desk backup monitoring / inventory | **VERIFIED gap** — cron/wrapper drift, stale status, missing cloud inventory |
| Other production databases on KVM8 | **VERIFIED not covered** by automated backup |
| Hostinger VPS automated backup | **UNKNOWN** |
| Pre-snapshot gate design | **READY TO DESIGN** at policy level, **blocked on VPS backup verification** and multi-DB policy |

**STOP — BACKUP CONFIGURATION REQUIRES OWNER/IMPLEMENTATION DECISION** for: (1) cron vs `backup-schedule.sh` / watchdog alignment, (2) `cloud-inventory.json` ops schedule, (3) automated backup scope for non-Desk databases on KVM8, (4) confirmation of Hostinger VPS daily backup on KVM8 via hPanel/API.

---

## 12. Source links

| Resource | URL |
|----------|-----|
| Hostinger Connector overview | https://docs.hostinger.com/hostinger-connector/overview |
| Hostinger API reference | https://developers.hostinger.com/ |
| API documentation index (llms.txt) | https://docs.hostinger.com/llms.txt |
| VPS backup & snapshot (hPanel guide) | https://www.hostinger.com/support/1583232-how-to-back-up-or-restore-a-vps-at-hostinger/ |
| Official Python SDK (endpoint paths) | https://github.com/hostinger/api-python-sdk |
| Radium Desk backup runbook | [`docs/backup-runbook.md`](../backup-runbook.md) |
| Radium Desk KVM deploy toolkit | [`tools/README.md`](../../tools/README.md) |
| Hub/Spoke recovery architecture | [`docs/architecture/hub-spoke-payment-order-recovery.md`](./hub-spoke-payment-order-recovery.md) |

---

## 13. Audit metadata

| Field | Value |
|-------|-------|
| Audit dates | 2026-09-14 |
| Auditor | Cursor Cloud Agent (read-only) |
| Repository | `radium-foundation/radium-desk` |
| Branch | `cursor/hostinger-production-safety-audit-786d` |
| Prompt IDs | `RadiumDesk-P-07-09-164`, `RadiumDesk-P-07-09-165` |
| Production changes | **None** |
| Snapshot/backup/restore executed | **None** |
| Hostinger VPS backup API/hPanel verification | **None** (state **UNKNOWN**) |
| KVM8 read-only SSH verification | **Yes** — cron, manifests, logs, `/var/backups/*` (Desk pipeline) |
