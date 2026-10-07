# Customer 360 Central Wallet identity

## Authoritative architecture

Desk Central Wallet is the sole wallet authority. Websites and Customer 360 are readers.

The permanent customer chain is:

```text
local spoke customer (site_code + local_user_id)
    → Desk customer (central_customers.id)
    → Desk Central Wallet (central_wallet_id)
```

Customer 360 adds a **display gate** on top of that chain:

```text
case order email
    → exactly one verified Desk identity credential (verified_email / desk_email)
    → that credential's Desk customer
    → that customer's Central Wallet
```

Customer 360 does **not** use account links, order email alone, migration anchors, or browser-supplied CWIDs as identity proof.

## Trusted identity sources

| Source | Customer 360 | Wallet ensure / refund destination |
|--------|--------------|--------------------------------------|
| `verified_email` / `desk_email` credential | Yes | Yes |
| `verified_mobile` / `desk_mobile` credential | Not yet for 360 email path | Yes |
| Google / M2 OTP account-link methods | No for 360 | Spending / link trust only |
| Active account link alone | **No** | Returns existing CWID only |
| `migration_cohort_anchor` | **No** | **No** — migration metadata only |
| `orders.customer_id` UUID | Veto only when conflicting | No |
| Raw order email | No | Spoke attestation input only |
| Browser CWID | Ignored | Ignored |

## Normal onboarding

When a spoke calls `CentralWalletCustomerIdentityEnsureService::ensure()` without an existing account link, Desk may:

1. Match an existing verified credential
2. Match historical contact index data
3. Provision a new Desk customer + Central Wallet + verified credential + account link

That path always creates a `verified_email` or `verified_mobile` credential for new customers.

## Migration cohort gap

Owner-authorized migration created approximately **50** production Desk customers with:

- Desk customer + Central Wallet
- active account link (`owner_migration_cohort`)
- `migration_cohort_anchor` credential
- **no** `verified_email` credential

Before this correction, `ensure()` returned immediately when an account link already existed and never backfilled the verified credential. Customer 360 therefore correctly hid those wallets even though the wallet layer was authoritative.

## Permanent correction (code)

When an active account link already exists and the spoke attests email/mobile on `ensure()`:

- Desk adds the missing `verified_email` / `verified_mobile` credential to the **linked Desk customer only**
- only when no other Desk customer already owns that credential
- without creating a new customer or wallet
- without treating `migration_cohort_anchor` as email proof

Customer 360 continues to require the verified credential. After backfill, case email resolves through the existing resolver.

## RD9064 classification

- Desk order `RD9064`, case `SC59056`, email `jogisun5865@gmail.com`
- Desk customer `c85258e3-fa6b-4213-97ef-305cb0496238`
- Central Wallet `bf43289e-6c05-4007-8349-c84ed0d3d406`
- Active rdservice.in link for local user `557730`
- Wallet activity and refund `343` are correct
- Hidden in 360 because verified `desk_email` credential is still missing

**Root cause:** migration cohort provisioning + early-return account-link path skipped verified credential creation.

**Not a resolver bug.** Do not weaken 360 to follow account links or migration anchors.

## Existing records requiring Owner gate

Approximately **50** cohort-linked customers still lack verified email credentials in production.

Safe remediation options:

1. **Lazy backfill:** next spoke `ensure()` or wallet-refund-destination call with attested email/mobile
2. **Controlled batch:** Owner-approved command that replays attested contact data per linked customer (separate prompt; not executed here)

Do not manually invent credentials from case email alone inside Customer 360.

## Forbidden shortcuts

- Account link → wallet in Customer 360
- migration_cohort_anchor → email identity
- order email string match without verified credential
- browser CWID override
- guessed spoke local user id from case email inside 360
