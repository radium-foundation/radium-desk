# Central Wallet R4 — Read-only ledger query API design (Desk-R4.a)

**Prompt ID:** `RadiumDesk-P-25-09-91`  
**Date:** 2026-09-28  
**Mode:** Design / documentation only — no implementation  
**Desk foundation commit:** `23e270aa73353fbffa6d713267d08b699db9acfe`  
**Companion spoke design:** `radiumbox.com` — `radiumbox.com-P-28-09-06` (`docs/central-wallet-radiumbox-reconciliation-design-p-28-09-06.md`)

---

## 1. Objective

Design a **read-only** Central Wallet HTTP API on the Desk hub that allows authorized spokes (e.g. RadiumBox) to **independently query ledger entries** for reconciliation evidence.

This resolves the verified R4 dependency: Desk Phase 1 (`23e270aa`) exposes ledger **append** (`POST /wallets/{cwid}/ledger-entries`) but **no** suitable ledger **read/list** API.

The API must **never**:

- mutate balances
- create, reverse, or void ledger entries
- reserve or commit funds
- alter idempotency records

---

## 2. Verified current Desk ledger schema

**Source:** `database/migrations/2026_09_28_100000_create_central_wallet_foundation_tables.php` @ `23e270aa`

### 2.1 Table: `central_wallet_ledger_entries`

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `id` | `BIGINT` PK AI | No | **ledger_entry_id** in API responses |
| `central_wallet_id` | `UUID` | No | FK → `central_wallets.id` |
| `entry_type` | `VARCHAR(32)` | No | Enum: `credit`, `debit`, `reversal`, `adjustment` |
| `amount` | `DECIMAL(12,2)` | No | Positive amount |
| `currency` | `CHAR(3)` | No | Default `INR` |
| `status` | `VARCHAR(32)` | No | Enum: `posted`, `pending`, `voided` (default `posted`) |
| `source_system` | `VARCHAR(64)` | No | Set from **request body** on write |
| `source_reference` | `VARCHAR(191)` | Yes | RadiumBox: `(string) users_wallet.id` |
| `correlation_id` | `UUID` | No | From `X-Correlation-Id` at write time |
| `business_reference` | `VARCHAR(191)` | Yes | e.g. `desk_refund_reference` or `RADBOX:{ordercode}` |
| `reservation_id` | `UUID` | Yes | Unused in Phase 1 shadow |
| `metadata` | `JSON` | Yes | Optional extension |
| `posted_at` | `TIMESTAMP` | No | Financial posting time |
| `created_at` / `updated_at` | timestamps | No | Row metadata |

### 2.2 Existing indexes (VERIFIED)

| Index name | Columns |
|------------|---------|
| `cw_ledger_wallet_status_idx` | `(central_wallet_id, status)` |
| `cw_ledger_correlation_idx` | `correlation_id` |
| `cw_ledger_source_idx` | `(source_system, source_reference)` |

**Not indexed (VERIFIED absence):** `posted_at`, `(source_system, posted_at)`, `(central_wallet_id, posted_at)`, `id` alone (PK covers point lookup).

### 2.3 Related tables (reconciliation context)

| Table | Relevance |
|-------|-----------|
| `central_wallet_idempotency_records` | Stores `caller_id`, `idempotency_key`, `response_body`; **not** FK-linked to ledger rows |
| `central_wallet_account_links` | `site_code`, `central_wallet_id`, `local_user_id`, `status` |
| `central_wallet_reconciliation_runs` | Desk-side run scaffold |
| `central_wallet_reconciliation_items` | Desk-side item scaffold |

### 2.4 Fields **not** on ledger rows (do not invent)

| Field | Where it lives |
|-------|----------------|
| `idempotency_key` | `central_wallet_idempotency_records` only |
| `caller_id` | Idempotency + auth middleware attribute — **not persisted on ledger** |
| `site_code` | `central_wallet_account_links` only |

---

## 3. Existing API capabilities (VERIFIED @ `23e270aa`)

**Prefix:** `/api/central-wallet/v1`  
**Middleware stack:** `api` → `central_wallet.correlation` → `central_wallet.enabled` → `central_wallet.auth`

| Method | Route | Mutating? |
|--------|-------|-----------|
| `GET` | `/health` | No |
| `POST` | `/wallets` | Yes |
| `GET` | `/wallets/{cwid}` | No |
| `GET` | `/wallets/{cwid}/balance` | No |
| `POST` | `/wallets/{cwid}/ledger-entries` | Yes |
| `POST` | `/account-links` | Yes |
| `GET` | `/account-links` | No |
| `POST` | `/wallet-reservations` | Yes (501) |

