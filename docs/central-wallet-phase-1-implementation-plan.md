# Central Wallet — Phase 1 Implementation Plan (Foundation Only)

**Document type:** Implementation plan (design/planning — **not an automatic build authorization**)  
**Prompt ID:** `RadiumDesk-P-25-09-86`  
**Date:** 2026-09-28  
**Status:** **PLANNING — FOUNDATION SCOPE ONLY**  
**Platform repository:** `radium-desk` (Desk hub)  
**First pilot spoke:** `rdservice.in` (integration begins **after** Phase 1; not in Phase 1 scope)  
**Governance:** R12 (implementation spec) + R13 (pilot charter) **OWNER APPROVED** 2026-09-28  

**Upstream (approved):**

| Document | Role |
|----------|------|
| `docs/central-wallet-implementation-spec.md` | Canonical specification (R12) |
| `rdservice.in/docs/central-wallet-pilot-charter.md` | Pilot charter (R13) |
| `rdservice.in/docs/central-wallet-regression-safety.md` | Protected production baseline |
| Discovery P-25-09-81/82/83 | **VERIFIED** production facts |

---

## Legend

| Label | Meaning |
|-------|---------|
| **VERIFIED** | Established in discovery, code inspection, or production read-only evidence |
| **INFERRED** | Proposed design; requires validation during build |
| **UNKNOWN** | Not decided or not evidenced |
| **BLOCKED** | Cannot proceed until external prerequisite satisfied |
| **OWNER APPROVED** | Recorded Owner decision (unchanged by this plan) |

**Phase 1 explicitly excludes:** RadiumBox wallet checkout, RadiumBox debit/reservation production flow, RadiumBox historical balance migration, RadiumBox reversal deployment, Central Wallet production cutover, Radium Money, mobile wallet functionality, spoke production writes, balance migration, automatic cutover.

---

## 1. Objectives and success criteria

### 1.1 Objectives

Build an **isolated Central Wallet foundation** inside the Desk hub (`radium-desk`) that:

1. Owns a dedicated data store (no cross-project DB coupling).
2. Issues and stores **UUID v4** Central Wallet IDs (CWID).
3. Provides append-only **ledger**, **account-link registry**, **idempotency store** (90-day retention), and **audit/event** tables.
4. Exposes **API-primary** internal/service contracts (versioned, idempotent) without production spoke integration.
5. Scaffolds **reconciliation job architecture** (no production rdin/shadow runs in Phase 1).
6. Is structured so **reserve/commit** APIs for RadiumBox can be added in a later phase **without** premature RadiumBox coupling.

### 1.2 Phase 1 success criteria

| # | Criterion | Classification |
|---|-----------|----------------|
| 1 | Module loads in Desk app with feature flag **OFF** by default | **INFERRED** |
| 2 | Migrations create central-wallet tables in **Desk DB only** (or dedicated schema) | **INFERRED** |
| 3 | CWID issuance, link registry CRUD (service-level), ledger append, idempotency dedup demonstrated in **non-production** tests | **INFERRED** |
| 4 | No `rdservice.in` or `radiumbox.com` production API calls or DB writes | **VERIFIED** requirement |
| 5 | Existing Desk wallet refund orchestration to spokes **unchanged** when flag OFF | **VERIFIED** requirement (regression) |
| 6 | Reconciliation worker skeleton enqueueable in staging with rate limits; no financial side effects | **INFERRED** |
| 7 | Observability: structured logs without secrets; correlation IDs on all service operations | **INFERRED** |

### 1.3 Out of scope (deferred)

| Item | Deferred to | Status |
|------|-------------|--------|
| rdin shadow adapter (production read) | Phase 4 | Per implementation spec §S |
| rdin parallel-run / customer-visible balance | Phase 6+ | **OWNER APPROVED** first visible = refund + balance display |
| RadiumBox `POST /wallet-reservations` | Phase 8+ | **OWNER APPROVED** R-CW-01–08; **BLOCKED** on R-CW-05 reversal |
| Balance migration | Separate Owner decision | **OWNER APPROVED** no auto migration |
| Production cutover | Owner written authority | **OWNER APPROVED** R5 |

---

## 2. Repository / module structure

### 2.1 Placement (**OWNER APPROVED** R7)

Central Wallet lives **inside** `radium-desk`, not a separate repository.

**INFERRED** module layout:

