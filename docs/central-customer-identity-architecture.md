# Central Customer Identity + Central Wallet Architecture

## Core principles

- **Desk Customer ID** is the permanent central customer identity.
- **CWID** (Central Wallet ID) is a separate financial identity: one Desk Customer ID maps to one CWID.
- **Desk is the sole authoritative Central Wallet financial source.** Spoke balance is read-through/cache only.
- **OTP is not wallet redemption authorization.** OTP establishes or re-establishes trusted identity only when required.
- **Email/mobile string equality alone is not sufficient** for cross-site identity.

## Identity states

### Trusted identity

Verified Google subject or verified email credential resolves Desk Customer ID → CWID. Normal Central Wallet balance display and checkout/reservation authorization are permitted (subject to existing gates).

### Provisional identity

Authenticated spoke users with **unverified email** may receive a **read-only provisional balance** when Desk finds **exactly one** existing customer with a matching **verified_email** credential hash. This is informational only.

Provisional matching rule (unambiguous):

1. Spoke sends normalized email with `email_verified=false`.
2. Desk hashes email identically to verified_email credentials.
3. Exactly **one** `verified_email` credential match → provisional balance returned.
4. Zero matches → unresolved (no balance).
5. Conflicting active site link to a different CWID → ambiguous (fail closed).
6. Existing trusted active link → use trusted path instead.

Provisional responses **never** include CWID or Desk Customer ID. Financial mutation endpoints require a trusted active account link (`trusted_google`, `verified_email`, `m2_dual_otp`, `m2_whatsapp_otp`).

### Unresolved identity

Ambiguous, unmatched, or disabled states return no balance. No guessing, no auto-linking.

## Identity model

```
Desk Customer ID
  ├── verified Google identity (stable provider subject)
  ├── verified email(s)
  ├── optional verified mobile
  ├── site-local account(s): (site_code, local_user_id)
  └── Central Wallet CWID
```

Trusted credentials resolve through Desk `POST /api/central-wallet/v1/customer-identity/resolve`:

| Credential | Requirement | Verification method |
|------------|-------------|---------------------|
| Google | Stable `google_subject` from OAuth provider | `trusted_google` |
| Verified email | Spoke asserts `email_verified_at` / verified state | `verified_email` |
| Verified mobile | Optional; E.164; never sole customer key | `verified_mobile` |

Unverified email accounts must complete email OTP or Google Sign-In before Central Wallet resolution.

## Site account mapping

```
(site_code, local_user_id) → Desk Customer ID → CWID
```

- Scoped to authenticated spoke integration (`X-Site-Code` + bearer token).
- No client-supplied arbitrary Customer ID or CWID.
- Ambiguous identity matches fail closed (409).
- Legacy M2 WhatsApp OTP ceremony remains available; it does not auto-migrate historical balances.

## Balance read

1. Authenticated spoke account with active verified link
2. Desk Customer ID + CWID (local cache on spoke)
3. `GET /api/central-wallet/v1/wallets/{cwid}/balance` (Desk authoritative)
4. Short-lived spoke cache for presentation only (`central-wallet:balance-read:{site}:{user}`)
5. Checkout always uses Desk reservation/commit — never stale UI cache

## Checkout authorization

Normal wallet redemption: login → display Desk balance → reserve → pay → commit → refresh balance. **No OTP** for linked customers with trusted identity.

## Historical migration / cutover

Identity linking and checkout **never** trigger historical local-wallet migration. Migration is a separate financial operation with explicit Owner authorization.

## Rollout flags (default OFF)

| Flag | Scope |
|------|-------|
| `CENTRAL_WALLET_CUSTOMER_IDENTITY_ENABLED` | Desk resolve API |
| `CENTRAL_WALLET_CUSTOMER_IDENTITY_GOOGLE_ENABLED` | Google credential resolution |
| `CENTRAL_WALLET_CUSTOMER_IDENTITY_VERIFIED_EMAIL_ENABLED` | Verified email resolution |
| `CENTRAL_WALLET_CUSTOMER_IDENTITY_LINKING_ENABLED` | Spoke auto-link on trusted auth |
| `CENTRAL_WALLET_BALANCE_READ_ENABLED` | Spoke balance display |
| `CENTRAL_WALLET_CHECKOUT_ENABLED` | Spoke checkout reservation path |

## Future direction

Group-wide verified identity / SSO so identity established once works across all group websites without repeated verification.

## Spoke status (2026-09-30)

| Site | Auth | Trusted identity | CW mapping | Balance read | Checkout |
|------|------|------------------|------------|--------------|----------|
| radiumbox.com | Google, email/password | Implemented (flag OFF) | Local + Desk | Existing (flag OFF) | Existing (flag OFF) |
| rdservice.in | Google, email/password | Implemented (flag OFF) | Local + Desk | Not yet | Not yet |
| rdservice.net | TBD | Not in scope | — | — | — |
| rdserviceonline.in | TBD | Not in scope | — | — | — |
| radiumsign.com | TBD | Not in scope | — | — | — |
