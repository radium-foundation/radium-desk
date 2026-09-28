# Central Wallet ceremony complete API — Desk design

**Prompt ID:** `RadiumDesk-P-25-09-105`  
**Date:** 2026-09-28  
**Mode:** Design / architecture only — no implementation  
**Repository:** `/Users/ravi/RadiumWebsites/radium-desk`  
**Branch:** `release/central-wallet-m2-v4.0.153`  
**HEAD (design time):** `d77fe92c55817e3156963105e7fd0283f36b8672`  
**Companion spoke design:** `radiumbox.com-P-28-09-47` (`docs/central-wallet-automatic-cwid-design-p-28-09-47.md`)  
**Builds on:** `docs/central-wallet-implementation-spec.md`, Phase 1 foundation (`app/CentralWallet/`)

---

## Executive summary

**VERIFIED:** Desk today exposes `POST /wallets`, `POST /account-links`, `POST /account-links/{id}/confirm`, and `GET /account-links`. These require a pre-known `central_wallet_id` and a two-step link ceremony. There is **no** ceremony identity registry, **no** HTTP revoke route (service method exists), and **no** DB partial-unique constraints on active links (application `lockForUpdate` only).

**INFERRED (recommended):** Add **one** atomic customer-ceremony endpoint:

`POST /api/central-wallet/v1/ceremony/complete`

Minimal input: `site_code`, `local_user_id`, `ceremony_verification_ref`, `idempotency_key` (+ optional `verification_method`, `correlation_id` via header).

Desk validates a **Box-issued, Desk-verifiable signed ceremony proof** (not raw OTP), then in **one DB transaction**: resolve-or-create CWID via ceremony identity registry → create+confirm active account link → audit → return safe customer-facing payload.

Existing M2 APIs remain unchanged for operator paths and backward compatibility.

---

## 1. Recommended endpoint

| Item | Value | Classification |
|------|-------|----------------|
| Method / path | `POST /api/central-wallet/v1/ceremony/complete` | **INFERRED** |
| Purpose | Atomic: validate ceremony proof → resolve/create CWID → activate account link | **INFERRED** |
| Why not `wallet-provision` + separate link calls | Owner requires fully automatic flow; staged calls reintroduce orphan-CWID / partial-link states (P-28-09-47 §7) | **INFERRED** |
| Why this name | Matches “complete the connect ceremony”; distinct from bare `/wallets` and staged `/account-links` | **INFERRED** |

**VERIFIED:** Route prefix and auth pattern match existing `routes/central_wallet.php` + `AuthenticateCentralWalletIntegration`.

---

## 2. Request / response

### 2.1 Request

**Headers (VERIFIED pattern):**

| Header | Required | Purpose |
|--------|----------|---------|
| `Authorization: Bearer <integration_token>` | yes | Server-to-server auth |
| `X-Site-Code: <site_code>` | yes | Caller identity; must match body `site_code` |
| `X-Correlation-Id: <uuid>` | optional | Propagated to audit (VERIFIED middleware exists) |

**Body (JSON):**

```json
{
  "idempotency_key": "box-ceremony-complete:{attempt_uuid}",
  "site_code": "radiumbox.com",
  "local_user_id": "3",
  "ceremony_verification_ref": "<opaque-signed-token>",
  "verification_method": "m2_whatsapp_otp"
}
```

| Field | Required | Notes | Classification |
|-------|----------|-------|----------------|
| `idempotency_key` | yes | Caller-scoped; max 128 chars (VERIFIED existing limit) | **VERIFIED** |
| `site_code` | yes | Must equal `X-Site-Code` | **INFERRED** |
| `local_user_id` | yes | Site-local user id string | **VERIFIED** (existing link API) |
| `ceremony_verification_ref` | yes | Opaque signed proof; see §3 | **INFERRED** |
| `verification_method` | optional | Default `m2_whatsapp_otp`; must match token claim if present | **INFERRED** |

**Explicitly forbidden in request (VERIFIED charter + this design):**

- `central_wallet_id` / customer UUID
- raw OTP
- `email`, `phone`, `mobile` as lookup keys
- customer password

### 2.2 Success response (200 / 201)