**Write contract (RadiumBox shadow projection — VERIFIED):**

```http
POST /api/central-wallet/v1/wallets/{cwid}/ledger-entries
Authorization: Bearer {token}
X-Site-Code: radiumbox.com
X-Correlation-Id: {uuid}

{
  "idempotency_key": "box-sync:{users_wallet.id}",
  "entry_type": "credit|debit",
  "amount": "250.00",
  "source_system": "radiumbox.com",
  "source_reference": "{users_wallet.id}",
  "business_reference": "REF-… or RADBOX:RDE…"
}
```

**Write response includes:** `ledger_entry_id`, `central_wallet_id`, `entry_type`, `amount`, `currency`, `source_reference`, `business_reference`, `correlation_id` — **does not include `source_system`** in JSON (but stored in DB).

**Auth (VERIFIED):** `CentralWalletIntegrationAuthenticator` — bearer token `hash_equals`; `callerId()` = `X-Site-Code` header or fallback `central_wallet_service`.

**Known write-path gap (VERIFIED — `CentralWalletSecurityTest`):** `source_system` in POST body is **not** validated against `X-Site-Code`. Different site codes with same idempotency key create **separate** ledger rows. Read API design must **not** repeat this weakness.

---

## 4. Proposed read API

### 4.1 Design principles

1. **Read-only** — `GET` only; no side effects.
2. **Caller-bound** — every query implicitly scoped to authenticated `caller_id` (= `X-Site-Code`).
3. **Fail closed** — missing/invalid auth → `401`; cross-caller access → `404` (not `403`) to avoid existence leakage.
4. **Match repository nesting** — ledger writes are under `/wallets/{cwid}/ledger-entries`; reads follow the same resource hierarchy plus a caller-scoped collection endpoint for daily sweeps.

### 4.2 Endpoints (proposed)

#### A. Single entry by ID (per-event reconciliation)

```http
GET /api/central-wallet/v1/ledger-entries/{ledger_entry_id}
```

**Purpose:** RadiumBox sync-state `central_ledger_entry_id` → fetch authoritative Desk row → field comparison.

**Authorization rule (mandatory):**

```
entry.source_system === authenticated caller_id
```

If mismatch or not found → **`404`** with generic `not_found` (no hint that another caller owns the row).

**Optional corroboration when `central_wallet_id` known:**

```
Query may include ?central_wallet_id={uuid}
→ if provided, entry.central_wallet_id must match (else 404)
```

#### B. Wallet-scoped list (per-CWID daily batch)

```http
GET /api/central-wallet/v1/wallets/{cwid}/ledger-entries
```

**Purpose:** Paginate all entries for one CWID belonging to the caller.

**Mandatory rules:**

1. `cwid` must be valid UUID v4.
2. **Caller ownership:** at least one of:
   - **(Preferred)** EXISTS `central_wallet_account_links` WHERE `site_code = caller_id` AND `central_wallet_id = cwid` AND `status = 'active'`
   - **(Fallback)** at least one ledger row WHERE `central_wallet_id = cwid` AND `source_system = caller_id`
3. Every returned row: `source_system = caller_id` (defense in depth).

If wallet not linked and no caller-scoped rows → **`404`** `wallet_not_found` (avoid CWID enumeration across callers).

#### C. Caller-scoped collection (unexpected-entry sweep)

```http
GET /api/central-wallet/v1/ledger-entries
```

**Purpose:** Bounded daily reconciliation across **all** entries for `source_system = caller_id` without pre-enumerating CWIDs.

**Mandatory implicit filter:**

```
WHERE source_system = authenticated caller_id
```

**Never** accept `source_system` as a client override query parameter.

---

## 5. Authentication and authorization

### 5.1 Authentication (VERIFIED — reuse Phase 1)

| Header | Required | Role |
|--------|----------|------|
| `Authorization` | Yes | `Bearer {CENTRAL_WALLET_INTEGRATION_TOKEN}` |
| `X-Site-Code` | Yes for spoke isolation | Becomes `central_wallet_caller_id` |
| `X-Correlation-Id` | Optional on read | Audit trace; auto-assigned if absent |

Same middleware as write path: `central_wallet.enabled`, `central_wallet.auth`, `central_wallet.correlation`.