```
radium-desk/
  app/
    CentralWallet/
      Domain/           # Entities, value objects (Money, Cwid, SiteCode)
      Application/      # Use cases: CreateWallet, AppendLedgerEntry, RegisterIdempotency
      Infrastructure/   # Eloquent repos, queue jobs, HTTP controllers
      Contracts/        # DTOs, API request/response shapes (versioned)
  config/
    central_wallet.php  # Feature flags, retention, rate limits
  database/
    migrations/         # central_wallet_* tables only
  routes/
    central_wallet.php  # Internal/service routes (prefix TBD)
  tests/
    Unit/CentralWallet/
    Feature/CentralWallet/
```

### 2.2 Boundaries

| Boundary | Rule | Classification |
|----------|------|----------------|
| Desk commerce/refund modules | May **call** Central Wallet application services when explicitly wired (Phase 2+) | **INFERRED** |
| Spoke databases | **No** cross-DB queries or foreign keys | **VERIFIED** requirement |
| RadiumBox checkout code | **No** imports from `app/CentralWallet` in Phase 1 | **INFERRED** |
| Existing `users_wallet` on spokes | Untouched | **VERIFIED** |

### 2.3 Feature flags (**INFERRED**)

| Flag | Default (Phase 1) | Purpose |
|------|-------------------|---------|
| `CENTRAL_WALLET_ENABLED` | `false` | Master switch — module dormant |
| `CENTRAL_WALLET_API_ENABLED` | `false` | HTTP routes registered but 503/disabled |
| `CENTRAL_WALLET_RECONCILIATION_ENABLED` | `false` | Reconciliation jobs no-op |

RadiumBox checkout flag (`R-CW-06`) is **not** created in Phase 1.

---

## 3. Central Wallet service boundary

### 3.1 Service definition

**INFERRED:** Central Wallet is a **domain module** within Desk with:

- **Authoritative** central ledger for linked CWIDs (once cutover — not Phase 1 production effect).
- **Link registry** mapping `(site_code, local_user_id) ↔ cwid`.
- **Idempotency** layer for all mutating APIs.
- **Audit/event** stream for compliance and reconciliation.

### 3.2 What Phase 1 service does

| Capability | Phase 1 |
|------------|---------|
| Create CWID | Yes (internal/service API) |
| Register account link (pending/verified states) | Yes (data model + service; customer UI Phase 2) |
| Append ledger credit/debit (internal) | Yes (test/staging only) |
| Idempotency enforcement | Yes |
| Emit audit events | Yes |
| Balance read API | Yes (internal) |
| Reserve/commit/release | **Schema hooks only** — no business logic (RadiumBox Phase 8) |

### 3.3 What Phase 1 service does **not** do

- Route Desk production refunds to central ledger.
- Read or write spoke `users_wallet`.
- Expose public customer-facing endpoints.
- Perform cutover or balance promotion.

---

## 4. Environment separation

| Environment | Phase 1 use | Classification |
|-------------|-------------|----------------|
| **Local dev** | Migrations, unit/feature tests, optional SQLite/MySQL | **VERIFIED** existing Desk pattern |
| **CI** | Migrate + test suite; no secrets | **INFERRED** |
| **Staging** | Optional isolated deploy with flag OFF; synthetic ledger tests | **UNKNOWN** — Desk staging URL/host not verified in discovery |
| **Production** | **No Phase 1 deploy** without explicit Owner gate | **INFERRED** policy |

**VERIFIED:** Production Desk path `/var/www/radium-desk` on KVM8 (`P-25-09-82`).

**UNKNOWN:** Dedicated staging Central Wallet API hostname.

**INFERRED rules:**

- Central Wallet tables live in Desk application database (separate table prefix `central_wallet_*`).
- No shared credentials with `rdservice_in_prod` or `radiumbox_prod`.
- Environment config via `.env` only; never commit secrets.

---

## 5. Authentication / service identity model

### 5.1 Caller types

| Caller | Phase 1 | Classification |
|--------|---------|----------------|
| Desk internal jobs (reconciliation scaffold) | Service identity | **INFERRED** |
| Future spoke adapters (rdin, box) | Per-site bearer token | **INFERRED** — pattern **VERIFIED** on existing `desk.integration` / spoke `wallet-refunds` |
| Customer browser | **Not** in Phase 1 | **OWNER APPROVED** UX deferred |

### 5.2 **INFERRED** authentication design

Extend existing Desk integration auth patterns:

