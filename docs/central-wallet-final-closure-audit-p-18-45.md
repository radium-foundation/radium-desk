# P-18-45 — Central Wallet final closure audit

**Global ID:** P-18-45  
**Desk ledger:** `RadiumDesk-P-18-45`  
**Cursor session:** `e6c3d1f5-d67d-4af0-a872-b849091c382b`  
**Audit window:** 2026-11-18 ~16:45–17:20 IST (**2026-11-18T11:15–11:50Z**)  
**Authorization:** read-only production inspection; documentation only — **no** financial mutation, deploy, or config change.

**Related work:** [P-18-42](desk-p16-02-permanent-regression-invariants.md) protected-journey verification · [P-18-43](cursor-prompt-ledger.md) concurrent ledger credit · [P-18-44](cursor-prompt-ledger.md) **v4.1.91** deploy · [P-18-24](P-18-24-desk-cw-http-origin-restriction-design.md) HTTP origin ACL (design only).

---

## 1. Architecture and ownership (verified)

| Role | Owner | Evidence |
|------|--------|----------|
| Spendable balance & ledger | **Radium Desk** | Prod `central_wallet_ledger_entries`; `config:show central_wallet.wallet_refund` **2026-11-18** |
| Spoke display / spend (contract) | Desk HTTP APIs + spoke clients | Spoke `api_base_url` + `api_host_header`; `contracts/central-wallet/v1/` per repo |
| Wallet refund **execution** (desk-first) | Desk `DeskFirstWalletRefundOrchestrator` | Prod `desk_first_enabled=true`, allowlist **three spokes** |
| Spoke refund **audit** (non-spendable) | `POST /api/integrations/v1/wallet-refunds` with `central_wallet_ledger_entry_id` | RadiumBox `DeskWalletRefundCreditService`; RDIN routes in `routes/api.php` |

Policy reference: [`docs/central-wallet-ownership-policy.md`](central-wallet-ownership-policy.md).

---

## 2. Production release identity (2026-11-18)

| Project | Path (KVM) | Release identity | Notes |
|---------|------------|------------------|--------|
| **Radium Desk** | `/var/www/radium-desk` | **`4.1.91` / `966567336`** (`v4.1.91`) deployed **16:42:07+05:30** | `storage/app/private/release.json` **VERIFIED** |
| **radiumbox.com** | `/var/www/radiumbox.com` | **UNKNOWN** (no `release.json`; not a git checkout on server) | Overlay deploy; artisan mtime **2026-10-18** |
| **rdservice.in** | `/var/www/rdservice.in` | **UNKNOWN** (same) | artisan mtime **2026-09-03** |
| **rdservice.net** | `/var/www/rdservice.net` | **Git `d9aa03b`** only | No `release.json`; `refund_destination.enabled=false` |

**Local inspection HEADs (not production):** Desk worktree `main` @ `15e448bc7` (ledger after P-18-44); radiumbox.com `f7ddd77f`; rdservice.in `786d0c3a`; rdservice.net `b8ddedc0`.

**Desk financial fixes on production runtime (read-only grep / file presence):**

- `CentralWalletRefundLedgerCreditService.php`: `UniqueConstraintViolationException` → `findExistingCredit()` — **present** (P-18-43 / **v4.1.91**).
- `RefundCompletionFollowUpJob` + `RefundRequestService::complete` → `afterResponse()` — **present** (P-18-42 / **v4.1.90+**).

---

## 3. Desk authority verification

**Prod config (`central_wallet.wallet_refund`, values only):**

| Key | Value |
|-----|--------|
| `desk_first_enabled` | **true** |
| `desk_authoritative_enabled` | **true** |
| `desk_first_spoke_allowlist` | **`rdservice.in,radiumbox.com,rdservice.net`** |
| `block_radiumbox_legacy_spendable_without_desk_ledger` | **false** |

**Inference:** Desk is the sole writer of authoritative CW ledger credits for desk-first refunds; spokes on the allowlist are eligible for orchestrated execution. Legacy Box spendable blocking remains **off** (Owner migration posture unchanged).

**API exposure (read-only curl):**

- Loopback `Host: desk.radiumbox.com` → `/api/central-wallet/v1/health` → **401** (reachable).
- Public `https://desk.radiumbox.com/api/central-wallet/v1/health` → **401** (reachable).

See [P-18-24](P-18-24-desk-cw-http-origin-restriction-design.md): path ACL **not applied**; off-loopback reachability **confirmed**.

---

## 4. Spoke integration summary

### 4.1 radiumbox.com