### 5.2 Authorization model

| Layer | Rule |
|-------|------|
| API gate | `central_wallet.api_enabled` + `central_wallet.enabled` (fail closed) |
| Caller identity | `caller_id = trim(X-Site-Code)`; reject empty |
| Row scope | `ledger.source_system === caller_id` always |
| CWID scope | Account-link or caller-scoped ledger evidence (§4.2 B) |
| Cross-caller | Return `404`, never `403` with body distinguishing "exists elsewhere" |

### 5.3 Phase 1 shared-token limitation (VERIFIED)

All spokes sharing one bearer token can impersonate any `X-Site-Code`. **Mitigation today:** network isolation + spoke config discipline.

**Minimum future hardening (document only — not in Desk-R4.a scope):**

| Phase | Hardening |
|-------|-----------|
| Phase 2 | Per-spoke integration tokens mapped to allowed `site_code` values |
| Phase 3 | mTLS or signed service identity |

Read API design is compatible with per-spoke tokens by validating `token → allowed_site_codes[]`.

---

## 6. Caller isolation

### 6.1 Enforcement mechanism (INFERRED — required for R4.a.4)

```php
// Pseudocode — not implementation
$callerId = $request->attributes->get('central_wallet_caller_id');

$query->where('source_system', $callerId); // ALWAYS applied, never from query string
```

For `GET /ledger-entries/{id}`:

```php
$entry = LedgerEntry::find($id);
if ($entry === null || $entry->source_system !== $callerId) {
    return 404;
}
```

### 6.2 What callers cannot do

| Attempt | Response |
|---------|----------|
| `?source_system=other.site` | **Ignored** or `422` — must not broaden scope |
| Read entry ID belonging to another `source_system` | `404` |
| List entries for CWID with no link and no caller rows | `404` |
| Infer other callers' entry counts | Prevented by `404` uniformity |

---

## 7. Filtering

### 7.1 Supported query parameters

| Parameter | Endpoints | Type | Notes |
|-----------|-----------|------|-------|
| `source_reference` | B, C | string, max 191 | Uses index `(source_system, source_reference)` when combined with caller filter |
| `business_reference` | B, C | string, max 191 | Exact match; **no index** — use with other filters or TBD index |
| `correlation_id` | B, C | UUID | Uses `cw_ledger_correlation_idx` |
| `entry_type` | B, C | enum | `credit`, `debit`, `reversal`, `adjustment` |
| `status` | B, C | enum | Default `posted` if omitted for recon (**INFERRED**) |
| `posted_from` | B, C | ISO8601 UTC | Inclusive lower bound on `posted_at` |
| `posted_to` | B, C | ISO8601 UTC | Exclusive upper bound (**INFERRED** convention) |
| `central_wallet_id` | C only | UUID | Optional narrowing |
| `ledger_entry_id` | C only | integer | Alternative to path-based single fetch |
| `cursor` | B, C | opaque string | Keyset pagination (§8) |
| `limit` | B, C | integer | Page size |

**Not supported on read API (by design):**

| Parameter | Reason |
|-----------|--------|
| `idempotency_key` | Not on ledger table; separate admin API **TBD** if ever needed |
| `source_system` | Derived from auth only |

### 7.2 RadiumBox match-key mapping (VERIFIED fields)

| RadiumBox key | Desk query |
|---------------|------------|
| Primary: `central_ledger_entry_id` | `GET /ledger-entries/{id}` |
| `box-sync:{users_wallet.id}` | **Not on ledger** — use `source_reference = {id}` + caller filter, or idempotency table (**TBD** separate read) |
| `(source_system, source_reference)` | `GET /ledger-entries?source_reference={id}` |
| CWID | `GET /wallets/{cwid}/ledger-entries` |
| `business_reference` | `?business_reference=` |
| `central_correlation_id` | `?correlation_id=` |

### 7.3 `source_reference` format (VERIFIED for RadiumBox R3)

RadiumBox `CentralWalletHttpClient` sets:

```
source_reference = (string) users_wallet.id
```

Example: `"1137"`, `"50042"` — decimal string, no prefix.

---

## 8. Pagination

### 8.1 Strategy: keyset (cursor) pagination

**Ordering (deterministic):**

```sql
ORDER BY posted_at ASC, id ASC
```

**Cursor payload (opaque, base64 JSON):**

```json
{"posted_at":"2026-09-28T02:15:00Z","id":12345}
```