| Header / mechanism | Purpose |
|--------------------|---------|
| `Authorization: Bearer <token>` | Service-to-service |
| `X-Idempotency-Key` | Mutations (also stored in body per API) |
| `X-Correlation-Id` | Tracing (optional client; server generates if absent) |
| `X-Site-Code` | Spoke identifier (`rdservice.in`, `radiumbox.com`) — future |

**Per-site tokens:** stored in secrets manager / `.env`; rotated independently.

**UNKNOWN:** Token issuance UI, rotation SLA, vault product.

**Security rule (**VERIFIED** policy):** Fail-closed — empty/invalid token → 401; no anonymous mutating endpoints.

---

## 6. UUID v4 CWID foundation

| Attribute | Specification | Classification |
|-----------|---------------|----------------|
| Format | UUID v4 (RFC 4122) | **OWNER APPROVED** R17 |
| Generation | Server-side only (`Str::uuid()` or equivalent) | **INFERRED** |
| Storage | `CHAR(36)` or `UUID` column; indexed unique | **INFERRED** |
| Exposure | Opaque string to clients; no embedded PII | **OWNER APPROVED** |
| Issuance rule | Created when first **verified** link established OR explicit pre-provision for testing | **INFERRED** — align Phase 2 linking |

**Tests:** UUID format validation; collision handling (DB unique constraint).

---

## 7. Account-link data model

### 7.1 Table: `central_wallet_account_links` (**INFERRED**)

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `central_wallet_id` | UUID FK | → `central_wallets.id` |
| `site_code` | string(64) | e.g. `rdservice.in` |
| `local_user_id` | string(64) | Spoke user PK as string |
| `status` | enum | `pending_verification`, `active`, `revoked`, `suspended` |
| `verification_method` | string nullable | `m1_dual_session`, `m2_dual_otp`, `m3_ops`, `m4_expedited` — **UNKNOWN** which will be used |
| `linked_at` | timestamp nullable | Set on `active` |
| `revoked_at` | timestamp nullable | |
| `created_by` | string | `customer`, `system`, `operator:{id}` |
| `metadata` | json nullable | Non-PII audit refs |

**Constraints:**

- Unique `(site_code, local_user_id)` where `status = active` (**INFERRED**).
- Unique `(central_wallet_id, site_code)` where `status = active` (**INFERRED**).
- **No auto-link from email** — enforced in application layer (**OWNER APPROVED** R1).

### 7.2 Table: `central_wallets` (**INFERRED**)

| Column | Notes |
|--------|-------|
| `id` | UUID PK |
| `status` | `active`, `suspended`, `closed` |
| `created_at` | |

### 7.3 Expedited path (11,212 candidates)

Data model supports `verification_method = m4_expedited` with explicit confirmation audit event (**OWNER APPROVED** R2). Implementation of M4 ceremony is **Phase 2**, not Phase 1.

---

## 8. Wallet ledger foundation

### 8.1 Design principles

| Principle | Classification |
|-----------|----------------|
| Append-only ledger entries | **INFERRED** (industry standard; matches spoke `users_wallet` pattern **VERIFIED**) |
| No UPDATE of amount columns on posted entries | **INFERRED** |
| Reversals = compensating entries, not deletes | **VERIFIED** on rdin reversal API |
| Currency INR, amounts in paise (integer) or decimal(12,2) | **INFERRED** — match Desk money handling |

### 8.2 Table: `central_wallet_ledger_entries` (**INFERRED**)

| Column | Notes |
|--------|-------|
| `id` | bigint PK |
| `central_wallet_id` | UUID FK |
| `entry_type` | `credit`, `debit`, `reversal`, `adjustment` |
| `amount` | Positive decimal |
| `currency` | `INR` |
| `status` | `posted`, `pending`, `voided` — reserve/commit adds `reserved` in Phase 8 |
| `source_system` | e.g. `radium_desk`, `reconciliation` |
| `source_reference` | e.g. `desk_refund_reference` (future) |
| `correlation_id` | UUID |
| `metadata` | json — order refs, spoke ids |
| `posted_at` | timestamp |

**Phase 1:** Implement `posted` credits/debits only. Add nullable `reservation_id` column for future RadiumBox reserve/commit (**INFERRED** forward-compat) — **no** reservation logic.

### 8.3 Balance derivation

