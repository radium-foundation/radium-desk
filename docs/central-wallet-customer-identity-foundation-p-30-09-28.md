# Central Wallet — Customer Identity Foundation (P-30-09-28)

**Prompt ID:** `RadiumDesk-P-30-09-28`  
**Mode:** Identity foundation only — **no financial migration**

---

## Production deployment

| Field | Value |
|---|---|
| Server | KVM8 `srv1910783` / `187.127.129.16` |
| Document root | `/var/www/radium-desk` |
| Desk release (unchanged) | `v4.0.168` (`013ec2c6`) |
| Desk DB | `radium_desk` |
| Migration applied | `2026_09_30_200000_create_central_customer_identity_tables` (batch 45) |

### Schema deployed

- `central_customers` — YES
- `central_customer_identity_credentials` — YES
- `central_wallet_account_links.desk_customer_id` — YES (nullable FK)

### Surgical overlay (identity only)

- Migration + `CentralCustomer` / `CentralCustomerIdentityCredential` models
- `CustomerFoundationFromCeremonyService` + `central-wallet:establish-customer-from-ceremony`
- `CentralWalletAccountLink` fillable `desk_customer_id`
- Minimal `CentralWalletServiceProvider` binding

**Not deployed:** refund migration tables (`2026_10_01_*`), migration execution flags, bulk CWID creation.

---

## User 3 identity foundation

| Field | Value |
|---|---|
| REF | `REF-2026-000300` / refund **300** / **₹499** |
| Desk Customer ID | `46c69a65-7fb9-4d36-948f-32d00475fd0e` |
| CWID | `50ff2e87-6030-4ae8-b93a-163884db90c5` |
| Active link | id **7** (`radiumbox.com` user **3**) |
| Credential | `verified_mobile` / ceremony phone hash |
| Financial mutation | **NO** |

---

## Financial invariants (unchanged)

| Metric | Before | After |
|---|---:|---:|
| `central_wallet_ledger_entries` count | 0 | 0 |
| Ledger amount sum | 0 | 0 |
| `central_wallets` count | 2 | 2 |
| `central_wallet_account_links` count | 2 | 2 |
| Refund population (wallet terminal) | 291 / ₹164,991* | unchanged |

\*Live Desk query at deploy time returned 291 rows (₹164,991); authoritative 292-row manifest (₹165,708) verified in P-30-09-27 via cross-DB population SQL.

---

## CWID provisioning manifest

| Gate | Count | Amount (₹) |
|---|---:|---:|
| CWID provisioning candidates | **226** | **124,243** (227 refunds) |
| Owner identity required | **55** | **36,512** |
| Ambiguous (Owner resolution) | **10** | **4,953** |
| User 3 (existing CWID + Desk Customer) | **1** | **499** |

Artifact: `storage/app/private/cw-migration-cwid-provisioning-p30-09-28.json`

**Ceremony:** Do not bulk-create CWIDs. Each of 226 customers requires controlled ceremony or trusted identity resolve per identity rules.

---

## Remaining blockers

1. Owner resolution for 10 ambiguous spoke rows (₹4,953)
2. Owner identity for 55 rows (₹36,512)
3. Controlled CWID provisioning for 226 customers (no bulk automation)
4. Financial migration execution gate (`EXECUTION READY = NO`)
5. User 3 Lane 1 cutover (₹499 rdin → CW) — separate Owner authorization
