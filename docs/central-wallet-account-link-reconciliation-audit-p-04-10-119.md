# Central Wallet Account-Link Reconciliation Audit (P-04-10-119)

**READ ONLY.** No synchronization, repair, wallet mutation, or production configuration change.

Audit timestamp (production read): **2026-10-08T14:48:50Z**  
Host: KVM8 `187.127.129.16` (SELECT-only via `tools/audit/central-wallet-account-link-reconciliation.php`)

## Desk authority (VERIFIED)

| Field | Value |
|---|---|
| Table | `central_wallet_account_links` |
| PK | `id` (bigint) |
| Wallet ID | `central_wallet_id` (UUID → `central_wallets.id`) |
| Customer identity | `desk_customer_id` (UUID → `central_customers.id`, nullable on migration cohort rows) |
| Site | `site_code` |
| Spoke user | `local_user_id` (string on Desk; bigint on spokes) |
| Status column | `status` enum: `pending_verification`, `active`, `revoked`, `suspended` |
| Verification | `verification_method` |
| Timestamps | `linked_at`, `revoked_at`, `created_at`, `updated_at` |
| Active semantics | `status = 'active'` |
| Uniqueness | Application guards + partial unique indexes (SQLite tests); MariaDB relies on `AccountLinkService` guards |

### Desk population (production)

| Metric | Count |
|---|---|
| Total rows | 135 |
| **Active authoritative** | **134** |
| Inactive | 1 (`revoked`: 1) |
| Duplicate active (site+user) | 0 |

**Active by site:**

| Site | Active links |
|---|---|
| rdservice.in | 91 |
| radiumbox.com | 14 |
| rdservice.net | 29 |

## Spoke local representation (VERIFIED)

All spokes use `central_wallet_account_links` with `link_status` (not `status`).  
Role: **cache/metadata for trusted spend & checkout** — **not financial SSOT**. Desk remains authoritative.

| Spoke | Prod DB | Local active | Notes |
|---|---|---|---|
| rdservice.in | `rdservice_in_prod` | 2 | Mirror overlay deployed; `desk_link_id` populated on read |
| radiumbox.com | `radiumbox_prod` | 1 | No mirror service |
| rdservice.net | `rdservice_net_prod` | 1 | No mirror service |

Identity matching key used: **`(site_code, local_user_id)`** — matches Desk `AccountLinkController::index` and spoke `CentralWalletAccountLinkResolver`.

## Reconciliation table (production)

| Spoke | Desk Active | Spoke Local Active | Match | Missing on Spoke | Spoke-only | Wallet mismatch | Identity mismatch | Duplicate | Verification mismatch |
|---|---|---|---|---|---|---|---|---|---|
| **rdservice.in** | 91 | 2 | 2 | **89** | 0 | 0 | 0 | 0 | 0 |
| **radiumbox.com** | 14 | 1 | 1 | **13** | 0 | 0 | 0 | 0 | 0 |
| **rdservice.net** | 29 | 1 | 1 | **28** | 0 | 0 | 0 | 0 | 0 |

**Interpretation:** Large gaps are almost entirely **Desk authoritative links absent from spoke local cache**. Zero wallet-ID mismatches and zero spoke-only rows — spokes are a subset, not an divergent authority.

## Functional dependency table (code-traced)

| Spoke | Display | Trusted spend | Checkout | Refund | Identity resolution |
|---|---|---|---|---|---|
| **rdservice.in** | Remote `wallet-visibility` API — **no local link required** | Local `activeTrustedLinkForUser()` — **requires local link + trusted verification method** | Same as spend — **local trusted link** | `CentralWalletRefundDestinationResolver` → Desk HTTP — **no local link** | Ceremony creates local link; mirror can populate metadata on balance read |
| **radiumbox.com** | Remote `wallet-visibility` — **no local link** | Local trusted link | Checkout coordinator checks local trusted link | Desk HTTP refund destination — **no local link** | Account-link ceremony writes local row |
| **rdservice.net** | Remote `wallet-visibility` — **no local link** | Local trusted link | Checkout **disabled** on prod | Desk HTTP refund destination enabled — **no local link** | Ceremony / owner gate writes local row |