| Item | Status |
|------|--------|
| Desk HTTP route | `http://127.0.0.1` + `Host: desk.radiumbox.com` | **VERIFIED** |
| Balance / statement / checkout CW | `balance_read`, `statement_read`, `checkout` **enabled** | **VERIFIED** |
| `refund_destination.enabled` | **false** | **VERIFIED** |
| Refund audit API | `DeskWalletRefundCreditService` supports desk-authoritative audit when `central_wallet_ledger_entry_id` set; **legacy spendable path remains** when absent | Code + prod flag |
| Release gate `pre` | **`final: FAIL`** (hash/drift classes; synthetic section present) | **2026-11-18** |

### 4.2 rdservice.in

| Item | Status |
|------|--------|
| Desk HTTP route | `http://127.0.0.1` + `Host: desk.radiumbox.com` | **VERIFIED** |
| Checkout CW | **enabled** | **VERIFIED** |
| Integrations | `POST .../wallet-refunds`, `wallet-refund-reversals` | **VERIFIED** (repo routes) |
| Prod release | **UNKNOWN** | |
| Release gate `pre` | **`final: FAIL`** | **2026-11-18** |

### 4.3 rdservice.net

| Item | Status |
|------|--------|
| `refund_destination.enabled` | **false** | **VERIFIED** |
| `api_base_url` | **`http://desk-uat.radiumbox.com`** (prod config) | **VERIFIED** — **misalignment risk** vs loopback contract used on Box/RDIN |
| Checkout CW | **false** | **VERIFIED** |
| Git on server | **`d9aa03b`** | **VERIFIED** |
| Release gate `pre` | **ERROR** (artisan stack trace; gate **NOT RUN** to completion) | **2026-11-18** |

**Authentication:** All spokes use shared integration Bearer toward Desk; **token values intentionally omitted** from this report.

---

## 5. Refund and ledger integrity (read-only SQL)

### 5.1 Historical RadiumBox migration credits (Desk CW **#79–#84**)

| Ledger ID | `business_reference` | Amount (INR) | `source_system` |
|-----------|----------------------|-------------|-----------------|
| 79 | REF-2026-000281 | 617.00 | radiumbox.com |
| 80 | REF-2026-000298 | 717.00 | radiumbox.com |
| 81 | REF-67330 | 617.00 | radiumbox.com |
| 82 | REF-67347 | 917.00 | radiumbox.com |
| 83 | REF-67356 | 49.00 | radiumbox.com |
| 84 | REF-67374 | 997.00 | radiumbox.com |

**Sum #79–#84:** **₹3,914.00** — **VERIFIED**.

**`central_wallet_refund_migrations` rows** with `destination_ledger_entry_id` ∈ {79…84}: **0 rows** — migration table linkage **UNKNOWN**; ledger rows themselves **VERIFIED** on Desk.

### 5.2 RN357 / RN377 (protected records — not used as test candidates)

| Desk refund `id` | Status | Amount | `execution_reference_no` | Desk CW ledger |
|------------------|--------|--------|--------------------------|----------------|
| **479** | **closed** | ₹599.00 | **CW:152** | **#152** `REF-67494` |
| **494** | **closed** | ₹979.00 | **CW:151** | **#151** `REF-67516` |

**Duplicate credit check:** `COUNT(*)` credits where `business_reference` ∈ {REF-67494, REF-67516} = **2** (exactly one per ref) — **VERIFIED**.

---

## 6. P-18-42 / P-18-43 / P-18-44 implementation evidence

| Prompt | Requirement | Evidence |
|--------|-------------|----------|
| **P-18-42** | Post-commit follow-up off HTTP thread | Prod `RefundCompletionFollowUpJob`; `RefundRequestService` dispatches `afterResponse()`; focused tests **47 PASS** (ledger P-18-42, local — **not re-run** in P-18-45) |
| **P-18-43** | Unique constraint race → idempotent replay | Prod service file; commit **`910ba39fd`**; MariaDB concurrency harness **PASS** (ledger P-18-43, local — **not re-run** in P-18-45) |
| **P-18-44** | Deploy **v4.1.91** | Prod `release.json` **4.1.91/966567336**; post-RIP **PASS**; refunds **479/494** unchanged **closed** |

---

## 7. Outstanding risks (A–E)

### A. P-18-24 API access restriction — **OPEN**

- Design doc only; **no** OLS path ACL deployed.
- Off-loopback CW health returns **401** (not denied at edge).
- Full off-KVM caller inventory: **UNKNOWN** (not authorized to change ACL).

**Follow-up:** Owner approval + paired spoke loopback verification per [P-18-24](P-18-24-desk-cw-http-origin-restriction-design.md).

### B. Synthetic monitoring — **OPEN**

| Project | `central-wallet:verify-release-gate --phase=pre` |
|---------|--------------------------------------------------|
| Desk | **`final: FAIL`** (`hash_mismatch` / `runtime_file_hashes` — expected on full deploy vs overlay manifest) |
| radiumbox.com | **`final: FAIL`** |
| rdservice.in | **`final: FAIL`** |
| rdservice.net | **NOT RUN** (CLI error) |