```json
{
  "status": "connected",
  "link_id": 42,
  "customer_display_ref": "CW-••••-7829",
  "provision_action": "created|resolved_existing|resolved_local_link",
  "linked_at": "2026-09-28T18:00:00+00:00",
  "idempotent_replay": false
}
```

| Field | Customer-facing? | Notes |
|-------|------------------|-------|
| `status` | yes | Always `connected` on success |
| `customer_display_ref` | yes | Masked ref; not raw UUID |
| `link_id` | internal to Box cache | Box persists; not shown in UI |
| `central_wallet_id` | **omit from default response** | Box may need internally — return only if `include_internal_ids=true` query flag for server use (**OWNER DECISION REQUIRED**) |

**INFERRED:** Default response omits `central_wallet_id` from customer-visible API surface; Box stores it from Desk response body in server-side cache only.

### 2.3 Error responses

| HTTP | `error` | Retryable | Customer UX (Box maps) |
|------|---------|-----------|------------------------|
| 401 | `unauthorized` | no | Temporary failure |
| 403 | `site_mismatch` | no | Temporary failure |
| 409 | `idempotency_key_reused_with_different_request` | no | Temporary failure |
| 409 | `idempotency_request_in_progress` | yes (backoff) | Temporary failure |
| 409 | `ceremony_identity_linked_elsewhere` | no | Conflict |
| 409 | `cwid_linked_elsewhere` | no | Conflict |
| 422 | `invalid_ceremony_proof` | no | Temporary failure (expired/invalid) |
| 422 | `ceremony_proof_replayed` | no | Temporary failure |
| 422 | `validation_failed` | no | Temporary failure |
| 503 | `desk_unavailable` | yes | Temporary failure |

**INFERRED:** Never expose Desk DB / internal exception text to customer.

---

## 3. Ceremony proof mechanism

### 3.1 Architecture (preferred — **INFERRED**)

```
RadiumBox                          RadiumDesk
─────────                          ──────────
Customer OTP verify (Interakt)
  → Box issues signed ref
  → POST /ceremony/complete
       ceremony_verification_ref ──► Verify signature + claims
                                     Consume jti (single-use)
                                     Atomic provision + link
```

- **Who creates the reference:** **RadiumBox**, immediately after successful OTP verification.
- **Who trusts it:** **RadiumDesk**, via cryptographic validation + one-time consumption registry.
- **Raw OTP never sent to Desk:** **VERIFIED** requirement; OTP stays on Box (hash in `central_wallet_account_link_otp_challenges`).

### 3.2 Token format (minimum — **INFERRED**)

Signed assertion (JWT or compact HMAC JSON). Desk validates with **per-site shared secret** (`CENTRAL_WALLET_CEREMONY_SIGNING_SECRET_{SITE}` or HKDF from integration token + site_code).

**Minimum claims:**

| Claim | Requirement |
|-------|-------------|
| `jti` | Unique token id (UUID); Desk single-use consumption |
| `aud` | Fixed: `radium-desk:ceremony-complete` |
| `iss` | `radiumbox.com` (or site_code) |
| `sub` | `ceremony:{attempt_uuid}` |
| `site_code` | Must match request + header |
| `local_user_id` | Must match request |
| `ceremony_attempt_id` | Box attempt UUID |
| `verified_phone_e164_hash` | SHA-256 of normalized E.164; **only after OTP** |
| `verification_method` | e.g. `m2_whatsapp_otp` |
| `iat` / `exp` | TTL **5 minutes** (**INFERRED**; align with Box attempt `otp_verified` window) |

**Validation steps (Desk):**

1. Signature valid with site secret.
2. `exp` not passed; `iat` not in future (clock skew ≤ 60s).
3. `aud`, `iss`, `site_code`, `local_user_id` match request.
4. `jti` not previously consumed (lookup `central_wallet_ceremony_proof_consumptions`).
5. Mark `jti` consumed **in same transaction** as link creation (prevents replay).

**Binding to ceremony attempt:** `ceremony_attempt_id` in claims ties proof to one Box attempt; Box must not reuse ref across attempts.

### 3.3 Alternative considered — Desk-issued ref (**OWNER DECISION REQUIRED**)

Two-call flow: Box `POST /ceremony/verify` → Desk returns ref → Box `POST /ceremony/complete`. Rejected as default because Owner asked for **one** customer-ceremony Desk operation and minimal latency. Keep as fallback only if Owner rejects Box-signed proofs.