### Trusted verification methods (spoke spend gate)

`trusted_google`, `verified_email`, `m2_dual_otp`, `m2_whatsapp_otp` only.

**Not trusted (display/provisional only even when Desk link exists):** `owner_migration_cohort`, `canonical_account_identity`.

## Classification impact

| Classification | Count (all spokes) | Financial risk | Customer impact | Current workaround |
|---|---|---|---|---|
| desk_missing_on_spoke | 130 | **Low** for display/refund (Desk HTTP) | Historical balance visible via Desk API when route deployed; trusted spend blocked without local trusted link | `wallet-visibility` + cohort flags; rdin mirror on balance read (overlay) |
| match | 4 | None | Test user `3` trusted on all sites | — |
| wallet_id_mismatch | 0 | Would be **high** if present | None observed | — |
| spoke_only | 0 | Low | None | — |

## Sample mismatches (masked)

### rdservice.in — desk_missing_on_spoke

| local_user_id | Desk link_id | CWID prefix | verification_method | Journey impact |
|---|---|---|---|---|
| 558781 | 77 | 266700ea… | owner_migration_cohort | Display OK via visibility API (RD10575); **trusted spend blocked** (non-trusted method) |
| 129989 | 8 | fc6c141b… | verified_email | Would need local trusted link for spend; display/refund via Desk remote |
| 126483 | 30 | 325c958d… | owner_migration_cohort | Migration cohort — display only |

**Matched on rdin:** user `3` (verified_email, trusted), user `558781` (mirror overlay — provisional display).

### radiumbox.com — desk_missing_on_spoke

| local_user_id | verification_method | Notes |
|---|---|---|
| (13 users) | mostly `trusted_google`, `canonical_account_identity` | Only user `3` has local link; checkout UAT limited to user `3` |

### rdservice.net — desk_missing_on_spoke

| local_user_id | verification_method | Notes |
|---|---|---|
| (28 users) | mostly `canonical_account_identity` | Checkout off; refund destination uses Desk HTTP |

## Scale / performance

| Metric | Value |
|---|---|
| Desk rows | 135 |
| Max spoke comparison set | 91 (rdin) |
| Strategy | In-memory hash join on `(site_code, local_user_id)` |
| Indexes used | `cw_account_links_site_user_idx`, status indexes |
| Runtime | < 1s observed |
| Incremental needed? | **No** at current scale |

## Architectural decision input

| Spoke | Requires local mirror? | Evidence | Classification |
|---|---|---|---|
| **rdservice.in** | **NO** for display/refund; **YES** only for trusted spend/checkout path | `wallet-visibility` resolves from Desk; refund via HTTP; 89 out of 91 Desk links are non-trusted migration/canonical methods anyway | **(2) Local metadata mirror useful but non-authoritative** for display; **(3) required** only when enabling trusted spend beyond cohort |
| **radiumbox.com** | **NO** for current prod (display + refund remote) | Historical visibility enabled; checkout UAT user `3` only; 13/14 Desk links missing locally | **(1) Remote Desk resolution sufficient** for display/refund today |
| **rdservice.net** | **NO** for current prod | Checkout disabled; refund destination remote; 28/29 Desk links missing locally | **(1) Remote Desk resolution sufficient** |

**Do not implement bulk account-link mirrors** based on gap count alone — most gaps are non-trusted migration/canonical links with **no trusted-spend dependency**.

## Financial safety (VERIFIED)

This audit used SELECT-only queries and a read-only PHP reporter. No ledger entries, balances, reservations, refunds, or account-link mutations were performed.

**Do not run:** `central-wallet:sync-desk-account-links`, ceremony confirm, or mirror upsert during audit.

## Re-run command

```bash
php tools/audit/central-wallet-account-link-reconciliation.php \
  --desk-env=/path/to/radium-desk/.env \
  --spoke-env=/path/to/spoke/.env \
  --site-code=rdservice.in \
  --json
```

## Invariants

- **Desk `central_wallet_account_links` + LedgerService = financial SSOT**
- **UNAVAILABLE != ZERO** (display contract)
- Spoke local links = metadata cache for trusted spend, not wallet authority