**Next page:**

```sql
WHERE (posted_at, id) > (cursor.posted_at, cursor.id)
ORDER BY posted_at ASC, id ASC
LIMIT :limit
```

### 8.2 Response envelope

```json
{
  "data": [ /* LedgerEntryResource[] */ ],
  "pagination": {
    "limit": 100,
    "next_cursor": "eyJ...",
    "has_more": true
  }
}
```

### 8.3 Parameters

| Parameter | Proposed default | Proposed max | Status |
|-----------|------------------|--------------|--------|
| `limit` | 100 | 500 | **TBD** — calibrate from workload |
| `posted_from` / `posted_to` max span | 31 days per request | 90 days | **TBD** |
| Daily recon checkpoint | Store `next_cursor` on RadiumBox run | — | Spoke-side |

### 8.4 Safety properties

| Property | Guarantee |
|----------|-----------|
| No skipped entries | Keyset on unique `(posted_at, id)` with monotonic `id` |
| No duplicate pages | Cursor advances past last seen tuple |
| Resumable | Checkpoint cursor persisted between batches |
| Stable under inserts | New rows during sweep appear in later pages or next run — document as timing, not error |

---

## 9. Response contract

### 9.1 Ledger entry resource (minimum fields)

| Field | Source column | Include? |
|-------|---------------|----------|
| `ledger_entry_id` | `id` | **Yes** |
| `central_wallet_id` | `central_wallet_id` | **Yes** |
| `entry_type` | `entry_type` | **Yes** |
| `amount` | `amount` | **Yes** — string `^\d+(\.\d{1,2})?$` |
| `currency` | `currency` | **Yes** |
| `status` | `status` | **Yes** |
| `source_system` | `source_system` | **Yes** — always equals caller |
| `source_reference` | `source_reference` | **Yes** |
| `business_reference` | `business_reference` | **Yes** if non-null |
| `correlation_id` | `correlation_id` | **Yes** |
| `posted_at` | `posted_at` | **Yes** — ISO8601 UTC |
| `created_at` | `created_at` | **Yes** — audit |
| `metadata` | `metadata` | **No** by default — omit or allow `?include=metadata` **TBD** |
| `reservation_id` | `reservation_id` | Include if non-null |
| `idempotency_key` | — | **No** — not on ledger |

### 9.2 Error responses

| HTTP | `error` code | When |
|------|--------------|------|
| `401` | `unauthorized` | Missing/invalid token or API disabled |
| `404` | `not_found` | Entry/wallet not found or not caller-scoped |
| `422` | `validation_error` | Invalid UUID, date range, cursor, limit |
| `429` | `rate_limited` | Exceeded read rate limit **TBD** |
| `500` | `internal_error` | Sanitized message |

**Never expose:** stack traces, SQL, tokens, other callers' identifiers.

---

## 10. Index requirements (recommendations only — no migration in this task)

### 10.1 Existing index coverage

| Query pattern | Index used? |
|---------------|-------------|
| `source_system = ? AND source_reference = ?` | **Yes** — `cw_ledger_source_idx` (caller filter + source_reference) |
| `id = ?` | **Yes** — PK |
| `correlation_id = ?` | **Yes** — `cw_ledger_correlation_idx` |
| `central_wallet_id = ? AND status = ?` | **Yes** — `cw_ledger_wallet_status_idx` |

### 10.2 Recommended new indexes (Desk-R4.a.3)

| Index | Columns | Rationale |
|-------|---------|-----------|
| `cw_ledger_source_posted_idx` | `(source_system, posted_at, id)` | Daily sweep + keyset pagination by caller |
| `cw_ledger_wallet_posted_idx` | `(central_wallet_id, posted_at, id)` | Wallet-scoped pagination |
| `cw_ledger_business_ref_idx` | `(source_system, business_reference)` | **Optional** — only if business_reference lookups needed at scale |

**Do not create in this design task.**

---

## 11. Security

| Topic | Requirement |
|-------|-------------|
| Authentication | Fail closed — same as write API |
| Authorization | `source_system === caller_id` on every row |
| Input validation | UUID v4, ISO8601 dates, `limit` cap, cursor signature/version |
| Rate limiting | **TBD** — propose reuse `central_wallet.reconciliation.rate_limit_per_minute` or new `read_rate_limit_per_minute` |
| Pagination cap | Enforce `limit <= max_page_size` server-side |
| Audit logging | Log read operations: `caller_id`, route, filter summary, result count — **no** full payloads in logs |
| PII | Ledger rows contain no email/mobile by schema — **VERIFIED** |
| Error sanitization | Match `IdempotencyResponseSanitizer` discipline |
| Idempotency table | **Do not** expose via public read API in R4.a (contains response bodies) |