### 3.4 Authentication between Box and Desk (**VERIFIED**)

Existing integration: `Bearer CENTRAL_WALLET_INTEGRATION_TOKEN` + `X-Site-Code`. Ceremony endpoint uses same middleware (`AuthenticateCentralWalletIntegration`). Additional proof validation is defense-in-depth beyond bearer token.

---

## 4. Transaction sequence (atomic)

All steps below run inside **one DB transaction**, wrapped by existing `IdempotencyService::execute` (**VERIFIED**).

| Step | Action | On failure |
|------|--------|------------|
| 1 | Validate bearer auth + `X-Site-Code` == body `site_code` | 401 / 403 |
| 2 | Idempotency: lock `(caller_id, idempotency_key)` | 409 replay / in-progress |
| 3 | Parse + validate `ceremony_verification_ref` | 422 |
| 4 | Consume `jti` (insert consumption row; unique on `jti`) | 422 if replay |
| 5 | **Find:** active link for `(site_code, local_user_id)` | If exists → return `resolved_local_link` (skip create) |
| 6 | **Resolve identity:** lookup `ceremony_identities` by `(site_code, verified_phone_e164_hash)` | If row has `central_wallet_id` → candidate resolve |
| 7 | **Conflict:** if candidate CWID has active link to **different** `local_user_id` on site | 409 `cwid_linked_elsewhere` |
| 8 | **Conflict:** if another active link exists for this user to **different** CWID | 409 `ceremony_identity_linked_elsewhere` (should not happen if step 5 clean) |
| 9 | **Create CWID** if no ceremony identity row or row has null `central_wallet_id` | `CentralWalletService::create` pattern |
| 10 | Upsert ceremony identity → bind `central_wallet_id` | Unique on `(site_code, phone_hash)` |
| 11 | Create account link **directly as `active`** (or pending+confirm inline) | Reuse `AccountLinkService` logic |
| 12 | Audit: `ceremony.linked` (+ `ceremony.wallet_created` or `ceremony.wallet_resolved`) | Append-only |
| 13 | Store idempotency response; commit | |

**INFERRED:** Steps 11 uses internal service calls, not nested HTTP. Pending state may be skipped for this path because OTP proof satisfies “explicit verified confirmation” (charter R1).

**VERIFIED:** `AccountLinkService::revokeLink` exists but no customer HTTP route; not part of this endpoint.

**Important:** If step 9 creates a new CWID but step 11 fails, transaction **rolls back** CWID + identity row. **Do not** delete a CWID that was committed in a **prior successful** idempotent response.

---

## 5. Idempotency

**VERIFIED:** 90-day retention (`config/central_wallet.php` → `idempotency_retention_days`); caller-scoped unique `(caller_id, idempotency_key)`; `request_hash` mismatch → 409; processing → 409 `idempotency_request_in_progress`; replay adds `idempotent_replay: true`.

| Scenario | Behavior | Classification |
|----------|----------|----------------|
| Same key + same request | Return stored success body + `idempotent_replay: true` | **VERIFIED** |
| Same key + different request | 409 `idempotency_key_reused_with_different_request` | **VERIFIED** |
| Timeout after success | Retry same key → same result; no second CWID | **INFERRED** |
| Network failure before response | Safe retry | **INFERRED** |
| Duplicate browser submit | Same `idempotency_key` from attempt id | **INFERRED** |
| Concurrent submissions | Second gets 409 in-progress or waits retry | **VERIFIED** pattern |

**INFERRED:** Box uses `box-ceremony-complete:{attempt_uuid}` — one key per connect attempt, stable across retries.

**INFERRED:** `request_hash` = SHA-256 of canonical JSON of body fields (exclude correlation header).

---

## 6. Data model / constraints

### 6.1 New tables (**INFERRED required**)

#### `central_wallet_ceremony_identities`

| Column | Type | Purpose |
|--------|------|---------|
| `id` | UUID PK | Desk-internal ceremony identity id |
| `site_code` | string(64) | Site scope |
| `phone_e164_hash` | char(64) | SHA-256 of verified E.164; **not** profile phone |
| `central_wallet_id` | UUID FK nullable → `central_wallets` | Bound after first successful provision |
| `first_verified_at` | timestamp | Audit |
| `created_at` / `updated_at` | timestamps | |