**INFERRED:** `available_balance = sum(posted credits) - sum(posted debits) - sum(active reservations)` — reservations table stub in Phase 1 optional.

---

## 9. Idempotency storage (90-day retention)

### 9.1 Table: `central_wallet_idempotency_records` (**INFERRED**)

| Column | Notes |
|--------|-------|
| `id` | bigint PK |
| `caller_id` | e.g. `radium_desk`, `rdservice.in` |
| `idempotency_key` | string(128) |
| `request_hash` | sha256 of canonical request body |
| `response_status` | http code |
| `response_body_hash` | or snapshot ref |
| `resource_type` | `ledger_entry`, `link`, … |
| `resource_id` | nullable until created |
| `created_at` | |
| `expires_at` | `created_at + 90 days` |

**OWNER APPROVED** R16: **90-day** retention.

**INFERRED purge job:** Daily delete where `expires_at < now()`; audit count purged.

**VERIFIED precedent:** rdin `(source_system, desk_refund_reference)` uniqueness for wallet refunds.

---

## 10. Audit / event model

### 10.1 Table: `central_wallet_audit_events` (**INFERRED**)

| Column | Notes |
|--------|-------|
| `id` | bigint PK |
| `event_type` | `link.suggested`, `link.confirmed`, `ledger.appended`, `idempotency.replay`, … |
| `central_wallet_id` | nullable UUID |
| `actor_type` | `system`, `service`, `operator`, `customer` |
| `actor_id` | opaque ref |
| `correlation_id` | UUID |
| `payload` | json — **no secrets, no raw tokens** |
| `occurred_at` | timestamp |

### 10.2 Event emission rules

- Every mutating use case writes audit event in same DB transaction as domain change (**INFERRED**).
- Logs use structured JSON; redact bearer tokens (**INFERRED** policy).

**UNKNOWN:** Long-term audit retention / archival policy beyond idempotency 90 days.

---

## 11. API-primary contract foundation

### 11.1 Versioning (**INFERRED**)

- Prefix: `/api/central-wallet/v1/` (exact host **UNKNOWN**).
- OpenAPI spec generated or hand-maintained in `docs/central-wallet-api-v1.yaml` (Phase 1 deliverable).

### 11.2 Phase 1 endpoints (internal/service only)

| Method | Path | Purpose | Phase 1 |
|--------|------|---------|---------|
| `GET` | `/health` | Liveness | Yes |
| `POST` | `/wallets` | Provision CWID (admin/service) | Yes |
| `GET` | `/wallets/{cwid}` | Metadata | Yes |
| `GET` | `/wallets/{cwid}/balance` | Available balance | Yes |
| `POST` | `/wallets/{cwid}/ledger-entries` | Append credit/debit (service) | Yes |
| `POST` | `/account-links` | Create pending link | Yes (service) |
| `GET` | `/account-links` | Query by site+user | Yes |
| `POST` | `/wallet-reservations` | Reserve funds | **Stub 501** or schema only |
| `POST` | `/wallet-reservations/{id}/commit` | Commit | **Not implemented** |
| `POST` | `/wallet-reservations/{id}/release` | Release | **Not implemented** |

**OWNER APPROVED** R8: API-primary. Event bus may be added later as secondary notification (**INFERRED**).

### 11.3 Standard request fields (mutations)

| Field | Required |
|-------|----------|
| `idempotency_key` | Yes |
| `correlation_id` | Recommended |
| `amount` / `currency` | Ledger mutations |
| `site_code` / `local_user_id` | Link mutations |

**RadiumBox `order_reference` format (`RADBOX:<BusinessOrderId>`)** documented in OpenAPI components for future use; **not** accepted in Phase 1 production paths.

---

## 12. Observability / logging

| Requirement | Implementation | Classification |
|-------------|----------------|----------------|
| Correlation ID on all requests | Middleware | **INFERRED** |
| Structured logs (JSON) | Laravel logging channel `central_wallet` | **INFERRED** |
| No secrets in logs | Redact `Authorization`, tokens, OTP | **INFERRED** policy |
| Metrics | Request count, latency, idempotency replay count | **INFERRED** — tooling **UNKNOWN** |
| Financial audit | `central_wallet_audit_events` + ledger immutability | **INFERRED** |

**UNKNOWN:** APM vendor, log retention period, alert routing.

---

## 13. Reconciliation job architecture

### 13.1 Purpose in Phase 1