---

## 12. Performance

| Concern | Assessment |
|---------|------------|
| Point lookup by `ledger_entry_id` | PK — O(1) — suitable for per-event recon |
| Filter by `(source_system, source_reference)` | Indexed — suitable for RadiumBox row lookup |
| Daily full sweep by `source_system` | **Requires new index** `(source_system, posted_at, id)` for large volumes |
| `business_reference` filter | Full scan without optional index — acceptable only with narrow date window |
| Balance endpoint | Already exists — supplementary aggregate check only |

**Volume evidence:** Production ledger row count **UNKNOWN** (Central Wallet not deployed). Batch sizes **TBD**.

---

## 13. Reconciliation usage (RadiumBox)

### 13.1 Per-event flow (VERIFIED intent from RadiumBox R4)

```
1. RadiumBox projection succeeds → stores central_ledger_entry_id
2. GET /api/central-wallet/v1/ledger-entries/{id}
   (Authorization + X-Site-Code: radiumbox.com)
3. Compare:
   - amount ↔ users_wallet credit/debit
   - entry_type ↔ credit/debit
   - source_reference ↔ users_wallet.id
   - business_reference ↔ desk_refund_reference or RADBOX:ordercode
   - central_wallet_id ↔ account link
4. matched → reconciliation_status = matched, sync_status = reconciled
   mismatch → blocked (no balance change)
```

### 13.2 Corroborating lookup (timeout recovery)

When `central_ledger_entry_id` is null but projection may have succeeded:

```
GET /ledger-entries?source_reference={users_wallet.id}
```

Expect 0 or 1 posted row for shadow phase. >1 → duplicate detection.

### 13.3 Daily bounded reconciliation

```
For each linked CWID (or single collection sweep):
  GET /ledger-entries?posted_from={start}&posted_to={end}&cursor=…
  Match against RadiumBox projected population
  Detect missing / unexpected / duplicate
```

**292 pending rows:** excluded from expected Central population — not queried as missing.

---

## 14. Existing reconciliation scaffold

### 14.1 `ReconciliationDailyJob` (VERIFIED @ `23e270aa`)

| Property | Value |
|----------|-------|
| Trigger | Scheduled when `central_wallet.reconciliation.enabled` |
| Behaviour | Creates run with `mode: scaffold_only`, `items_processed: 0` |
| Consumes ledger read API? | **No** |
| Performs spoke reconciliation? | **No** |

### 14.2 Desk vs spoke responsibility (INFERRED)

| Concern | Owner |
|---------|-------|
| RadiumBox ↔ Central Wallet row matching | **RadiumBox** (spoke-side recon per P-28-09-06) |
| Desk internal hub integrity (future) | **Desk** `ReconciliationDailyJob` evolution |
| Ledger read API | **Desk** provides evidence; does not reconcile |

The new read API should **not** embed reconciliation logic. Desk job may **optionally** consume the same repository layer in a future phase for hub-self-checks — **TBD**, separate from RadiumBox R4.

---

## 15. Snapshot alternative comparison

RadiumBox R4 also proposed storing `projection_response_snapshot` on sync-state at projection time.

| Criterion | Desk read API | RadiumBox snapshot |
|-----------|---------------|-------------------|
| **Independence** | Spoke verifies against Desk SoT directly | Spoke trusts its own stored copy |
| **Completeness** | Can read current Desk truth | Only what was captured at projection |
| **Unexpected Central entries** | **Detectable** via collection sweep | **Not detectable** |
| **Deleted/corrupted local state** | Can re-fetch and heal sync-state | Limited to snapshot |
| **Auditability** | Desk audit + spoke run logs | Spoke-only evidence |
| **Operational complexity** | Requires Desk deploy + indexes | RadiumBox-only change |
| **Performance** | Network + pagination | Local DB read |
| **Security** | Must enforce caller isolation | No cross-service read |
| **Idempotency replay evidence** | Partial via `source_reference` lookup | Can store full 201 body |

### 15.1 Conclusion (evidence-based)