**Unique:** `(site_code, phone_e164_hash)` — **INFERRED per-site scope** (**OWNER DECISION REQUIRED** for cross-site).

**Resolve semantics (VERIFIED charter compliance):**

- “Find existing wallet” = row exists for `(site_code, phone_e164_hash)` **from verified ceremony proof**, not from `users.email` / profile `users.phone`.
- Email is **never** a lookup key.

#### `central_wallet_ceremony_proof_consumptions`

| Column | Type | Purpose |
|--------|------|---------|
| `jti` | string(64) PK | Single-use proof id |
| `site_code` | string(64) | |
| `local_user_id` | string(64) | |
| `ceremony_attempt_id` | string(36) | Box attempt |
| `consumed_at` | timestamp | |
| `expires_at` | timestamp | TTL cleanup |

**Unique:** `jti`

### 6.2 Constraint upgrades (**INFERRED required**)

**VERIFIED gap:** `central_wallet_account_links` has indexes but **no** partial unique constraints on active links (Phase 1 plan §INFERRED; not in migration `2026_09_28_100000`).

Add (MySQL 8+ / MariaDB compatible partial unique via generated column or app-enforced + migration):

```sql
-- Conceptual (exact DDL at implementation time)
UNIQUE (site_code, local_user_id, active_slot) WHERE status = 'active'
UNIQUE (site_code, central_wallet_id, active_slot) WHERE status = 'active'
```