No change made to monitoring configuration in P-18-45.

### C. Backup and recovery — **DEFERRED** (spokes); **partial CLOSED** (Desk)

- Desk: backup **`20261118T110809Z`** referenced in **P-18-44** (`cloud_uploaded`, GPG/gzip verified in that task).
- Spoke backup / restore drills: **UNKNOWN** (not inspected in depth).
- Restore import: **NOT RUN** (not authorized).

### D. Identity and customer display — **DEFERRED**

Checkout trusted-link, Connect Wallet UX, C360 display policy gaps remain tracked in `RADIUM_CENTRAL_WALLET_MASTER_IMPORT_SUMMARY.md` §0 / §5 — **out of Central Wallet implementation closure** unless Owner expands scope.

### E. Legacy local wallet paths — **OPEN**

- **RadiumBox:** `refund_destination=false`; `DeskWalletRefundCreditService::creditLegacySpendable()` still active when Desk omits `central_wallet_ledger_entry_id`.
- **RDNET:** `refund_destination=false`; merchant prod still on **`desk-uat`** base URL for Desk client.
- Desk `block_radiumbox_legacy_spendable_without_desk_ledger=false`.

---

## 8. Closure matrix

| Area | Classification | Evidence / limitation |
|------|----------------|------------------------|
| Desk Central Wallet authority | **CLOSED** | Prod ledger + desk-first flags; **v4.1.91** identity |
| RadiumBox wallet integration | **OPEN** | Read/checkout CW **VERIFIED**; legacy spendable refund path + `refund_destination=false`; prod release **UNKNOWN** |
| rdservice.in integration | **OPEN** | Loopback Desk client **VERIFIED**; prod release **UNKNOWN**; gate **FAIL** |
| rdservice.net integration | **OPEN** | `refund_destination=false`; **desk-uat** API base on prod; gate **NOT RUN** |
| Refund execution and audit contracts | **CLOSED** | Desk-first **on**; RN357/RN377 **closed**; spoke audit routes exist |
| Concurrent ledger idempotency | **CLOSED** | **v4.1.91** on prod |
| Refund post-commit processing | **CLOSED** | **v4.1.90+** job on prod |
| API access restriction (P-18-24) | **OPEN** | No ACL; design only |
| Synthetic monitoring | **OPEN** | Gates **FAIL** or **NOT RUN** |
| Cross-project backup and recovery | **DEFERRED** | Desk backup cited; spoke/recovery not evidenced |
| Identity and display policy | **DEFERRED** | Explicitly separate milestone |
| Legacy wallet mutation paths | **OPEN** | Legacy spendable + flags documented above |

---

## 9. Recommended follow-up task IDs

| ID | Owner project | Topic |
|----|---------------|--------|
| **P-18-24** | Desk / infra | Implement path-scoped CW HTTP origin ACL after spoke loopback sign-off |
| **P-18-46+** (proposed) | radiumbox.com | Enable `refund_destination` + retire legacy spendable for new refunds under desk-first |
| **P-18-46+** (proposed) | rdservice.net | Align prod `CENTRAL_WALLET_API_BASE_URL` to loopback Desk route; deploy refund audit overlay |
| **P-04-10-120** lineage | All | Restore **PASS** `verify-release-gate` on prod runtimes (synthetic + hash alignment) |
| Customer 360 / UX | Desk + spokes | Identity/display policy (DEFERRED) |

---

## 10. Final decision

**PARTIALLY CLOSED** — Desk remains the verified financial authority; desk-first refund execution and **v4.1.90–v4.1.91** safety fixes are on production; RN357/RN377 and historical CW **#79–#84** ledger state are verified. Spoke runtime parity, HTTP edge restriction, synthetic gates, legacy spendable retirement, and RDNET Desk URL alignment remain **OPEN** or **DEFERRED**.

This is **not** a statement that the wallet ecosystem is risk-free.

---

## 11. Explicit non-actions (P-18-45)

- **NO** — production financial mutation  
- **NO** — refund execution or replay  
- **NO** — production DB writes or restore  
- **NO** — production configuration changes  
- **NO** — production deployment  
- **NO** — full PHPUnit from Cursor  

---

## 12. PR / evidence links

- Desk **v4.1.91** merge: **`910ba39fd`** ([#341](https://github.com/radium-foundation/radium-desk/pull/341))  
- Desk **v4.1.90** follow-up: **`c10d499a6`** ([#338](https://github.com/radium-foundation/radium-desk/pull/338))  
- Ledger: **RadiumDesk-P-18-44**, **RadiumDesk-P-18-45** in [`docs/cursor-prompt-ledger.md`](cursor-prompt-ledger.md)  
- Handover addendum: [`docs/central-wallet-handover-p-18-45-addendum.md`](central-wallet-handover-p-18-45-addendum.md)