| Approach | Recommendation |
|----------|----------------|
| **Desk read API** | **Required** for independent reconciliation, unexpected-entry detection, and Owner-approved ₹0-tolerance audit |
| **Projection snapshot** | **Optional supplement** for faster per-event recon and timeout recovery — does **not** replace read API |

**Do not choose one exclusively.** Approved architecture: **read API primary**; optional sanitized snapshot as performance cache with periodic Desk cross-check.

---

## 16. Implementation phases (if approved)

| Phase | Deliverable |
|-------|-------------|
| **Desk-R4.a.1** | API contract finalization + OpenAPI-style doc in repo |
| **Desk-R4.a.2** | Authorization/isolation (`LedgerEntryQueryService` + caller scope) |
| **Desk-R4.a.3** | Index migration `(source_system, posted_at, id)` + optional wallet index |
| **Desk-R4.a.4** | `LedgerEntryController` + routes + resources |
| **Desk-R4.a.5** | Feature tests: auth, isolation, pagination, 404 cross-caller, filter correctness |
| **Desk-R4.a.6** | Non-production validation with RadiumBox recon integration tests (mocked HTTP) |
| **Desk-R4.a.7** | Production rollout gate: flags, rate limits, Owner approval, coordinate with RadiumBox R4.7 |

**Also recommend (write-path hardening — separate task):**

- Validate `source_system === caller_id` on `POST ledger-entries` to close cross-site body spoofing.

---

## 17. Verified / Inferred / TBD

### Verified

- Ledger schema columns and indexes @ `23e270aa`
- No read API exists today
- Write API request/response shape
- `source_reference` stored; RadiumBox uses wallet id string
- Auth middleware and `caller_id` from `X-Site-Code`
- Shared-token cross-site idempotency scoping in tests
- `ReconciliationDailyJob` is scaffold only
- RadiumBox R4 match-key hierarchy and ₹0 tolerance policies

### Inferred

- `404` preferred over `403` for cross-caller isolation
- Default `status=posted` filter for recon queries
- Keyset pagination on `(posted_at, id)`
- Account-link check for wallet-scoped list
- Desk read API is primary; snapshot is optional supplement

### TBD

- `limit` default/max and date-range caps
- Read rate limit values
- Whether to expose `metadata` on read
- Separate idempotency read endpoint for `box-sync:{id}` recovery
- Per-spoke token hardening timeline
- Production ledger volume / index sizing
- Whether Desk `ReconciliationDailyJob` eventually consumes same query layer

---

## 18. Risks and blockers

| Risk | Severity | Mitigation |
|------|----------|------------|
| No read API blocks RadiumBox R4 | **Critical** | This design — implement Desk-R4.a.4 |
| Write `source_system` not tied to caller | **High** | Read isolation + future write validation |
| Shared bearer token | **Medium** | Per-spoke tokens (future) |
| Missing pagination index at scale | **Medium** | Desk-R4.a.3 before production sweep |
| `idempotency_key` not on ledger | **Low** | Use `source_reference` lookup |
| 90-day idempotency expiry | **Low** | Ledger row persists; read by id unaffected |

---

## 19. Completion report

| Field | Value |
|-------|-------|
| **Project** | Central Wallet / Desk hub |
| **Repository** | `/Users/ravi/RadiumWebsites/radium-desk` |
| **Branch** | `feat/refund-statutory-adjustment-p-25-09-79` |
| **Before SHA** | `23e270aa73353fbffa6d713267d08b699db9acfe` |
| **After SHA** | `23e270aa73353fbffa6d713267d08b699db9acfe` |
| **Prompt ID** | `RadiumDesk-P-25-09-91` |
| **Changed** | Documentation only: this file (+ local ledger/log) |
| **Tests** | NO — Not performed |
| **Committed** | NO — Not performed |
| **Commit SHA** | NO — Not performed |
| **Pushed** | NO — Not performed |
| **Deployed** | NO — Not performed |
| **Production migration** | NO — Not performed |
| **Production data modified** | NO |
| **Central Wallet data modified** | NO |
| **Ledger entries created** | NO |
| **Ledger entries changed** | NO |

### Unperformed actions

| Action | Status |
|--------|--------|
| Application code changes | NO — Not performed |
| Migrations / indexes | NO — Not performed |
| Production access | NO — Not performed |
| Central Wallet API calls | NO — Not performed |
| Ledger writes | NO — Not performed |
| RadiumBox changes | NO — Not performed |
| Commit / push / deploy | NO — Not performed |

---

*End of Desk-R4.a ledger read API design document.*