**INFERRED:** Use `active_slot = 1` generated column when `status = 'active'`, else NULL (NULLs don't collide in unique index).

### 6.3 What is NOT stored

- Raw OTP (**VERIFIED** — `AuditEventRecorder` redacts `otp`)
- Raw phone numbers in Desk audit (store `phone_hash` prefix only, e.g. first 8 hex)
- Signing secrets in audit payload

---

## 7. Security analysis

| Threat | Mitigation | Classification |
|--------|------------|----------------|
| Replayed verification ref | `jti` single-use consumption in same TX as link | **INFERRED** |
| Stolen ref before use | Short TTL (5m); bound to `site_code` + `local_user_id` + attempt | **INFERRED** |
| Stolen ref after use | `jti` already consumed → 422 | **INFERRED** |
| Wrong `local_user_id` | Claim must match body; mismatch → 422 | **INFERRED** |
| Wrong `site_code` | Header/body/claim alignment | **INFERRED** |
| Duplicate requests | Idempotency + DB unique active-link constraints | **VERIFIED** + **INFERRED** |
| Concurrent requests | `lockForUpdate` on idempotency + identity row + link checks | **VERIFIED** pattern |
| Compromised Box credential | Bearer allows API calls; ceremony proof limits blast radius to crafted refs — still requires valid signed proof with phone_hash | **INFERRED** |
| Compromised Desk credential | Attacker could link arbitrary users if also forging proofs — rotate integration + signing secrets | **INFERRED** |
| Malicious CWID association | Box never sends CWID; Desk assigns from ceremony identity only | **INFERRED** |
| Profile email/phone auto-link | Forbidden; not in API contract | **VERIFIED** charter |
| Operator bypass | No operator fields on endpoint; integration token only | **INFERRED** |

**Attack surface minimization:** One new write endpoint; no raw OTP; no profile lookup; no customer UUID input.

---

## 8. Failure / retry

| Failure | Desk state after rollback | Box recovery | Classification |
|---------|---------------------------|--------------|----------------|
| Invalid/expired proof | None | Re-run OTP → new ref | **INFERRED** |
| 409 identity conflict | None | Show conflict UX | **INFERRED** |
| 503 / timeout | Unknown | Retry **same** idempotency_key | **INFERRED** |
| Success + Box cache write fails | Desk link **active** | GET `/account-links` + upsert cache | **VERIFIED** M2 pattern |
| Idempotent replay after success | Unchanged | Treat as success | **VERIFIED** |

**VERIFIED:** Do not delete legitimate CWIDs on later failure (Owner requirement). Idempotent success path returns existing link without recreating wallet.

**INFERRED:** Orphan metric `ceremony_orphan_cwid` should be **zero** for atomic endpoint (CWID only commits with link). Staged M2 path may still orphan; monitor separately.

---

## 9. Audit

Minimum Desk `central_wallet_audit_events` (**INFERRED** event names):

| Event | When | Payload (sanitized) |
|-------|------|---------------------|
| `ceremony.complete_requested` | Idempotency handler entry | `site_code`, `local_user_id`, `ceremony_attempt_id` |
| `ceremony.proof_validated` | After signature OK | `jti`, `verification_method` |
| `ceremony.wallet_created` | New CWID | `provision_action=created` |
| `ceremony.wallet_resolved` | Existing ceremony identity CWID | `provision_action=resolved_existing` |
| `ceremony.local_link_resolved` | User already had active link | `provision_action=resolved_local_link` |
| `ceremony.linked` | Active link committed | `link_id`, `customer_display_ref` |
| `ceremony.conflict` | 409 paths | `error` code only |
| `ceremony.failed` | Terminal 422 | `error` code only |

**VERIFIED:** Existing link events `link.pending_created`, `link.confirmed` may still fire internally or be aliased — **OWNER DECISION REQUIRED** whether to duplicate or replace for ceremony path.

**Do not store:** OTP, secrets, full phone numbers.

---

## 10. RadiumBox responsibilities

| Responsibility | Detail | Classification |
|----------------|--------|----------------|
| Customer UX | Connect → WhatsApp confirm → OTP → progress → Connected | **VERIFIED** P-28-09-47 |
| OTP | Interakt send/verify locally | **VERIFIED** M2 |
| Issue `ceremony_verification_ref` | Signed JWT/HMAC after `otp.verified` | **INFERRED** |
| Call Desk atomic endpoint | Single `ceremony/complete` after proof issued | **INFERRED** |
| Idempotency key | `box-ceremony-complete:{attempt_id}` | **INFERRED** |
| Local cache | Upsert `central_wallet_account_links` from response | **VERIFIED** M2 |
| Customer display | Show `customer_display_ref` + status; hide UUID | **INFERRED** |
| Interakt reliability | HTTP 400 history (P-28-09-33/34) blocks pilot | **VERIFIED** |
| Remove UUID field from connect UI | Required before automatic flow | **VERIFIED** gap |

---

## 11. Desk responsibilities

| Responsibility | Detail | Classification |
|----------------|--------|----------------|
| Authoritative CWID + link + ledger | Unchanged hub role | **VERIFIED** charter |
| Implement `ceremony/complete` | This design | **INFERRED** |
| Ceremony identity registry | New table + constraints | **INFERRED** |
| Proof validation + consumption | Signing secret per site | **INFERRED** |
| Partial unique active-link indexes | Race-safe invariants | **INFERRED** (planned Phase 1) |
| Preserve existing APIs | No removal | **VERIFIED** requirement |
| `customer_display_ref` generation | e.g. `CW-` + last 4 of hash(link_id) | **INFERRED** |
| Metrics / alerts | See §Observability | **INFERRED** |

---

## 12. Migration requirements

| Migration | Purpose | Production impact |
|-----------|---------|-------------------|
| `create_central_wallet_ceremony_tables` | identities + proof consumptions | Additive |
| `add_central_wallet_active_link_unique_constraints` | partial unique active links | Additive; verify 0 active links before deploy (**VERIFIED** prod had 0 links at P-25-09-104) |
| Config: `ceremony_signing_secrets` map per site | Proof validation | Env only; no default secret |

**VERIFIED:** No cross-project DB. Site-local `local_user_id` unchanged.

**Deploy order:** Migration → config secrets → deploy code → enable Box feature flag → cohort test.

---

## 13. Rollout gates

| Phase | Desk gate | Classification |
|-------|-----------|----------------|
| D1 — Desk dev/staging | Unit + feature tests for ceremony complete; idempotency; conflict; replay | **INFERRED** |
| D2 — Desk prod (inert code) | Migrations applied; API behind `central_wallet.api_enabled` + new `ceremony_complete_enabled` flag default **false** | **INFERRED** |
| D3 — Owner technical test | Box + Desk integration; cohort user 3 | **VERIFIED** P-28-09-44 |
| D4 — Customer pilot | Interakt OTP SLO met; zero orphan CWIDs; conflict UX validated | **VERIFIED** blockers |
| D5 — GA | Remove cohort; monitor SLOs | **INFERRED** |

**VERIFIED blocker:** Interakt OTP delivery failures remain **outside** Desk but gate end-to-end ceremony success.

---

## 14. Remaining Owner decisions

| # | Decision | Classification |
|---|----------|----------------|
| O1 | Box-signed proof (recommended) vs Desk-issued two-step ref | **OWNER DECISION REQUIRED** |
| O2 | Ceremony identity scope: per-site vs global `phone_e164_hash` | **OWNER DECISION REQUIRED** |
| O3 | Include `central_wallet_id` in Desk response for Box server cache (recommended: yes server-only) | **OWNER DECISION REQUIRED** |
| O4 | `verification_method` enum: keep `m2_dual_otp` vs rename `m2_whatsapp_otp` | **OWNER DECISION REQUIRED** |
| O5 | Exact conflict copy when WhatsApp ceremony identity linked to another account | **OWNER DECISION REQUIRED** |
| O6 | HTTP `POST /account-links/{id}/revoke` for disconnect (P1, separate from complete) | **OWNER DECISION REQUIRED** |
| O7 | Signing secret rotation procedure | **OWNER DECISION REQUIRED** |

---

## Compatibility with existing APIs

| API | Role after ceremony complete ships | Classification |
|-----|-----------------------------------|----------------|
| `POST /wallets` | Operator/test bare CWID creation (P-25-09-104 test wallet) | **VERIFIED** |
| `POST /account-links` | Staged M2 / future spokes with known CWID | **VERIFIED** |
| `POST /account-links/{id}/confirm` | Staged M2 confirm | **VERIFIED** |
| `GET /account-links` | Cache repair + status check | **VERIFIED** |

**INFERRED:** RadiumBox automatic flow **replaces** its client usage of staged link APIs; Desk endpoints remain for backward compatibility and non-Box callers.

---

## Create-or-find semantics (Desk-side)

| Scenario | `ceremony/complete` behavior | Classification |
|----------|------------------------------|----------------|
| A — No CWID for ceremony identity | Create CWID + identity row + active link | **INFERRED** |
| B — CWID exists for same ceremony identity (same phone hash) | Return same CWID + link if unlinked; else link user | **INFERRED** |
| C — Active link already for `local_user_id` | `resolved_local_link`; idempotent 200 | **INFERRED** |
| D — CWID linked to another local user | 409 `cwid_linked_elsewhere` | **INFERRED** |
| E — Concurrent attempts | Idempotency + DB constraints | **INFERRED** |
| F — Desk DB down | 503 | **INFERRED** |
| G/H — Partial failure | **Avoided** by atomic TX (vs staged M2) | **INFERRED** |

**Forbidden:** resolve by `users.email` or profile phone (**VERIFIED** charter).

---

## Observability

| Metric | Purpose |
|--------|---------|
| `cw_ceremony_complete_success` | Happy path |
| `cw_ceremony_complete_latency_ms` | SLO |
| `cw_ceremony_wallet_created` | New CWIDs |
| `cw_ceremony_wallet_resolved` | Existing identity |
| `cw_ceremony_conflict` | 409 by code |
| `cw_ceremony_proof_invalid` | 422 |
| `cw_ceremony_idempotent_replay` | Retries |
| `cw_ceremony_orphan_cwid` | Target **0** (atomic design) |

**VERIFIED separate dependency:** Interakt OTP `issue_failed` / HTTP 400 — Box-side metric; GA blocker per P-28-09-33/34.

**INFERRED reconciliation:** Daily job can compare ceremony identity rows with active links; alert on identity with CWID but no active link > 24h (should not occur if atomic).

---

## Explicit non-goals

- Implementing code, migrations, or deploy in this prompt
- Removing or changing existing `/wallets` or `/account-links` contracts
- Email/profile phone auto-link
- Sending or storing raw OTP on Desk
- Customer-facing UUID display
- Global one-wallet-per-human without ceremony proof
- Merging RadiumBox local wallet balance

---

## Completion attestation

| Item | Status |
|------|--------|
| Code changed | **NO** |
| Database changed | **NO** |
| Production changed | **NO** |
| CWID created | **NO** |
| Account link created | **NO** |
| OTP sent | **NO** |
| Refund issued | **NO** |
| Deployed | **NO** |