Scaffold only — implements **job structure**, **rate limiting**, and **run registry** without comparing production spoke ledgers.

**OWNER APPROVED** R3: per-event + bounded daily; async/queued; batched; rate-limited; prefer off-peak.

### 13.2 Tables (**INFERRED**)

**`central_wallet_reconciliation_runs`**

| Column | Notes |
|--------|-------|
| `id` | UUID |
| `scope` | `daily_full`, `per_event`, `manual` |
| `status` | `queued`, `running`, `completed`, `failed` |
| `started_at`, `completed_at` | |
| `summary` | json — counts, variance totals |

**`central_wallet_reconciliation_items`**

| Column | Notes |
|--------|-------|
| `run_id` | FK |
| `item_type` | `amount_mismatch`, `missing_shadow`, … |
| `severity` | P1–P3 |
| `details` | json |

### 13.3 Jobs (**INFERRED**)

| Job | Schedule | Phase 1 behaviour |
|-----|----------|-------------------|
| `ReconciliationDailyJob` | Off-peak cron | No-op or self-test against synthetic data |
| `ReconciliationPerEventJob` | Queue on domain events | Enqueue hook only |
| `IdempotencyPurgeJob` | Daily | Purge expired idempotency rows |
| `ReservationExpiryJob` | Every minute | **Not registered** in Phase 1 (R-CW-07 Phase 8) |

**OWNER APPROVED** R4: ₹0 unexplained tolerance — enforced in reconciliation engine logic (Phase 5); constants defined in Phase 1 config.

---

## 14. Security boundaries

| Boundary | Control | Classification |
|----------|---------|----------------|
| Network | Service APIs internal/VPN or authenticated only | **UNKNOWN** production topology |
| Auth | Bearer per caller; fail-closed | **INFERRED** |
| Data | CWID has no PII; link table stores spoke user id only | **OWNER APPROVED** |
| Email | Never used as sole link proof | **OWNER APPROVED** R1 |
| Cross-DB | Forbidden | **VERIFIED** |
| Privilege | Admin mutations require Desk operator auth + audit | **INFERRED** |
| Rate limiting | Per-caller token bucket on mutations | **INFERRED** |

**UNKNOWN:** WAF rules, IP allowlists, penetration test schedule.

---

## 15. Test strategy

### 15.1 Test layers

| Layer | Scope | Classification |
|-------|-------|----------------|
| Unit | Money, UUID, idempotency key normalization, state machines | **INFERRED** |
| Feature | HTTP APIs with flag ON in `phpunit.xml` | **INFERRED** |
| Integration | Transaction boundaries: ledger + audit + idempotency atomicity | **INFERRED** |
| Regression | Existing Desk refund tests + rdin wallet baseline (**51 tests**) unchanged with flag OFF | **VERIFIED** requirement |

### 15.2 Required test cases (Phase 1)

1. Idempotency replay returns same response without duplicate ledger row.
2. Duplicate `(site_code, local_user_id)` active link rejected.
3. CWID UUID v4 format enforced.
4. Append-only ledger — no update endpoint.
5. Invalid bearer → 401.
6. Flag OFF → routes 404 or 503; Desk refund path unchanged.
7. Idempotency purge removes only expired rows.
8. Correlation ID propagated to audit events.

### 15.3 Not tested in Phase 1

- Production spoke integration.
- RadiumBox reserve/commit races (R-CW-07).
- OTP/email verification flows (**UNKNOWN** provider).

---

## 16. Migration / rollback boundaries

### 16.1 Migrations (Phase 1)

| Rule | Classification |
|------|----------------|
| Expand-only new tables | **INFERRED** |
| No alterations to spoke schemas | **VERIFIED** |
| No data backfill from `users_wallet` | **OWNER APPROVED** R6 |
| Reversible `down()` for dev; production rollback = forward fix | **INFERRED** |

### 16.2 Rollback plan

| Scenario | Action | Customer impact |
|----------|--------|-----------------|
| Phase 1 deploy issue | Set `CENTRAL_WALLET_ENABLED=false`; redeploy prior Desk tag | **None** — no spoke integration |
| Migration failure | Do not deploy; restore DB snapshot in staging | **None** in prod if gate followed |
| Accidental ledger write in staging | Truncate staging central tables | **None** in prod |

**OWNER APPROVED:** No automatic cutover; local wallet remains authoritative during pilot/shadow.

---

## 17. Forward compatibility — RadiumBox (no Phase 1 coupling)

| Future need | Phase 1 preparation | Classification |
|-------------|---------------------|----------------|
| R-CW-03 `RADBOX:<BusinessOrderId>` | Document in OpenAPI; optional `order_reference` column on future `reservations` table | **INFERRED** |
| R-CW-01 15m TTL | Config constant `reservation_ttl_seconds = 900`; table not active | **OWNER APPROVED** |
| R-CW-02 reserve→Cashfree→commit | Stub route returns 501; no Cashfree integration | **OWNER APPROVED** |
| R-CW-05 reversal gate | Document **BLOCKED** dependency | **VERIFIED** reversal API absent on box |
| R-CW-08 no balance migration | No import jobs | **OWNER APPROVED** |

**BLOCKED for RadiumBox checkout:** Reversal API must exist and be verified on `radiumbox.com` before any central wallet checkout enablement.

**Risk:** 292 pending RadiumBox wallet debits — provenance analysis required before any future migration decision (**VERIFIED** count; analysis **UNKNOWN**).

---

## 18. Implementation sequence (suggested)

| Step | Deliverable | Owner gate |
|------|-------------|------------|
| 1 | Config + feature flags + empty module registration | — |
| 2 | Migrations: wallets, links, ledger, idempotency, audit, reconciliation runs | **Before prod migrate** |
| 3 | Domain services + repositories | — |
| 4 | Internal HTTP controllers + OpenAPI draft | — |
| 5 | Idempotency middleware | — |
| 6 | Reconciliation job skeleton + scheduler entries (disabled) | — |
| 7 | Test suite (§15) | — |
| 8 | Documentation: runbook, env vars (no secrets) | — |

**Estimated phases 2–3 weeks engineering** — **INFERRED**, not committed.

---

## 19. Classification summary

### VERIFIED

- Central Wallet platform under Desk hub (**OWNER APPROVED** R7).
- API-primary pattern; UUID v4 CWID; 90-day idempotency; no email auto-link.
- rdin `users_wallet` authoritative during pilot; Desk wallet refund APIs deployed on rdin.
- RadiumBox reversal API **absent** — checkout **BLOCKED**.
- 292 pending RadiumBox wallet debits exist.
- 51 rdin wallet regression tests baseline.
- No cross-project DB coupling requirement.
- Existing integration auth pattern (bearer, 401 on failure).

### INFERRED

- Module layout, table schemas, API paths, job schedules, feature flag names.
- Paise vs decimal money storage (follow Desk conventions).
- Staging environment availability.
- Phase 1 duration and team capacity.

### UNKNOWN

- Production Central Wallet API hostname / public URL.
- OTP/email verification provider (R1 mechanism).
- Named cutover signatory (R5 partial).
- APM/metrics stack; audit long-term retention.
- Desk staging parity for Central Wallet UAT.
- Refund / Connect Wallet customer copy.
- RadiumBox pending debit cleanup job behaviour.
- Legal/compliance review for stored-value regulations.

### BLOCKED

| Item | Blocker |
|------|---------|
| RadiumBox central wallet checkout | R-CW-05 — reversal API not deployed |
| RadiumBox balance migration | Separate Owner decision + provenance (R-CW-08); 292 pending debits |
| Production spoke shadow (rdin) | Phase 4 prerequisite; Phase 1 foundation first |
| Customer linking ceremony | OTP provider **UNKNOWN** |
| Production cutover | Owner written authority; 30-day shadow minimum not started |

---

## 20. Phase 1 build authorization gate

| Gate | Status |
|------|--------|
| R13 Pilot Charter | **OWNER APPROVED** 2026-09-28 |
| R12 Implementation Specification | **OWNER APPROVED** 2026-09-28 |
| Phase 0 complete | **YES** |
| Phase 1 plan published | **YES** (this document) |
| **Owner authorization to execute Phase 1 code/migrations** | **REQUIRED** — explicit prompt or signed instruction referencing this plan |

Until the final row is satisfied, **no application implementation, migrations, or production deploy** should begin.

---

## Document control

| Field | Value |
|-------|-------|
| Version | 1.0 |
| Prompt ID | `RadiumDesk-P-25-09-86` |
| Companion prompt | `P27-09-07` |
| Canonical spec | `docs/central-wallet-implementation-spec.md` v0.4 |

---

*Planning only. Phase 1 foundation build requires explicit Owner authorization per §20.*
